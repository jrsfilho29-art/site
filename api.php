<?php
// Essência — software proprietário. Copyright (c) 2026. Todos os direitos reservados (veja LICENSE).
/* ============================================================
   Essência — API de sincronização (PHP + MySQL)
   Coloque este arquivo junto com index.html e database.php em public_html.
   Todas as chamadas são POST com JSON: { action, key, ... }
   Ações: ping | pull | push
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($code, $arr){
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}
function fail($code, $msg, $extra = []){ out($code, array_merge(['ok'=>false, 'error'=>$msg], $extra)); }

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'Use POST.');

/* ---- só aceita chamadas feitas pelo próprio site (bloqueia uso a partir de outros domínios) ---- */
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if($__origin !== '' && $__origin !== 'null'){
  $__oh = strtolower((string)parse_url($__origin, PHP_URL_HOST));
  $__rh = strtolower((string)parse_url('//'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
  if($__oh !== $__rh && !in_array($__oh, ['localhost','127.0.0.1'], true)) fail(403, 'Origem não autorizada.', ['code'=>'bad_origin']);
}

$dbFile = __DIR__ . '/database.php';
if(!is_file($dbFile)) fail(500, 'Arquivo database.php não encontrado ao lado do api.php.', ['code'=>'no_config']);
require $dbFile;

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if(!is_array($req)) fail(400, 'Corpo da requisição inválido (JSON esperado).');

/* ---- configuração ainda não preenchida? ---- */
$placeholder = (!defined('DB_NAME') || (strpos(DB_NAME, 'TROQUE') !== false && !getenv('ESSENCIA_DSN')) || !defined('SYNC_KEY') || strpos(SYNC_KEY, 'TROQUE') !== false || strlen(SYNC_KEY) < 8);
if($placeholder) fail(503, 'Servidor ainda não configurado: preencha DB_NAME, DB_USER, DB_PASS e SYNC_KEY (mín. 8 caracteres) no database.php.', ['code'=>'not_configured']);

/* ---- conexão ---- */
try{
  $pdo = db();
}catch(Throwable $e){
  fail(500, 'Não foi possível conectar ao banco de dados. Confira DB_HOST, DB_NAME, DB_USER e DB_PASS no database.php.', ['code'=>'db_connect']);
}

/* ---- perfil dos usuários (admin / user): cria a coluna 'role' sozinho em bancos antigos.
        Quem já existia antes vira 'admin', para ninguém perder acesso. ---- */
$hasRole = true;
try{ $pdo->query('SELECT role FROM users LIMIT 1'); }
catch(Throwable $e){
  try{ $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'"); }
  catch(Throwable $e2){ $hasRole = false; }
}

/* ---- vendedor da venda (comissões): cria as colunas sozinho em bancos antigos ---- */
$salesSeller = true;
try{ $pdo->query('SELECT seller_id, seller_name FROM sales LIMIT 1'); }
catch(Throwable $e){
  try{ $pdo->exec('ALTER TABLE sales ADD COLUMN seller_id VARCHAR(30) DEFAULT NULL'); $pdo->exec('ALTER TABLE sales ADD COLUMN seller_name VARCHAR(80) DEFAULT NULL'); }
  catch(Throwable $e2){ $salesSeller = false; }
}

/* ---- promoção e novidade dos produtos: criam as colunas sozinhas em bancos antigos ---- */
$prodPromo = true;
try{ $pdo->query('SELECT promo_price, is_new FROM products LIMIT 1'); }
catch(Throwable $e){
  try{ $pdo->exec('ALTER TABLE products ADD COLUMN promo_price DECIMAL(12,2) DEFAULT NULL'); $pdo->exec('ALTER TABLE products ADD COLUMN is_new TINYINT(1) NOT NULL DEFAULT 0'); }
  catch(Throwable $e2){ $prodPromo = false; }
}

$prodLast = true;
try{ $pdo->query('SELECT last_units FROM products LIMIT 1'); }
catch(Throwable $e){
  try{ $pdo->exec('ALTER TABLE products ADD COLUMN last_units TINYINT(1) NOT NULL DEFAULT 0'); }
  catch(Throwable $e2){ $prodLast = false; }
}

/* ---- colunas extras de clientes (código e endereço): criadas sozinhas em bancos antigos ---- */
$custExtra = ['code'=>20, 'number'=>20, 'district'=>120, 'city'=>120, 'state'=>10, 'zip'=>12];
$custHas = [];
foreach($custExtra as $col=>$len){
  $custHas[$col] = true;
  try{ $pdo->query('SELECT `'.$col.'` FROM customers LIMIT 1'); }
  catch(Throwable $e){
    try{ $pdo->exec('ALTER TABLE customers ADD COLUMN `'.$col.'` VARCHAR('.$len.") NOT NULL DEFAULT ''"); }
    catch(Throwable $e2){ $custHas[$col] = false; }
  }
}

/* ---- status público: aparelho novo descobre se o servidor já tem usuários (não revela mais nada) ---- */
if((string)($req['action'] ?? '') === 'status'){
  try{ $n = (int)$pdo->query('SELECT COUNT(*) AS n FROM users')->fetch()['n']; }catch(Throwable $e){ $n = 0; }
  out(200, ['ok'=>true, 'hasUsers'=>($n > 0)]);
}

/* ---- autenticação: senha de sincronização OU usuário e senha de uma conta cadastrada ---- */
$key = (string)($req['key'] ?? '');
$authRole = null;
if($key !== '' && hash_equals((string)SYNC_KEY, $key)){
  $authRole = 'admin';
}elseif(!empty($req['user']) && !empty($req['hash'])){
  try{
    $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?)');
    $st->execute([(string)$req['user']]);
    $u = $st->fetch();
    if($u && hash_equals((string)$u['password_hash'], (string)$req['hash'])){
      $authRole = (($u['role'] ?? 'admin') === 'user') ? 'user' : 'admin';
    }
  }catch(Throwable $e){}
}
if($authRole === null){
  usleep(800000); // freia tentativas de adivinhar senha
  fail(401, 'Senha de sincronização, usuário ou senha incorretos.', ['code'=>'bad_key']);
}

/* ---- utilidades ---- */
function s($v){ return ($v === null) ? '' : (string)$v; }
function sn($v){ return ($v === null || $v === '') ? null : (string)$v; }
function num($v){ return is_numeric($v) ? 0 + $v : 0; }
function toDt($iso){
  if(!$iso) return null;
  $t = strtotime((string)$iso);
  return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
}
function fromDt($d){
  if(!$d) return null;
  try{ $o = new DateTime($d, new DateTimeZone('UTC')); return $o->format('Y-m-d\TH:i:s.000\Z'); }
  catch(Throwable $e){ return null; }
}
function getVersion($pdo){
  $st = $pdo->prepare('SELECT v FROM meta WHERE k = ?');
  $st->execute(['version']);
  $r = $st->fetch();
  return $r ? (int)$r['v'] : 0;
}
function setVersion($pdo, $n){
  $st = $pdo->prepare('UPDATE meta SET v = ? WHERE k = ?');
  $st->execute([(string)$n, 'version']);
  if($st->rowCount() === 0){
    $pdo->prepare('INSERT INTO meta (k, v) VALUES (?, ?)')->execute(['version', (string)$n]);
  }
}
function getMeta($pdo, $k){
  try{ $st = $pdo->prepare('SELECT v FROM meta WHERE k = ?'); $st->execute([$k]); $r = $st->fetch(); return $r ? (string)$r['v'] : ''; }catch(Throwable $e){ return ''; }
}
function setMeta($pdo, $k, $v){
  $st = $pdo->prepare('UPDATE meta SET v = ? WHERE k = ?'); $st->execute([(string)$v, $k]);
  if($st->rowCount() === 0 && getMeta($pdo, $k) === ''){
    $pdo->prepare('DELETE FROM meta WHERE k = ?')->execute([$k]);
    $pdo->prepare('INSERT INTO meta (k, v) VALUES (?, ?)')->execute([$k, (string)$v]);
  }
}
function rows($pdo, $sql){ return $pdo->query($sql)->fetchAll(); }

$action = (string)($req['action'] ?? '');

function ensureOnlineOrders($pdo){
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
}

try{
  /* ===================== PEDIDOS ONLINE (feitos no catálogo público) ===================== */
  if($action === 'onlineorders'){
    ensureOnlineOrders($pdo);
    $r = rows($pdo, 'SELECT * FROM online_orders ORDER BY created_at DESC LIMIT 300');
    out(200, ['ok'=>true, 'orders'=>array_map(fn($o)=>[
      'id'=>$o['id'], 'createdAt'=>$o['created_at'], 'productId'=>s($o['product_id']), 'productName'=>s($o['product_name']),
      'unitPrice'=>(float)$o['unit_price'], 'qty'=>(int)$o['qty'], 'customerName'=>s($o['customer_name']), 'phone'=>s($o['phone']),
      'note'=>s($o['note']), 'status'=>s($o['status']), 'payment'=>s($o['payment'] ?? ''),
      'total'=>(float)($o['total'] ?? 0), 'sellerName'=>s($o['seller_name'] ?? ''), 'sellerId'=>s($o['seller_id'] ?? ''),
      'items'=>(function($j){ $x = json_decode((string)$j, true); return is_array($x) ? $x : []; })($o['items'] ?? '')
    ], $r)]);
  }
  if($action === 'onlineorder_status'){
    ensureOnlineOrders($pdo);
    $st = (string)($req['status'] ?? '');
    if(!in_array($st, ['novo','atendimento','concluido','cancelado'], true)) fail(400, 'Status inválido.');
    $q = $pdo->prepare('UPDATE online_orders SET status = ? WHERE id = ?'); $q->execute([$st, (string)($req['id'] ?? '')]);
    out(200, ['ok'=>true]);
  }

  if($action === 'onlineorders_clear'){
    if($authRole !== 'admin') fail(403, 'Apenas o administrador pode apagar os pedidos online.');
    ensureOnlineOrders($pdo);
    $n = (int)$pdo->query('SELECT COUNT(*) AS n FROM online_orders')->fetch()['n'];
    $pdo->exec('DELETE FROM online_orders');
    out(200, ['ok'=>true, 'deleted'=>$n]);
  }

  /* ===================== PING ===================== */
  if($action === 'ping'){
    $v = getVersion($pdo);
    $empty = ((int)$pdo->query('SELECT COUNT(*) AS n FROM users')->fetch()['n'] === 0)
          && ((int)$pdo->query('SELECT COUNT(*) AS n FROM products')->fetch()['n'] === 0);
    out(200, ['ok'=>true, 'version'=>$v, 'empty'=>$empty]);
  }

  /* ===================== PULL ===================== */
  if($action === 'pull'){
    $d = [];
    $d['users'] = array_map(fn($r)=>[
      'id'=>$r['id'], 'name'=>$r['name'], 'username'=>$r['username'],
      'passwordHash'=>$r['password_hash'], 'createdAt'=>fromDt($r['created_at']),
      'role'=>(($r['role'] ?? 'admin') === 'user' ? 'user' : 'admin')
    ], rows($pdo, 'SELECT * FROM users ORDER BY created_at, id'));

    $d['customers'] = array_map(fn($r)=>[
      'id'=>$r['id'], 'code'=>s($r['code'] ?? ''), 'number'=>s($r['number'] ?? ''), 'district'=>s($r['district'] ?? ''), 'city'=>s($r['city'] ?? ''), 'state'=>s($r['state'] ?? ''), 'zip'=>s($r['zip'] ?? ''), 'name'=>$r['name'], 'phone'=>s($r['phone']), 'email'=>s($r['email']),
      'cpf'=>s($r['cpf']), 'address'=>s($r['address']), 'notes'=>s($r['notes']), 'createdAt'=>fromDt($r['created_at'])
    ], rows($pdo, 'SELECT * FROM customers ORDER BY name'));

    $d['categories'] = array_map(fn($r)=>['id'=>$r['id'], 'name'=>$r['name']],
      rows($pdo, 'SELECT * FROM categories ORDER BY name'));
    $d['subcategories'] = array_map(fn($r)=>['id'=>$r['id'], 'name'=>$r['name'], 'category'=>s($r['category'])],
      rows($pdo, 'SELECT * FROM subcategories ORDER BY name'));

    $d['products'] = array_map(fn($r)=>[
      'id'=>$r['id'], 'name'=>$r['name'], 'category'=>s($r['category']), 'subcategory'=>s($r['subcategory']),
      'image'=>s($r['image']), 'costPrice'=>(float)$r['cost_price'], 'salePrice'=>(float)$r['sale_price'],
      'markup'=>(float)$r['markup'], 'stock'=>(int)$r['stock'], 'minStock'=>(int)$r['min_stock'],
      'maxStock'=>($r['max_stock'] === null ? null : (int)$r['max_stock']),
      'active'=>((int)$r['active'] === 1), 'description'=>s($r['description']), 'sku'=>s($r['sku']),
      'brand'=>s($r['brand']), 'volume'=>s($r['volume']), 'supplier'=>s($r['supplier']),
      'promoPrice'=>(isset($r['promo_price']) && $r['promo_price'] !== null) ? (float)$r['promo_price'] : 0, 'isNew'=>!empty($r['is_new']), 'lastUnits'=>!empty($r['last_units']),
      'createdAt'=>fromDt($r['created_at'])
    ], rows($pdo, 'SELECT * FROM products ORDER BY created_at, name'));

    /* vendas */
    $items = []; foreach(rows($pdo, 'SELECT * FROM sale_items ORDER BY id') as $r){
      $items[$r['sale_id']][] = ['productId'=>$r['product_id'], 'name'=>$r['name'], 'qty'=>(int)$r['qty'],
        'unitPrice'=>(float)$r['unit_price'], 'subtotal'=>(float)$r['subtotal']];
    }
    $pays = []; foreach(rows($pdo, 'SELECT * FROM sale_payments ORDER BY id') as $r){
      $pays[$r['sale_id']][] = ['method'=>$r['method'], 'amount'=>(float)$r['amount']];
    }
    $d['sales'] = array_map(function($r) use ($items, $pays){
      $o = [
        'id'=>$r['id'], 'date'=>fromDt($r['date']), 'customerId'=>$r['customer_id'],
        'customerName'=>s($r['customer_name']), 'items'=>$items[$r['id']] ?? [],
        'subtotal'=>(float)$r['subtotal'], 'discount'=>(float)$r['discount'], 'total'=>(float)$r['total'],
        'payments'=>$pays[$r['id']] ?? [], 'status'=>$r['status']
      ];
      if($r['order_id']) $o['orderId'] = $r['order_id'];
      if(!empty($r['seller_id'])) $o['sellerId'] = $r['seller_id'];
      if(!empty($r['seller_name'])) $o['sellerName'] = $r['seller_name'];
      return $o;
    }, rows($pdo, 'SELECT * FROM sales ORDER BY date DESC, id DESC'));

    /* pedidos */
    $oitems = []; foreach(rows($pdo, 'SELECT * FROM order_items ORDER BY id') as $r){
      $oitems[$r['order_id']][] = ['productId'=>$r['product_id'], 'name'=>$r['name'], 'qty'=>(int)$r['qty'],
        'unitPrice'=>(float)$r['unit_price'], 'subtotal'=>(float)$r['subtotal']];
    }
    $d['orders'] = array_map(function($r) use ($oitems){
      return [
        'id'=>$r['id'], 'orderNumber'=>$r['order_number'], 'customerId'=>$r['customer_id'] ?? '',
        'customerName'=>s($r['customer_name']), 'items'=>$oitems[$r['id']] ?? [],
        'subtotal'=>(float)$r['subtotal'], 'discount'=>(float)$r['discount'], 'shipping'=>(float)$r['shipping'],
        'total'=>(float)$r['total'], 'status'=>$r['status'], 'notes'=>s($r['notes']),
        'expectedDate'=>s($r['expected_date']), 'createdBy'=>s($r['created_by']),
        'saleId'=>$r['sale_id'], 'invoicedAt'=>fromDt($r['invoiced_at']), 'createdAt'=>fromDt($r['created_at'])
      ];
    }, rows($pdo, 'SELECT * FROM orders ORDER BY created_at DESC, id DESC'));

    $__sl = json_decode((string)getMeta($pdo, 'sellers'), true);
    $__px = json_decode((string)getMeta($pdo, 'pix'), true);
    $d['settings'] = ['whatsapp'=>getMeta($pdo, 'whatsapp'), 'sellers'=>is_array($__sl) ? $__sl : [], 'pix'=>is_array($__px) ? $__px : new stdClass(), 'commissionMain'=>(float)getMeta($pdo, 'commission_main'), 'catalogColor'=>(string)getMeta($pdo, 'catalog_color')];
    out(200, ['ok'=>true, 'version'=>getVersion($pdo), 'data'=>$d]);
  }

  /* ===================== PUSH ===================== */
  if($action === 'push'){
    $d = $req['data'] ?? null;
    if(!is_array($d)) fail(400, 'Dados ausentes.');
    foreach(['users','customers','categories','subcategories','products','sales','orders'] as $k){
      if(!isset($d[$k]) || !is_array($d[$k])) fail(400, "Campo '$k' ausente ou inválido.");
    }
    $base  = (int)($req['baseVersion'] ?? 0);
    $force = !empty($req['force']);

    $pdo->beginTransaction();
    try{
      $cur = getVersion($pdo);
      if(!$force && $cur !== $base){
        $pdo->rollBack();
        fail(409, 'O servidor tem uma versão diferente dos dados. Baixe do servidor ou escolha sobrescrever.', ['code'=>'conflict', 'version'=>$cur]);
      }

      foreach(['sale_items','sale_payments','sales','order_items','orders','products','subcategories','categories','customers','users'] as $t){
        if($t === 'users' && $authRole !== 'admin') continue;   // conta 'user' não altera contas nem perfis
        $pdo->exec("DELETE FROM $t");
      }

      $ins = fn($sql) => $pdo->prepare($sql);

      $st = $ins($hasRole
        ? 'INSERT INTO users (id,name,username,password_hash,created_at,role) VALUES (?,?,?,?,?,?)'
        : 'INSERT INTO users (id,name,username,password_hash,created_at) VALUES (?,?,?,?,?)');
      if($authRole === 'admin') foreach($d['users'] as $u){
        $row = [s($u['id']??''), s($u['name']??''), s($u['username']??''), s($u['passwordHash']??''), toDt($u['createdAt']??null) ?? gmdate('Y-m-d H:i:s')];
        if($hasRole) $row[] = (($u['role'] ?? 'admin') === 'user') ? 'user' : 'admin';
        $st->execute($row);
      }

      $cCols = ['id','name','phone','email','cpf','address','notes','created_at'];
      foreach($custExtra as $col=>$len){ if($custHas[$col]) $cCols[] = $col; }
      $st = $ins('INSERT INTO customers ('.implode(',', array_map(fn($x)=>'`'.$x.'`', $cCols)).') VALUES ('.implode(',', array_fill(0, count($cCols), '?')).')');
      foreach($d['customers'] as $c){
        $row = [s($c['id']??''), s($c['name']??''), s($c['phone']??''), s($c['email']??''), s($c['cpf']??''), s($c['address']??''), s($c['notes']??''), toDt($c['createdAt']??null) ?? gmdate('Y-m-d H:i:s')];
        foreach($custExtra as $col=>$len){ if($custHas[$col]) $row[] = mb_substr(s($c[$col]??''), 0, $len); }
        $st->execute($row);
      }

      $st = $ins('INSERT INTO categories (id,name) VALUES (?,?)');
      foreach($d['categories'] as $c){ $st->execute([s($c['id']??''), s($c['name']??'')]); }

      $st = $ins('INSERT INTO subcategories (id,name,category) VALUES (?,?,?)');
      foreach($d['subcategories'] as $c){ $st->execute([s($c['id']??''), s($c['name']??''), s($c['category']??'')]); }

      $stLast = $prodLast ? $pdo->prepare('UPDATE products SET last_units = ? WHERE id = ?') : null;
      $st = $prodPromo ? $ins('INSERT INTO products (id,name,category,subcategory,image,cost_price,sale_price,markup,stock,min_stock,max_stock,active,description,sku,brand,volume,supplier,created_at,promo_price,is_new) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                       : $ins('INSERT INTO products (id,name,category,subcategory,image,cost_price,sale_price,markup,stock,min_stock,max_stock,active,description,sku,brand,volume,supplier,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      foreach($d['products'] as $p){
        $mx = $p['maxStock'] ?? null;
        $st->execute([
          s($p['id']??''), s($p['name']??''), s($p['category']??''), s($p['subcategory']??''), s($p['image']??''),
          num($p['costPrice']??0), num($p['salePrice']??0), num($p['markup']??0),
          (int)num($p['stock']??0), (int)num($p['minStock']??0),
          ($mx === null || $mx === '') ? null : (int)num($mx),
          (($p['active'] ?? true) === false) ? 0 : 1,
          s($p['description']??''), s($p['sku']??''), s($p['brand']??''), s($p['volume']??''), s($p['supplier']??''),
          toDt($p['createdAt']??null) ?? gmdate('Y-m-d H:i:s')
        ] + ($prodPromo ? [18=>(num($p['promoPrice']??0) > 0 && num($p['promoPrice']??0) < num($p['salePrice']??0)) ? num($p['promoPrice']) : null, 19=>!empty($p['isNew']) ? 1 : 0] : []));
        if($stLast && !empty($p['lastUnits'])) $stLast->execute([1, s($p['id']??'')]);
      }

      $st  = $salesSeller ? $ins('INSERT INTO sales (id,date,customer_id,customer_name,subtotal,discount,total,status,order_id,created_at,seller_id,seller_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                          : $ins('INSERT INTO sales (id,date,customer_id,customer_name,subtotal,discount,total,status,order_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
      $sti = $ins('INSERT INTO sale_items (sale_id,product_id,name,qty,unit_price,subtotal) VALUES (?,?,?,?,?,?)');
      $stp = $ins('INSERT INTO sale_payments (sale_id,method,amount) VALUES (?,?,?)');
      foreach($d['sales'] as $sa){
        $dt = toDt($sa['date']??null) ?? gmdate('Y-m-d H:i:s');
        $st->execute([s($sa['id']??''), $dt, sn($sa['customerId']??null), s($sa['customerName']??'Consumidor Final'),
          num($sa['subtotal']??0), num($sa['discount']??0), num($sa['total']??0), s($sa['status']??'concluida'), sn($sa['orderId']??null), $dt]
          + ($salesSeller ? [10=>mb_substr(s($sa['sellerId']??''), 0, 30), 11=>mb_substr(s($sa['sellerName']??''), 0, 80)] : []));
        foreach(($sa['items']??[]) as $i){
          $sti->execute([s($sa['id']), sn($i['productId']??null), s($i['name']??''), (int)num($i['qty']??1), num($i['unitPrice']??0), num($i['subtotal']??0)]);
        }
        foreach(($sa['payments']??[]) as $pm){
          $stp->execute([s($sa['id']), s($pm['method']??''), num($pm['amount']??0)]);
        }
      }

      $st  = $ins('INSERT INTO orders (id,order_number,customer_id,customer_name,subtotal,discount,shipping,total,status,notes,expected_date,created_by,sale_id,invoiced_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $sti = $ins('INSERT INTO order_items (order_id,product_id,name,qty,unit_price,subtotal) VALUES (?,?,?,?,?,?)');
      foreach($d['orders'] as $o){
        $exp = sn($o['expectedDate']??null);
        if($exp !== null && !preg_match('/^\d{4}-\d{2}-\d{2}/', $exp)) $exp = null;
        if($exp !== null) $exp = substr($exp, 0, 10);
        $st->execute([s($o['id']??''), s($o['orderNumber']??''), sn($o['customerId']??null), s($o['customerName']??'Consumidor Final'),
          num($o['subtotal']??0), num($o['discount']??0), num($o['shipping']??0), num($o['total']??0),
          s($o['status']??'Rascunho'), s($o['notes']??''), $exp, s($o['createdBy']??''), sn($o['saleId']??null),
          toDt($o['invoicedAt']??null), toDt($o['createdAt']??null) ?? gmdate('Y-m-d H:i:s')]);
        foreach(($o['items']??[]) as $i){
          $sti->execute([s($o['id']), sn($i['productId']??null), s($i['name']??''), (int)num($i['qty']??1), num($i['unitPrice']??0), num($i['subtotal']??0)]);
        }
      }

      $new = $cur + 1;
      setVersion($pdo, $new);
      if($authRole === 'admin' && isset($d['settings']) && is_array($d['settings']) && isset($d['settings']['sellers']) && is_array($d['settings']['sellers'])){
        $__out = [];
        foreach(array_slice($d['settings']['sellers'], 0, 20) as $__s){
          if(!is_array($__s)) continue;
          $__id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($__s['id'] ?? '')));
          $__nm = trim(strip_tags((string)($__s['name'] ?? '')));
          $__ph = substr(preg_replace('/\D/', '', (string)($__s['phone'] ?? '')), 0, 20);
          if($__id === '' || strlen($__id) > 24 || $__nm === '' || strlen($__ph) < 10) continue;
          $__out[] = ['id'=>$__id, 'name'=>mb_substr($__nm, 0, 60), 'phone'=>$__ph, 'commission'=>max(0, min(100, round((float)str_replace(',', '.', (string)($__s['commission'] ?? 0)), 2)))];
        }
        setMeta($pdo, 'sellers', json_encode($__out, JSON_UNESCAPED_UNICODE));
      }
      if($authRole === 'admin' && isset($d['settings']) && is_array($d['settings']) && array_key_exists('whatsapp', $d['settings']) && preg_replace('/\D/', '', (string)$d['settings']['whatsapp']) !== ''){
        setMeta($pdo, 'whatsapp', substr(preg_replace('/\D/', '', (string)$d['settings']['whatsapp']), 0, 20));
      }
      if($authRole === 'admin' && isset($d['settings']) && is_array($d['settings']) && array_key_exists('catalogColor', $d['settings'])){
        $__cc = (string)$d['settings']['catalogColor'];
        setMeta($pdo, 'catalog_color', preg_match('/^#[0-9a-fA-F]{6}$/', $__cc) ? strtolower($__cc) : '');
      }
      if($authRole === 'admin' && isset($d['settings']) && is_array($d['settings']) && array_key_exists('commissionMain', $d['settings'])){
        setMeta($pdo, 'commission_main', (string)max(0, min(100, round((float)str_replace(',', '.', (string)$d['settings']['commissionMain']), 2))));
      }
      if($authRole === 'admin' && isset($d['settings']['pix']) && is_array($d['settings']['pix'])){
        $__p = $d['settings']['pix'];
        $__t = in_array(($__p['type'] ?? ''), ['cpf_cnpj', 'phone', 'email', 'random'], true) ? $__p['type'] : 'random';
        $__k = trim(mb_substr((string)($__p['key'] ?? ''), 0, 77));
        if($__k !== ''){   /* chave vazia é ignorada, para um aparelho desatualizado não apagar a chave */
          setMeta($pdo, 'pix', json_encode(['type'=>$__t, 'key'=>$__k, 'name'=>mb_substr(trim((string)($__p['name'] ?? '')), 0, 25), 'city'=>mb_substr(trim((string)($__p['city'] ?? '')), 0, 15)], JSON_UNESCAPED_UNICODE));
        }
      }
      $pdo->commit();
      out(200, ['ok'=>true, 'version'=>$new]);
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      if($e instanceof PDOException){
        $msg = (strpos($e->getMessage(), 'nique') !== false || strpos($e->getMessage(), 'uplicate') !== false)
          ? 'Dados duplicados (ex.: dois usuários com o mesmo login ou IDs repetidos).'
          : 'Erro ao gravar no banco. Confirme que o schema.sql foi importado.';
        fail(500, $msg, ['code'=>'db_write']);
      }
      throw $e;
    }
  }

  fail(400, 'Ação desconhecida.');
}catch(Throwable $e){
  fail(500, 'Erro no servidor. Confirme que o schema.sql foi importado no banco.', ['code'=>'server']);
}
