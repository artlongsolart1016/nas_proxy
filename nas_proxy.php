<?php
declare(strict_types=1);
/**
 * PMES Single Reverse Proxy Entry Point
 * Location on sakatamalaybalay.com: /api/lcr/nas_proxy.php
 *
 * Routes (?op=):
 *   - api    : JSON POST (DB queries, auth, lookups) -> 25 MB max, 60 s timeout
 *   - health : GET / POST health probe               -> 0 MB body, 10 s timeout
 *   - sync   : POST multipart/form-data or JSON      -> 100 MB max, 180 s timeout
 *   - report : POST JSON -> binary stream / JSON     -> 1 MB body, 300 s timeout
 *   - file   : POST JSON -> binary attachment stream -> 1 MB body, 60 s timeout
 */

error_reporting(0);
ini_set('display_errors', '0');

$configPath = __DIR__ . '/nas_proxy_config.php';
if (!is_file($configPath)) {
    $configPath = __DIR__ . '/nas_proxy_config.example.php';
}
$config = require $configPath;

$requestId = bin2hex(random_bytes(8));

set_exception_handler(static function (Throwable $e) use ($requestId): void {
    @error_log(sprintf('[nas_proxy] [req:%s] Uncaught exception: %s (%s:%d)', $requestId, $e->getMessage(), basename($e->getFile()), $e->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Request-Id: ' . $requestId);
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'PROXY_INTERNAL_ERROR',
            'message' => "Reverse proxy encountered an internal error. Reference: {$requestId}",
        ],
        'meta'    => ['request_id' => $requestId],
    ]);
    exit;
});

register_shutdown_function(static function () use ($requestId): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if (($err['type'] & $fatalTypes) === 0) {
        return;
    }
    @error_log(sprintf('[nas_proxy] [req:%s] Fatal error: %s (%s:%d)', $requestId, $err['message'] ?? '', basename((string)($err['file'] ?? '-')), (int)($err['line'] ?? 0)));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Request-Id: ' . $requestId);
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'PROXY_FATAL_ERROR',
            'message' => "Reverse proxy encountered a fatal error. Reference: {$requestId}",
        ],
        'meta'    => ['request_id' => $requestId],
    ]);
});

$op = strtolower(trim((string)($_GET['op'] ?? 'api')));

$routes = $config['routes'] ?? [];
if (!isset($routes[$op])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Request-Id: ' . $requestId);
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'INVALID_OPERATION',
            'message' => 'Unsupported proxy operation.',
        ],
        'meta'    => ['request_id' => $requestId],
    ]);
    exit;
}

$routeCfg = $routes[$op];

// ============================================================================
// 1. Strict Origin Allowlist & CORS Headers
// ============================================================================
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = $config['allowed_origins'] ?? [];
$isOriginAllowed = false;

if ($origin !== '') {
    if (in_array($origin, $allowedOrigins, true)) {
        $isOriginAllowed = true;
    } else {
        $parsedHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
        foreach (($config['allowed_origin_suffixes'] ?? []) as $suffix) {
            if ($suffix !== '' && substr($parsedHost, -strlen($suffix)) === strtolower($suffix)) {
                $isOriginAllowed = true;
                break;
            }
        }
    }
}

if ($origin !== '' && $isOriginAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Client-Token, Client-Token, X-App-Id, X-NAS-User-Token, X-Requested-With, X-Correlation-Id');
    header('Access-Control-Expose-Headers: Content-Disposition, Content-Type, Content-Length, X-Request-Id, X-Upstream-Channel');
    header('Access-Control-Max-Age: 600');
}

header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:; worker-src 'self' blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob: https://*.tile.openstreetmap.org https://*.basemaps.cartocdn.com https://server.arcgisonline.com https://unpkg.com https://sakatamalaybalay.com https://www.sakatamalaybalay.com; media-src 'self' data: blob:; frame-src 'self' blob: data:; connect-src 'self' blob: data: https://epmes.ccromalaybalaybuk.workers.dev https://pmesmlybly.ai.studio https://sakatamalaybalay.com https://www.sakatamalaybalay.com https://*.tile.openstreetmap.org https://*.basemaps.cartocdn.com https://server.arcgisonline.com http://localhost:3000 http://localhost:5173 http://127.0.0.1:3000 http://127.0.0.1:5173; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Vary: Origin, Authorization');
header('X-Request-Id: ' . $requestId);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    if ($origin === '' || !$isOriginAllowed) {
        http_response_code(403);
        exit;
    }
    http_response_code(204);
    exit;
}

