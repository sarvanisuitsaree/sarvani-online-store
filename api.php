<?php
// Sarvani – secure catalogue API. Admin login is checked HERE on the server, never in the browser.
declare(strict_types=1);
error_reporting(0);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

$DATA    = __DIR__ . '/data';
$UPLOADS = __DIR__ . '/uploads';
if (!is_dir($DATA))    @mkdir($DATA, 0750, true);
if (!is_dir($UPLOADS)) @mkdir($UPLOADS, 0755, true);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('sarvani_sid');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

function out($d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function jread(string $f, $def) { if (!is_file($f)) return $def; $j = json_decode((string)@file_get_contents($f), true); return is_array($j) ? $j : $def; }
function jwrite(string $f, $d): void {
    $t = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) out(['error' => 'Could not save. Please make the "data" folder writable (permission 755 or 775).'], 500);
    rename($t, $f);
}
function clean($s, int $max): string { $s = trim(strip_tags((string)$s)); $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s); return mb_substr($s, 0, $max); }

$PRODUCTS = $DATA . '/products.json';
$SETTINGS = $DATA . '/settings.json';
$CONFIG   = $DATA . '/config.php';
$defaultSettings = [
    'whatsapp'  => '917976900021',
    'tagline'   => 'Timeless Indian elegance, chosen with love for every occasion.',
    'about'     => 'Established in 2015 in Murlipura, Jaipur, Sarvani Suit & Saree brings you traditional and designer ethnic wear: sarees, suits, lehariya prints, cotton and silk fabrics, and blouses for every occasion.',
    'address'   => 'Plot No. 9, Road Number 2, opposite VKI (Vishwakarma Industrial Area), Ram Nagar, Murlipura, Jaipur, Rajasthan 302039',
    'hours'     => '',
    'instagram' => 'https://instagram.com/sarvani_jaipur',
];
function settings(): array { global $SETTINGS, $defaultSettings; return array_merge($defaultSettings, jread($SETTINGS, [])); }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = !empty($_SESSION['admin']);

// ---------- public ----------
if ($method === 'GET' && $action === 'catalog') {
    out(['products' => jread($PRODUCTS, []), 'settings' => settings()]);
}
if ($method === 'GET' && $action === 'me') {
    out(['admin' => $isAdmin, 'csrf' => $isAdmin ? ($_SESSION['csrf'] ?? '') : null]);
}

if ($method !== 'POST') out(['error' => 'Not found'], 404);

// ---------- login ----------
if ($action === 'login') {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x');
    $f  = $DATA . '/attempts.json';
    $att = jread($f, []);
    foreach ($att as $k => $v) if (time() - ($v['t'] ?? 0) > 900) unset($att[$k]);
    if (($att[$ip]['n'] ?? 0) >= 5) { jwrite($f, $att); out(['error' => 'Too many wrong attempts. Please try again after 15 minutes.'], 429); }
    $cfg = is_file($CONFIG) ? include $CONFIG : null;
    $ok = false;
    if (is_array($cfg)) {
        $idOk = hash_equals(strtolower((string)$cfg['admin_id']), strtolower(trim((string)($in['id'] ?? ''))));
        $pwOk = password_verify((string)($in['password'] ?? ''), (string)$cfg['password_hash']);
        $ok = $idOk && $pwOk;
    }
    if (!$ok) {
        $att[$ip] = ['n' => ($att[$ip]['n'] ?? 0) + 1, 't' => time()];
        jwrite($f, $att);
        usleep(500000);
        out(['error' => 'Incorrect ID or password.'], 401);
    }
    unset($att[$ip]); jwrite($f, $att);
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf']  = bin2hex(random_bytes(16));
    out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

// ---------- everything below needs admin ----------
if (!$isAdmin) out(['error' => 'Please log in as admin.'], 401);
$tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$tok)) out(['error' => 'Session expired. Please log in again.'], 403);

if ($action === 'logout') { $_SESSION = []; session_destroy(); out(['ok' => true]); }

$lock = fopen($DATA . '/.lock', 'c'); if ($lock) flock($lock, LOCK_EX);

function valid_img(string $n): bool { return (bool)preg_match('/^[a-f0-9]{20}\.(jpg|png|webp)$/', $n); }

function process_image(array $f): string {
    global $UPLOADS;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('Photo upload failed. Try a smaller photo.');
    if ($f['size'] > 12 * 1024 * 1024) throw new Exception('Photo is too large (max 12 MB).');
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) throw new Exception('Only JPG, PNG or WebP photos are allowed.');
    $name = bin2hex(random_bytes(10));
    if (function_exists('imagecreatetruecolor')) {
        $src = false;
        if ($info[2] === IMAGETYPE_JPEG) $src = @imagecreatefromjpeg($f['tmp_name']);
        elseif ($info[2] === IMAGETYPE_PNG) $src = @imagecreatefrompng($f['tmp_name']);
        elseif (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($f['tmp_name']);
        if ($src) {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $ex = @exif_read_data($f['tmp_name']);
                $o = $ex['Orientation'] ?? 1;
                if ($o === 3) $src = imagerotate($src, 180, 0); elseif ($o === 6) $src = imagerotate($src, -90, 0); elseif ($o === 8) $src = imagerotate($src, 90, 0);
            }
            $w = imagesx($src); $h = imagesy($src); $max = 1600;
            $r = min(1, $max / max($w, $h));
            $nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            if (imagejpeg($dst, $UPLOADS . '/' . $name . '.jpg', 86)) return $name . '.jpg';
        }
    }
    if (!move_uploaded_file($f['tmp_name'], $UPLOADS . '/' . $name . '.' . $types[$info[2]])) throw new Exception('Could not store photo. Make the "uploads" folder writable.');
    return $name . '.' . $types[$info[2]];
}

