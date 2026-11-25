<?php
require __DIR__ . '/config.php';
date_default_timezone_set('Asia/Makassar');
const DATA = __DIR__ . '/data/', UP = __DIR__ . '/uploads/';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

function out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fail($m, $c = 400) { http_response_code($c); out(['ok' => false, 'err' => $m]); }
function rd($f, $d) { $p = DATA . $f . '.php'; if (!is_file($p)) return $d; $s = file_get_contents($p); $j = json_decode(substr($s, strpos($s, "\n") + 1), true); return $j === null ? $d : $j; }
function wr($f, $v) { if (!is_dir(DATA)) mkdir(DATA, 0755, true); $p = DATA . $f . '.php'; file_put_contents($p . '.tmp', "<?php http_response_code(403); exit; ?>\n" . json_encode($v, JSON_UNESCAPED_UNICODE), LOCK_EX); rename($p . '.tmp', $p); }
function cl($s, $n) { return mb_substr(trim(strip_tags((string)$s)), 0, $n); }
function img($s) { return preg_match('#^uploads/[a-f0-9]{24}\.jpg$#', (string)$s) ? $s : ''; }
function fid($x) { return floor(max(0, (float)$x)); }
function uname($s) { $s = trim((string)$s); return preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $s) ? $s : ''; }
function heroCfg($h) {
  $d = ['orbit'=>['show'=>true,'color'=>'#ffffff','opacity'=>12,'scale'=>100,'dash'=>true], 'cookies'=>['show'=>true,'color'=>'#dca45b','scale'=>100], 'pouch'=>['show'=>true,'color'=>'#f6ead2','text'=>'BARUASA','scale'=>100], 'badge'=>['show'=>true,'color'=>'#d97752','top'=>'KHAS','text'=>'Makassar','scale'=>100]];
  $h = is_array($h) ? $h : [];
  $part = function($k) use($h) { return isset($h[$k]) && is_array($h[$k]) ? $h[$k] : []; };
  $color = function($v, $fallback) { return preg_match('/^#[0-9a-f]{6}$/i', (string)$v) ? strtolower((string)$v) : $fallback; };
  $num = function($v, $fallback, $min, $max) { return is_numeric($v) ? max($min, min($max, round((float)$v))) : $fallback; };
  $bool = function($v, $fallback) { return is_bool($v) ? $v : $fallback; };
  $text = function($v, $max, $fallback) { $s = cl($v, $max); return $s !== '' ? $s : $fallback; };
  $o = $part('orbit'); $c = $part('cookies'); $p = $part('pouch'); $b = $part('badge');
  $d['orbit'] = ['show'=>$bool($o['show'] ?? null,$d['orbit']['show']), 'color'=>$color($o['color'] ?? '',$d['orbit']['color']), 'opacity'=>$num($o['opacity'] ?? null,$d['orbit']['opacity'],0,60), 'scale'=>$num($o['scale'] ?? null,$d['orbit']['scale'],60,140), 'dash'=>$bool($o['dash'] ?? null,$d['orbit']['dash'])];
  $d['cookies'] = ['show'=>$bool($c['show'] ?? null,$d['cookies']['show']), 'color'=>$color($c['color'] ?? '',$d['cookies']['color']), 'scale'=>$num($c['scale'] ?? null,$d['cookies']['scale'],60,140)];
  $d['pouch'] = ['show'=>$bool($p['show'] ?? null,$d['pouch']['show']), 'color'=>$color($p['color'] ?? '',$d['pouch']['color']), 'text'=>$text($p['text'] ?? '',18,$d['pouch']['text']), 'scale'=>$num($p['scale'] ?? null,$d['pouch']['scale'],60,140)];
  $d['badge'] = ['show'=>$bool($b['show'] ?? null,$d['badge']['show']), 'color'=>$color($b['color'] ?? '',$d['badge']['color']), 'top'=>$text($b['top'] ?? '',12,$d['badge']['top']), 'text'=>$text($b['text'] ?? '',24,$d['badge']['text']), 'scale'=>$num($b['scale'] ?? null,$d['badge']['scale'],60,140)];
  return $d;
}
function lim($k, $max, $win) {
  $a = rd('lim', []); $t = time();
  foreach ($a as $x => $v) { $a[$x] = array_values(array_filter($v, fn($q) => $q > $t - 3600)); if (!$a[$x]) unset($a[$x]); }
  $r = array_values(array_filter($a[$k] ?? [], fn($q) => $q > $t - $win));
  if (count($r) >= $max) fail('Terlalu banyak percobaan. Coba lagi nanti.', 429);
  $r[] = $t; $a[$k] = $r; wr('lim', $a);
}

