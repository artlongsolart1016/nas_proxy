<?php
/**
 * dmslcr004_gateway.php — IIS web-app reverse proxy (no secrets)
 * Upload next to dmslcr004.php: /var/www/html/api/lcr/dmslcr004_gateway.php
 *
 * Browser → this file (via dmslcr004.php) → https://192.168.108.89/dmsgateway/app/...
 * Allowed apps: emenu, evital, evital-react, cgtway, ephp
 */

if (!defined('IIS_GATEWAY_HEALTH_URL')) {
    define('IIS_GATEWAY_HEALTH_URL', 'https://192.168.108.89/dmsgateway/health');
}
if (!defined('IIS_GATEWAY_CCROR_HEALTH_URL')) {
    define('IIS_GATEWAY_CCROR_HEALTH_URL', 'https://192.168.108.89/dmsgateway/api/v2/nas/ccro/health');
}
if (!defined('IIS_GATEWAY_DYN_HEALTH_URL')) {
    define('IIS_GATEWAY_DYN_HEALTH_URL', 'https://192.168.108.89/dmsgateway/api/v2/nas/dyn/health');
}
if (!defined('IIS_GATEWAY_APP_BASE')) {
    define('IIS_GATEWAY_APP_BASE', 'https://192.168.108.89/dmsgateway/app');
}

function DmsGateway_SslVerify()
{
    return defined('SSL_VERIFY_PEER') ? (bool) SSL_VERIFY_PEER : true;
}

function DmsGateway_SslCertPath()
{
    return defined('SSL_IIS_CERT_PATH')
        ? SSL_IIS_CERT_PATH
        : '/var/www/html/lcr/dmslcr004_SSL/SSL_dms004_IIS.crt';
}

