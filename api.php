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
function rows($pdo, $sql){ return $pdo->query($sql)->fetchAll(); }

$action = (string)($req['action'] ?? '');

try{
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

      $st = $ins('INSERT INTO products (id,name,category,subcategory,image,cost_price,sale_price,markup,stock,min_stock,max_stock,active,description,sku,brand,volume,supplier,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
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
        ]);
      }

      $st  = $ins('INSERT INTO sales (id,date,customer_id,customer_name,subtotal,discount,total,status,order_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
      $sti = $ins('INSERT INTO sale_items (sale_id,product_id,name,qty,unit_price,subtotal) VALUES (?,?,?,?,?,?)');
      $stp = $ins('INSERT INTO sale_payments (sale_id,method,amount) VALUES (?,?,?)');
      foreach($d['sales'] as $sa){
        $dt = toDt($sa['date']??null) ?? gmdate('Y-m-d H:i:s');
        $st->execute([s($sa['id']??''), $dt, sn($sa['customerId']??null), s($sa['customerName']??'Consumidor Final'),
          num($sa['subtotal']??0), num($sa['discount']??0), num($sa['total']??0), s($sa['status']??'concluida'), sn($sa['orderId']??null), $dt]);
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
