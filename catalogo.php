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
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

try { $pdo = db(); } catch(Throwable $ex){ plain(503, 'Catálogo indisponível no momento.'); }
if($WHATSAPP === ''){ try{ $__m = $pdo->prepare('SELECT v FROM meta WHERE k = ?'); $__m->execute(['whatsapp']); $WHATSAPP = (string)($__m->fetchColumn() ?: ''); }catch(Throwable $__e){} }
$WHATSAPP = preg_replace('/\D/', '', (string)$WHATSAPP);
if($WHATSAPP !== '' && strlen($WHATSAPP) <= 11) $WHATSAPP = '55'.$WHATSAPP;   /* DDI do Brasil */

/* ---------- pedido feito pelo cliente no catálogo ---------- */
if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['a'] ?? '') === 'pedido'){
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  $jerr = function($code, $msg){ http_response_code($code); echo json_encode(['ok'=>false, 'error'=>$msg]); exit; };
  $ho = strtolower((string)parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST));
  $hh = strtolower((string)parse_url('//'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
  if($ho === '' || $ho !== $hh) $jerr(403, 'Origem não permitida.');
  $in = json_decode((string)file_get_contents('php://input'), true);
  if(!is_array($in)) $jerr(400, 'Dados inválidos.');
  if(trim((string)($in['website'] ?? '')) !== '') { echo json_encode(['ok'=>true, 'id'=>'x']); exit; }   /* isca anti-robô */
  $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['name'] ?? ''))));
  $phone = preg_replace('/\D/', '', (string)($in['phone'] ?? ''));
  $note = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['note'] ?? ''))));
  $payAllowed = ['PIX', 'Dinheiro', 'Cartão de Débito', 'Cartão de Crédito'];
  $payment = (string)($in['payment'] ?? '');
  if(mb_strlen($name) < 2 || mb_strlen($name) > 120) $jerr(422, 'Informe seu nome.');
  if(strlen($phone) < 10 || strlen($phone) > 13) $jerr(422, 'Informe seu WhatsApp com DDD.');
  if(!in_array($payment, $payAllowed, true)) $jerr(422, 'Escolha a forma de pagamento.');
  $note = mb_substr($note, 0, 300);
  /* itens do carrinho (aceita também o formato antigo: um único produto) */
  $raw = $in['items'] ?? null;
  if(!is_array($raw)) $raw = [['productId'=>$in['productId'] ?? '', 'qty'=>$in['qty'] ?? 1]];
  if(count($raw) < 1) $jerr(422, 'Seu carrinho está vazio.');
  if(count($raw) > 30) $jerr(422, 'Muitos itens no carrinho.');
  $want = [];
  foreach($raw as $it){
    if(!is_array($it)) continue;
    $pid = (string)($it['productId'] ?? ''); $q = (int)($it['qty'] ?? 0);
    if($pid === '' || $q < 1) continue;
    $want[$pid] = ($want[$pid] ?? 0) + $q;
  }
  if(!$want) $jerr(422, 'Seu carrinho está vazio.');
  $items = []; $total = 0.0; $units = 0;
  try{
    $st = $pdo->prepare('SELECT id,name,sale_price,stock FROM products WHERE id = ? AND active = 1 AND stock > 0');
    foreach($want as $pid => $q){
      $st->execute([$pid]); $pr = $st->fetch();
      if(!$pr) $jerr(404, 'Um dos produtos não está mais disponível. Atualize a página e confira seu carrinho.');
      if($q > 20) $jerr(422, 'Quantidade inválida.');
      if($q > (int)$pr['stock']) $jerr(422, 'Só temos '.(int)$pr['stock'].' unidade(s) de '.$pr['name'].'.');
      $price = (float)$pr['sale_price'];
      $items[] = ['productId'=>$pr['id'], 'name'=>$pr['name'], 'qty'=>$q, 'unitPrice'=>$price];
      $total += $price * $q; $units += $q;
    }
  }catch(Throwable $ex){ $jerr(500, 'Não foi possível enviar o pedido agora. Tente pelo WhatsApp.'); }
  try{
    $mysql = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
    $pdo->exec('CREATE TABLE IF NOT EXISTS online_orders (
      id VARCHAR(40) NOT NULL PRIMARY KEY, created_at VARCHAR(30) NOT NULL, product_id VARCHAR(60), product_name VARCHAR(255),
      unit_price DECIMAL(12,2) NOT NULL DEFAULT 0, qty INT NOT NULL DEFAULT 1, customer_name VARCHAR(120), phone VARCHAR(30),
      note VARCHAR(500), status VARCHAR(20) NOT NULL DEFAULT \'novo\', ip_hash VARCHAR(40),
      items TEXT, payment VARCHAR(30), total DECIMAL(12,2) NOT NULL DEFAULT 0
    )'.($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    try{ $pdo->query('SELECT items, payment, total FROM online_orders LIMIT 1'); }
    catch(Throwable $e){
      foreach(['items TEXT', 'payment VARCHAR(30)', 'total DECIMAL(12,2) NOT NULL DEFAULT 0'] as $col){ try{ $pdo->exec('ALTER TABLE online_orders ADD COLUMN '.$col); }catch(Throwable $e2){} }
    }
    $ip = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '').'|essencia'), 0, 32);
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
    $c = $pdo->prepare('SELECT COUNT(*) FROM online_orders WHERE ip_hash = ? AND created_at > ?'); $c->execute([$ip, $since]);
    if((int)$c->fetchColumn() >= 5) $jerr(429, 'Muitos pedidos em pouco tempo. Tente novamente em alguns minutos.');
    $id = 'ped_'.bin2hex(random_bytes(8));
    $summary = count($items) === 1 ? $items[0]['name'] : $items[0]['name'].' + '.(count($items)-1).' item(ns)';
    $pdo->prepare('INSERT INTO online_orders (id,created_at,product_id,product_name,unit_price,qty,customer_name,phone,note,status,ip_hash,items,payment,total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, gmdate('Y-m-d\TH:i:s\Z'), $items[0]['productId'], mb_substr($summary, 0, 250), $items[0]['unitPrice'], $units, $name, $phone, $note, 'novo', $ip, json_encode($items, JSON_UNESCAPED_UNICODE), $payment, round($total, 2)]);
    echo json_encode(['ok'=>true, 'id'=>$id, 'total'=>round($total, 2)]); exit;
  }catch(Throwable $ex){ $jerr(500, 'Não foi possível enviar o pedido agora. Tente pelo WhatsApp.'); }
}

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