$act = $_GET['action'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$b = $post ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$users = rd('users', []);
$me = $_SESSION['u'] ?? null; $role = $_SESSION['r'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';

function need($p = true) {
  global $me, $post;
  if (!$me) fail('Belum login.', 401);
  if ($p && (!$post || !hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Sesi tidak valid. Muat ulang halaman.', 403);
}
function start($u, $r) {
  session_regenerate_id(true);
  $_SESSION['u'] = $u; $_SESSION['r'] = $r; $_SESSION['csrf'] = bin2hex(random_bytes(16));
  out(['ok' => true, 'user' => $u, 'role' => $r, 'csrf' => $_SESSION['csrf']]);
}
function locked($fn) {
  if (!is_dir(DATA)) mkdir(DATA, 0755, true);
  $lk = fopen(DATA . 'lock.txt', 'c'); flock($lk, LOCK_EX); $fn(); flock($lk, LOCK_UN);
}

switch ($act) {
case 'visit':
  if (!$post) fail('Metode salah.', 405);
  $now = time();
  if ($now - (int)($_SESSION['last_visit'] ?? 0) < 1800) out(['ok' => true]);
  locked(function () use ($now) { $v = rd('visits', []); $day = date('Y-m-d', $now); $v[$day] = (int)($v[$day] ?? 0) + 1; wr('visits', $v); });
  $_SESSION['last_visit'] = $now; out(['ok' => true]);
case 'stats':
  need(false);
  $v = rd('visits', []); $today = date('Y-m-d'); $month = date('Y-m'); $year = date('Y');
  $dayCount = $monthCount = $yearCount = 0;
  foreach ($v as $day => $count) { $count = (int)$count; if ($day === $today) $dayCount += $count; if (strpos($day, $month) === 0) $monthCount += $count; if (strpos($day, $year) === 0) $yearCount += $count; }
  out(['ok' => true, 'today' => $dayCount, 'month' => $monthCount, 'year' => $yearCount, 'total' => array_sum($v)]);
case 'get':
  out(['ok' => true, 'state' => rd('state', (object)[]), 'reviews' => rd('reviews', [])]);
case 'me':
  out(['ok' => true, 'user' => $me, 'role' => $role, 'csrf' => $me ? ($_SESSION['csrf'] ?? '') : '', 'needsSetup' => !$users]);
case 'setup':
  if (!$post || $users) fail('Setup sudah selesai.');
  if (SETUP_KEY === 'GANTI-KODE-INI') fail('Ubah SETUP_KEY di file config.php dulu.');
  lim('s' . $ip, 5, 600);
  if (!hash_equals(SETUP_KEY, (string)($b['k'] ?? ''))) fail('Kode setup salah.', 403);
  $u = uname($b['u'] ?? ''); $p = (string)($b['p'] ?? '');
  if (!$u || strlen($p) < 8) fail('Username 3-30 karakter (huruf/angka) dan password minimal 8 karakter.');
  wr('users', [['u' => $u, 'h' => password_hash($p, PASSWORD_DEFAULT), 'r' => 'manager']]);
  start($u, 'manager');
case 'login':
  if (!$post) fail('Metode salah.', 405);
  lim('l' . $ip, 5, 600);
  foreach ($users as $x) if (hash_equals($x['u'], (string)($b['u'] ?? '')) && password_verify((string)($b['p'] ?? ''), $x['h'])) start($x['u'], $x['r']);
  fail('Username atau password salah.', 401);
case 'logout':
  session_destroy(); out(['ok' => true]);
case 'changepw':
  need();
  foreach ($users as $i => $x) if ($x['u'] === $me) {
    if (!password_verify((string)($b['o'] ?? ''), $x['h'])) fail('Password lama salah.');
    if (strlen((string)($b['p'] ?? '')) < 8) fail('Password baru minimal 8 karakter.');
    $users[$i]['h'] = password_hash($b['p'], PASSWORD_DEFAULT); wr('users', $users); out(['ok' => true]);
  }
  fail('Akun tidak ditemukan.');
case 'users':
  need(false); if ($role !== 'manager') fail('Hanya pengelola.', 403);
  out(['ok' => true, 'users' => array_column($users, 'u')]);
case 'setusers':
  need(); if ($role !== 'manager') fail('Hanya pengelola.', 403);
  $new = []; $seen = [];
  foreach (array_slice($b['users'] ?? [], 0, 3) as $i => $n) {
    $u = uname($n['u'] ?? ''); $p = (string)($n['p'] ?? '');
    if (!$u || isset($seen[strtolower($u)])) fail('Username tidak valid atau kembar.');
    $seen[strtolower($u)] = 1; $h = '';
    if ($p !== '') { if (strlen($p) < 8) fail('Password minimal 8 karakter.'); $h = password_hash($p, PASSWORD_DEFAULT); }
    else foreach ($users as $x) if ($x['u'] === $u) $h = $x['h'];
    if (!$h) fail('Isi password untuk akun ' . $u . '.');
    $new[] = ['u' => $u, 'h' => $h, 'r' => $i === 0 ? 'manager' : 'user'];
  }
  if (!$new) fail('Minimal satu akun.');
  wr('users', $new); $_SESSION['u'] = $new[0]['u']; out(['ok' => true]);
case 'save':
  need();
  $s = ['name' => cl($b['name'] ?? '', 60), 'tag' => cl($b['tag'] ?? '', 120), 'cover' => img($b['cover'] ?? ''), 'wa' => preg_replace('/\D/', '', (string)($b['wa'] ?? '')),
    'addr' => cl($b['addr'] ?? '', 200), 'oname' => cl($b['oname'] ?? '', 60), 'oimg' => img($b['oimg'] ?? ''), 'maps' => cl($b['maps'] ?? '', 200),
    'soc' => [], 'products' => [], 'acts' => [], 'certs' => [], 'arts' => [], 'hero' => heroCfg($b['hero'] ?? [])];
  foreach (['fb', 'ig', 'tt', 'yt'] as $k) { $u = trim((string)($b['soc'][$k] ?? '')); $s['soc'][$k] = preg_match('#^https://[^\s<>"\']{3,300}$#i', $u) ? $u : ''; }
  foreach (array_slice($b['products'] ?? [], 0, 200) as $p) {
    $base = max(0, min(1000000000, (int)($p['price'] ?? 0)));
    $inBoxes = is_array($p['boxes'] ?? null) ? $p['boxes'] : []; $boxes = [];
    foreach (array_values(array_slice($inBoxes, 0, 2)) as $j => $box) $boxes[] = ['name' => $j === 0 ? 'BOX Besar' : 'BOX Kecil',
      'price' => max(0, min(1000000000, (int)(is_array($box) ? ($box['price'] ?? $base) : $base)))];
    while (count($boxes) < 2) $boxes[] = ['name' => count($boxes) === 0 ? 'BOX Besar' : 'BOX Kecil', 'price' => $base];
    $s['products'][] = ['id' => fid($p['id'] ?? 0), 'name' => cl($p['name'] ?? '', 80),
      'price' => $boxes[0]['price'], 'boxes' => $boxes, 'cat' => cl($p['cat'] ?? 'Lainnya', 30), 'emoji' => cl($p['emoji'] ?? '', 8),
      'bg' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($p['bg'] ?? '')) ? $p['bg'] : '#e6efe9', 'img' => img($p['img'] ?? ''), 'desc' => cl($p['desc'] ?? '', 200), 'komp' => cl($p['komp'] ?? '', 400)];
  }
  foreach (['acts', 'certs'] as $k) foreach (array_slice($b[$k] ?? [], 0, 60) as $a) $s[$k][] = ['id' => fid($a['id'] ?? 0), 'img' => img($a['img'] ?? ''),
    'title' => cl($a['title'] ?? '', 100), 'text' => cl($a['text'] ?? '', 2000), 'date' => cl($a['date'] ?? '', 20)];
  foreach (array_slice($b['arts'] ?? [], 0, 60) as $a) $s['arts'][] = ['id' => fid($a['id'] ?? 0), 'img' => img($a['img'] ?? ''), 'title' => cl($a['title'] ?? '', 120),
    'body' => mb_substr(strip_tags((string)($a['body'] ?? '')), 0, 20000)];
  wr('state', $s); out(['ok' => true]);
case 'upload':
  need();
  $d = (string)($b['data'] ?? '');
  if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $d)) fail('Format gambar tidak didukung.');
  $bin = base64_decode(substr($d, strpos($d, ',') + 1), true);
  if (!$bin || strlen($bin) > 4000000) fail('Gambar tidak valid atau terlalu besar.');
  $i = @getimagesizefromstring($bin);
  if (!$i || !in_array($i[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP]) || $i[0] > 4000 || $i[1] > 4000) fail('Gambar tidak valid.');
  if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($bin))) { ob_start(); imagejpeg($im, null, 85); $bin = ob_get_clean(); }
  elseif ($i[2] !== IMAGETYPE_JPEG) fail('Server perlu ekstensi GD untuk gambar ini.');
  if (!is_dir(UP)) mkdir(UP, 0755, true);
  $n = bin2hex(random_bytes(12)) . '.jpg'; file_put_contents(UP . $n, $bin);
  out(['ok' => true, 'url' => 'uploads/' . $n]);
case 'review':
case 'addrev':
  if (!$post) fail('Metode salah.', 405);
  if ($act === 'review') lim('r' . $ip, 3, 3600); else need();
  $st = (int)($b['stars'] ?? 0); $nm = cl($b['name'] ?? '', 40) ?: 'Anonim'; $tx = cl($b['text'] ?? '', 400);
  if ($st < 1 || $st > 5) fail('Bintang harus 1 sampai 5.');
  locked(function () use ($nm, $st, $tx) { $r = rd('reviews', []); array_unshift($r, ['id' => round(microtime(true) * 1000), 'name' => $nm, 'stars' => $st, 'text' => $tx]); wr('reviews', array_slice($r, 0, 300)); });
  out(['ok' => true]);
case 'delrev':
  need(); $id = (float)($b['id'] ?? 0);
  locked(function () use ($id) { wr('reviews', array_values(array_filter(rd('reviews', []), fn($x) => abs($x['id'] - $id) > 0.5))); });
  out(['ok' => true]);
default:
  fail('Aksi tidak dikenal.', 404);
}
