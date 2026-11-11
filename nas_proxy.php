<?php
/**
 * nas_proxy.php - PMES reverse proxy (CentOS / sakatamalaybalay.com/api/lcr/)


if (is_file(__DIR__ . '/nas_proxy_config.php')) {
    require __DIR__ . '/nas_proxy_config.php';
}
function nas_proxy_def($k, $v) { if (!defined($k)) { define($k, $v); } }

// ---- settings (override any of these in nas_proxy_config.php) ----------------------------
nas_proxy_def('NAS_PROXY_URL_PRIMARY',   'http://192.168.108.44/lcr/1NAS_api/nas_db_config.php');           // LAN, tried first
nas_proxy_def('NAS_PROXY_URL_FALLBACK',  'https://continued-adrian-whom-vatican.trycloudflare.com/1NAS_api/nas_db_config.php');  // Cloudflare Tunnel from NAS, used if LAN is unreachable
nas_proxy_def('NAS_PROXY_CHANNEL_KEY',         'CHANGE_ME');
nas_proxy_def('NAS_PROXY_CHANNEL_HMAC_SECRET', 'CHANGE_ME');
nas_proxy_def('NAS_PROXY_BOOTSTRAP_ORIGINS', []);        // used ONLY if the NAS has never been reachable (no cache yet), e.g. ['https://pmes-xxxx.web.app']
nas_proxy_def('NAS_PROXY_CORS_CACHE_TTL',    300);
nas_proxy_def('NAS_PROXY_CACHE_DIR',         '');        // '' = auto (system temp, private sub folder)
nas_proxy_def('NAS_PROXY_MAX_BODY',          2097152);   // bytes
nas_proxy_def('NAS_PROXY_TIMEOUT',           60);
nas_proxy_def('NAS_PROXY_CONNECT_TIMEOUT_PRIMARY',  3);
nas_proxy_def('NAS_PROXY_CONNECT_TIMEOUT_FALLBACK', 8);
nas_proxy_def('NAS_PROXY_CA_FILE',           '');        // optional CA bundle for the HTTPS fallback; TLS verification is always ON
nas_proxy_def('NAS_PROXY_DEBUG',             false);
// ---------------------------------------------------------------------------------------------------

ini_set('display_errors', '0');

function nas_proxy_fail($status, $code, $message, $extra = [])
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'data' => null, 'error' => ['code' => $code, 'message' => $message] + $extra], JSON_UNESCAPED_SLASHES);
    exit;
}

function nas_proxy_cache_dir()
{
    static $dir = null;
    if ($dir !== null) { return $dir; }
    $cands = [];
    if (NAS_PROXY_CACHE_DIR !== '') { $cands[] = NAS_PROXY_CACHE_DIR; }
    $cands[] = sys_get_temp_dir() . '/nas_proxy_' . substr(md5(__DIR__), 0, 10);
    $cands[] = __DIR__ . '/nas_proxy_cache';
    foreach ($cands as $c) {
        if ((is_dir($c) || @mkdir($c, 0700, true)) && is_writable($c)) { return $dir = $c; }
    }
    return $dir = '';
}

/** Anchored origin matching. "*" in a pattern matches exactly one DNS label. */
function nas_proxy_origin_matches($origin, array $cfg)
{
    $origin = strtolower(rtrim($origin, '/'));
    foreach (($cfg['origins'] ?? []) as $o) {
        if ($origin === strtolower(rtrim((string) $o, '/'))) { return true; }
    }
    foreach (($cfg['patterns'] ?? []) as $p) {
        $p = strtolower(rtrim((string) $p, '/'));
        if (!preg_match('#^https?://([^/]+)$#', $p, $m) || strpos($m[1], '*') === false) { continue; }
        $first = explode('.', $m[1])[0];
        if (strlen(str_replace('*', '', $first)) < 3 || substr_count($m[1], '.') < 2) { continue; }   // refuse https://*.web.app
        $re = '#^' . str_replace('\*', '[a-z0-9-]+', preg_quote($p, '#')) . '$#';
        if (preg_match($re, $origin)) { return true; }
    }
    return false;
}

/**
 * One signed call to the NAS. Tries LAN first, then the public URL. Fails over only when the TCP connection
 * was never established (so a write is never sent twice).
 * @return array [status, headers(lowercase), body, via('lan'|'fallback')] or ['error'=>'unreachable'|'timeout']
 */
