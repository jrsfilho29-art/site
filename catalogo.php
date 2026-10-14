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
/* vendedores extras: catalogo.php?vend=ID usa o WhatsApp desse vendedor e marca os pedidos com o nome dele */
$SELLERS = []; $SELLER_ID = ''; $SELLER_NAME = '';
try{ $__q = $pdo->prepare('SELECT v FROM meta WHERE k = ?'); $__q->execute(['sellers']); $__j = json_decode((string)($__q->fetchColumn() ?: '[]'), true); if(is_array($__j)) $SELLERS = $__j; }catch(Throwable $__e){}
$__vend = isset($_GET['vend']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string)$_GET['vend'])) : '';
if($__vend !== ''){ foreach($SELLERS as $__s){ if(($__s['id'] ?? '') === $__vend){ $SELLER_ID = $__vend; $SELLER_NAME = (string)$__s['name']; $__ph = preg_replace('/\D/', '', (string)$__s['phone']); if(strlen($__ph) <= 11) $__ph = '55'.$__ph; $WHATSAPP = $__ph; break; } } }

/* ---------- PIX (BR Code "copia e cola") ---------- */
$PIX = null;
try{ $__q = $pdo->prepare('SELECT v FROM meta WHERE k = ?'); $__q->execute(['pix']); $__j = json_decode((string)($__q->fetchColumn() ?: ''), true); if(is_array($__j) && !empty($__j['key'])) $PIX = $__j; }catch(Throwable $__e){}
function pix_tlv($id, $v){ return $id.str_pad((string)strlen($v), 2, '0', STR_PAD_LEFT).$v; }
function pix_crc($str){ $crc = 0xFFFF; for($i = 0, $n = strlen($str); $i < $n; $i++){ $crc ^= ord($str[$i]) << 8; for($b = 0; $b < 8; $b++){ $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1); $crc &= 0xFFFF; } } return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT)); }
function pix_text($t, $max){
  $t = strtr((string)$t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ü'=>'U','Ç'=>'C']);
  $t = strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', $t));
  return trim(substr($t, 0, $max));
}
function pix_key_norm($type, $key){
  $key = trim((string)$key);
  if($type === 'email') return strtolower($key);
  if($type === 'random') return strtolower($key);
  $d = preg_replace('/\D/', '', $key);
  if($type === 'phone'){ if(strlen($d) >= 12 && substr($d, 0, 2) === '55') $d = substr($d, 2); return '+55'.$d; }
  return $d;   /* CPF/CNPJ */
}
function pix_payload($pix, $amount, $txid){
  $key = pix_key_norm($pix['type'] ?? '', $pix['key'] ?? '');
  if($key === '') return '';
  $name = pix_text($pix['name'] ?? '', 25); if($name === '') $name = 'LOJA';
  $city = pix_text($pix['city'] ?? '', 15); if($city === '') $city = 'BRASIL';
  $tx = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$txid), 0, 25); if($tx === '') $tx = '***';
  $acc = pix_tlv('00', 'br.gov.bcb.pix').pix_tlv('01', $key);
  $p = pix_tlv('00', '01').pix_tlv('26', $acc).pix_tlv('52', '0000').pix_tlv('53', '986');
  if($amount > 0) $p .= pix_tlv('54', number_format($amount, 2, '.', ''));
  $p .= pix_tlv('58', 'BR').pix_tlv('59', $name).pix_tlv('60', $city).pix_tlv('62', pix_tlv('05', $tx));
  $p .= '6304';
  return $p.pix_crc($p);
}