/* ---------- foto em JPEG para a prévia do link (WhatsApp/Instagram) ---------- */
if(isset($_GET['ogimg'])){
  try{
    $st = $pdo->prepare('SELECT image FROM products WHERE id = ? AND active = 1');
    $st->execute([(string)$_GET['ogimg']]);
    $img = (string)($st->fetchColumn() ?: '');
  }catch(Throwable $ex){ $img = ''; }
  if(preg_match('#^data:(image/(?:jpeg|png|gif|webp));base64,(.+)$#s', $img, $m)){
    $bin = base64_decode($m[2], true);
    if($bin !== false){
      $mime = $m[1];
      if($mime !== 'image/jpeg' && function_exists('imagecreatefromstring') && function_exists('imagejpeg')){
        $im = @imagecreatefromstring($bin);
        if($im){
          $w = imagesx($im); $h = imagesy($im); $S = 600;               /* quadrado branco 600x600 */
          $can = imagecreatetruecolor($S, $S); imagefill($can, 0, 0, imagecolorallocate($can, 255, 255, 255));
          $sc = min($S/$w, $S/$h); $nw = max(1,(int)round($w*$sc)); $nh = max(1,(int)round($h*$sc));
          imagecopyresampled($can, $im, (int)(($S-$nw)/2), (int)(($S-$nh)/2), 0, 0, $nw, $nh, $w, $h);
          ob_start(); imagejpeg($can, null, 86); $jpg = ob_get_clean();
          if($jpg){ $bin = $jpg; $mime = 'image/jpeg'; }
        }
      }
      header('Content-Type: '.$mime); header('Cache-Control: public, max-age=3600'); header('X-Content-Type-Options: nosniff'); header('Content-Length: '.strlen($bin));
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
  global $WHATSAPP, $__base;
  $attrs .= ' data-id="'.e($p['id']).'" data-pv="'.e(number_format((float)$p['sale_price'], 2, '.', '')).'" data-st="'.(int)min(20, (int)$p['stock']).'"';
  if($WHATSAPP !== ''){
    $plink = $__base.'catalogo.php?p='.rawurlencode($p['id']);   /* o WhatsApp mostra a foto do produto na prévia deste link */
    $__d = substr($WHATSAPP, 2); $__ph = strlen($__d) >= 10 ? '('.substr($__d,0,2).') '.substr($__d,2,strlen($__d)-6).'-'.substr($__d,-4) : $__d;
    $attrs .= ' data-ph="'.e($__ph).'"';
    $attrs .= ' data-msg="'.e('Olá! Escolhi este perfume no catálogo da Essência: '.$p['name'].' ('.brl($p['sale_price']).'). Quero finalizar a compra! '.$plink).'"';
    $attrs .= ' data-wa="https://wa.me/'.e($WHATSAPP).'?text='.e(rawurlencode('Olá! Escolhi este perfume no catálogo da Essência: '.$p['name'].' ('.brl($p['sale_price']).'). Quero finalizar a compra! '.$plink)).'"';
  }
  $btn = '<span class="buy">Ver detalhes</span>';
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
<?php $__h = (string)($_SERVER['HTTP_HOST'] ?? 'seuperfume.net'); $__local = (bool)preg_match('/^(localhost|127\.|\[::1\]|.*\.test$|.*\.localhost$)/i', $__h); $__scheme = $__local ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http') : 'https'; $__base = $__scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'seuperfume.net').rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/')),'/').'/'; ?>
<?php
$__op = null;
if(isset($_GET['p'])){ foreach($prods as $__x){ if((string)$__x['id'] === (string)$_GET['p']){ $__op = $__x; break; } } }
if($__op){
  $__ot = trim((string)$__op['name']).' — '.brl($__op['sale_price']);
  $__od = 'Escolhi este perfume na Essência. '.trim((string)$__op['brand'].' '.(is_numeric($__op['volume']) ? $__op['volume'].'ml' : (string)$__op['volume']));
  $__oi = (strncmp((string)$__op['image'], 'data:', 5) === 0) ? $__base.'catalogo.php?ogimg='.rawurlencode($__op['id']).'&v='.substr(md5((string)$__op['image']),0,8) : (preg_match('#^https?://#i', (string)$__op['image']) ? (string)$__op['image'] : '');
?>
<meta property="og:title" content="<?= e($__ot) ?>">
<meta property="og:description" content="<?= e(trim($__od)) ?>">
<?php if($__oi !== ''): ?><meta property="og:image" content="<?= e($__oi) ?>">
<meta property="og:image:secure_url" content="<?= e($__oi) ?>">
<meta property="og:image:width" content="600">
<meta property="og:image:height" content="600">
<?php endif; ?>
<meta property="og:url" content="<?= e($__base) ?>catalogo.php?p=<?= e(rawurlencode($__op['id'])) ?>">
<?php } ?>
<meta name="description" content="Catálogo de perfumes da Essência. Escolha o seu!">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Essência">
<?php if(!$__op): ?><meta property="og:title" content="Essência — Catálogo de Perfumes">
<meta property="og:description" content="Veja nosso catálogo de perfumes e escolha o seu!">
<meta property="og:image" content="<?= e($__base) ?>og-catalogo.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="<?= e($__base) ?>catalogo.php">
<?php endif; ?>
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
  
  .lb-note{display:none;font-size:11px;color:var(--muted);text-align:center;margin-top:6px;line-height:1.4;}
  .lb-wa{display:none;margin-top:12px;text-align:center;text-decoration:none;background:#25D366;color:#073B1B;font-weight:700;font-size:14px;padding:12px;border-radius:999px;box-shadow:0 4px 12px rgba(37,211,102,.35);}
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

  /* carrinho */
  /* caixa do produto: ajusta ao conteúdo, mostrando todas as informações (rola só se a tela for menor) */
  .lb-box{height:auto !important;max-height:92vh;overflow-y:auto;grid-template-rows:auto;align-items:start;}
  .lb-info{overflow:visible;min-height:0;display:block;}
  .lb-desc{flex:none;overflow:visible;min-height:0;max-height:none;margin-bottom:4px;}
  .lb-img{align-self:stretch;}
  .lb-img img{max-height:46vh;}
  .lb-box.ni{grid-template-columns:1fr;}
  @media (min-width:641px){
    .lb-box{max-width:760px;grid-template-columns:minmax(0,1fr) minmax(0,1fr);}
    .lb-img{align-items:flex-start;padding:14px;}
    .lb-img img{max-height:60vh;}
    .lb-info{padding:22px 20px 18px;}
    .lb-name{font-size:18px;}
  }
  @media (max-width:640px){
    .lb-box{max-height:94vh;grid-template-columns:1fr;grid-template-rows:auto;}
    .lb-img img{max-height:34vh;height:auto;}
    .lb-info{padding-bottom:20px;}
  }
  .lb-act{display:block;width:100%;margin-top:10px;text-align:center;border:none;cursor:pointer;font:inherit;font-weight:700;font-size:14px;padding:12px;border-radius:999px;flex:none;}
  .lb-add{background:var(--plum);color:#fff;}
  .lb-add:hover{background:var(--plum-dark);}
  .lb-buy{background:#25D366;color:#073B1B;box-shadow:0 4px 12px rgba(37,211,102,.35);}
  .lb-buy:disabled{opacity:.5;cursor:default;}
  .cfab{position:fixed;right:14px;bottom:16px;z-index:60;display:flex;align-items:center;gap:8px;background:var(--plum);color:#fff;border:none;border-radius:999px;padding:12px 18px;font:inherit;font-weight:700;font-size:14px;box-shadow:0 8px 24px rgba(0,0,0,.3);cursor:pointer;}
  .cfab-i{font-size:17px;line-height:1;}
  .cfab #cfab-n{background:var(--gold);color:var(--plum);border-radius:999px;min-width:22px;height:22px;line-height:22px;text-align:center;font-size:12px;padding:0 6px;}
  .ct{position:fixed;inset:0;z-index:100;background:rgba(13,18,48,.62);display:none;align-items:center;justify-content:center;padding:16px;}
  .ct.on{display:flex;}
  .ct-box{position:relative;background:#fff;border-radius:18px;width:100%;max-width:440px;max-height:92vh;overflow-y:auto;padding:18px 16px 16px;box-shadow:0 30px 80px rgba(0,0,0,.4);}
  .ct-it{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);}
  .ct-im{width:64px;height:64px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:8px;flex:none;}
  div.ct-im{background:var(--tint);}
  .ct-m{min-width:0;flex:1;}
  .ct-n{font-size:13.5px;font-weight:600;color:var(--plum);line-height:1.25;}
  .ct-p{font-size:12px;color:var(--muted);margin:2px 0 6px;}
  .ct-it .of-q{margin:0;gap:10px;}
  .ct-it .of-q button{width:30px;height:30px;font-size:17px;}
  .ct-rm{border:none;background:none;color:#B83A57;font:inherit;font-size:12px;cursor:pointer;padding:6px 0 0;}
  .ct-empty{padding:18px 0;text-align:center;color:var(--muted);font-size:13px;}
  .ct-tot{display:flex;justify-content:space-between;align-items:center;padding:12px 0;font-size:16px;color:var(--plum);}
  .ct-tot b{font-size:20px;}
  .of-lbl{font-size:12px;font-weight:600;color:var(--plum);margin-bottom:6px;}
  .pay{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;}
  .pay button{font:inherit;font-size:13px;font-weight:600;padding:10px 6px;border:1.5px solid var(--line);background:#fff;color:var(--plum);border-radius:10px;cursor:pointer;}
  .pay button.on{border-color:var(--gold);background:#FFF6DF;box-shadow:inset 0 0 0 1px var(--gold);}
  .toast{position:fixed;left:50%;bottom:78px;transform:translateX(-50%) translateY(20px);z-index:130;background:var(--plum);color:#fff;padding:10px 16px;border-radius:999px;font-size:13px;font-weight:600;opacity:0;pointer-events:none;transition:.25s;max-width:90vw;text-align:center;}
  .toast.on{opacity:1;transform:translateX(-50%);}
  .ct .lb-x{top:10px;right:10px;}
  .ct .of-t{padding-right:40px;}
  .ct .of-ok{display:block;}
  /* pedido online */
  .lb-box.fm{height:auto;max-height:90vh;grid-template-columns:1fr;grid-template-rows:minmax(0,1fr);max-width:380px;}
  .lb-box.fm .lb-img{display:none;}
  .lb-box.fm .lb-info{overflow-y:auto;}
  .lb-box.fm .lb-desc,.lb-box.fm .lb-wa#lb-w,.lb-box.fm #lb-wn{display:none !important;}
  #lb-wn{display:block;}
  .lb-wa#lb-w{display:block;border:none;cursor:pointer;width:100%;font:inherit;font-weight:700;font-size:14px;}
  .of label{display:block;font-size:12px;font-weight:600;color:var(--plum);margin-bottom:10px;}
  .of input{display:block;width:100%;margin-top:4px;font:inherit;font-size:15px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);}
  .of input:focus{outline:2px solid var(--gold);border-color:var(--gold);}
  .of-t{font-size:15px;font-weight:700;color:var(--plum);margin:0 0 10px;padding-right:30px;}
  .of-q{display:flex;align-items:center;gap:14px;margin-top:4px;}
  .of-q button{width:36px;height:36px;border-radius:50%;border:1px solid var(--line);background:#fff;font-size:20px;line-height:1;cursor:pointer;color:var(--plum);}
  .of-q b{min-width:24px;text-align:center;font-size:17px;}
  .of-e{color:#B83A57;font-size:12.5px;min-height:0;margin:2px 0 8px;}
  .of-b{display:block;width:100%;margin-top:8px;border:none;background:none;color:var(--muted);font:inherit;font-size:13px;cursor:pointer;padding:8px;}
  .of-ok{text-align:center;padding:10px 4px;}
  .of-ok-i{width:54px;height:54px;border-radius:50%;background:#25D366;color:#fff;font-size:30px;line-height:54px;margin:0 auto 10px;}
  .of-ok-t{font-size:18px;font-weight:700;color:var(--plum);margin-bottom:6px;}
  .of-ok-m{font-size:13px;line-height:1.5;color:var(--ink);margin-bottom:12px;}
  .lb-box.fm #lb-ok .lb-wa{display:block;}
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
<div class="lb" id="lb" aria-hidden="true"><div class="lb-box" id="lb-box" role="dialog" aria-modal="true"><button class="lb-x" type="button" aria-label="Fechar">&times;</button><div class="lb-img"><img id="lb-i" alt=""></div><div class="lb-info" id="lb-info"><div class="lb-brand" id="lb-b"></div><div class="lb-name" id="lb-n"></div><div class="lb-price" id="lb-p"></div>
<div class="lb-desc" id="lb-d"></div>
<button type="button" class="lb-act lb-add" id="lb-add">Adicionar ao carrinho</button>
<button type="button" class="lb-act lb-buy" id="lb-buy">Comprar agora</button>
</div></div></div>
<button type="button" class="cfab" id="cfab" style="display:none" aria-label="Abrir carrinho"><span class="cfab-i">&#128722;</span><span id="cfab-n">0</span><span class="cfab-t" id="cfab-t"></span></button>
<div class="ct" id="ct" aria-hidden="true"><div class="ct-box" role="dialog" aria-modal="true">
<button class="lb-x" type="button" id="ct-x" aria-label="Fechar">&times;</button>
<div id="ct-main">
  <div class="of-t">Seu carrinho</div>
  <div id="ct-l"></div>
  <div class="ct-tot"><span>Total</span><b id="ct-t">R$ 0,00</b></div>
  <form class="of" id="ct-f" novalidate autocomplete="on">
    <label>Seu nome<input id="of-n" maxlength="120" autocomplete="name" placeholder="Como podemos te chamar?"></label>
    <label>Seu WhatsApp<input id="of-p" inputmode="tel" maxlength="16" autocomplete="tel" placeholder="(11) 99999-9999"></label>
    <div class="of-lbl">Forma de pagamento</div>
    <div class="pay" id="pay"><button type="button" data-pay="PIX">PIX</button><button type="button" data-pay="Dinheiro">Dinheiro</button><button type="button" data-pay="Cartão de Débito">Cartão de débito</button><button type="button" data-pay="Cartão de Crédito">Cartão de crédito</button></div>
    <label>Observação (opcional)<input id="of-o" maxlength="300" placeholder="Ex.: prefiro retirar na loja"></label>
    <input id="of-h" name="contato_extra" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0">
    <div class="of-e" id="of-e" role="alert"></div>
    <button type="submit" class="lb-act lb-buy" id="of-s">Enviar pedido para a loja</button>
    <div class="lb-note" id="ct-n" style="display:block">O pagamento e a entrega são combinados com a loja pelo WhatsApp.</div>
    <button type="button" class="of-b" id="ct-more">Continuar escolhendo</button>
  </form>
</div>
<div class="of-ok" id="ct-ok" style="display:none"><div class="of-ok-i">&#10003;</div><div class="of-ok-t">Pedido enviado!</div><div class="of-ok-m" id="ct-okm"></div><a class="lb-act lb-buy" id="ct-ow" target="_blank" rel="noopener" style="display:none;text-decoration:none;text-align:center">Falar agora no WhatsApp</a><button type="button" class="of-b" id="ct-oc">Fechar</button></div>
</div></div>
<div class="toast" id="toast" role="status"></div>
<script>
document.addEventListener('contextmenu',function(e){if(e.target&&e.target.tagName==='IMG')e.preventDefault();});
document.addEventListener('dragstart',function(e){if(e.target&&e.target.tagName==='IMG')e.preventDefault();});
(function(){
  var lb=document.getElementById('lb');if(!lb)return;
  function $(i){return document.getElementById(i);}
  var WA=<?= json_encode($WHATSAPP) ?>;
  var cur=null,cart=[],pay='';
  function ls(k,v){try{if(v===undefined)return localStorage.getItem(k)||'';localStorage.setItem(k,v);}catch(e){return '';}}
  function brl(v){return 'R$ '+(Math.round(v*100)/100).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');}
  function mask(v){v=v.replace(/\D/g,'').slice(0,11);if(v.length>6)return '('+v.slice(0,2)+') '+v.slice(2,v.length>10?7:6)+'-'+v.slice(v.length>10?7:6);if(v.length>2)return '('+v.slice(0,2)+') '+v.slice(2);return v;}
  function cardById(id){var cs=document.querySelectorAll('.card[data-id]');for(var i=0;i<cs.length;i++)if(cs[i].getAttribute('data-id')===id)return cs[i];return null;}
  function saveCart(){ls('ess_cart',JSON.stringify(cart.map(function(i){return {id:i.id,qty:i.qty};})));}
  function loadCart(){
    var raw=[];try{raw=JSON.parse(ls('ess_cart')||'[]');}catch(e){}
    cart=[];(raw||[]).forEach(function(r){var c=cardById(r&&r.id);if(!c)return;cart.push(fromCard(c,Math.max(1,Math.min(+r.qty||1,+c.getAttribute('data-st')||1))));});
  }
  function fromCard(c,q){var im=c.querySelector('.imgwrap img');return {id:c.getAttribute('data-id'),name:c.getAttribute('data-name')||'',price:+c.getAttribute('data-pv')||0,max:+c.getAttribute('data-st')||1,qty:q,img:im?im.getAttribute('src'):''};}
  function count(){return cart.reduce(function(a,i){return a+i.qty;},0);}
  function total(){return cart.reduce(function(a,i){return a+i.qty*i.price;},0);}
  function toast(m){var t=$('toast');t.textContent=m;t.className='toast on';clearTimeout(toast._t);toast._t=setTimeout(function(){t.className='toast';},2200);}
  function fab(){var n=count(),f=$('cfab');f.style.display=n?'flex':'none';$('cfab-n').textContent=n;$('cfab-t').textContent=brl(total());}
  function add(c){
    var id=c.getAttribute('data-id'),it=null;cart.forEach(function(i){if(i.id===id)it=i;});
    if(it){if(it.qty>=it.max){toast('Máximo disponível: '+it.max+' un.');return false;}it.qty++;}
    else cart.push(fromCard(c,1));
    saveCart();fab();return true;
  }
  function drawCart(){
    var l=$('ct-l');l.innerHTML='';
    if(!cart.length){var em=document.createElement('div');em.className='ct-empty';em.textContent='Seu carrinho está vazio.';l.appendChild(em);}
    cart.forEach(function(i,ix){
      var r=document.createElement('div');r.className='ct-it';
      var im=document.createElement(i.img?'img':'div');if(i.img){im.src=i.img;im.alt='';}im.className='ct-im';r.appendChild(im);
      var m=document.createElement('div');m.className='ct-m';var n=document.createElement('div');n.className='ct-n';n.textContent=i.name;var p=document.createElement('div');p.className='ct-p';p.textContent=brl(i.price)+' × '+i.qty+' = '+brl(i.price*i.qty);
      var q=document.createElement('div');q.className='of-q';
      [['−','dec'],['',''],['+','inc']].forEach(function(x,k){if(k===1){var b=document.createElement('b');b.textContent=i.qty;q.appendChild(b);return;}var bt=document.createElement('button');bt.type='button';bt.textContent=x[0];bt.setAttribute('data-op',x[1]);bt.setAttribute('data-ix',ix);bt.setAttribute('aria-label',x[1]==='dec'?'Menos':'Mais');q.appendChild(bt);});
      var rm=document.createElement('button');rm.type='button';rm.className='ct-rm';rm.textContent='Remover';rm.setAttribute('data-op','rm');rm.setAttribute('data-ix',ix);
      m.appendChild(n);m.appendChild(p);m.appendChild(q);m.appendChild(rm);r.appendChild(m);l.appendChild(r);
    });
    $('ct-t').textContent=brl(total());
    $('of-s').disabled=!cart.length;
    var bs=$('pay').querySelectorAll('button');for(var k=0;k<bs.length;k++)bs[k].className=bs[k].getAttribute('data-pay')===pay?'on':'';
  }
  function openCart(){
    close();$('toast').className='toast';
    $('ct-main').style.display='block';$('ct-ok').style.display='none';
    $('of-n').value=$('of-n').value||ls('ess_c_n');$('of-p').value=$('of-p').value||ls('ess_c_p');
    pay=pay||ls('ess_c_pay');
    $('of-e').textContent='';drawCart();
    $('ct').className='ct on';document.body.style.overflow='hidden';
  }
  function closeCart(){$('ct').className='ct';document.body.style.overflow='';}
  function open(c){
    var ci=c.querySelector('.imgwrap img'),s=ci?ci.getAttribute('src'):'',im=$('lb-i');
    if(s){im.src=s;im.alt=c.getAttribute('data-name')||'';im.parentNode.style.display='';}else{im.removeAttribute('src');im.parentNode.style.display='none';}
    $('lb-b').textContent=c.getAttribute('data-meta')||'';
    $('lb-n').textContent=c.getAttribute('data-name')||'';
    $('lb-p').textContent=c.getAttribute('data-price')||'';
    var d=c.getAttribute('data-desc')||'',de=$('lb-d');
    de.textContent=d||'Sem descrição cadastrada.';de.className='lb-desc'+(d?'':' empty');
    cur=c;$('lb-box').classList.toggle('ni',!s);
    lb.className='lb on';document.body.style.overflow='hidden';
  }
  function close(){lb.className='lb';if($('ct').className.indexOf('on')<0)document.body.style.overflow='';}
  document.addEventListener('click',function(e){
    var t=e.target;
    if(t.id==='lb-add'){if(cur&&add(cur)){close();toast('Adicionado ao carrinho ✓');}return;}
    if(t.id==='lb-buy'){if(cur&&add(cur))openCart();else if(cur)openCart();return;}
    if(t.closest&&t.closest('#cfab')){openCart();return;}
    if(t.id==='ct-x'||t.id==='ct-more'||t.id==='ct-oc'){closeCart();return;}
    if(t.id==='ct'&&(!window._lbd||window._lbd===t)){closeCart();return;}
    if(t.getAttribute&&t.getAttribute('data-pay')){pay=t.getAttribute('data-pay');ls('ess_c_pay',pay);$('of-e').textContent='';drawCart();return;}
    var op=t.getAttribute&&t.getAttribute('data-op');
    if(op){var ix=+t.getAttribute('data-ix'),it=cart[ix];if(!it)return;
      if(op==='inc'){if(it.qty<it.max)it.qty++;else toast('Máximo disponível: '+it.max+' un.');}
      else if(op==='dec'){it.qty--;if(it.qty<1)cart.splice(ix,1);}
      else cart.splice(ix,1);
      saveCart();fab();drawCart();return;}
    var c=t.closest&&t.closest('.card[data-name]');
    if(c&&!lb.contains(t)){open(c);return;}
    if((t===lb&&(!window._lbd||window._lbd===lb))||(t.closest&&t.closest('.lb-x')&&t.id!=='ct-x'))close();
  });
  document.addEventListener('input',function(e){var er=$('of-e');if(er&&e.target.closest&&e.target.closest('#ct-f'))er.textContent='';if(e.target.id==='of-p'){var v=mask(e.target.value);if(v!==e.target.value)e.target.value=v;}});
  document.addEventListener('submit',function(e){
    if(e.target.id!=='ct-f')return;e.preventDefault();
    var n=$('of-n').value.trim(),ph=$('of-p').value.replace(/\D/g,''),er=$('of-e'),btn=$('of-s');
    if(!cart.length){er.textContent='Seu carrinho está vazio.';return;}
    if(n.length<2){er.textContent='Informe seu nome.';$('of-n').focus();return;}
    if(ph.length<10){er.textContent='Informe seu WhatsApp com DDD.';$('of-p').focus();return;}
    if(!pay){er.textContent='Escolha a forma de pagamento.';return;}
    er.textContent='';btn.disabled=true;btn.textContent='Enviando...';
    var sent=cart.slice(),tot=total();
    fetch('catalogo.php?a=pedido',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:sent.map(function(i){return {productId:i.id,qty:i.qty};}),name:n,phone:ph,payment:pay,note:$('of-o').value,website:$('of-h').value})})
    .then(function(r){return r.json().catch(function(){return {ok:false,error:'Erro ao enviar.'};});})
    .then(function(j){
      btn.disabled=false;btn.textContent='Enviar pedido para a loja';
      if(!j.ok){er.textContent=j.error||'Não foi possível enviar. Tente novamente.';return;}
      ls('ess_c_n',n);ls('ess_c_p',$('of-p').value);
      var nn=sent.reduce(function(a,i){return a+i.qty;},0);
      $('ct-okm').textContent='Obrigado, '+n.split(' ')[0]+'! Recebemos seu pedido ('+nn+(nn===1?' item':' itens')+', '+brl(tot)+', pagamento: '+pay+'). A loja vai falar com você pelo WhatsApp '+$('of-p').value+' para combinar pagamento e entrega.';
      var ow=$('ct-ow');if(WA){ow.href='https://wa.me/'+WA+'?text='+encodeURIComponent('Olá! Acabei de enviar um pedido pelo catálogo em nome de '+n+'.');ow.style.display='block';}else ow.style.display='none';
      cart=[];saveCart();fab();$('of-o').value='';
      $('ct-main').style.display='none';$('ct-ok').style.display='block';
    }).catch(function(){btn.disabled=false;btn.textContent='Enviar pedido para a loja';er.textContent='Sem conexão. Verifique a internet e tente novamente.';});
  });
  document.addEventListener('mousedown',function(e){window._lbd=e.target;},true);
  document.addEventListener('keydown',function(e){if(e.key==='Escape'){close();closeCart();}});
  loadCart();fab();
  try{var pid=new URLSearchParams(location.search).get('p');if(pid){var c0=cardById(pid);if(c0)open(c0);}}catch(e){}
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