function nas_proxy_nas_call($method, $op, $body, array $extraHeaders = [])
{
    $urls = [];
    $dir = nas_proxy_cache_dir();
    $lanDown = ($dir !== '' && is_file($dir . '/lan.down') && (time() - (int) @filemtime($dir . '/lan.down')) < 60);
    if (NAS_PROXY_URL_PRIMARY !== '' && !$lanDown) { $urls[] = ['lan', NAS_PROXY_URL_PRIMARY, NAS_PROXY_CONNECT_TIMEOUT_PRIMARY]; }
    if (NAS_PROXY_URL_FALLBACK !== '') { $urls[] = ['fallback', NAS_PROXY_URL_FALLBACK, NAS_PROXY_CONNECT_TIMEOUT_FALLBACK]; }
    if (!$urls && NAS_PROXY_URL_PRIMARY !== '') { $urls[] = ['lan', NAS_PROXY_URL_PRIMARY, NAS_PROXY_CONNECT_TIMEOUT_PRIMARY]; }

    $ts = (string) time();
    $nonce = bin2hex(random_bytes(12));
    $sig = hash_hmac('sha256', strtoupper($method) . "\n" . $op . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body), NAS_PROXY_CHANNEL_HMAC_SECRET);
    $headers = array_merge([
        'X-NAS-Key: ' . NAS_PROXY_CHANNEL_KEY, 'X-NAS-Timestamp: ' . $ts, 'X-NAS-Nonce: ' . $nonce, 'X-NAS-Signature: ' . $sig,
        'Accept: application/json',
    ], $extraHeaders);

    $last = 'unreachable';
    foreach ($urls as $u) {
        list($via, $base, $ct) = $u;
        $url = $base . ($op !== 'api' ? (strpos($base, '?') === false ? '?' : '&') . 'op=' . rawurlencode($op) : '');
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => $ct,
            CURLOPT_TIMEOUT        => NAS_PROXY_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) { $respHeaders[strtolower(trim($p[0]))] = trim($p[1]); }
                return strlen($line);
            },
        ]);
        if (NAS_PROXY_CA_FILE !== '') { curl_setopt($ch, CURLOPT_CAINFO, NAS_PROXY_CA_FILE); }
        if ($body !== '' && strtoupper($method) !== 'GET') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $connected = curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
        curl_close($ch);

        if ($errno === 0 && $status > 0) {
            if ($via === 'lan' && $dir !== '') { @unlink($dir . '/lan.down'); }
            return [$status, $respHeaders, (string) $resp, $via];
        }
        if ($via === 'lan' && $dir !== '') { @touch($dir . '/lan.down'); }
        $last = ($errno === 28 && $connected) ? 'timeout' : 'unreachable';
        if ($connected) { break; }   // the request may have reached the NAS: never retry it elsewhere
    }
    return ['error' => $last];
}

/** Allowed origins from the NAS (cached, stale copy used if the NAS is down). */
function nas_proxy_cors_config()
{
    $dir = nas_proxy_cache_dir();
    $file = $dir !== '' ? $dir . '/cors.json' : '';
    $fail = $dir !== '' ? $dir . '/cors.fail' : '';
    $cached = null;
    if ($file !== '' && is_file($file)) {
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached) && (time() - (int) @filemtime($file)) < NAS_PROXY_CORS_CACHE_TTL) { return $cached; }
    }
    if ($fail !== '' && is_file($fail) && (time() - (int) @filemtime($fail)) < 30) {
        return is_array($cached) ? $cached : ['origins' => NAS_PROXY_BOOTSTRAP_ORIGINS, 'patterns' => []];
    }
    $r = nas_proxy_nas_call('GET', 'cors', '');
    if (!isset($r['error']) && $r[0] === 200) {
        $j = json_decode($r[2], true);
        if (is_array($j) && !empty($j['success']) && is_array($j['data'] ?? null)) {
            $cfg = ['origins' => array_values((array) ($j['data']['origins'] ?? [])), 'patterns' => array_values((array) ($j['data']['patterns'] ?? [])),
                    'max_age' => (int) ($j['data']['max_age'] ?? 86400)];
            if ($file !== '') { $tmp = $file . '.' . getmypid(); @file_put_contents($tmp, json_encode($cfg)); @rename($tmp, $file); }
            return $cfg;
        }
    }
    if ($fail !== '') { @touch($fail); }
    return is_array($cached) ? $cached : ['origins' => NAS_PROXY_BOOTSTRAP_ORIGINS, 'patterns' => []];
}

function nas_proxy_request_header($name)
{
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$k])) { return (string) $_SERVER[$k]; }
    if ($name === 'Authorization') {
        foreach (['REDIRECT_HTTP_AUTHORIZATION'] as $alt) { if (isset($_SERVER[$alt])) { return (string) $_SERVER[$alt]; } }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $h => $v) { if (strtolower($h) === 'authorization') { return (string) $v; } }
        }
    }
    return '';
}

// =====================================================================================================
header('Cache-Control: no-store');                 // Cloudflare must never cache API traffic
header('X-Content-Type-Options: nosniff');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$op = (string) ($_GET['op'] ?? 'api');
if (!preg_match('/^[a-z]{1,10}$/', $op)) { nas_proxy_fail(400, 'bad_request', 'Bad op'); }

if (in_array(NAS_PROXY_CHANNEL_KEY, ['', 'CHANGE_ME', 'PASTE-FROM-NAS-INIT'], true)
    || in_array(NAS_PROXY_CHANNEL_HMAC_SECRET, ['', 'CHANGE_ME', 'PASTE-FROM-NAS-INIT'], true)) {
    nas_proxy_fail(500, 'not_configured', 'Proxy is not configured (see nas_proxy_config.php)');
}