function DmsGateway_ApplySsl($ch)
{
    if (DmsGateway_SslVerify()) {
        curl_setopt($ch, CURLOPT_CAINFO, DmsGateway_SslCertPath());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
}

function DmsGateway_ForwardUrl($url)
{
    $allowed = array(
        IIS_GATEWAY_HEALTH_URL,
        IIS_GATEWAY_CCROR_HEALTH_URL,
        IIS_GATEWAY_DYN_HEALTH_URL,
    );
    if (!in_array($url, $allowed, true)) {
        throw new Exception('Connection to gateway failed.');
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    DmsGateway_ApplySsl($ch);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    if ($curlError) {
        error_log('[PHP_PROXY] GATEWAY CURL Error: ' . $curlError);
        throw new Exception('Connection to gateway failed.');
    }
    return array('httpCode' => $httpCode, 'response' => $response);
}

function DmsGateway_IsAppName($name)
{
    $g = strtolower((string) $name);
    return ($g === 'emenu' || $g === 'evital' || $g === 'evital-react'
        || $g === 'evitalnewreact' || $g === 'cgtway' || $g === 'ephp');
}

function DmsGateway_MapPath($pathInfo)
{
    $info = '/' . trim(str_replace('\\', '/', (string) $pathInfo), '/');
    if ($info === '/' || $info === '') {
        return '/dmsgateway/app/emenu/index.php';
    }
    if (preg_match('#^/s/m(/.*)?$#i', $info, $m)) {
        return '/dmsgateway/app/emenu' . (isset($m[1]) ? $m[1] : '/index.php');
    }
    if (preg_match('#^/s/e(/.*)?$#i', $info, $m)) {
        return '/dmsgateway/app/evital' . (isset($m[1]) ? $m[1] : '/index.php');
    }
    if (preg_match('#^/s/v(/.*)?$#i', $info, $m)) {
        return '/dmsgateway/app/evital-react' . (isset($m[1]) ? $m[1] : '/index.php');
    }
    if (preg_match('#^/s/g(/.*)?$#i', $info, $m)) {
        return '/dmsgateway/app/cgtway' . (isset($m[1]) ? $m[1] : '/');
    }
    if (preg_match('#^/(emenu|eMenu)(/.*)?$#', $info, $m)) {
        return '/dmsgateway/app/emenu' . (isset($m[2]) && $m[2] !== '' ? $m[2] : '/index.php');
    }
    if (preg_match('#^/(evital-react|evitalNewReact)(/.*)?$#', $info, $m)) {
        return '/dmsgateway/app/evital-react' . (isset($m[2]) && $m[2] !== '' ? $m[2] : '/index.php');
    }
    if (preg_match('#^/(evital|eVital)(/.*)?$#', $info, $m)) {
        return '/dmsgateway/app/evital' . (isset($m[2]) && $m[2] !== '' ? $m[2] : '/index.php');
    }
    if (preg_match('#^/(cgtway|cGtway)(/.*)?$#', $info, $m)) {
        return '/dmsgateway/app/cgtway' . (isset($m[2]) && $m[2] !== '' ? $m[2] : '/');
    }
    if (preg_match('#^/dmsgateway/app/(emenu|evital-react|evital|cgtway|ephp|lcr)(/.*)?$#i', $info, $m)) {
        return '/dmsgateway/app/' . strtolower($m[1]) . (isset($m[2]) ? $m[2] : '');
    }
    if (preg_match('#^/lcr/eMenu(/.*)?$#', $info, $m)) {
        return '/dmsgateway/app/emenu' . (isset($m[1]) ? $m[1] : '/index.php');
    }
    if (preg_match('#^/lcr/evitalNewReact(/.*)?$#', $info, $m)) {
        return '/dmsgateway/app/evital-react' . (isset($m[1]) ? $m[1] : '/index.php');
    }
    if (preg_match('#^/lcr/eVital(/.*)?$#', $info, $m)) {
        return '/dmsgateway/app/evital' . (isset($m[1]) ? $m[1] : '/index.php');
    }
    if (preg_match('#^/ephp(/.*)?$#i', $info, $m)) {
        $rest = isset($m[1]) ? $m[1] : '';
        if (strpos($rest, '..') !== false || preg_match('#webman|webapi|@eaDir#i', $rest)) {
            return null;
        }
        return '/dmsgateway/app/ephp' . $rest;
    }
    if (preg_match('#^/lcr(/.*)?$#', $info, $m)) {
        $rest = isset($m[1]) ? $m[1] : '';
        if (strpos($rest, '..') !== false || preg_match('#webman|webapi|@eaDir#i', $rest)) {
            return null;
        }
        return '/dmsgateway/app/ephp' . $rest;
    }
    return null;
}

function DmsGateway_IsAllowedAppPath($iisPath)
{
    return (
        strpos($iisPath, '/dmsgateway/app/emenu') === 0 ||
        strpos($iisPath, '/dmsgateway/app/evital-react') === 0 ||
        strpos($iisPath, '/dmsgateway/app/evital') === 0 ||
        strpos($iisPath, '/dmsgateway/app/cgtway') === 0 ||
        strpos($iisPath, '/dmsgateway/app/ephp') === 0 ||
        strpos($iisPath, '/dmsgateway/app/lcr') === 0
    );
}

function DmsGateway_ForwardApp($iisPath)
{
    if (!DmsGateway_IsAllowedAppPath($iisPath)) {
        throw new Exception('Connection to gateway failed.');
    }
    $url = 'https://192.168.108.89' . $iisPath;
    $qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    if ($qs !== '') {
        parse_str($qs, $qparams);
        unset($qparams['app']);
        if (isset($qparams['gateway']) && DmsGateway_IsAppName($qparams['gateway'])) {
            unset($qparams['gateway']);
        }
        $built = http_build_query($qparams);
        if ($built !== '') {
            $url .= '?' . $built;
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $fwdProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
    if ($fwdProto === 'https') {
        $https = true;
    }
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/dmslcr004.php';
    $public = ($https ? 'https' : 'http') . '://' . $host . $script;

    $reqId = isset($_SERVER['HTTP_X_REQUEST_ID']) ? (string) $_SERVER['HTTP_X_REQUEST_ID'] : '';
    $headers = array(
        'X-Gateway-Public-Base: ' . $public,
        'X-Request-ID: ' . (preg_match('/^[A-Za-z0-9\\-]{8,64}$/', $reqId) ? $reqId : ''),
    );
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $headers[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
    }
    if (!empty($_SERVER['HTTP_ACCEPT'])) {
        $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
    }
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if ($ct !== '') {
        $headers[] = 'Content-Type: ' . $ct;
    }

    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    $body = file_get_contents('php://input');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($body !== false && $body !== '' && $method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    DmsGateway_ApplySsl($ch);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($errno || $raw === false) {
        throw new Exception('Connection to gateway failed.');
    }
    $headerBlob = substr($raw, 0, $headerSize);
    $respBody = substr($raw, $headerSize);
    http_response_code($http > 0 ? $http : 502);
    foreach (preg_split("/\r\n|\n|\r/", $headerBlob) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        $parts = explode(':', $line, 2);
        $name = $parts[0];
        $value = isset($parts[1]) ? $parts[1] : '';
        $lname = strtolower(trim($name));
        if (in_array($lname, array('transfer-encoding', 'connection', 'keep-alive', 'content-length'), true)) {
            continue;
        }
        header(trim($name) . ':' . $value, false);
    }
    echo $respBody;
}

function DmsGateway_WriteProbe($result, $okMessage, $endpoint, $statusMap)
{
    $passed = ($result['httpCode'] === 200 && is_string($result['response'])
        && strpos($result['response'], '"status":"Passed"') !== false);
    $statusInfo = isset($statusMap[$result['httpCode']])
        ? $statusMap[$result['httpCode']]
        : array('status' => 'error', 'message' => 'Unexpected response');
    if ($result['httpCode'] === 200) {
        $statusInfo = $passed
            ? array('status' => 'success', 'message' => $okMessage)
            : array('status' => 'error', 'message' => 'Gateway returned an unexpected response');
    }
    header('Content-Type: application/json');
    echo json_encode(array(
        'status' => $statusInfo['status'],
        'message' => $statusInfo['message'],
        'endpoint_tested' => $endpoint,
        'http_code' => $result['httpCode'],
        'timestamp' => date('Y-m-d H:i:s')
    ), JSON_PRETTY_PRINT);
}

function DmsGateway_WriteFail()
{
    header('Content-Type: application/json');
    http_response_code(503);
    echo json_encode(array(
        'status' => 'error',
        'message' => 'Gateway connection failed',
        'timestamp' => date('Y-m-d H:i:s')
    ), JSON_PRETTY_PRINT);
}

function DmsGateway_HandleApp($pathInfo)
{
    $mapped = DmsGateway_MapPath($pathInfo);
    if ($mapped === null) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(array(
            'error' => true,
            'message' => 'Unknown application path. Use /emenu, /evital, /evital-react, /cgtway, or /s/m /s/e /s/v /s/g.'
        ));
        return;
    }
    try {
        DmsGateway_ForwardApp($mapped);
    } catch (Exception $e) {
        DmsGateway_WriteFail();
    }
}

/**
 * @return bool true if this request was a gateway/web-app call (response already sent)
 */
function DmsGateway_Dispatch()
{
    $pathInfo = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
    if ($pathInfo === '' && isset($_GET['app']) && is_string($_GET['app']) && $_GET['app'] !== '') {
        $pathInfo = '/' . $_GET['app'];
    }
    if ($pathInfo === '' && isset($_GET['gateway']) && is_string($_GET['gateway'])
        && $_GET['gateway'] !== '' && $_GET['gateway'] !== '1'
        && DmsGateway_IsAppName($_GET['gateway'])) {
        $pathInfo = '/' . $_GET['gateway'];
    }
    if ($pathInfo !== '') {
        DmsGateway_HandleApp($pathInfo);
        return true;
    }

    if (isset($_GET['gateway'])) {
        try {
            $result = DmsGateway_ForwardUrl(IIS_GATEWAY_HEALTH_URL);
            DmsGateway_WriteProbe($result, 'Gateway connection successful', 'dmsgateway/health', array(
                200 => array('status' => 'success', 'message' => 'Gateway connection successful'),
                401 => array('status' => 'error', 'message' => 'Authentication required'),
                403 => array('status' => 'error', 'message' => 'Not authorized'),
                404 => array('status' => 'error', 'message' => 'Gateway route missing'),
                405 => array('status' => 'error', 'message' => 'Method not allowed'),
                500 => array('status' => 'error', 'message' => 'Gateway server error'),
                503 => array('status' => 'error', 'message' => 'Service unavailable'),
            ));
        } catch (Exception $e) {
            DmsGateway_WriteFail();
        }
        return true;
    }

    if (isset($_GET['ccror'])) {
        try {
            $result = DmsGateway_ForwardUrl(IIS_GATEWAY_CCROR_HEALTH_URL);
            DmsGateway_WriteProbe($result, 'NAS ccroAPI reachable via gateway', 'dmsgateway/api/v2/nas/ccro/health', array(
                401 => array('status' => 'error', 'message' => 'Gateway authentication required'),
                403 => array('status' => 'error', 'message' => 'Not authorized'),
                404 => array('status' => 'error', 'message' => 'Gateway route missing'),
                502 => array('status' => 'error', 'message' => 'Backend service returned an invalid response'),
                503 => array('status' => 'error', 'message' => 'Service unavailable'),
                504 => array('status' => 'error', 'message' => 'Backend timeout'),
            ));
        } catch (Exception $e) {
            DmsGateway_WriteFail();
        }
        return true;
    }

    if (isset($_GET['dyn'])) {
        try {
            $result = DmsGateway_ForwardUrl(IIS_GATEWAY_DYN_HEALTH_URL);
            DmsGateway_WriteProbe($result, 'NAS Dynamic API reachable via gateway', 'dmsgateway/api/v2/nas/dyn/health', array(
                401 => array('status' => 'error', 'message' => 'Gateway authentication required'),
                403 => array('status' => 'error', 'message' => 'Not authorized'),
                404 => array('status' => 'error', 'message' => 'Gateway route missing'),
                502 => array('status' => 'error', 'message' => 'Backend service returned an invalid response'),
                503 => array('status' => 'error', 'message' => 'Service unavailable'),
                504 => array('status' => 'error', 'message' => 'Backend timeout'),
            ));
        } catch (Exception $e) {
            DmsGateway_WriteFail();
        }
        return true;
    }

    return false;
}

if (!defined('DMSLCR004_SQL_PROXY')) {
    if (!defined('SSL_VERIFY_PEER')) {
        define('SSL_VERIFY_PEER', true);
    }
    if (!defined('SSL_IIS_CERT_PATH')) {
        define('SSL_IIS_CERT_PATH', '/var/www/html/lcr/dmslcr004_SSL/SSL_dms004_IIS.crt');
    }
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
    ini_set('log_errors', 1);
    ini_set('error_log', '/var/log/php/dmslcr004_errors.log');
    if (!DmsGateway_Dispatch()) {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(array(
            'error' => true,
            'message' => 'Use ?app=emenu, ?app=cgtway, ?gateway, or PATH_INFO /emenu. SQL POST stays on dmslcr004.php.'
        ));
    }
}