if ($origin !== '' && !$isOriginAllowed) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'ORIGIN_FORBIDDEN',
            'message' => 'Origin is not authorized to use this reverse proxy.',
        ],
        'meta'    => ['request_id' => $requestId],
    ]);
    exit;
}

// ============================================================================
// 2. Method & Content-Length / Per-Kind Upload Limit Validation
// ============================================================================
$allowedMethods = $routeCfg['methods'] ?? ['POST'];
if (!in_array($method, $allowedMethods, true)) {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'METHOD_NOT_ALLOWED',
            'message' => "HTTP {$method} is not allowed for op={$op}.",
        ],
        'meta'    => ['request_id' => $requestId],
    ]);
    exit;
}

$maxBodyBytes = (int)($routeCfg['max_body_bytes'] ?? 26214400);
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($maxBodyBytes > 0 && $contentLength > $maxBodyBytes) {
    http_response_code(413);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'error'   => [
            'code'    => 'PAYLOAD_TOO_LARGE',
            'message' => "Request body ({$contentLength} bytes) exceeds route limit ({$maxBodyBytes} bytes).",
        ],
        'meta'    => ['request_id' => $requestId],
    ]);
    exit;
}

// ============================================================================
// 3. Client Token Verification (constant-time hash_equals; required if Origin is empty)
// ============================================================================
$requireClientToken = (bool)($config['require_client_token'] ?? false) || ($origin === '');
$configuredTokens = $config['client_tokens'] ?? [];
$providedClientToken = trim((string)($_SERVER['HTTP_X_CLIENT_TOKEN'] ?? $_SERVER['HTTP_CLIENT_TOKEN'] ?? ''));

if ($requireClientToken && $op !== 'health') {
    $matchedApp = null;
    foreach ($configuredTokens as $appName => $expectedToken) {
        if (is_string($expectedToken) && $expectedToken !== '' && strpos($expectedToken, 'CHANGE_ME') === false) {
            if (hash_equals($expectedToken, $providedClientToken)) {
                $matchedApp = $appName;
                break;
            }
        }
    }
    if ($matchedApp === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => 'INVALID_CLIENT_TOKEN',
                'message' => 'Missing or invalid X-Client-Token (required when Origin header is absent).',
            ],
            'meta'    => ['request_id' => $requestId],
        ]);
        exit;
    }
}

// ============================================================================
// 4. Trusted Proxy IP Resolution (F-12) & Flock-Backed Rate Limiter (F-13)
// ============================================================================
function pmesProxyIpMatchesCidr(string $ip, string $cidr): bool {
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) {
        return false;
    }
    if (strpos($cidr, '/') === false) {
        $netBin = @inet_pton($cidr);
        return $netBin !== false && $ipBin === $netBin;
    }
    [$subnet, $bitsStr] = explode('/', $cidr, 2);
    $netBin = @inet_pton($subnet);
    $bits = (int)$bitsStr;
    if ($netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    $fullBytes = intdiv($bits, 8);
    $remBits = $bits % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
        return false;
    }
    if ($remBits > 0) {
        $mask = (0xFF << (8 - $remBits)) & 0xFF;
        if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
            return false;
        }
    }
    return true;
}

$remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
$trustedProxies = $config['trusted_proxies'] ?? [
    '127.0.0.1/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

$isTrustedProxy = false;
foreach ($trustedProxies as $cidr) {
    if (is_string($cidr) && $cidr !== '' && pmesProxyIpMatchesCidr($remoteAddr, $cidr)) {
        $isTrustedProxy = true;
        break;
    }
}

$clientIp = $remoteAddr;
if ($isTrustedProxy) {
    $forwardedIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if (strpos($forwardedIp, ',') !== false) {
        $forwardedIp = trim(explode(',', $forwardedIp)[0]);
    }
    if ($forwardedIp !== '' && filter_var($forwardedIp, FILTER_VALIDATE_IP) !== false) {
        $clientIp = $forwardedIp;
    }
}

$rateLimitMax = (int)($routeCfg['rate_limit_per_min'] ?? 120);

// Diskless rate-limiting: memory-only via APCu if available; zero server-side disk storage/cache
if ($rateLimitMax > 0 && function_exists('apcu_inc')) {
    $rateKey = 'pmes_rl:' . hash('sha256', $clientIp . '|' . $op . '|' . floor(time() / 60));
    $currentHits = apcu_inc($rateKey, 1, $apcuSuccess, 60);
    if (!$apcuSuccess) {
        apcu_add($rateKey, 1, 60);
        $currentHits = 1;
    }
    if ($currentHits > $rateLimitMax) {
        http_response_code(429);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => 'RATE_LIMIT_EXCEEDED',
                'message' => "Rate limit exceeded for route op={$op}. Please retry shortly.",
            ],
            'meta'    => ['request_id' => $requestId],
        ]);
        exit;
    }
}