// ---- 1. dynamic CORS -----------------------------------------------------------------------------
$cors = nas_proxy_cors_config();
$originOk = ($origin !== '') && nas_proxy_origin_matches($origin, $cors);
if ($originOk) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-App-Id, Client-Token, X-NAS-User-Token, X-Requested-With');
    header('Access-Control-Expose-Headers: Retry-After, X-Request-Id, Content-Disposition');
    header('Access-Control-Max-Age: ' . (int) ($cors['max_age'] ?? 86400));
} else {
    header('Vary: Origin');
}
if ($method === 'OPTIONS') {                       // answered here, never forwarded
    http_response_code($originOk ? 204 : 403);
    exit;
}
if ($origin !== '' && !$originOk) {
    nas_proxy_fail(403, 'origin_not_allowed', 'Origin not allowed');
}

// ---- 2. health -----------------------------------------------------------------------------------
if ($op === 'health') {
    $r = nas_proxy_nas_call('GET', 'health', '');
    $nas = isset($r['error']) ? 'down' : (($r[0] === 200) ? $r[3] : 'error');
    $age = (nas_proxy_cache_dir() !== '' && is_file(nas_proxy_cache_dir() . '/cors.json')) ? time() - (int) filemtime(nas_proxy_cache_dir() . '/cors.json') : null;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $nas === 'lan' || $nas === 'fallback', 'data' => ['proxy' => 'ok', 'nas' => $nas, 'cors_cache_age_s' => $age, 'php' => PHP_VERSION]]);
    exit;
}
if ($op !== 'api') { nas_proxy_fail(404, 'not_found', 'Unknown op'); }

// ---- 3. validate (the NAS does the real authorisation) ---------------------------------------------
if ($method !== 'POST') { nas_proxy_fail(405, 'method_not_allowed', 'POST only'); }
$appId = nas_proxy_request_header('X-App-Id');
$clientToken = trim(nas_proxy_request_header('Client-Token'));      // server-side clients (eVital, eTransmission...) identify their account with this
if ($appId === '' && $clientToken === '') { nas_proxy_fail(400, 'bad_request', 'X-App-Id or Client-Token header is required'); }
if ($appId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $appId)) { nas_proxy_fail(400, 'bad_request', 'Bad X-App-Id'); }
$cl = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($cl > NAS_PROXY_MAX_BODY) { nas_proxy_fail(413, 'too_large', 'Request body too large'); }
$body = (string) file_get_contents('php://input', false, null, 0, NAS_PROXY_MAX_BODY + 1);
if (strlen($body) > NAS_PROXY_MAX_BODY) { nas_proxy_fail(413, 'too_large', 'Request body too large'); }

$token = trim(nas_proxy_request_header('X-NAS-User-Token'));
if ($token === '') {
    $a = trim(nas_proxy_request_header('Authorization'));
    if (stripos($a, 'Bearer ') === 0) { $token = trim(substr($a, 7)); }
}
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$fwd = [
    'Content-Type: application/json',
    'X-NAS-App: ' . $appId,
    'X-NAS-Client-Token: ' . preg_replace('/[^\x21-\x7E]/', '', $clientToken),
    'X-NAS-Origin: ' . ($originOk ? $origin : ''),
    'X-NAS-Client-IP: ' . preg_replace('/[^0-9a-fA-F.:]/', '', $clientIp),
];
if ($token !== '') { $fwd[] = 'X-NAS-User-Token: ' . preg_replace('/[^\x21-\x7E]/', '', $token); }

// ---- 4. forward ----------------------------------------------------------------------------------
$r = nas_proxy_nas_call('POST', 'api', $body, $fwd);
if (isset($r['error'])) {
    if ($r['error'] === 'timeout') { nas_proxy_fail(504, 'upstream_timeout', 'Backend timed out'); }
    nas_proxy_fail(502, 'upstream_unreachable', 'Backend unreachable');
}
list($status, $hdr, $respBody) = $r;

// Synology may force HTTP 200 and put the real status in the JSON body ("http_code"): restore it.
if ($status === 200 && strlen($respBody) < 1048576 && strpos($respBody, '"http_code"') !== false) {
    $j = json_decode($respBody);   // objects (not arrays) so empty {} stays {} when re-encoded
    if (is_object($j) && isset($j->http_code) && (int) $j->http_code >= 400 && (int) $j->http_code <= 599) {
        $status = (int) $j->http_code;
        unset($j->http_code);
        $respBody = json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
if (strpos($respBody, '<html') !== false && strpos($respBody, '{') !== 0) {      // NAS/Web Station HTML error page: never pass internals through
    nas_proxy_fail(502, 'upstream_error', 'Backend returned an unexpected response');
}

http_response_code($status);
// only a whitelist of upstream headers is relayed (so NAS CORS/server headers never leak)
header('Content-Type: ' . ($hdr['content-type'] ?? 'application/json; charset=utf-8'));
foreach (['retry-after' => 'Retry-After', 'x-request-id' => 'X-Request-Id', 'content-disposition' => 'Content-Disposition'] as $k => $name) {
    if (isset($hdr[$k])) { header($name . ': ' . preg_replace('/[\r\n]/', '', $hdr[$k])); }
}
echo $respBody;
