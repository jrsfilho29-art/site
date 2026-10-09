<?php
/* ============================================================
   Essência — Catálogo público (monta a página direto do banco)
   Abra https://SEU-SITE/catalogo.php  — sempre atualizado, sem subir arquivo.
   Mostra só produtos ativos e com estoque. Não exige senha (é público),
   mas NÃO expõe custo, estoque, fornecedor nem dados de clientes.
   ?img=ID entrega a foto do produto (com cache).
   ============================================================ */
$dbFile = __DIR__ . '/database.php';
function plain($code, $msg){ http_response_code($code); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
  echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Catálogo</title><body style="font-family:system-ui,sans-serif;text-align:center;padding:60px 20px;color:#5A6088">'.htmlspecialchars($msg).'</body>'; exit; }
if(!is_file($dbFile)) plain(503, 'Catálogo indisponível no momento.');
require $dbFile;
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

try { $pdo = db(); } catch(Throwable $ex){ plain(503, 'Catálogo indisponível no momento.'); }

/* ---------- foto de um produto ---------- */
if(isset($_GET['img'])){
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
  $prods = $pdo->query('SELECT id,name,category,subcategory,image,sale_price,stock,brand,volume FROM products WHERE active = 1 AND stock > 0')->fetchAll();
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
  $meta = ($brand !== '' || $vol !== '') ? '<div class="cat">'.e($brand).($brand !== '' && $vol !== '' ? ' · ' : '').e($vol).'</div>' : '';
  return '<div class="card"><div class="imgwrap">'.$im.'</div><div class="info">'.$meta.'<div class="name">'.e($p['name']).'</div><div class="price">'.brl($p['sale_price']).'</div></div></div>';
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
<style>
  :root{--ink:#141A3C;--plum:#17204A;--plum-dark:#0D1230;--gold:#F0A81F;--gold-soft:#FBD98A;--gold-deep:#9A6400;--cream:#F1F1F8;--line:#D9DBEC;--muted:#5A6088;--tint:#E9EAF6;
    --lattice:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40' viewBox='0 0 40 40'%3E%3Cg fill='none' stroke='%23F0A81F' stroke-width='1'%3E%3Crect x='6' y='6' width='28' height='28'/%3E%3Crect x='6' y='6' width='28' height='28' transform='rotate(45 20 20)'/%3E%3Ccircle cx='20' cy='20' r='2.5'/%3E%3C/g%3E%3C/svg%3E");}
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Segoe UI Variable Text','Segoe UI',system-ui,-apple-system,'Helvetica Neue',Arial,sans-serif;background:var(--cream);color:var(--ink);font-variant-numeric:lining-nums tabular-nums;}
  header{position:relative;background:radial-gradient(circle at 25% 0,#222C66,var(--plum) 50%,var(--plum-dark));color:#fff;padding:44px 20px 40px;text-align:center;border-bottom:4px solid var(--gold);overflow:hidden;}
  header::before{content:'';position:absolute;inset:0;background:var(--lattice);background-size:40px 40px;opacity:.10;}
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
  @media (prefers-reduced-motion:reduce){.card{transition:none;}}
</style>
</head>
<body>
  <header>
    <h1>Essência</h1>
    <p>Catálogo de Perfumes</p>
  </header>
<?php if(count($groups) > 1): ?>
  <nav class="nav"><?php foreach($groups as $i=>$g): ?><a href="#cat-<?= $i ?>"><?= e($g['name']) ?> <span><?= (int)$g['count'] ?></span></a><?php endforeach; ?></nav>
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
  </div>
  <footer>Atualizado em <?= date('d/m/Y') ?> · preços e disponibilidade sujeitos a alteração.</footer>
</body>
</html>