// ============================================================================
// 5. Same-Host In-Process Fast-Path (F-17) OR Remote HMAC Upstream Signing
// ============================================================================
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$isMultipart = strpos($contentType, 'multipart/form-data') !== false;
$mode = strtolower((string)($config['upstream_mode'] ?? 'lan_first'));
$enableLocalDispatch = (bool)($config['enable_local_dispatch'] ?? true);
$localEntryPath = (string)($config['local_nas_entry_path'] ?? (dirname(__DIR__) . '/1NAS_api/nas_db_config.php'));
$canLocalDispatch = $enableLocalDispatch
    && $mode !== 'tunnel_only'
    && $localEntryPath !== ''
    && is_file($localEntryPath)
    && is_readable($localEntryPath);

if ($isMultipart) {
    $kind = strtolower(trim((string)($_POST['kind'] ?? '')));
    $actionField = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($kind === '') {
        if ($actionField === 'upload_proof_document') {
            $kind = 'project_proof';
        } elseif ($actionField === 'upload_inspection_attachment') {
            $kind = 'inspection_attachment';
        } elseif ($actionField === 'upload_billing_attachment') {
            $kind = 'billing_attachment';
        }
    }

    $limitsByKind = $config['upload_limits_by_kind'] ?? [
        'project_proof'         => 104857600, // 100 MB
        'inspection_attachment' => 52428800,  // 50 MB
        'billing_attachment'    => 26214400,  // 25 MB
    ];
    $kindCap = $limitsByKind[$kind] ?? 104857600;

    foreach ($_FILES as $fileInfo) {
        if (!isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
            continue;
        }
        $fSize = (int)($fileInfo['size'] ?? filesize($fileInfo['tmp_name']));
        if ($fSize > $kindCap) {
            $limitMb = round($kindCap / 1048576);
            http_response_code(413);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => false,
                'error'   => [
                    'code'    => 'UPLOAD_LIMIT_EXCEEDED',
                    'message' => "Uploaded file for '{$kind}' ({$fSize} bytes) exceeds the {$limitMb} MB limit.",
                ],
                'meta'    => ['request_id' => $requestId],
            ]);
            exit;
        }
    }

    if ($canLocalDispatch) {
        $_GET['op'] = $op;
        $_SERVER['HTTP_X_PROXY_SOURCE'] = 'sakata-reverse-proxy-local';
        $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $clientIp;
        $_SERVER['HTTP_X_FORWARDED_ORIGIN'] = $origin;
        header('X-Upstream-Channel: local-in-process');
        define('PMES_PROXY_LOCAL_DISPATCH', true);
        require $localEntryPath;
        exit;
    }

    $manifestParts = [];
    $forwardFields = [];
    foreach ($_POST as $k => $v) {
        if (is_scalar($v)) {
            $forwardFields[$k] = (string)$v;
            $manifestParts[] = $k . '=' . (string)$v;
        }
    }

    foreach ($_FILES as $fieldKey => $fileInfo) {
        if (!isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
            continue;
        }
        $fSize = (int)($fileInfo['size'] ?? filesize($fileInfo['tmp_name']));
        $fileSha = hash_file('sha256', $fileInfo['tmp_name']);
        $manifestParts[] = $fieldKey . ':' . ($fileInfo['name'] ?? 'file') . ':' . $fSize . ':' . $fileSha;
        $forwardFields[$fieldKey] = new CURLFile(
            $fileInfo['tmp_name'],
            $fileInfo['type'] ?: 'application/octet-stream',
            $fileInfo['name'] ?: 'upload.bin'
        );
    }

    sort($manifestParts);
    $bodyDigest = hash('sha256', implode('|', $manifestParts));
    $postFields = $forwardFields;
} else {
    $readLimit = $maxBodyBytes > 0 ? ($maxBodyBytes + 1) : null;
    $rawBody = (string)file_get_contents('php://input', false, null, 0, $readLimit);
    if ($maxBodyBytes > 0 && strlen($rawBody) > $maxBodyBytes) {
        http_response_code(413);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => 'PAYLOAD_TOO_LARGE',
                'message' => 'Request body exceeds maximum size allowed for this route.',
            ],
            'meta'    => ['request_id' => $requestId],
        ]);
        exit;
    }

    if ($canLocalDispatch) {
        $_GET['op'] = $op;
        $GLOBALS['__PMES_RAW_INPUT__'] = $rawBody;
        $_SERVER['HTTP_X_PROXY_SOURCE'] = 'sakata-reverse-proxy-local';
        $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $clientIp;
        $_SERVER['HTTP_X_FORWARDED_ORIGIN'] = $origin;
        header('X-Upstream-Channel: local-in-process');
        define('PMES_PROXY_LOCAL_DISPATCH', true);
        require $localEntryPath;
        exit;
    }

    $bodyDigest = hash('sha256', $rawBody);
    $postFields = $rawBody;
}