/* ---------- FRETE ---------- */
$SHIP = ['on'=>false];
try{ $__q = $pdo->prepare('SELECT v FROM meta WHERE k = ?'); $__q->execute(['shipping']); $__j = json_decode((string)($__q->fetchColumn() ?: ''), true); if(is_array($__j) && !empty($__j['on'])) $SHIP = $__j; }catch(Throwable $__e){}
function ship_norm($t){
  $t = mb_strtolower(trim((string)$t), 'UTF-8');
  $t = strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
  return trim(preg_replace('/[^a-z0-9]+/', ' ', $t));
}
function ship_money($v){ return 'R$ '.number_format((float)$v, 2, ',', '.'); }
/* devolve ['mode'=>'free|paid|combine', 'price'=>float, 'days'=>string, 'label'=>string] */
function ship_quote($ship, $city, $uf, $sub){
  $uf = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$uf)); $ck = ship_norm($city);
  $found = null;
  foreach(preg_split('/\r?\n/', (string)($ship['rules'] ?? '')) as $ln){
    if(strpos($ln, '=') === false) continue;
    list($k, $r) = explode('=', $ln, 2);
    $parts = explode('|', $r, 2);
    $price = (float)str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $parts[0])); $days = trim($parts[1] ?? '');
    $k = trim($k); $kuf = '';
    if(preg_match('/^(.*?)[\s]*[-\/,][\s]*([A-Za-z]{2})$/u', $k, $m)){ $kc = ship_norm($m[1]); $kuf = strtoupper($m[2]); }
    elseif(preg_match('/^[A-Za-z]{2}$/', $k)){ if(strtoupper($k) === $uf && !isset($found['state'])) $found['state'] = [$price, $days]; continue; }
    else { $kc = ship_norm($k); }
    if($kc !== '' && $kc === $ck && ($kuf === '' || $kuf === $uf)) $found['city'] = [$price, $days];
  }
  $hit = $found['city'] ?? ($found['state'] ?? null);
  $defd = (string)($ship['days'] ?? '');
  if($hit){ $price = $hit[0]; $days = $hit[1] !== '' ? $hit[1] : $defd; }
  elseif(isset($ship['def']) && $ship['def'] !== null && $ship['def'] !== ''){ $price = (float)$ship['def']; $days = $defd; }
  else return ['mode'=>'combine', 'price'=>0.0, 'days'=>'', 'label'=>'Frete a combinar pelo WhatsApp'];
  $free = (float)($ship['free'] ?? 0);
  if($price <= 0 || ($free > 0 && $sub >= $free)) return ['mode'=>'free', 'price'=>0.0, 'days'=>$days, 'label'=>'Frete grátis'];
  return ['mode'=>'paid', 'price'=>round($price, 2), 'days'=>$days, 'label'=>'Frete '.ship_money($price)];
}
function ship_http($url){
  $raw = false;
  if(function_exists('curl_init')){ $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>6, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>'essencia-catalogo']); $raw = curl_exec($c); curl_close($c); }
  if(!$raw){ $raw = @file_get_contents($url, false, stream_context_create(['http'=>['timeout'=>6, 'header'=>"User-Agent: essencia-catalogo\r\n"]])); }
  return $raw;
}
/* centro aproximado de cada estado (reserva quando o CEP não tem coordenadas) */
$UFC = ['AC'=>[-9.97,-67.81],'AL'=>[-9.67,-35.74],'AP'=>[0.03,-51.07],'AM'=>[-3.12,-60.02],'BA'=>[-12.97,-38.50],'CE'=>[-3.73,-38.52],'DF'=>[-15.79,-47.88],'ES'=>[-20.32,-40.34],'GO'=>[-16.68,-49.25],'MA'=>[-2.53,-44.30],'MT'=>[-15.60,-56.10],'MS'=>[-20.45,-54.62],'MG'=>[-19.92,-43.94],'PA'=>[-1.46,-48.50],'PB'=>[-7.12,-34.86],'PR'=>[-25.43,-49.27],'PE'=>[-8.05,-34.88],'PI'=>[-5.09,-42.80],'RJ'=>[-22.91,-43.17],'RN'=>[-5.79,-35.21],'RS'=>[-30.03,-51.22],'RO'=>[-8.76,-63.90],'RR'=>[2.82,-60.67],'SC'=>[-27.59,-48.55],'SP'=>[-23.55,-46.63],'SE'=>[-10.91,-37.07],'TO'=>[-10.18,-48.33]];
/* dados do CEP: cidade, UF, rua, bairro e coordenadas (BrasilAPI; reserva ViaCEP). Guarda em cache por 30 dias. */
function ship_geo($cep){
  global $UFC;
  $cep = preg_replace('/\D/', '', (string)$cep); if(strlen($cep) !== 8) return null;
  $f = sys_get_temp_dir().'/ess_cep_'.$cep.'.json';
  if(is_file($f) && filemtime($f) > time() - 2592000){ $j = json_decode((string)@file_get_contents($f), true); if(is_array($j)) return $j; }
  $g = null;
  $j = json_decode((string)ship_http('https://brasilapi.com.br/api/cep/v2/'.$cep), true);
  if(is_array($j) && !empty($j['city']) && !empty($j['state'])){
    $co = $j['location']['coordinates'] ?? [];
    $g = ['street'=>(string)($j['street'] ?? ''), 'district'=>(string)($j['neighborhood'] ?? ''), 'city'=>(string)$j['city'], 'uf'=>strtoupper((string)$j['state']),
          'lat'=>isset($co['latitude']) && $co['latitude'] !== '' ? (float)$co['latitude'] : null, 'lng'=>isset($co['longitude']) && $co['longitude'] !== '' ? (float)$co['longitude'] : null, 'approx'=>false];
  }
  if(!$g){
    $v = json_decode((string)ship_http('https://viacep.com.br/ws/'.$cep.'/json/'), true);
    if(is_array($v) && empty($v['erro']) && !empty($v['localidade'])) $g = ['street'=>(string)($v['logradouro'] ?? ''), 'district'=>(string)($v['bairro'] ?? ''), 'city'=>(string)$v['localidade'], 'uf'=>strtoupper((string)($v['uf'] ?? '')), 'lat'=>null, 'lng'=>null, 'approx'=>false];
  }
  if(!$g) return null;
  if($g['lat'] === null || $g['lng'] === null || ($g['lat'] == 0 && $g['lng'] == 0)){
    if(isset($UFC[$g['uf']])){ $g['lat'] = $UFC[$g['uf']][0]; $g['lng'] = $UFC[$g['uf']][1]; $g['approx'] = true; } else { $g['lat'] = null; $g['lng'] = null; }
  }
  @file_put_contents($f, json_encode($g, JSON_UNESCAPED_UNICODE));
  return $g;
}
function ship_km($la1, $lo1, $la2, $lo2){
  $r = 6371; $a = deg2rad($la2 - $la1); $b = deg2rad($lo2 - $lo1);
  $h = sin($a/2)**2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($b/2)**2;
  return 2 * $r * asin(min(1, sqrt($h)));
}
/* estimativa por distância: devolve lista de opções [{id,label,price,days,mode}] ou null */
function ship_dist_options($ship, $dest, $sub, $units){
  global $UFC;
  $o = ship_geo($ship['origin'] ?? '');
  if(!$o || $o['lat'] === null || !$dest || $dest['lat'] === null) return null;
  $km = ship_km($o['lat'], $o['lng'], $dest['lat'], $dest['lng']) * 1.25;   /* distância rodoviária ≈ 1,25 × linha reta */
  if($o['uf'] === $dest['uf'] && $km < 15) $km = 15;
  $pk = (float)($ship['pkgKg'] ?? 0); if($pk <= 0) $pk = 0.4;
  $w = max(0.3, $pk * max(1, (int)$units));
  $base = (float)($ship['base'] ?? 0); if($base <= 0) $base = 15;
  $p100 = (float)($ship['per100'] ?? 0); if($p100 <= 0) $p100 = 2.5;
  $pkg = (float)($ship['perKg'] ?? 0); if($pkg <= 0) $pkg = 4;
  $std = $base + $p100 * ($km / 100) + $pkg * $w + (float)($ship['add'] ?? 0);
  $std = ceil($std * 10) / 10;
  $d1 = max(2, (int)ceil($km / 450) + 2);
  $opts = [['id'=>'eco', 'name'=>'Econômico', 'price'=>$std, 'days'=>$d1.($d1 === 1 ? ' dia útil' : ' dias úteis')]];
  if(!empty($ship['express'])){
    $m = (float)($ship['expMult'] ?? 0); if($m < 1) $m = 1.6;
    $d2 = max(1, (int)ceil($km / 800) + 1);
    $opts[] = ['id'=>'exp', 'name'=>'Expresso', 'price'=>ceil($std * $m * 10) / 10, 'days'=>$d2.($d2 === 1 ? ' dia útil' : ' dias úteis')];
  }
  $free = (float)($ship['free'] ?? 0);
  foreach($opts as $i => &$q){
    if($i === 0 && $free > 0 && $sub >= $free){ $q['price'] = 0.0; $q['mode'] = 'free'; $q['label'] = $q['name'].' · Frete grátis'; }
    else { $q['mode'] = 'paid'; $q['label'] = $q['name'].' · '.ship_money($q['price']); }
  }
  unset($q);
  return $opts;
}
/* opções de frete para um destino (modo distância com reserva nas regras) */
function ship_options($ship, $cep, $city, $uf, $sub, $units){
  global $UFC;
  if(($ship['mode'] ?? 'rules') === 'distance'){
    $dest = ship_geo($cep);
    if(!$dest && isset($UFC[strtoupper($uf)])) $dest = ['city'=>$city, 'uf'=>strtoupper($uf), 'lat'=>$UFC[strtoupper($uf)][0], 'lng'=>$UFC[strtoupper($uf)][1], 'approx'=>true];
    $o = ship_dist_options($ship, $dest, $sub, $units);
    if($o) return $o;
  }
  $q = ship_quote($ship, $city, $uf, $sub); $q['id'] = 'regra'; $q['name'] = 'Entrega';
  return [$q];
}
/* consulta de frete */
if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['a'] ?? '') === 'frete'){
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  $ho = strtolower((string)parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST));
  $hh = strtolower((string)parse_url('//'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
  if($ho === '' || $ho !== $hh){ http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Origem não permitida.']); exit; }
  if(empty($SHIP['on'])){ echo json_encode(['ok'=>false, 'error'=>'Frete indisponível.']); exit; }
  $in = json_decode((string)file_get_contents('php://input'), true); if(!is_array($in)) $in = [];
  $cep = preg_replace('/\D/', '', (string)($in['cep'] ?? '')); $sub = max(0, (float)($in['sub'] ?? 0)); $units = max(1, min(500, (int)($in['units'] ?? 1)));
  $city = trim(strip_tags((string)($in['city'] ?? ''))); $uf = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($in['uf'] ?? '')));
  $addr = null;
  if($city === '' || strlen($uf) !== 2){
    if(strlen($cep) !== 8){ echo json_encode(['ok'=>false, 'error'=>'Informe um CEP com 8 dígitos.']); exit; }
    $addr = ship_geo($cep);
    if(!$addr){ echo json_encode(['ok'=>false, 'manual'=>true, 'error'=>'Não localizamos esse CEP. Informe cidade e estado.']); exit; }
    $city = $addr['city']; $uf = strtoupper($addr['uf']);
  }
  $opts = ship_options($SHIP, $cep, $city, $uf, $sub, $units);
  echo json_encode(['ok'=>true, 'address'=>$addr ? ['street'=>$addr['street'], 'district'=>$addr['district']] : null, 'city'=>$city, 'uf'=>$uf, 'options'=>$opts, 'estimate'=>(($SHIP['mode'] ?? 'rules') === 'distance')], JSON_UNESCAPED_UNICODE); exit;
}

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
  $sellerId = ''; $sellerName = '';
  $__sv = preg_replace('/[^a-z0-9]/', '', strtolower((string)($in['seller'] ?? '')));
  if($__sv !== ''){ foreach($SELLERS as $__s){ if(($__s['id'] ?? '') === $__sv){ $sellerId = $__sv; $sellerName = mb_substr((string)$__s['name'], 0, 80); break; } } }
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
    $__pc = true; try{ $pdo->query('SELECT promo_price FROM products LIMIT 1'); }catch(Throwable $__e){ $__pc = false; }
    $st = $pdo->prepare('SELECT id,name,sale_price,'.($__pc ? 'promo_price,' : '').'stock FROM products WHERE id = ? AND active = 1 AND stock > 0');
    foreach($want as $pid => $q){
      $st->execute([$pid]); $pr = $st->fetch();
      if(!$pr) $jerr(404, 'Um dos produtos não está mais disponível. Atualize a página e confira seu carrinho.');
      if($q > 100000) $jerr(422, 'Quantidade inválida.');
      if($q > (int)$pr['stock']) $jerr(422, 'Só temos '.(int)$pr['stock'].' unidade(s) de '.$pr['name'].'.');
      $price = eff_price($pr);
      $items[] = ['productId'=>$pr['id'], 'name'=>$pr['name'], 'qty'=>$q, 'unitPrice'=>$price];
      $total += $price * $q; $units += $q;
    }
  }catch(Throwable $ex){ $jerr(500, 'Não foi possível enviar o pedido agora. Tente pelo WhatsApp.'); }
  /* entrega / frete (sempre recalculado aqui, nunca confiado ao navegador) */
  $shipCost = 0.0; $dl = '';
  $dv = is_array($in['delivery'] ?? null) ? $in['delivery'] : [];
  if(!empty($SHIP['on'])){
    $cl = function($k, $n) use ($dv){ return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)($dv[$k] ?? '')))), 0, $n); };
    if(($dv['mode'] ?? '') === 'home'){
      $dcep = preg_replace('/\D/', '', (string)($dv['cep'] ?? ''));
      $dcity = $cl('city', 80); $duf = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($dv['uf'] ?? '')));
      $dst = $cl('street', 120); $dnum = $cl('number', 20);
      if(strlen($dcep) !== 8 || $dcity === '' || strlen($duf) !== 2 || $dst === '' || $dnum === '') $jerr(422, 'Preencha o endereço de entrega (CEP, rua, número, cidade e estado).');
      $opts = ship_options($SHIP, $dcep, $dcity, $duf, $total, $units); $q = $opts[0];
      foreach($opts as $__o){ if(($dv['service'] ?? '') === $__o['id']){ $q = $__o; break; } }
      $shipCost = (float)$q['price'];
      $dl = 'ENTREGA: '.$dst.', '.$dnum.($cl('comp', 60) !== '' ? ' - '.$cl('comp', 60) : '').($cl('district', 80) !== '' ? ', '.$cl('district', 80) : '').' - '.$dcity.'/'.$duf.' - CEP '.substr($dcep, 0, 5).'-'.substr($dcep, 5).' | '.($q['mode'] === 'combine' ? 'Frete a combinar' : (($q['name'] ?? '') !== '' && $q['id'] !== 'regra' ? $q['name'].' - ' : '').($q['mode'] === 'free' ? 'Frete grátis' : 'Frete '.ship_money($shipCost))).($q['days'] !== '' ? ' ('.$q['days'].')' : '');
    } elseif(!empty($SHIP['pickup'])){ $dl = 'RETIRADA NA LOJA'; }
    elseif(($dv['mode'] ?? '') !== 'home') $jerr(422, 'Escolha como deseja receber (entrega).');
    if($dl !== '') $note = mb_substr($dl.($note !== '' ? ' || Obs.: '.$note : ''), 0, 500);
    $total += $shipCost;
  }
  try{
    $mysql = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
    $pdo->exec('CREATE TABLE IF NOT EXISTS online_orders (
      id VARCHAR(40) NOT NULL PRIMARY KEY, created_at VARCHAR(30) NOT NULL, product_id VARCHAR(60), product_name VARCHAR(255),
      unit_price DECIMAL(12,2) NOT NULL DEFAULT 0, qty INT NOT NULL DEFAULT 1, customer_name VARCHAR(120), phone VARCHAR(30),
      note VARCHAR(500), status VARCHAR(20) NOT NULL DEFAULT \'novo\', ip_hash VARCHAR(40),
      items TEXT, payment VARCHAR(30), total DECIMAL(12,2) NOT NULL DEFAULT 0, seller_id VARCHAR(30), seller_name VARCHAR(80)
    )'.($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    try{ $pdo->query('SELECT items, payment, total, seller_id, seller_name FROM online_orders LIMIT 1'); }
    catch(Throwable $e){
      foreach(['items TEXT', 'payment VARCHAR(30)', 'total DECIMAL(12,2) NOT NULL DEFAULT 0', 'seller_id VARCHAR(30)', 'seller_name VARCHAR(80)'] as $col){ try{ $pdo->exec('ALTER TABLE online_orders ADD COLUMN '.$col); }catch(Throwable $e2){} }
    }
    $ip = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '').'|essencia'), 0, 32);
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
    $c = $pdo->prepare('SELECT COUNT(*) FROM online_orders WHERE ip_hash = ? AND created_at > ?'); $c->execute([$ip, $since]);
    if((int)$c->fetchColumn() >= 5) $jerr(429, 'Muitos pedidos em pouco tempo. Tente novamente em alguns minutos.');
    $id = 'ped_'.bin2hex(random_bytes(8));
    $summary = count($items) === 1 ? $items[0]['name'] : $items[0]['name'].' + '.(count($items)-1).' item(ns)';
    $pdo->prepare('INSERT INTO online_orders (id,created_at,product_id,product_name,unit_price,qty,customer_name,phone,note,status,ip_hash,items,payment,total,seller_id,seller_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, gmdate('Y-m-d\TH:i:s\Z'), $items[0]['productId'], mb_substr($summary, 0, 250), $items[0]['unitPrice'], $units, $name, $phone, $note, 'novo', $ip, json_encode($items, JSON_UNESCAPED_UNICODE), $payment, round($total, 2), $sellerId, $sellerName]);
    $resp = ['ok'=>true, 'id'=>$id, 'total'=>round($total, 2), 'shipping'=>round($shipCost, 2)];
    if($payment === 'PIX' && $PIX){
      $code = pix_payload($PIX, round($total, 2), strtoupper(substr($id, 4, 16)));
      if($code !== '') $resp['pix'] = ['code'=>$code, 'name'=>pix_text($PIX['name'] ?? '', 25), 'amount'=>round($total, 2)];
    }
    echo json_encode($resp); exit;
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
  try{ $prods = $pdo->query('SELECT id,name,category,subcategory,image,sale_price,promo_price,is_new,last_units,stock,brand,volume,description FROM products WHERE active = 1 AND stock > 0')->fetchAll(); }
  catch(Throwable $__e){ try{ $prods = $pdo->query('SELECT id,name,category,subcategory,image,sale_price,promo_price,is_new,stock,brand,volume,description FROM products WHERE active = 1 AND stock > 0')->fetchAll(); }catch(Throwable $__e2){ $prods = $pdo->query('SELECT id,name,category,subcategory,image,sale_price,stock,brand,volume,description FROM products WHERE active = 1 AND stock > 0')->fetchAll(); } }
}catch(Throwable $ex){ plain(503, 'Catálogo indisponível no momento.'); }

