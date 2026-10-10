<?php
// Essência — software proprietário. Copyright (c) 2026. Todos os direitos reservados (veja LICENSE).
/* ============================================================
   Essência — Catálogo público (monta a página direto do banco)
   Abra https://SEU-SITE/catalogo.php  — sempre atualizado, sem subir arquivo.
   Mostra só produtos ativos e com estoque. Não exige senha (é público),
   mas NÃO expõe custo, estoque, fornecedor nem dados de clientes.
   ?img=ID entrega a foto do produto (com cache).
   ============================================================ */
$dbFile = __DIR__ . '/database.php';
$WHATSAPP = '';  /* número da loja com DDI+DDD, só dígitos (ex.: 5511999998888). Ou defina CATALOG_WHATSAPP no database.php */
function plain($code, $msg){ http_response_code($code); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
  echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Catálogo</title><body style="font-family:system-ui,sans-serif;text-align:center;padding:60px 20px;color:#5A6088">'.htmlspecialchars($msg).'</body>'; exit; }
if(!is_file($dbFile)) plain(503, 'Catálogo indisponível no momento.');
require $dbFile;
if(defined('CATALOG_WHATSAPP') && $WHATSAPP === '') $WHATSAPP = (string)CATALOG_WHATSAPP;
$WHATSAPP = preg_replace('/\D/', '', (string)$WHATSAPP);
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

try { $pdo = db(); } catch(Throwable $ex){ plain(503, 'Catálogo indisponível no momento.'); }

/* ---------- foto de um produto ---------- */
if(isset($_GET['img'])){
  $__ref = $_SERVER['HTTP_REFERER'] ?? '';
  if($__ref !== '' && strtolower((string)parse_url($__ref, PHP_URL_HOST)) !== strtolower((string)parse_url('//'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST))){
    http_response_code(403); exit; /* impede usar as fotos em outros sites (hotlink) */
  }
  try{
    $st = $pdo->prepare('SELECT image FROM products WHERE id = ? AND active = 1');
    $st->execute([(string)$_GET['img']]);
    $img = (string)($st->fetchColumn() ?: '');
  }catch(Throwable $ex){ $img = ''; }
  if(preg_match('#^data:(image/(?:jpeg|png|gif|webp));base64,(.+)$#s', $img, $m)){
    $bin = base64_decode($m[2], true);
    if($bin !== false){
      $etag = '"'.md5($bin).'"';
      header('ETag: '.$etag);
      header('Cache-Control: public, max-age=86400');
      header('X-Content-Type-Options: nosniff');
      if(($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag){ http_response_code(304); exit; }
      header('Content-Type: '.$m[1]);
      header('Content-Length: '.strlen($bin));
      echo $bin; exit;
    }
  }
  http_response_code(404); exit;
}

/* ---------- dados ---------- */
try{
  $cats = $pdo->query('SELECT name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
  $subs = $pdo->query('SELECT name, category FROM subcategories ORDER BY name')->fetchAll();
  $prods = $pdo->query('SELECT id,name,category,subcategory,image,sale_price,stock,brand,volume,description FROM products WHERE active = 1 AND stock > 0')->fetchAll();
}catch(Throwable $ex){ plain(503, 'Catálogo indisponível no momento.'); }

$coll = class_exists('Collator') ? new Collator('pt_BR') : null;
if($coll) $coll->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
function cmpName($a, $b){ global $coll; $x=(string)$a['name']; $y=(string)$b['name'];
  return $coll ? $coll->compare($x,$y) : strnatcasecmp($x,$y); }
function brl($v){ return 'R$ '.number_format((float)$v, 2, ',', '.'); }
function lower($s){ return mb_strtolower(trim((string)$s), 'UTF-8'); }

/* categoria > subgrupo > produtos (mesma lógica do sistema) */
$groups = []; $gidx = [];
foreach($cats as $c){ $gidx[lower($c)] = count($groups); $groups[] = ['name'=>trim($c), 'items'=>[]]; }
foreach($prods as $p){
  $n = trim((string)$p['category']); if($n === '') $n = 'Sem categoria';
  $k = lower($n);
  if(!isset($gidx[$k])){ $gidx[$k] = count($groups); $groups[] = ['name'=>$n, 'items'=>[]]; }
  $groups[$gidx[$k]]['items'][] = $p;
}
$groups = array_values(array_filter($groups, fn($g)=>count($g['items'])>0));
foreach($groups as &$g){
  $subList = []; $sidx = [];
  foreach($subs as $sc){ if(lower($sc['category']) === lower($g['name'])){ $k = lower($sc['name']); $sidx[$k] = count($subList); $subList[] = ['name'=>trim($sc['name']), 'items'=>[]]; } }
  $others = [];
  foreach($g['items'] as $p){
    $n = trim((string)$p['subcategory']);
    if($n === ''){ $others[] = $p; continue; }
    $k = lower($n);
    if(!isset($sidx[$k])){ $sidx[$k] = count($subList); $subList[] = ['name'=>$n, 'items'=>[]]; }
    $subList[$sidx[$k]]['items'][] = $p;
  }
  $filled = [];
  foreach($subList as $x){ if($x['items']){ usort($x['items'], 'cmpName'); $filled[] = $x; } }
  if($others){ usort($others, 'cmpName'); $filled[] = ['name'=>($filled ? 'Outros' : ''), 'items'=>$others]; }
  $g['count'] = count($g['items']); $g['subs'] = $filled;
}
unset($g);

function card($p){
  $img = (string)$p['image'];
  if(strncmp($img, 'data:', 5) === 0) $src = 'catalogo.php?img='.rawurlencode($p['id']).'&v='.substr(md5($img), 0, 8);
  elseif(preg_match('#^https?://#i', $img)) $src = $img;
  else $src = '';
  $im = $src !== '' ? '<img src="'.e($src).'" alt="'.e($p['name']).'" loading="lazy">' : '<div class="noimg">sem foto</div>';
  $vol = trim((string)$p['volume']); if($vol !== '' && is_numeric($vol)) $vol .= 'ml';
  $brand = trim((string)$p['brand']);
  $meta = '<div class="cat">'.(($brand !== '' || $vol !== '') ? e($brand).($brand !== '' && $vol !== '' ? ' · ' : '').e($vol) : '&nbsp;').'</div>';
  $meta_txt = trim($brand.($brand !== '' && $vol !== '' ? ' · ' : '').$vol);
  $attrs = ' data-name="'.e($p['name']).'" data-meta="'.e($meta_txt).'" data-price="'.e(brl($p['sale_price'])).'" data-desc="'.e(trim((string)($p['description'] ?? ''))).'"';
  global $WHATSAPP;
  $btn = $WHATSAPP !== ''
    ? '<a class="buy wa" target="_blank" rel="noopener" href="https://wa.me/'.e($WHATSAPP).'?text='.rawurlencode('Olá! Tenho interesse neste perfume: '.$p['name'].' ('.brl($p['sale_price']).')').'">Pedir no WhatsApp</a>'
    : '<span class="buy">Ver detalhes</span>';
  return '<div class="card"'.$attrs.'><div class="imgwrap">'.$im.'</div><div class="info">'.$meta.'<div class="name">'.e($p['name']).'</div><div class="price">'.brl($p['sale_price']).'</div>'.$btn.'</div></div>';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Essência — Catálogo</title>
<?php $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http'; $__base = $__scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'seuperfume.net').rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/')),'/').'/'; ?>
<meta name="description" content="Catálogo de perfumes da Essência. Escolha o seu!">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Essência">
<meta property="og:title" content="Essência — Catálogo de Perfumes">
<meta property="og:description" content="Veja nosso catálogo de perfumes e escolha o seu!">
<meta property="og:image" content="<?= e($__base) ?>og-catalogo.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="<?= e($__base) ?>catalogo.php">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/png" href="<?= e($__base) ?>logo.png">
<style>
  :root{--ink:#141A3C;--plum:#17204A;--plum-dark:#0D1230;--gold:#F0A81F;--gold-soft:#FBD98A;--gold-deep:#9A6400;--cream:#F1F1F8;--line:#D9DBEC;--muted:#5A6088;--tint:#E9EAF6;
    --lattice:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40' viewBox='0 0 40 40'%3E%3Cg fill='none' stroke='%23F0A81F' stroke-width='1'%3E%3Crect x='6' y='6' width='28' height='28'/%3E%3Crect x='6' y='6' width='28' height='28' transform='rotate(45 20 20)'/%3E%3Ccircle cx='20' cy='20' r='2.5'/%3E%3C/g%3E%3C/svg%3E");}
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Segoe UI Variable Text','Segoe UI',system-ui,-apple-system,'Helvetica Neue',Arial,sans-serif;background:var(--cream);color:var(--ink);font-variant-numeric:lining-nums tabular-nums;}
  header{position:relative;background:radial-gradient(circle at 25% 0,#222C66,var(--plum) 50%,var(--plum-dark));color:#fff;padding:44px 20px 40px;text-align:center;border-bottom:4px solid var(--gold);overflow:hidden;}
  header::before{content:'';position:absolute;inset:0;background:var(--lattice);background-size:40px 40px;opacity:.10;}
  header .logo{position:relative;display:block;margin:0 auto 10px;}
  header h1,header p{position:relative;}
  header h1{font-family:'Avenir Next','Century Gothic','Segoe UI Variable Display','Trebuchet MS',system-ui,sans-serif;font-weight:300;font-size:36px;margin:0;letter-spacing:.16em;}
  header p{color:var(--gold);font-size:14px;margin:8px 0 0;}
  .wrap{max-width:1180px;margin:0 auto;padding:28px 20px 60px;}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:18px;}
  .card{background:#fff;border:1px solid var(--line);border-radius:6px;overflow:hidden;transition:transform .15s ease,box-shadow .15s ease;}
  .card:hover{transform:translate(-2px,-2px);box-shadow:4px 4px 0 var(--plum);}
  .imgwrap{aspect-ratio:1/1;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}
  .imgwrap img{width:100%;height:100%;object-fit:contain;display:block;}
  .imgwrap img{background:#fff;mix-blend-mode:multiply;}
  .noimg{color:var(--muted);font-size:12px;}
  .tag-soldout{position:absolute;top:8px;right:8px;background:#B83A57;color:#fff;font-size:11px;padding:3px 8px;border-radius:3px;}
  .info{padding:14px;text-align:center;}
  .cat{color:var(--gold-deep);font-size:12px;margin-bottom:3px;}
  .name{font-weight:600;font-size:14.5px;margin-bottom:6px;}
  .price{font-family:'Avenir Next','Century Gothic','Segoe UI Variable Display','Trebuchet MS',system-ui,sans-serif;font-weight:300;font-size:21px;color:var(--plum);}
  .empty{text-align:center;color:var(--muted);padding:60px 20px;}
  .catsec{margin-bottom:34px;}
  .catsec h2{font-family:'Avenir Next','Century Gothic','Segoe UI Variable Display','Trebuchet MS',system-ui,sans-serif;font-weight:300;font-size:21px;color:var(--plum);border-bottom:2px solid var(--plum);padding-bottom:10px;margin-bottom:16px;display:flex;align-items:center;gap:10px;}
  .catsec h2 .count{font-family:inherit;font-size:12px;font-weight:600;color:var(--plum);background:var(--tint);padding:2px 9px;border-radius:3px;}
  footer{text-align:center;color:var(--muted);font-size:12px;padding:24px;}
  :focus-visible{outline:2px solid var(--gold);outline-offset:2px;}
  html{scroll-behavior:smooth;}
  .nav{position:sticky;top:0;z-index:5;display:flex;gap:8px;overflow-x:auto;padding:10px 20px;background:#fff;border-bottom:1px solid var(--line);}
  .nav a{flex:0 0 auto;text-decoration:none;color:var(--plum);font-size:13px;font-weight:600;padding:6px 12px;border:1px solid var(--line);border-radius:4px;background:var(--cream);}
  .nav a span{font-weight:400;color:var(--muted);margin-left:4px;}
  .nav a:hover{border-color:var(--plum);background:var(--tint);}
  .catsec{scroll-margin-top:64px;}
  .catsec h3{font-size:14px;font-weight:600;color:var(--gold-deep);margin:20px 0 12px;display:flex;align-items:center;gap:8px;}
  .catsec h3 .count{font-size:11px;font-weight:600;color:var(--plum);background:var(--tint);padding:1px 7px;border-radius:3px;}
  .catsec h3:first-of-type{margin-top:4px;}
  img{max-width:100%;}
  @media (max-width:640px){
    header{padding:28px 16px 26px;}
    header h1{font-size:28px;letter-spacing:.12em;}
    .wrap{padding:18px 12px 44px;}
    .grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
    .card:hover{transform:none;box-shadow:none;}
    .info{padding:10px 8px 12px;}
    .cat{font-size:11px;}
    .name{font-size:13px;line-height:1.3;margin-bottom:4px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.6em;}
    .price{font-size:18px;}
    .tag-soldout{top:6px;right:6px;font-size:10px;padding:2px 6px;}
    .nav{padding:8px 12px;gap:6px;-webkit-overflow-scrolling:touch;}
    .nav a{padding:8px 12px;font-size:13px;}
    .catsec{scroll-margin-top:56px;margin-bottom:26px;}
    .catsec h2{font-size:18px;}
  }
  /* compacto + categorias separadas */
  .grid{grid-template-columns:repeat(auto-fill,150px);justify-content:start;gap:12px;}
  .imgwrap{aspect-ratio:1/1;}
  .info{padding:9px 10px 11px;}
  .name{font-size:13px;line-height:1.3;margin-bottom:4px;}
  .price{font-size:17px;}
  .catsec{background:#fff;border:1px solid var(--line);border-radius:8px;padding:0 16px 18px;margin-bottom:30px;overflow:hidden;}
  .catsec h2{background:var(--plum);color:#fff;border-bottom:3px solid var(--gold);margin:0 -16px 16px;padding:12px 16px;font-size:19px;}
  .catsec h2 .count{background:var(--gold);color:var(--plum);}
  @media (max-width:640px){
    .grid{grid-template-columns:repeat(3,minmax(0,1fr));justify-content:stretch;gap:8px;}
    .info{padding:6px 6px 8px;}
    .name{font-size:11.5px;min-height:2.6em;}
    .price{font-size:14px;}
    .cat{display:none;}
    .catsec{padding:0 10px 12px;margin-bottom:22px;}
    .catsec h2{margin:0 -10px 12px;padding:10px 12px;font-size:16px;}
  }
  @media (max-width:340px){.grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
  /* detalhe do produto */
  .card{cursor:pointer;}
  .lb{position:fixed;inset:0;z-index:50;background:rgba(13,18,48,.72);display:none;align-items:center;justify-content:center;padding:16px;}
  .lb.on{display:flex;}
  .lb-box{position:relative;background:#fff;border-radius:10px;max-width:400px;width:100%;max-height:78vh;overflow:auto;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);box-shadow:0 30px 80px rgba(0,0,0,.45);}
  .lb-img{background:#fff;display:flex;align-items:center;justify-content:center;padding:8px;min-height:140px;border-right:1px solid var(--line);}
  .lb-img img{width:100%;max-height:30vh;object-fit:contain;display:block;}
  .lb-info{padding:16px 14px 14px;}
  .lb-brand{font-size:12px;color:var(--gold-deep);letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px;}
  .lb-name{font-size:15px;font-weight:600;color:var(--plum);line-height:1.25;margin-bottom:10px;}
  .lb-price{font-size:18px;font-weight:300;color:var(--plum);margin-bottom:14px;}
  .lb-desc{font-size:12px;line-height:1.6;color:var(--ink);white-space:pre-line;border-top:1px solid var(--line);padding-top:14px;}
  .lb-desc.empty{color:var(--muted);font-style:italic;}
  .lb-x{position:absolute;top:8px;right:8px;width:34px;height:34px;border-radius:50%;border:none;background:var(--plum);color:#fff;font-size:20px;line-height:1;cursor:pointer;z-index:2;}
  @media (max-width:640px){.lb{padding:0;align-items:flex-end;}.lb-box{grid-template-columns:1fr;max-height:68vh;border-radius:14px 14px 0 0;}.lb-img{min-height:0;border-right:none;border-bottom:1px solid var(--line);}.lb-img img{max-height:22vh;}.lb-info{padding:18px 16px 22px;}.lb-name{font-size:14px;}}
  .imgwrap img,.lb-img img{-webkit-user-drag:none;user-select:none;-webkit-touch-callout:none;}
  /* tamanho padrão: todos os cards e a janela do produto têm sempre as mesmas dimensões */
  .card{display:flex;flex-direction:column;}
  .card .info{flex:1;display:flex;flex-direction:column;}
  .cat{min-height:1.25em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .name{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3;min-height:2.6em;}
  .price{margin-top:auto;}
  .lb-box{height:min(380px,86vh);grid-template-rows:minmax(0,1fr);}
  .lb-img{min-height:0;}
  .lb-info{display:flex;flex-direction:column;min-height:0;overflow:hidden;}
  .lb-desc{flex:1;min-height:0;overflow-y:auto;}
  @media (max-width:640px){.lb-box{height:68vh;grid-template-rows:minmax(0,38%) minmax(0,1fr);}.lb-img img{max-height:100%;height:100%;}}

  /* ===== layout vitrine de loja ===== */
  body{background:#F7F5F0;}
  header{padding:38px 20px 30px;border-bottom:none;}
  header p{color:var(--gold-soft);letter-spacing:.2em;text-transform:uppercase;font-size:12px;margin-top:6px;}
  .hero-tools{position:relative;max-width:560px;margin:22px auto 0;display:flex;flex-direction:column;gap:12px;align-items:stretch;}
  .search{position:relative;}
  .search input{width:100%;font:inherit;font-size:15px;padding:13px 18px 13px 44px;border-radius:999px;border:2px solid transparent;background:#fff;color:var(--ink);outline:none;box-shadow:0 6px 20px rgba(0,0,0,.25);}
  .search input:focus{border-color:var(--gold);}
  .search svg{position:absolute;left:16px;top:50%;transform:translateY(-50%);width:18px;height:18px;color:var(--muted);pointer-events:none;}
  .cta-wa{display:inline-flex;align-items:center;justify-content:center;gap:8px;align-self:center;text-decoration:none;background:#25D366;color:#073B1B;font-weight:700;font-size:14px;padding:10px 22px;border-radius:999px;box-shadow:0 4px 14px rgba(0,0,0,.25);}
  .nav{top:0;justify-content:flex-start;gap:8px;padding:12px 20px;background:rgba(247,245,240,.96);backdrop-filter:blur(6px);border-bottom:1px solid var(--line);box-shadow:0 4px 14px rgba(13,18,48,.06);scrollbar-width:none;}
  .nav::-webkit-scrollbar{display:none;}
  .nav a{border-radius:999px;border:1px solid var(--line);background:#fff;padding:8px 16px;font-size:13.5px;white-space:nowrap;}
  .nav a.on{background:var(--plum);border-color:var(--plum);color:#fff;}
  .nav a.on span{color:var(--gold-soft);}
  .wrap{padding-top:24px;}
  .catsec{background:none;border:none;border-radius:0;padding:0;margin-bottom:38px;overflow:visible;}
  .catsec h2{background:none;color:var(--plum);border:none;margin:0 0 16px;padding:0 0 12px;font-size:24px;position:relative;}
  .catsec h2::after{content:'';position:absolute;left:0;bottom:0;width:54px;height:3px;background:var(--gold);border-radius:2px;}
  .catsec h2 .count{background:var(--tint);color:var(--plum);border-radius:999px;}
  .catsec h3{margin:22px 0 12px;color:var(--gold-deep);text-transform:uppercase;letter-spacing:.08em;font-size:12.5px;}
  .grid{grid-template-columns:repeat(auto-fill,minmax(210px,1fr));justify-content:stretch;gap:20px;}
  .card{border:none;border-radius:18px;box-shadow:0 2px 6px rgba(13,18,48,.06),0 10px 24px rgba(13,18,48,.07);}
  .card:hover{transform:translateY(-4px);box-shadow:0 6px 12px rgba(13,18,48,.08),0 18px 36px rgba(13,18,48,.14);}
  .imgwrap{border-bottom:none;background:#fff;padding:10px;}
  .info{padding:4px 16px 16px;text-align:left;}
  .cat{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;}
  .name{font-size:15px;min-height:2.6em;}
  .price{font-size:22px;font-weight:600;color:var(--plum);margin:8px 0 12px;}
  .buy{display:block;text-align:center;text-decoration:none;font-size:13.5px;font-weight:600;padding:10px 12px;border-radius:999px;background:var(--plum);color:#fff;border:none;cursor:pointer;}
  .buy:hover{background:var(--plum-dark);}
  .buy.wa{background:#25D366;color:#073B1B;}
  .buy.wa:hover{background:#1FB85A;}
  .noresult{display:none;text-align:center;color:var(--muted);padding:50px 20px;}
  .lb-wa{display:none;margin-top:12px;text-align:center;text-decoration:none;background:#25D366;color:#073B1B;font-weight:700;font-size:13px;padding:10px;border-radius:999px;}
  .lb-box{border-radius:18px;}
  @media (max-width:640px){
    header{padding:24px 14px 20px;}
    .hero-tools{margin-top:16px;}
    .nav{padding:10px 12px;}
    .wrap{padding:16px 12px 44px;}
    .grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
    .card{border-radius:14px;}
    .card:hover{transform:none;}
    .info{padding:2px 10px 12px;}
    .cat{display:block;font-size:10.5px;}
    .name{font-size:13px;min-height:2.6em;}
    .price{font-size:17px;margin:6px 0 10px;}
    .buy{font-size:12.5px;padding:9px 8px;}
    .catsec h2{font-size:20px;}
    .lb-box{border-radius:18px 18px 0 0;}
  }
  @media (max-width:340px){.grid{grid-template-columns:minmax(0,1fr);}}
  @media (prefers-reduced-motion:reduce){.card{transition:none;}}
</style>
</head>
<body>
  <header>
    <svg class="logo" viewBox="0 0 84 84" width="56" height="56" aria-hidden="true"><path d="M42 3 L52.6 18.4 L70.2 13.8 L65.6 31.4 L81 42 L65.6 52.6 L70.2 70.2 L52.6 65.6 L42 81 L31.4 65.6 L13.8 70.2 L18.4 52.6 L3 42 L18.4 31.4 L13.8 13.8 L31.4 18.4 Z" fill="none" stroke="#F0A81F" stroke-width="1.6"/><path d="M42 14 L67 29 V55 L42 70 L17 55 V29 Z" fill="none" stroke="#FBD98A" stroke-width="1" opacity=".7"/><circle cx="42" cy="42" r="3.5" fill="#F0A81F"/></svg>
    <h1>Essência</h1>
    <p>Catálogo de Perfumes</p>
    <div class="hero-tools">
      <label class="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input id="q" type="search" placeholder="Buscar perfume..." aria-label="Buscar perfume" autocomplete="off"></label>
<?php if($WHATSAPP !== ''): ?>      <a class="cta-wa" target="_blank" rel="noopener" href="https://wa.me/<?= e($WHATSAPP) ?>?text=<?= rawurlencode('Olá! Vi o catálogo da Essência e gostaria de ajuda.') ?>">Falar no WhatsApp</a>
<?php endif; ?>    </div>
  </header>
<?php if(count($groups) > 1): ?>
  <nav class="nav" id="tabs"><a href="#" data-cat="all" class="on">Todos</a><?php foreach($groups as $i=>$g): ?><a href="#cat-<?= $i ?>" data-cat="cat-<?= $i ?>"><?= e($g['name']) ?> <span><?= (int)$g['count'] ?></span></a><?php endforeach; ?></nav>
<?php endif; ?>
  <div class="wrap">
<?php if(!$groups): ?>
    <div class="empty">Nenhum produto disponível no momento.</div>
<?php else: foreach($groups as $i=>$g): ?>
    <section class="catsec" id="cat-<?= $i ?>">
      <h2><?= e($g['name']) ?> <span class="count"><?= (int)$g['count'] ?></span></h2>
<?php foreach($g['subs'] as $sub): ?>
<?php if($sub['name'] !== ''): ?>      <h3><?= e($sub['name']) ?> <span class="count"><?= count($sub['items']) ?></span></h3>
<?php endif; ?>
      <div class="grid"><?php foreach($sub['items'] as $p) echo card($p); ?></div>
<?php endforeach; ?>
    </section>
<?php endforeach; endif; ?>
    <div class="noresult" id="noresult">Nenhum perfume encontrado.</div>
  </div>
  <footer>Atualizado em <?= date('d/m/Y') ?> · preços e disponibilidade sujeitos a alteração.</footer>
<div class="lb" id="lb" aria-hidden="true"><div class="lb-box" role="dialog" aria-modal="true"><button class="lb-x" type="button" aria-label="Fechar">&times;</button><div class="lb-img"><img id="lb-i" alt=""></div><div class="lb-info"><div class="lb-brand" id="lb-b"></div><div class="lb-name" id="lb-n"></div><div class="lb-price" id="lb-p"></div><div class="lb-desc" id="lb-d"></div><a class="lb-wa" id="lb-w" target="_blank" rel="noopener">Pedir no WhatsApp</a></div></div></div>
<script>
document.addEventListener('contextmenu',function(e){if(e.target&&e.target.tagName==='IMG')e.preventDefault();});
document.addEventListener('dragstart',function(e){if(e.target&&e.target.tagName==='IMG')e.preventDefault();});
(function(){
  var lb=document.getElementById('lb');if(!lb)return;
  function $(i){return document.getElementById(i);}
  function open(c){
    var ci=c.querySelector('.imgwrap img'),s=ci?ci.getAttribute('src'):'',im=$('lb-i');
    if(s){im.src=s;im.alt=c.getAttribute('data-name')||'';im.parentNode.style.display='';}else{im.removeAttribute('src');im.parentNode.style.display='none';}
    $('lb-b').textContent=c.getAttribute('data-meta')||'';
    $('lb-n').textContent=c.getAttribute('data-name')||'';
    $('lb-p').textContent=c.getAttribute('data-price')||'';
    var d=c.getAttribute('data-desc')||'',de=$('lb-d');
    de.textContent=d||'Sem descrição cadastrada.';de.className='lb-desc'+(d?'':' empty');
    var w=$('lb-w'),cw=c.querySelector('a.buy.wa');if(w){if(cw){w.href=cw.href;w.style.display='block';}else w.style.display='none';}
    lb.className='lb on';document.body.style.overflow='hidden';
  }
  function close(){lb.className='lb';document.body.style.overflow='';}
  document.addEventListener('click',function(e){
    if(e.target.closest&&e.target.closest('a.buy'))return;
    var c=e.target.closest&&e.target.closest('.card[data-name]');
    if(c&&!lb.contains(e.target)){open(c);return;}
    if((e.target===lb&&(!window._lbd||window._lbd===lb))||(e.target.closest&&e.target.closest('.lb-x')))close();
  });
  document.addEventListener('mousedown',function(e){window._lbd=e.target;},true);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
})();

(function(){
  var q=document.getElementById('q'),tabs=document.getElementById('tabs'),active='all';
  var secs=[].slice.call(document.querySelectorAll('.catsec')),nr=document.getElementById('noresult');
  function norm(s){s=(s||'').toLowerCase();try{s=s.normalize('NFD').replace(/[̀-ͯ]/g,'');}catch(e){}return s;}
  function apply(){
    var t=norm(q?q.value:''),any=false;
    secs.forEach(function(sec){
      var on=active==='all'||sec.id===active,vis=0;
      [].slice.call(sec.querySelectorAll('.grid')).forEach(function(g){
        var n=0;[].slice.call(g.querySelectorAll('.card')).forEach(function(c){
          var m=!t||norm((c.getAttribute('data-name')||'')+' '+(c.getAttribute('data-meta')||'')).indexOf(t)>-1;
          c.style.display=m?'':'none';if(m)n++;});
        g.style.display=n?'':'none';var h=g.previousElementSibling;if(h&&h.tagName==='H3')h.style.display=n?'':'none';vis+=n;});
      sec.style.display=(on&&vis)?'':'none';if(on&&vis)any=true;});
    if(nr)nr.style.display=any?'none':'block';
  }
  if(q)q.addEventListener('input',apply);
  if(tabs)tabs.addEventListener('click',function(e){
    var a=e.target.closest('a[data-cat]');if(!a)return;e.preventDefault();
    active=a.getAttribute('data-cat');[].slice.call(tabs.querySelectorAll('a')).forEach(function(x){x.className=x===a?'on':'';});
    apply();var w=document.querySelector('.wrap');if(w&&window.scrollY>w.offsetTop)window.scrollTo(0,Math.max(0,w.offsetTop-60));
  });
})();
</script>
</body>
</html>