// ============================================================================
// 6. HMAC-SHA256 Signing & Upstream Channel Selection (LAN First -> Tunnel Fallback)
// ============================================================================
$timestamp = (string)time();
$nonce = bin2hex(random_bytes(16));
$sharedSecret = (string)($config['shared_hmac_secret'] ?? '');
$canonical = $timestamp . '.' . $nonce . '.' . $op . '.' . $bodyDigest;
$signature = hash_hmac('sha256', $canonical, $sharedSecret);

$upstreamHeaders = [
    'X-Proxy-Source: sakata-reverse-proxy',
    'X-Request-Id: ' . $requestId,
    'X-Forwarded-For: ' . $clientIp,
    'X-Forwarded-Origin: ' . $origin,
    'X-NAS-Op: ' . $op,
    'X-NAS-Timestamp: ' . $timestamp,
    'X-NAS-Nonce: ' . $nonce,
    'X-NAS-Content-SHA256: ' . $bodyDigest,
    'X-NAS-Signature: ' . $signature,
];

if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $upstreamHeaders[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
}
if ($providedClientToken !== '') {
    $upstreamHeaders[] = 'X-Client-Token: ' . $providedClientToken;
}
if (!$isMultipart) {
    $upstreamHeaders[] = 'Content-Type: application/json; charset=UTF-8';
}

$upstreams = [];
$mode = strtolower((string)($config['upstream_mode'] ?? 'tunnel_first'));
$lanUrl = rtrim((string)($config['lan_upstream_url'] ?? ''), '/');
$tunnelUrl = rtrim((string)($config['tunnel_upstream_url'] ?? ''), '/');

if ($mode === 'lan_only' && $lanUrl !== '') {
    $upstreams['lan'] = $lanUrl;
} elseif ($mode === 'tunnel_only' && $tunnelUrl !== '') {
    $upstreams['tunnel'] = $tunnelUrl;
} elseif ($mode === 'tunnel_first') {
    if ($tunnelUrl !== '' && strpos($tunnelUrl, 'CHANGE_ME') === false) {
        $upstreams['tunnel'] = $tunnelUrl;
    }
    if ($lanUrl !== '') {
        $upstreams['lan'] = $lanUrl;
    }
} else {
    if ($lanUrl !== '') {
        $upstreams['lan'] = $lanUrl;
    }
    if ($tunnelUrl !== '' && strpos($tunnelUrl, 'CHANGE_ME') === false) {
        $upstreams['tunnel'] = $tunnelUrl;
    }
}

$timeoutSec = (int)($routeCfg['timeout_sec'] ?? 60);
$connectTimeoutSec = (int)($config['connect_timeout_sec'] ?? 4);
$expectBinaryStream = in_array($op, ['report', 'file', 'sync'], true);