$coll = class_exists('Collator') ? new Collator('pt_BR') : null;
if($coll) $coll->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
function cmpName($a, $b){ global $coll; $x=(string)$a['name']; $y=(string)$b['name'];
  return $coll ? $coll->compare($x,$y) : strnatcasecmp($x,$y); }
function brl($v){ return 'R$ '.number_format((float)$v, 2, ',', '.'); }
function eff_price($p){ $pr = (float)($p['promo_price'] ?? 0); $sp = (float)$p['sale_price']; return ($pr > 0 && $pr < $sp) ? $pr : $sp; }
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
  $__eff = eff_price($p); $__promo = $__eff < (float)$p['sale_price']; $__new = !empty($p['is_new']);
  $__pct = $__promo ? (int)round((1 - $__eff / (float)$p['sale_price']) * 100) : 0; $__last = !empty($p['last_units']);
  $img = (string)$p['image'];
  if(strncmp($img, 'data:', 5) === 0) $src = 'catalogo.php?img='.rawurlencode($p['id']).'&v='.substr(md5($img), 0, 8);
  elseif(preg_match('#^https?://#i', $img)) $src = $img;
  else $src = '';
  $im = $src !== '' ? '<img src="'.e($src).'" alt="'.e($p['name']).'" loading="lazy">' : '<div class="noimg">sem foto</div>';
  $vol = trim((string)$p['volume']); if($vol !== '' && is_numeric($vol)) $vol .= 'ml';
  $brand = trim((string)$p['brand']);
  $meta = '<div class="cat">'.(($brand !== '' || $vol !== '') ? e($brand).($brand !== '' && $vol !== '' ? ' · ' : '').e($vol) : '&nbsp;').'</div>';
  $meta_txt = trim($brand.($brand !== '' && $vol !== '' ? ' · ' : '').$vol);
  $attrs = ' data-name="'.e($p['name']).'" data-meta="'.e($meta_txt).'" data-price="'.e(brl($__eff)).'" data-desc="'.e(trim((string)($p['description'] ?? ''))).'"';
  global $WHATSAPP, $__base, $SELLER_ID;
  if($__promo) $attrs .= ' data-promo="1" data-old="'.e(brl($p['sale_price'])).'"';
  if($__new) $attrs .= ' data-new="1"';
  $attrs .= ' data-id="'.e($p['id']).'" data-pv="'.e(number_format($__eff, 2, '.', '')).'" data-st="'.(int)$p['stock'].'"';
  if($WHATSAPP !== ''){
    $plink = $__base.'catalogo.php?p='.rawurlencode($p['id']).($SELLER_ID !== '' ? '&vend='.rawurlencode($SELLER_ID) : '');   /* o WhatsApp mostra a foto do produto na prévia deste link */
    $__d = substr($WHATSAPP, 2); $__ph = strlen($__d) >= 10 ? '('.substr($__d,0,2).') '.substr($__d,2,strlen($__d)-6).'-'.substr($__d,-4) : $__d;
    $attrs .= ' data-ph="'.e($__ph).'"';
    $attrs .= ' data-msg="'.e('Olá! Escolhi este perfume no catálogo da Essência: '.$p['name'].' ('.brl($__eff).'). Quero finalizar a compra! '.$plink).'"';
    $attrs .= ' data-wa="https://wa.me/'.e($WHATSAPP).'?text='.e(rawurlencode('Olá! Escolhi este perfume no catálogo da Essência: '.$p['name'].' ('.brl($__eff).'). Quero finalizar a compra! '.$plink)).'"';
  }
  $btn = '<div class="cb"><button type="button" class="cb-buy" data-cb="buy">Comprar agora</button><button type="button" class="cb-add" data-cb="add"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.6 12.2a1.6 1.6 0 0 0 1.6 1.3h8.6a1.6 1.6 0 0 0 1.6-1.2L21 8H6"/></svg>Adicionar ao carrinho</button></div>';
  $low = '';
  if($__promo) $low .= '<span class="tag-promo">-'.$__pct.'%</span>';
  if($__new) $low .= '<span class="tag-new">Novidade</span>';
  if($__last) $low .= '<span class="tag-low">Últimas unidades</span>';
  if($low !== '') $low = '<div class="tags">'.$low.'</div>';
  return '<div class="card"'.$attrs.'><div class="imgwrap">'.$low.$im.'</div><div class="info">'.$meta.'<div class="name">'.e($p['name']).'</div><div class="price">'.($__promo ? '<s class="old">'.brl($p['sale_price']).'</s>' : '').brl($__eff).'</div>'.$btn.'</div></div>';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate'); header('X-LiteSpeed-Cache-Control: no-cache');
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
  $__ot = trim((string)$__op['name']).' — '.brl(eff_price($__op));
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
<?php $__hc = ''; try{ $__q = $pdo->prepare('SELECT v FROM meta WHERE k = ?'); $__q->execute(['catalog_color']); $__hc = (string)($__q->fetchColumn() ?: ''); }catch(Throwable $__e){} if(!preg_match('/^#[0-9a-fA-F]{6}$/', $__hc)) $__hc = ''; ?>
<?php
$__bc = ''; try{ $__q = $pdo->prepare('SELECT v FROM meta WHERE k = ?'); $__q->execute(['catalog_btn']); $__bc = (string)($__q->fetchColumn() ?: ''); }catch(Throwable $__e){}
if(!preg_match('/^#[0-9a-fA-F]{6}$/', $__bc)) $__bc = ($__hc !== '') ? $__hc : '#17204A';   /* sem cor própria: botões seguem a cor do cabeçalho */
$__n = hexdec(substr($__bc, 1)); $__lum = 0.299*(($__n>>16)&255) + 0.587*(($__n>>8)&255) + 0.114*($__n&255);
$__tx = $__lum > 150 ? '#1a1a1a' : '#ffffff';
echo '<style>.cb-buy,.lb-buy,#of-s,#ct-paid,.lb-add{background:'.$__bc.'!important;color:'.$__tx.'!important;box-shadow:0 4px 12px rgba(0,0,0,.18)!important}.cb-buy:hover,.lb-buy:hover,.lb-add:hover,#of-s:hover{filter:brightness(1.12)}.cb-add{background:'.$__bc.'14!important;color:'.$__bc.'!important;box-shadow:inset 0 0 0 1.5px '.$__bc.'55!important}.cb-add:hover{background:'.$__bc.'!important;color:'.$__tx.'!important}</style>'."\n";
?>
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
  .lb-acts{position:sticky;bottom:0;background:#fff;padding:2px 0 6px;margin-top:6px;z-index:1;box-shadow:0 -10px 10px -8px rgba(0,0,0,.12);}
  @media (min-width:641px){
    .lb-box{max-width:760px;grid-template-columns:minmax(0,1fr) minmax(0,1fr);}
    .lb-img{align-items:flex-start;padding:14px;}
    .lb-img img{max-height:60vh;}
    .lb-info{padding:22px 20px 18px;}
    .lb-name{font-size:18px;}
  }
  @media (max-width:640px){
    .lb-box{max-height:94vh;grid-template-columns:1fr;grid-template-rows:auto;}
    .lb-img{padding:6px;}
    .lb-img img{max-height:28vh;height:auto;}
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
  .ct-sh{display:flex;justify-content:space-between;padding:4px 0 0;font-size:14px;color:var(--plum);}
  #dl-box{margin-bottom:12px;}
  #dl-box input{font:inherit;font-size:14px;width:100%;padding:10px;border:1.5px solid var(--line);border-radius:10px;margin:0 0 8px;background:#fff;color:var(--plum);box-sizing:border-box;}
  .dl-row{display:flex;gap:8px;}.dl-row input{flex:1;min-width:0;}
  .dl-btn{font:inherit;font-size:13px;font-weight:700;padding:0 14px;margin-bottom:8px;border:0;border-radius:10px;background:var(--plum);color:#fff;cursor:pointer;white-space:nowrap;}
  .dl-msg{font-size:13px;margin:0 0 8px;color:var(--plum);line-height:1.4;}.dl-msg.err{color:#b3261e;}
  .of-lbl{font-size:12px;font-weight:600;color:var(--plum);margin-bottom:6px;}
  .pay{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;}
  .pay button{font:inherit;font-size:13px;font-weight:600;padding:10px 6px;border:1.5px solid var(--line);background:#fff;color:var(--plum);border-radius:10px;cursor:pointer;}
  .pay button.on{border-color:var(--gold);background:#FFF6DF;box-shadow:inset 0 0 0 1px var(--gold);}
  .toast{position:fixed;left:50%;bottom:78px;transform:translateX(-50%) translateY(20px);z-index:130;background:var(--plum);color:#fff;padding:10px 16px;border-radius:999px;font-size:13px;font-weight:600;opacity:0;pointer-events:none;transition:.25s;max-width:90vw;text-align:center;}
  .toast.on{opacity:1;transform:translateX(-50%);}
  .okpop{position:fixed;inset:0;z-index:200;background:rgba(13,18,48,.7);display:none;align-items:center;justify-content:center;padding:18px;}
  .okpop.on{display:flex;animation:okfade .2s ease-out;}
  .okpop-b{background:#fff;border-radius:20px;width:100%;max-width:360px;padding:26px 22px 22px;text-align:center;box-shadow:0 30px 80px rgba(0,0,0,.45);animation:okpop .32s cubic-bezier(.2,1.2,.3,1);}
  .okpop-i{color:#1a8f4a;line-height:0;margin-bottom:10px;}
  .okpop-i path{stroke-dasharray:40;stroke-dashoffset:40;animation:okdraw .5s .15s ease-out forwards;}
  .okpop-t{font-size:20px;font-weight:800;color:var(--plum);margin-bottom:8px;}
  .okpop-m{font-size:14px;color:#4a4f6a;line-height:1.45;margin-bottom:6px;}
  .okpop-c{font-size:12.5px;color:#6b6f85;margin-bottom:16px;}
  @keyframes okfade{from{opacity:0}to{opacity:1}}
  @keyframes okpop{from{transform:scale(.8);opacity:0}to{transform:none;opacity:1}}
  @keyframes okdraw{to{stroke-dashoffset:0}}
  .atoast{position:fixed;left:50%;bottom:78px;transform:translateX(-50%) translateY(20px);z-index:131;width:min(92vw,380px);background:#fff;color:var(--plum);border:1px solid var(--line);border-radius:14px;box-shadow:0 12px 32px rgba(13,18,48,.28);padding:10px 12px;display:none;align-items:center;gap:10px;opacity:0;transition:.25s;}
  .atoast.on{display:flex;opacity:1;transform:translateX(-50%);}
  .atoast img,.atoast .at-ph{width:46px;height:46px;border-radius:8px;object-fit:cover;background:#eef0fa;flex:none;}
  .atoast .at-m{flex:1;min-width:0;}
  .atoast .at-t{font-size:11.5px;font-weight:700;color:#1a8f4a;}
  .atoast .at-n{font-size:13.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .atoast .at-q{font-size:12px;color:var(--muted,#6b6f85);}
  .atoast button{font-family:inherit;font-size:12.5px;font-weight:700;border:none;border-radius:10px;padding:9px 12px;cursor:pointer;flex:none;background:var(--plum);color:#fff;}
  .incart{position:absolute;left:6px;bottom:6px;z-index:2;background:rgba(26,143,74,.95);color:#fff;font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;box-shadow:0 2px 6px rgba(0,0,0,.25);}
  .imgwrap{position:relative;}
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
  .pixbox{border:1.5px dashed var(--gold,#b8923f);border-radius:12px;padding:14px 12px;margin:0 0 12px;background:#fff;text-align:center;}
  .pixbox h4{margin:0 0 4px;font-size:15px;color:var(--plum);}
  .pixbox .pv{font-size:22px;font-weight:800;color:var(--plum);margin:2px 0 8px;}
  .pixbox .pq{display:inline-block;background:#fff;padding:8px;border-radius:8px;border:1px solid #e6dccf;line-height:0;}
  .pixbox .pq svg{width:200px;height:200px;max-width:60vw;max-height:60vw;}
  .pixbox .pc{display:block;width:100%;margin:10px 0 0;font:11px/1.35 monospace;word-break:break-all;background:#f7f2ea;border:1px solid #e6dccf;border-radius:8px;padding:8px;color:#444;max-height:74px;overflow:auto;text-align:left;user-select:all;}
  .pixbox .ph{font-size:12px;color:#6b5b4d;margin-top:8px;line-height:1.45;}
  .lb-box.fm #lb-ok .lb-wa{display:block;}

  /* ===== repaginada: vitrine premium ===== */
  :root{--shadow-s:0 1px 3px rgba(13,18,48,.06),0 6px 18px rgba(13,18,48,.07);--shadow-m:0 4px 10px rgba(13,18,48,.08),0 20px 40px rgba(13,18,48,.15);}
  body{background:linear-gradient(180deg,#EEF0FA 0,#F7F5F0 340px);}
  header{padding:46px 20px 52px;border-radius:0 0 34px 34px;background:var(--plum);box-shadow:0 14px 34px rgba(13,18,48,.28);}
  header::before{display:none;}
  .hero-tools{position:static;}
  .cta-wa{position:absolute;top:16px;right:18px;padding:8px 16px;font-size:13px;box-shadow:0 6px 16px rgba(37,211,102,.3);z-index:3;}
  header h1{font-size:40px;letter-spacing:.2em;padding-left:.2em;}
  header p{font-size:11.5px;letter-spacing:.3em;color:var(--gold-soft);}
  header .logo{filter:drop-shadow(0 4px 14px rgba(240,168,31,.35));}
  .hero-tools{max-width:600px;margin-top:26px;}
  .search input{padding:16px 22px 16px 50px;font-size:16px;box-shadow:0 12px 30px rgba(0,0,0,.32);}
  .search svg{left:19px;width:20px;height:20px;}
  .cta-wa{box-shadow:0 8px 20px rgba(37,211,102,.35);padding:11px 26px;}
  .steps{position:relative;list-style:none;margin:-26px auto 0;padding:0;max-width:760px;display:flex;gap:10px;justify-content:center;z-index:2;}
  .steps li{flex:1;display:flex;align-items:center;gap:10px;background:#fff;border-radius:16px;padding:12px 14px;box-shadow:var(--shadow-s);font-size:13px;font-weight:600;color:var(--plum);}
  .steps b{flex:0 0 28px;width:28px;height:28px;border-radius:50%;background:var(--gold);color:var(--plum-dark);display:flex;align-items:center;justify-content:center;font-size:14px;}
  .steps small{display:block;font-weight:400;color:var(--muted);font-size:11.5px;margin-top:1px;}
  .nav{margin-top:14px;background:rgba(247,245,240,.92);}
  .nav a{background:#fff;border:1.5px solid var(--line);font-weight:600;transition:all .12s;}
  .nav a:hover{border-color:var(--plum);}
  .nav a.on{box-shadow:0 4px 12px rgba(23,32,74,.3);}
  .wrap{padding-top:22px;}
  .catsec{margin-bottom:46px;}
  .catsec h2{font-size:26px;font-weight:400;padding:0 0 14px;margin-bottom:18px;}
  .catsec h2::after{width:70px;height:4px;}
  .catsec h3{display:inline-flex;background:#FFF3D6;color:var(--gold-deep);padding:5px 13px;border-radius:999px;margin:6px 0 14px;font-size:12px;}
  .grid{grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;align-items:stretch;}
  .card{height:100%;}
  .card{border-radius:16px;box-shadow:var(--shadow-s);background:#fff;position:relative;transition:transform .18s ease,box-shadow .18s ease;}
  .card:hover{transform:translateY(-6px);box-shadow:var(--shadow-m);}
  .imgwrap{margin:7px 7px 0;border-radius:12px;background:linear-gradient(160deg,#F4F5FC,#FFF);padding:8px;}
  .imgwrap img{background:transparent;transition:transform .25s ease;}
  .card:hover .imgwrap img{transform:scale(1.06);}
  .tag-low{position:absolute;top:10px;left:10px;z-index:1;background:#FFF3D6;color:var(--gold-deep);font-size:10.5px;font-weight:700;letter-spacing:.03em;padding:4px 10px;border-radius:999px;box-shadow:0 2px 6px rgba(13,18,48,.12);}
  .info{padding:7px 11px 12px;}
  .cat{color:var(--gold-deep);font-weight:600;font-size:10px;}
  .name{font-size:12.5px;font-weight:600;color:var(--ink);}
  .price{font-size:17px;font-weight:700;margin:5px 0 9px;letter-spacing:-.01em;}
  .buy{font-size:12px;padding:7px 10px;}
  .buy{background:linear-gradient(135deg,#222C66,#0D1230);box-shadow:0 6px 14px rgba(23,32,74,.28);transition:transform .12s,box-shadow .12s;}
  .card:hover .buy{background:linear-gradient(135deg,var(--gold),#E09410);color:var(--plum-dark);box-shadow:0 6px 14px rgba(240,168,31,.4);}
  footer{padding:30px 20px 40px;border-top:1px solid var(--line);margin:0 20px;}
  .cfab{box-shadow:0 12px 30px rgba(13,18,48,.38);}
  @media (max-width:640px){
    header{padding:30px 14px 50px;border-radius:0 0 26px 26px;}
    .cta-wa{top:10px;right:10px;padding:6px 12px;font-size:12px;}
    header h1{font-size:31px;}
    .steps{margin:-30px 12px 0;gap:6px;}
    .steps li{flex-direction:column;text-align:center;gap:6px;padding:10px 6px;font-size:11.5px;border-radius:14px;}
    .steps small{display:none;}
    .grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;}
    .steps b{flex:none;width:28px;min-width:28px;}
    .card{border-radius:12px;}
    .imgwrap{margin:5px 5px 0;padding:5px;border-radius:9px;}
    .info{padding:5px 7px 9px;}
    .name{font-size:11px;}
    .price{font-size:14px;margin:4px 0 7px;}
    .buy{font-size:11px;padding:6px 4px;}
    .catsec h2{font-size:21px;}
    .tag-low{top:6px;left:6px;font-size:8.5px;padding:2px 6px;}
  }
  .tags{position:absolute;top:8px;left:8px;z-index:1;display:flex;flex-direction:column;align-items:flex-start;gap:4px;}
  .tags .tag-low{position:static;}
  .tag-promo,.tag-new{font-size:10.5px;font-weight:800;letter-spacing:.03em;padding:4px 10px;border-radius:999px;box-shadow:0 2px 6px rgba(13,18,48,.18);}
  .tag-promo{background:#D6334F;color:#fff;}
  .tag-new{background:var(--gold);color:var(--plum-dark);}
  .price .old{display:block;font-size:11px;font-weight:500;color:var(--muted);text-decoration:line-through;line-height:1.1;}
  .lb-p .old,.lb-price .old{font-size:13px;font-weight:400;color:var(--muted);text-decoration:line-through;margin-right:8px;}
  .nav a.hot{border-color:#D6334F;color:#B8213C;}
  .nav a.hot.on{background:#D6334F;border-color:#D6334F;color:#fff;}
  .nav a.nv{border-color:var(--gold);color:var(--gold-deep);}
  .nav a.nv.on{background:var(--gold);border-color:var(--gold);color:var(--plum-dark);}
  @media (max-width:640px){.tags{top:6px;left:6px;gap:3px;}.tag-promo,.tag-new{font-size:9px;padding:2px 6px;}.price .old{font-size:9.5px;}}
  /* janela de detalhes: foto menor */
  .lb-img img{max-height:40vh;}
  @media (min-width:641px){.lb-box{max-width:660px;}.lb-img{padding:10px;}.lb-img img{max-height:38vh;max-width:270px;margin:0 auto;}}
  @media (max-width:640px){.lb-img img{max-height:20vh;}}
  <?php if($__hc !== ''): ?>header{background:<?= e($__hc) ?> !important;}
  <?php endif; ?>
  @media (prefers-reduced-motion:reduce){.card,.card img{transition:none;}}
  .cta-wa{position:fixed!important;top:auto!important;left:auto!important;right:14px;bottom:18px;transition:bottom .2s;width:44px;height:44px;padding:0!important;font-size:0!important;border-radius:50%;z-index:59;background:#25D366;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,.28)!important;opacity:.92;}
  .cta-wa:hover{opacity:1;}
  body.has-cart .cta-wa{bottom:84px!important;}
  /* carrinho como gaveta no lado direito da tela */
  .ct{padding:0!important;align-items:stretch!important;justify-content:flex-end!important;}
  .ct-box{max-width:none!important;width:min(100%,440px)!important;height:100vh;height:100dvh;max-height:none!important;border-radius:18px 0 0 18px!important;padding:18px 16px calc(16px + env(safe-area-inset-bottom))!important;box-shadow:-18px 0 50px rgba(0,0,0,.35)!important;}
  .ct.on .ct-box{animation:ctin .26s ease-out;}
  @keyframes ctin{from{transform:translateX(100%)}to{transform:none}}
  @media (max-width:560px){.ct-box{width:100%!important;border-radius:0!important;}}
  .cb{display:flex;flex-direction:column;gap:7px;margin-top:auto;}
  .cb button{font-family:inherit;font-weight:700;border:none;cursor:pointer;line-height:1.1;display:flex;align-items:center;justify-content:center;gap:6px;border-radius:10px;transition:filter .12s,background .12s,color .12s;}
  .cb-buy{font-size:13px;padding:12px 8px;text-transform:uppercase;letter-spacing:.6px;}
  .cb-add{font-size:12px;padding:9px 6px;}
  @media (max-width:560px){.cb-buy{font-size:11.5px;padding:11px 4px;letter-spacing:.3px;}.cb-add{font-size:11px;padding:8px 3px;gap:4px;}.cb-add svg{display:none;}}
</style>
</head>
<body>
  <header>
    <svg class="logo" viewBox="0 0 84 84" width="56" height="56" aria-hidden="true"><path d="M42 3 L52.6 18.4 L70.2 13.8 L65.6 31.4 L81 42 L65.6 52.6 L70.2 70.2 L52.6 65.6 L42 81 L31.4 65.6 L13.8 70.2 L18.4 52.6 L3 42 L18.4 31.4 L13.8 13.8 L31.4 18.4 Z" fill="none" stroke="#F0A81F" stroke-width="1.6"/><path d="M42 14 L67 29 V55 L42 70 L17 55 V29 Z" fill="none" stroke="#FBD98A" stroke-width="1" opacity=".7"/><circle cx="42" cy="42" r="3.5" fill="#F0A81F"/></svg>
    <h1>Essência</h1>
    <p>Catálogo de Perfumes</p>
    <div class="hero-tools">
      <label class="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input id="q" type="search" placeholder="Buscar perfume..." aria-label="Buscar perfume" autocomplete="off"></label>
<?php if($WHATSAPP !== ''): ?>      <a class="cta-wa" target="_blank" rel="noopener" href="https://wa.me/<?= e($WHATSAPP) ?>?text=<?= rawurlencode('Olá! Vi o catálogo da Essência e gostaria de ajuda.') ?>" aria-label="Falar no WhatsApp" title="Falar no WhatsApp"><svg viewBox="0 0 32 32" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M16 3.2A12.700 12.700 0 0 0 5.100 22.300L3.200 28.800l6.700-1.800A12.700 12.700 0 1 0 16 3.200zm0 2.200a10.500 10.500 0 1 1-5.400 19.500l-.4-.2-4 1 1.100-3.900-.3-.4A10.500 10.500 0 0 1 16 5.400zm-4.200 5.300c-.2 0-.6.100-.9.400-.3.300-1.200 1.200-1.200 2.900s1.200 3.400 1.400 3.600c.2.200 2.400 3.800 5.900 5.100 2.900 1.100 3.500.9 4.100.8.600-.1 2-.8 2.200-1.600.3-.8.3-1.400.2-1.600-.1-.1-.3-.2-.7-.4s-2-1-2.300-1.100c-.3-.1-.5-.2-.8.200-.2.300-.9 1.100-1.100 1.300-.2.200-.4.300-.7.100-.4-.2-1.400-.5-2.700-1.600-1-.9-1.700-2-1.900-2.400-.2-.4 0-.6.200-.8l.5-.6c.2-.2.200-.4.300-.6.100-.2 0-.4 0-.6l-1-2.400c-.2-.6-.5-.5-.7-.5z"/></svg></a>
<?php endif; ?>    </div>
  </header>
  <ol class="steps"><li><b>1</b><span>Escolha<small>seus perfumes favoritos</small></span></li><li><b>2</b><span>Monte o carrinho<small>ajuste as quantidades</small></span></li><li><b>3</b><span>Envie o pedido<small>a loja combina o resto</small></span></li></ol>
<?php $nPromo = 0; $nNovo = 0; foreach($prods as $__q){ if(eff_price($__q) < (float)$__q['sale_price']) $nPromo++; if(!empty($__q['is_new'])) $nNovo++; } ?>
<?php if(count($groups) > 1 || $nPromo || $nNovo): ?>
  <nav class="nav" id="tabs"><a href="#" data-cat="all" class="on">Todos</a><?php if($nPromo): ?><a href="#" data-cat="f-promo" class="hot">Promoções <span><?= $nPromo ?></span></a><?php endif; ?><?php if($nNovo): ?><a href="#" data-cat="f-novo" class="nv">Novidades <span><?= $nNovo ?></span></a><?php endif; ?><?php foreach($groups as $i=>$g): ?><a href="#cat-<?= $i ?>" data-cat="cat-<?= $i ?>"><?= e($g['name']) ?> <span><?= (int)$g['count'] ?></span></a><?php endforeach; ?></nav>
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
  <footer>Atualizado em <?= date('d/m/Y') ?> · preços e disponibilidade sujeitos a alteração. <span style="opacity:.55">· v2026.10.14-p</span></footer>
<div class="lb" id="lb" aria-hidden="true"><div class="lb-box" id="lb-box" role="dialog" aria-modal="true"><button class="lb-x" type="button" aria-label="Fechar">&times;</button><div class="lb-img"><img id="lb-i" alt=""></div><div class="lb-info" id="lb-info"><div class="lb-brand" id="lb-b"></div><div class="lb-name" id="lb-n"></div><div class="lb-price" id="lb-p"></div>
<div class="lb-desc" id="lb-d"></div>
<div class="lb-acts"><button type="button" class="lb-act lb-add" id="lb-add">Adicionar ao carrinho</button>
<button type="button" class="lb-act lb-buy" id="lb-buy">Comprar agora</button></div>
</div></div></div>
<button type="button" class="cfab" id="cfab" style="display:none" aria-label="Abrir carrinho"><span class="cfab-i">&#128722;</span><span id="cfab-n">0</span><span class="cfab-t" id="cfab-t"></span></button>
<div class="ct" id="ct" aria-hidden="true"><div class="ct-box" role="dialog" aria-modal="true">
<button class="lb-x" type="button" id="ct-x" aria-label="Fechar">&times;</button>
<div id="ct-main">
  <div class="of-t">Seu carrinho</div>
  <div id="ct-l"></div>
  <div class="ct-sh" id="ct-sh" style="display:none"><span>Frete</span><b id="ct-shv"></b></div>
  <div class="ct-tot"><span>Total</span><b id="ct-t">R$ 0,00</b></div>
  <form class="of" id="ct-f" novalidate autocomplete="on">
    <label>Seu nome<input id="of-n" maxlength="120" autocomplete="name" placeholder="Como podemos te chamar?"></label>
    <label>Seu WhatsApp<input id="of-p" inputmode="tel" maxlength="16" autocomplete="tel" placeholder="(11) 99999-9999"></label>
    <div id="dl-box" style="display:none">
      <div class="of-lbl">Entrega</div>
      <div class="pay" id="dl-mode" style="grid-template-columns:1fr 1fr"><button type="button" data-dl="pickup" id="dl-pk">Retirar na loja</button><button type="button" data-dl="home">Receber em casa</button></div>
      <div id="dl-home" style="display:none">
        <div class="dl-row"><input id="dl-cep" inputmode="numeric" maxlength="9" placeholder="CEP" autocomplete="postal-code"><button type="button" id="dl-calc" class="dl-btn">Calcular frete</button></div>
        <div class="dl-msg" id="dl-msg"></div>
        <div class="pay" id="dl-opts" style="display:none;grid-template-columns:1fr;margin-bottom:8px"></div>
        <div id="dl-fields" style="display:none">
          <input id="dl-street" maxlength="120" placeholder="Rua / Avenida" autocomplete="address-line1">
          <div class="dl-row"><input id="dl-num" maxlength="20" placeholder="Número" style="max-width:110px"><input id="dl-comp" maxlength="60" placeholder="Complemento (opcional)"></div>
          <input id="dl-dist" maxlength="80" placeholder="Bairro">
          <div class="dl-row"><input id="dl-city" maxlength="80" placeholder="Cidade"><input id="dl-uf" maxlength="2" placeholder="UF" style="max-width:64px;text-transform:uppercase"></div>
        </div>
      </div>
    </div>
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
<div class="of-ok" id="ct-ok" style="display:none"><div class="of-ok-i">&#10003;</div><div class="of-ok-t">Pedido enviado!</div><div class="of-ok-m" id="ct-okm"></div><div class="pixbox" id="ct-pix" style="display:none"><h4>Pague com PIX</h4><div class="pv" id="ct-pixv"></div><div class="pq" id="ct-pixq"></div><textarea class="pc" id="ct-pixc" readonly rows="3" aria-label="Código PIX copia e cola"></textarea><button type="button" class="lb-act lb-buy" id="ct-pixb" style="margin-top:10px">Copiar código PIX</button><div class="ph">Abra o app do seu banco, escolha <b>PIX &rarr; Pagar com QR Code</b> (ou <b>Copia e Cola</b>) e confira o valor. Depois envie o comprovante no WhatsApp.</div></div><a class="lb-act lb-buy" id="ct-paid" target="_blank" rel="noopener" style="display:none;text-decoration:none;text-align:center;background:#25D366;color:#073B1B">Já fiz o pagamento — avisar a loja</a><a class="lb-act lb-buy" id="ct-ow" target="_blank" rel="noopener" style="display:none;text-decoration:none;text-align:center">Falar agora no WhatsApp</a><button type="button" class="of-b" id="ct-oc">Fechar</button></div>
</div></div>
<div class="toast" id="toast" role="status"></div>
<div class="okpop" id="okpop" role="alertdialog" aria-modal="true" aria-labelledby="okpop-t"><div class="okpop-b"><div class="okpop-i"><svg viewBox="0 0 52 52" width="64" height="64" aria-hidden="true"><circle cx="26" cy="26" r="24" fill="none" stroke="currentColor" stroke-width="3"/><path d="M15 27l8 8 15-17" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg></div><div class="okpop-t" id="okpop-t">Compra realizada com sucesso!</div><div class="okpop-m" id="okpop-m"></div><div class="okpop-c" id="okpop-c"></div><button type="button" class="lb-act lb-buy" id="okpop-x">OK</button></div></div>
<div class="atoast" id="atoast" role="status" aria-live="polite"></div>
<script>
document.addEventListener('contextmenu',function(e){if(e.target&&e.target.tagName==='IMG')e.preventDefault();});
document.addEventListener('dragstart',function(e){if(e.target&&e.target.tagName==='IMG')e.preventDefault();});
(function(){
  var lb=document.getElementById('lb');if(!lb)return;
  function $(i){return document.getElementById(i);}
  var WA=<?= json_encode($WHATSAPP) ?>,SELLER=<?= json_encode($SELLER_ID) ?>,SELLERN=<?= json_encode($SELLER_NAME) ?>;
  var SHIP=<?= json_encode(!empty($SHIP['on']) ? ['pickup'=>!empty($SHIP['pickup'])] : null) ?>;
  var cur=null,cart=[],pay='',dl={mode:'',q:null,opts:[]};
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
  /* abre direto o WhatsApp (aplicativo) e, se não abrir, a página do WhatsApp */
  function waGo(ph,tx){
    var q='phone='+ph+'&text='+encodeURIComponent(tx||''),web='https://api.whatsapp.com/send?'+q,app='whatsapp://send?'+q,done=false;
    function stop(){done=true;window.removeEventListener('blur',stop);document.removeEventListener('visibilitychange',stop);}
    window.addEventListener('blur',stop);document.addEventListener('visibilitychange',stop);
    setTimeout(function(){window.removeEventListener('blur',stop);document.removeEventListener('visibilitychange',stop);if(!done)location.href=web;},1300);
    location.href=app;
  }
  function fab(){var n=count(),f=$('cfab');f.style.display=n?'flex':'none';document.body.classList.toggle('has-cart',n>0);$('cfab-n').textContent=n;$('cfab-t').textContent=brl(total());markCards();}
  function markCards(){
    var cs=document.querySelectorAll('.card[data-id]');
    for(var k=0;k<cs.length;k++){
      var c=cs[k],id=c.getAttribute('data-id'),q=0;cart.forEach(function(i){if(i.id===id)q=i.qty;});
      var w=c.querySelector('.imgwrap'),b=c.querySelector('.incart');if(!w)continue;
      if(q>0){if(!b){b=document.createElement('span');b.className='incart';w.appendChild(b);}b.textContent=q+(q===1?' no carrinho':' no carrinho');}
      else if(b)b.parentNode.removeChild(b);
    }
  }
  function showAdded(c){
    var id=c.getAttribute('data-id'),it=null;cart.forEach(function(i){if(i.id===id)it=i;});if(!it)return;
    var a=$('atoast');a.innerHTML='';
    var im=document.createElement(it.img?'img':'div');if(it.img){im.src=it.img;im.alt='';}else im.className='at-ph';a.appendChild(im);
    var m=document.createElement('div');m.className='at-m';
    var t=document.createElement('div');t.className='at-t';t.textContent='✓ Adicionado ao carrinho';
    var n=document.createElement('div');n.className='at-n';n.textContent=it.name;
    var q=document.createElement('div');q.className='at-q';q.textContent=it.qty+(it.qty===1?' unidade':' unidades')+' · '+brl(it.qty*it.price)+' · carrinho: '+count()+(count()===1?' item':' itens');
    m.appendChild(t);m.appendChild(n);m.appendChild(q);a.appendChild(m);
    var bt=document.createElement('button');bt.type='button';bt.id='at-open';bt.textContent='Ver carrinho';a.appendChild(bt);
    a.className='atoast on';clearTimeout(showAdded._t);showAdded._t=setTimeout(function(){a.className='atoast';},3800);
  }
  function add(c){
    var id=c.getAttribute('data-id'),it=null;cart.forEach(function(i){if(i.id===id)it=i;});
    if(it){if(it.qty>=it.max){toast('Máximo disponível: '+it.max+' un.');return false;}it.qty++;}
    else cart.push(fromCard(c,1));
    saveCart();fab();return true;
  }

  function shipCost(){return (dl.mode==='home'&&dl.q&&dl.q.mode==='paid')?dl.q.price:0;}
  function grand(){return total()+shipCost();}
  function drawShip(){
    var box=$('dl-box');if(!box)return;
    box.style.display=SHIP?'block':'none';if(!SHIP){$('ct-t').textContent=brl(total());return;}
    var pk=$('dl-pk');pk.style.display=SHIP.pickup?'':'none';
    if(dl.q&&(dl.sub!==total()||dl.units!==count())){dl.q=null;dl.opts=[];shipMsg('O valor do carrinho mudou. Toque em Calcular frete de novo.',true);}
    var bs=$('dl-mode').querySelectorAll('button');for(var k=0;k<bs.length;k++)bs[k].className=bs[k].getAttribute('data-dl')===dl.mode?'on':'';
    $('dl-home').style.display=dl.mode==='home'?'block':'none';
    var ob=$('dl-opts');ob.innerHTML='';
    if(dl.mode==='home'&&dl.opts&&dl.opts.length){ob.style.display='grid';dl.opts.forEach(function(o,ix){var bt=document.createElement('button');bt.type='button';bt.setAttribute('data-opt',ix);bt.className=(dl.q&&dl.q.id===o.id)?'on':'';bt.textContent=(o.mode==='combine'||!o.name?'':o.name+' · ')+(o.mode==='combine'?'Frete a combinar':(o.mode==='free'?'Frete grátis':brl(o.price)))+(o.days&&o.mode!=='combine'?' · '+o.days:'');ob.appendChild(bt);});}else ob.style.display='none';
    var sh=$('ct-sh'),q=dl.q;
    if(dl.mode==='home'&&q){sh.style.display='flex';$('ct-shv').textContent=q.mode==='combine'?'a combinar':(q.mode==='free'?'Grátis':brl(q.price));}else sh.style.display='none';
    $('ct-t').textContent=brl(grand());
  }
  function shipMsg(t,err){var m=$('dl-msg');m.textContent=t||'';m.className='dl-msg'+(err?' err':'');}
  function shipCalc(){
    var cep=$('dl-cep').value.replace(/\D/g,''),city=$('dl-fields').style.display==='block'?$('dl-city').value.trim():'',uf=$('dl-fields').style.display==='block'?$('dl-uf').value.trim():'';
    if(cep.length!==8){shipMsg('Informe um CEP com 8 dígitos.',true);return;}
    var b=$('dl-calc');b.disabled=true;b.textContent='Calculando...';shipMsg('');
    fetch('catalogo.php?a=frete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({cep:cep,city:city,uf:uf,sub:total(),units:count()})})
    .then(function(r){return r.json().catch(function(){return {ok:false,error:'Erro ao calcular.'};});})
    .then(function(j){
      b.disabled=false;b.textContent='Calcular frete';
      if(!j.ok){dl.q=null;dl.opts=[];$('dl-fields').style.display='block';shipMsg(j.error||'Não foi possível calcular.',true);drawShip();return;}
      dl.opts=j.options||[];dl.q=dl.opts[0]||null;dl.sub=total();dl.units=count();dl.est=!!j.estimate;$('dl-fields').style.display='block';
      if(j.address){$('dl-street').value=j.address.street||$('dl-street').value;$('dl-dist').value=j.address.district||$('dl-dist').value;}
      $('dl-city').value=j.city;$('dl-uf').value=j.uf;
      shipMsg(j.city+'/'+j.uf+(dl.est?' · valores estimados':''));
      drawShip();
    }).catch(function(){b.disabled=false;b.textContent='Calcular frete';shipMsg('Sem conexão. Tente novamente.',true);});
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
    drawShip();
    $('of-s').disabled=!cart.length;
    var bs=$('pay').querySelectorAll('button');for(var k=0;k<bs.length;k++)bs[k].className=bs[k].getAttribute('data-pay')===pay?'on':'';
  }
  function openCart(){
    close();$('toast').className='toast';$('atoast').className='atoast';
    $('ct-main').style.display='block';$('ct-ok').style.display='none';
    $('of-n').value=$('of-n').value||ls('ess_c_n');$('of-p').value=$('of-p').value||ls('ess_c_p');
    pay=pay||ls('ess_c_pay');
    $('of-e').textContent='';drawCart();
    $('ct').className='ct on';document.body.style.overflow='hidden';
  }
  function buyNow(c){
    var id=c.getAttribute('data-id'),has=false;cart.forEach(function(i){if(i.id===id)has=true;});
    if(!has&&!add(c))return;
    openCart();
    setTimeout(function(){var f=$('ct-f');if(f&&f.scrollIntoView)try{f.scrollIntoView({block:'start',behavior:'smooth'});}catch(e){}},60);
  }
  function closeCart(){$('ct').className='ct';document.body.style.overflow='';}
  function open(c){
    var ci=c.querySelector('.imgwrap img'),s=ci?ci.getAttribute('src'):'',im=$('lb-i');
    if(s){im.src=s;im.alt=c.getAttribute('data-name')||'';im.parentNode.style.display='';}else{im.removeAttribute('src');im.parentNode.style.display='none';}
    $('lb-b').textContent=c.getAttribute('data-meta')||'';
    $('lb-n').textContent=c.getAttribute('data-name')||'';
    var lp=$('lb-p'),old=c.getAttribute('data-old');lp.textContent='';if(old){var so=document.createElement('s');so.className='old';so.textContent=old;lp.appendChild(so);}lp.appendChild(document.createTextNode(c.getAttribute('data-price')||''));
    var d=c.getAttribute('data-desc')||'',de=$('lb-d');
    de.textContent=d||'Sem descrição cadastrada.';de.className='lb-desc'+(d?'':' empty');
    cur=c;$('lb-box').classList.toggle('ni',!s);
    lb.className='lb on';document.body.style.overflow='hidden';
  }
  function close(){lb.className='lb';if($('ct').className.indexOf('on')<0)document.body.style.overflow='';}
  document.addEventListener('click',function(e){
    var t=e.target;
    var wal=t.closest&&t.closest('a[href*="wa.me/"]');
    if(wal){try{var wu=new URL(wal.href),wph=wu.pathname.replace(/\D/g,'');if(wph){e.preventDefault();waGo(wph,wu.searchParams.get('text')||'');return;}}catch(er){}}
    var cbb=t.closest&&t.closest('[data-cb]');
    if(cbb){var cc=cbb.closest('.card');if(cc){if(cbb.getAttribute('data-cb')==='buy')buyNow(cc);else if(add(cc))showAdded(cc);}return;}
    if(t.id==='okpop-x'||t.id==='okpop'){$('okpop').className='okpop';var okb=$('ct-ok');if(okb&&okb.scrollIntoView)try{okb.scrollIntoView({block:'start'});}catch(e){}return;}
    if(t.id==='at-open'){$('atoast').className='atoast';openCart();return;}
    if(t.id==='lb-add'){if(cur&&add(cur)){close();showAdded(cur);}return;}
    if(t.id==='lb-buy'){if(cur)buyNow(cur);return;}
    if(t.closest&&t.closest('#cfab')){openCart();return;}
    if(t.id==='ct-x'||t.id==='ct-more'||t.id==='ct-oc'){closeCart();return;}
    if(t.id==='ct'&&(!window._lbd||window._lbd===t)){closeCart();return;}
    if(t.id==='dl-calc'){shipCalc();return;}
    if(t.getAttribute&&t.getAttribute('data-opt')!==null&&t.parentNode&&t.parentNode.id==='dl-opts'){dl.q=dl.opts[+t.getAttribute('data-opt')]||dl.q;drawShip();return;}
    if(t.getAttribute&&t.getAttribute('data-dl')){dl.mode=t.getAttribute('data-dl');$('of-e').textContent='';drawCart();return;}
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
  document.addEventListener('input',function(e){var er=$('of-e');if(er&&e.target.closest&&e.target.closest('#ct-f'))er.textContent='';if(e.target.id==='dl-cep'){var cv=e.target.value.replace(/\D/g,'').slice(0,8);e.target.value=cv.length>5?cv.slice(0,5)+'-'+cv.slice(5):cv;dl.q=null;dl.opts=[];drawShip();}if(e.target.id==='dl-city'||e.target.id==='dl-uf'){dl.q=null;dl.opts=[];drawShip();}if(e.target.id==='of-p'){var v=mask(e.target.value);if(v!==e.target.value)e.target.value=v;}});
  document.addEventListener('submit',function(e){
    if(e.target.id!=='ct-f')return;e.preventDefault();
    var n=$('of-n').value.trim(),ph=$('of-p').value.replace(/\D/g,''),er=$('of-e'),btn=$('of-s');
    if(!cart.length){er.textContent='Seu carrinho está vazio.';return;}
    if(n.length<2){er.textContent='Informe seu nome.';$('of-n').focus();return;}
    if(ph.length<10){er.textContent='Informe seu WhatsApp com DDD.';$('of-p').focus();return;}
    var dv=null;
    if(SHIP){
      if(!dl.mode){er.textContent='Escolha como deseja receber: retirar ou entrega.';return;}
      if(dl.mode==='home'){
        dv={mode:'home',service:dl.q?dl.q.id:'',cep:$('dl-cep').value,street:$('dl-street').value,number:$('dl-num').value,comp:$('dl-comp').value,district:$('dl-dist').value,city:$('dl-city').value,uf:$('dl-uf').value};
        if(!dl.q){er.textContent='Toque em "Calcular frete" para ver o valor da entrega.';return;}
        if(!dv.street.trim()||!dv.number.trim()||!dv.city.trim()||dv.uf.trim().length!==2){er.textContent='Preencha rua, número, cidade e UF da entrega.';return;}
      }else dv={mode:'pickup'};
    }
    if(!pay){er.textContent='Escolha a forma de pagamento.';return;}
    er.textContent='';btn.disabled=true;btn.textContent='Enviando...';
    var sent=cart.slice(),tot=grand(),home=dv&&dv.mode==='home';
    fetch('catalogo.php?a=pedido',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:sent.map(function(i){return {productId:i.id,qty:i.qty};}),name:n,phone:ph,payment:pay,seller:SELLER,note:$('of-o').value,website:$('of-h').value,delivery:dv})})
    .then(function(r){return r.json().catch(function(){return {ok:false,error:'Erro ao enviar.'};});})
    .then(function(j){
      btn.disabled=false;btn.textContent='Enviar pedido para a loja';
      if(!j.ok){er.textContent=j.error||'Não foi possível enviar. Tente novamente.';return;}
      ls('ess_c_n',n);ls('ess_c_p',$('of-p').value);
      var nn=sent.reduce(function(a,i){return a+i.qty;},0);
      $('ct-okm').textContent='Obrigado, '+n.split(' ')[0]+'! Recebemos seu pedido ('+nn+(nn===1?' item':' itens')+', '+brl(tot)+(home&&dl.q&&dl.q.mode==='combine'?' + frete a combinar':'')+', pagamento: '+pay+'). '+(SELLERN?SELLERN:'A loja')+' vai falar com você pelo WhatsApp '+$('of-p').value+(j.pix?' para confirmar e combinar a entrega. Faça o pagamento abaixo:':' para combinar pagamento e entrega.');
      var ow=$('ct-ow');if(WA){ow.href='https://wa.me/'+WA+'?text='+encodeURIComponent('Olá! Acabei de enviar um pedido pelo catálogo em nome de '+n+'.');ow.style.display='block';}else ow.style.display='none';
      var pd=$('ct-paid'),oid=String(j.id||'').replace(/^ped_/,'').slice(0,8).toUpperCase();
      if(WA&&pay==='PIX'){pd.href='https://wa.me/'+WA+'?text='+encodeURIComponent('Olá! Já fiz o pagamento do meu pedido'+(oid?' '+oid:'')+' ('+brl(tot)+', '+pay+') em nome de '+n+'.'+(pay==='PIX'?' Segue o comprovante:':''));pd.style.display='block';}else pd.style.display='none';
      showPix(j.pix);
      (function(){var oc=String(j.id||'').replace(/^ped_/,'').slice(0,8).toUpperCase();
        $('okpop-m').textContent='Obrigado, '+n.split(' ')[0]+'! Recebemos seu pedido de '+brl(tot)+'.'+(j.pix?' Faça o pagamento pelo PIX para concluir.':' A loja vai falar com você pelo WhatsApp.');
        $('okpop-c').textContent=oc?'Pedido nº '+oc:'';$('okpop-x').textContent=j.pix?'Ver pagamento PIX':'OK';
        $('okpop').className='okpop on';})();
      cart=[];saveCart();fab();$('of-o').value='';
      $('ct-main').style.display='none';$('ct-ok').style.display='block';
    }).catch(function(){btn.disabled=false;btn.textContent='Enviar pedido para a loja';er.textContent='Sem conexão. Verifique a internet e tente novamente.';});
  });
  function showPix(px){
    var box=$('ct-pix');if(!box)return;
    if(!px||!px.code){box.style.display='none';return;}
    $('ct-pixv').textContent=brl(px.amount);$('ct-pixc').value=px.code;$('ct-pixq').innerHTML='';
    function draw(){try{var q=qrcode(0,'M');q.addData(px.code);q.make();$('ct-pixq').innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});}catch(e){$('ct-pixq').style.display='none';}}
    $('ct-pixq').style.display='inline-block';
    if(window.qrcode)draw();else{var sc=document.createElement('script');sc.src='qr.js?v=q';sc.onload=draw;sc.onerror=function(){$('ct-pixq').style.display='none';};document.head.appendChild(sc);}
    box.style.display='block';
  }
  document.addEventListener('click',function(e){
    if(!e.target||e.target.id!=='ct-pixb')return;
    var c=$('ct-pixc'),v=c.value,b=e.target;
    function ok(){b.textContent='Código copiado!';setTimeout(function(){b.textContent='Copiar código PIX';},2500);}
    function fb(){c.focus();c.select();try{c.setSelectionRange(0,99999);}catch(x){}try{document.execCommand('copy');ok();}catch(x){b.textContent='Toque e segure no código para copiar';}}
    if(navigator.clipboard&&window.isSecureContext)navigator.clipboard.writeText(v).then(ok,fb);else fb();
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
      var fl=active.indexOf('f-')===0,on=active==='all'||fl||sec.id===active,vis=0;
      [].slice.call(sec.querySelectorAll('.grid')).forEach(function(g){
        var n=0;[].slice.call(g.querySelectorAll('.card')).forEach(function(c){
          var m=(!t||norm((c.getAttribute('data-name')||'')+' '+(c.getAttribute('data-meta')||'')).indexOf(t)>-1)&&(active!=='f-promo'||c.getAttribute('data-promo')==='1')&&(active!=='f-novo'||c.getAttribute('data-new')==='1');
          c.style.display=m?'':'none';if(m)n++;});
        g.style.display=n?'':'none';var h=g.previousElementSibling;if(h&&h.tagName==='H3')h.style.display=n?'':'none';vis+=n;});
      sec.style.display=(on&&vis)?'':'none';if(on&&vis)any=true;});
    if(nr)nr.style.display=any?'none':'block';
  }
  if(q)q.addEventListener('input',apply);
  if(tabs)tabs.addEventListener('click',function(e){
    var a=e.target.closest('a[data-cat]');if(!a)return;e.preventDefault();
    active=a.getAttribute('data-cat');[].slice.call(tabs.querySelectorAll('a')).forEach(function(x){x.classList.toggle('on',x===a);});
    apply();var w=document.querySelector('.wrap');if(w&&window.scrollY>w.offsetTop)window.scrollTo(0,Math.max(0,w.offsetTop-60));
  });
  if(tabs){var mf=/[?&]f=(promo|novo)\b/.exec(location.search);if(mf){var ta=tabs.querySelector('a[data-cat="f-'+mf[1]+'"]');if(ta)ta.click();}}
})();
</script>
</body>
</html>