if ($action === 'save_product') {
    $products = jread($PRODUCTS, []);
    $id = clean($_POST['id'] ?? '', 20);
    $idx = -1;
    if ($id !== '') foreach ($products as $i => $p) if ($p['id'] === $id) $idx = $i;
    if ($id !== '' && $idx < 0) out(['error' => 'Product not found.'], 404);

    $name = clean($_POST['name'] ?? '', 120);
    if ($name === '') out(['error' => 'Please enter the product name.'], 422);
    $cat = in_array($_POST['category'] ?? '', ['Suits', 'Sarees', 'Blouses', 'Fabrics', 'Other'], true) ? $_POST['category'] : 'Suits';
    $priceRaw = trim((string)($_POST['price'] ?? ''));
    $price = null;
    if ($priceRaw !== '') {
        if (!is_numeric($priceRaw) || (float)$priceRaw < 0 || (float)$priceRaw > 10000000) out(['error' => 'Price must be a number.'], 422);
        $price = (int)round((float)$priceRaw);
    }
    $sizes = [];
    foreach ((array)json_decode((string)($_POST['sizes'] ?? '[]'), true) as $s) { $s = clean($s, 14); if ($s !== '' && !in_array($s, $sizes, true)) $sizes[] = $s; }
    $sizes = array_slice($sizes, 0, 20);
    $desc = clean($_POST['description'] ?? '', 1500);
    $inStock = ($_POST['in_stock'] ?? '1') === '1';

    // photos
    $old = $idx >= 0 ? ($products[$idx]['images'] ?? []) : [];
    $files = [];
    if (!empty($_FILES['images']['name'])) {
        foreach ((array)$_FILES['images']['name'] as $i => $_) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $files[] = ['name' => $_FILES['images']['name'][$i], 'tmp_name' => $_FILES['images']['tmp_name'][$i], 'error' => $_FILES['images']['error'][$i], 'size' => $_FILES['images']['size'][$i]];
        }
    }
    $order = json_decode((string)($_POST['order'] ?? '[]'), true) ?: [];
    $final = []; $saved = [];
    try {
        foreach ($order as $tok) {
            if (count($final) >= 8) break;
            if (strpos((string)$tok, 'old:') === 0) { $n = substr($tok, 4); if (in_array($n, $old, true) && valid_img($n) && !in_array($n, $final, true)) $final[] = $n; }
            elseif (strpos((string)$tok, 'new:') === 0) { $k = (int)substr($tok, 4); if (isset($files[$k])) { $n = process_image($files[$k]); $saved[] = $n; $final[] = $n; } }
        }
    } catch (Exception $e) { foreach ($saved as $n) @unlink($UPLOADS . '/' . $n); out(['error' => $e->getMessage()], 422); }
    foreach ($old as $n) if (!in_array($n, $final, true) && valid_img($n)) @unlink($UPLOADS . '/' . $n);

    $rec = ['id' => $id ?: 'p' . bin2hex(random_bytes(4)), 'name' => $name, 'category' => $cat, 'price' => $price, 'sizes' => $sizes,
            'description' => $desc, 'images' => $final, 'in_stock' => $inStock,
            'created' => $idx >= 0 ? ($products[$idx]['created'] ?? time()) : time(), 'updated' => time()];
    if ($idx >= 0) $products[$idx] = $rec; else array_unshift($products, $rec);
    jwrite($PRODUCTS, array_values($products));
    out(['ok' => true, 'product' => $rec]);
}

if ($action === 'delete_product') {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = (string)($in['id'] ?? '');
    $products = jread($PRODUCTS, []); $keep = [];
    foreach ($products as $p) { if ($p['id'] === $id) { foreach (($p['images'] ?? []) as $n) if (valid_img($n)) @unlink($UPLOADS . '/' . $n); } else $keep[] = $p; }
    jwrite($PRODUCTS, $keep);
    out(['ok' => true]);
}

if ($action === 'save_settings') {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $wa = preg_replace('/\D/', '', (string)($in['whatsapp'] ?? ''));
    if (strlen($wa) === 10) $wa = '91' . $wa;
    if (strlen($wa) < 11 || strlen($wa) > 15) out(['error' => 'Enter a valid WhatsApp number (10 digits, or with country code).'], 422);
    $ig = clean($in['instagram'] ?? '', 200);
    if ($ig !== '' && !preg_match('#^https?://#i', $ig)) $ig = 'https://instagram.com/' . ltrim($ig, '@/');
    $s = ['whatsapp' => $wa, 'tagline' => clean($in['tagline'] ?? '', 160), 'about' => clean($in['about'] ?? '', 800),
          'address' => clean($in['address'] ?? '', 250), 'hours' => clean($in['hours'] ?? '', 120), 'instagram' => $ig];
    jwrite($SETTINGS, $s);
    out(['ok' => true, 'settings' => settings()]);
}

if ($action === 'change_password') {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $cfg = include $CONFIG;
    if (!password_verify((string)($in['current'] ?? ''), (string)$cfg['password_hash'])) out(['error' => 'Current password is wrong.'], 422);
    $new = (string)($in['new'] ?? '');
    $newId = clean($in['new_id'] ?? '', 40) ?: $cfg['admin_id'];
    if (strlen($new) < 10) out(['error' => 'New password must be at least 10 characters.'], 422);
    $arr = ['admin_id' => $newId, 'password_hash' => password_hash($new, PASSWORD_DEFAULT)];
    if (@file_put_contents($CONFIG, "<?php\nreturn " . var_export($arr, true) . ";\n", LOCK_EX) === false) out(['error' => 'Could not save password. Check "data" folder permissions.'], 500);
    out(['ok' => true]);
}

out(['error' => 'Unknown action'], 400);