foreach ($upstreams as $channelName => $baseUrl) {
    $forwardQuery = $_GET;
    $forwardQuery['op'] = $op;
    $sep = strpos($baseUrl, '?') !== false ? '&' : '?';
    $targetUrl = $baseUrl . $sep . http_build_query($forwardQuery);

    if ($channelName === 'tunnel' && !empty($config['cf_access_client_id']) && $config['cf_access_client_id'] !== 'CHANGE_ME') {
        $channelHeaders = array_merge($upstreamHeaders, [
            'CF-Access-Client-Id: ' . $config['cf_access_client_id'],
            'CF-Access-Client-Secret: ' . $config['cf_access_client_secret'],
        ]);
    } else {
        $channelHeaders = $upstreamHeaders;
    }

    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method === 'GET' ? 'GET' : 'POST');
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $channelHeaders);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSec);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $upstreamHost = strtolower((string)(parse_url($targetUrl, PHP_URL_HOST) ?? ''));
    $isRfc1918Upstream = (bool)preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $upstreamHost)
        || $upstreamHost === '::1'
        || $upstreamHost === 'localhost';
    if ($isRfc1918Upstream) {
        $verifyTls = false;
    } else {
        $verifyTls = (bool)($config['verify_tls'] ?? false);
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
    $caBundle = (string)($config['ca_bundle_path'] ?? '');
    if ($caBundle !== '' && is_file($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }

    $respStatus = 200;
    $respContentType = 'application/json; charset=UTF-8';
    $respDisposition = '';
    $respContentLength = '';
    $respAcceptRanges = '';
    $respContentRange = '';
    $isStreamingBinary = false;
    $headersSentToClient = false;
    $bufferedBody = '';

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $headerLine) use (
        &$respStatus,
        &$respContentType,
        &$respDisposition,
        &$respContentLength,
        &$respAcceptRanges,
        &$respContentRange,
        &$isStreamingBinary,
        $expectBinaryStream
    ): int {
        $trimmed = trim($headerLine);
        if ($trimmed === '') {
            return strlen($headerLine);
        }
        if (stripos($trimmed, 'HTTP/') === 0) {
            $parts = explode(' ', $trimmed);
            if (isset($parts[1])) {
                $respStatus = (int)$parts[1];
            }
            return strlen($headerLine);
        }
        if (strpos($trimmed, ':') !== false) {
            [$hName, $hVal] = explode(':', $trimmed, 2);
            $hNameLower = strtolower(trim($hName));
            $hValTrim = trim($hVal);
            if ($hNameLower === 'content-type') {
                $respContentType = $hValTrim;
                $ctLower = strtolower($hValTrim);
                if (
                    $expectBinaryStream &&
                    strpos($ctLower, 'application/json') === false &&
                    (
                        strpos($ctLower, 'application/pdf') !== false ||
                        strpos($ctLower, 'application/vnd.') !== false ||
                        strpos($ctLower, 'application/msword') !== false ||
                        strpos($ctLower, 'application/octet-stream') !== false ||
                        strpos($ctLower, 'application/zip') !== false ||
                        strpos($ctLower, 'image/') !== false ||
                        strpos($ctLower, 'text/csv') !== false
                    )
                ) {
                    $isStreamingBinary = true;
                }
            } elseif ($hNameLower === 'content-disposition') {
                $respDisposition = $hValTrim;
            } elseif ($hNameLower === 'content-length') {
                $respContentLength = $hValTrim;
            } elseif ($hNameLower === 'accept-ranges') {
                $respAcceptRanges = $hValTrim;
            } elseif ($hNameLower === 'content-range') {
                $respContentRange = $hValTrim;
            }
        }
        return strlen($headerLine);
    });

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, string $chunk) use (
        &$isStreamingBinary,
        &$headersSentToClient,
        &$bufferedBody,
        &$respStatus,
        &$respContentType,
        &$respDisposition,
        &$respContentLength,
        &$respAcceptRanges,
        &$respContentRange,
        $channelName
    ): int {
        if ($isStreamingBinary) {
            if (!$headersSentToClient) {
                http_response_code($respStatus);
                header('Content-Type: ' . $respContentType);
                header('X-Upstream-Channel: ' . $channelName);
                if ($respDisposition !== '') {
                    header('Content-Disposition: ' . $respDisposition);
                }
                if ($respContentLength !== '') {
                    header('Content-Length: ' . $respContentLength);
                }
                if ($respAcceptRanges !== '') {
                    header('Accept-Ranges: ' . $respAcceptRanges);
                }
                if ($respContentRange !== '') {
                    header('Content-Range: ' . $respContentRange);
                }
                $headersSentToClient = true;
            }
            echo $chunk;
            flush();
            return strlen($chunk);
        }
        $bufferedBody .= $chunk;
        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    if ($ok !== false && $curlErrNo === 0) {
        if ($isStreamingBinary) {
            exit;
        }
        http_response_code($respStatus);
        header('Content-Type: ' . $respContentType);
        header('X-Upstream-Channel: ' . $channelName);
        if ($respDisposition !== '') {
            header('Content-Disposition: ' . $respDisposition);
        }
        echo $bufferedBody;
        exit;
    }
}

http_response_code(502);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'success' => false,
    'error'   => [
        'code'    => 'UPSTREAM_UNREACHABLE',
        'message' => 'NAS upstream is currently unreachable on both LAN and fallback channels.',
    ],
    'meta'    => ['request_id' => $requestId],
]);
