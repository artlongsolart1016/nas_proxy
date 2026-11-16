<?php
/**
 * eVital & PhilCRIS Registry Direct Bridge API
 *
 * Architecture (important):
 *   Browser (MIS UI on Synology/CentOS)  →  THIS PHP (clinic / local web server)  →  PhilCRIS MySQL:3306
 *
 * The browser never connects to MySQL. Port 3306 must be reachable from the machine that runs this file.
 * Deploy THIS file next to PhilCRIS (clinic XAMPP/IIS/eTransmission). MIS may host only the React dist/.
 *
 * Compatible with: CentOS 7/8/9/10, Apache, Nginx (php-fpm), XAMPP, Windows IIS
 * PHP Version: 7.4 / 8.0 / 8.1 / 8.2 / 8.3
 *
 * Endpoints & Actions:
 *  - ?action=get_stats         : Returns summary counts (Total, Registered, Unregistered/Pending, Transmitted)
 *  - ?action=list_records      : Returns paginated records (type=birth|death|marriage, search, page, per_page)
 *  - ?action=get_record        : Returns single Form 102/103/97 record + enclosures
 *  - ?action=send_record       : Transmits record payload to LCR / SQL Server / PSA endpoint
 *  - ?action=serve_attachment  : Serves attached civil registry PDF/AUSF document
 *  - ?action=ping              : Health check & DB connection diagnostics
 */

// Disable error display to guarantee clean JSON stream
error_reporting(0);
ini_set('display_errors', '0');
@ini_set('output_buffering', 'Off');
@ini_set('zlib.output_compression', 0);
// PDF attachments up to 20MB (runtime; also ship .user.ini for Synology/php-fpm)
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '600');
@ini_set('max_input_time', '600');
// upload_max_filesize / post_max_size usually require .user.ini or php.ini (cannot raise via ini_set)

define('LCR_PDF_MAX_BYTES', 20 * 1024 * 1024);
/** Server-side chunk when whole PDF is already in memory */
define('LCR_PDF_CHUNK_BYTES', 200 * 1024);
/** Browser→bridge chunk size (base64 text field) — stays under Synology 2M upload_max_filesize */
define('LCR_PDF_BROWSER_CHUNK_BYTES', 120 * 1024);

/** Default public LGU reverse proxy (same as working eTransmission client_token Primary Endpoint) */
define('LCR_DEFAULT_DMSLCR_URL', 'https://sakatamalaybalay.com/api/lcr/dmslcr004.php');

require_once __DIR__ . '/PayloadCrypto.php';

// Capture any accidental output
ob_start();

// CORS Headers for MIS Web Server and Local Workstations
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------------------------------------------------
// Database Configuration
// Priority:
// 1) Request body/query params from browser (per-client local node)
// 2) Environment variables (getenv returns false when unset — must coerce)
// 3) Safe defaults
// -------------------------------------------------------------
function cfgValue($requestKey, $envKey, $default) {
    if (isset($_REQUEST[$requestKey]) && $_REQUEST[$requestKey] !== '') {
        return $_REQUEST[$requestKey];
    }
    $env = getenv($envKey);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return $default;
}

$clientDbHost       = cfgValue('db_host', 'DB_HOST', '');
$clientDbPort       = cfgValue('db_port', 'DB_PORT', '3306');
$clientDbPortSupport= cfgValue('db_port_support', 'DB_PORT_SUPPORT', '3307');
$clientDbNameMain   = cfgValue('db_name_main', 'DB_NAME_MAIN', '');
$clientDbNameSupport= cfgValue('db_name_support', 'DB_NAME_SUPPORT', '');
$clientDbUser       = cfgValue('db_user', 'DB_USER', '');
$clientDbPass       = cfgValue('db_pass', 'DB_PASS', '');
$clientDbUserSupport= cfgValue('db_user_support', 'DB_USER_SUPPORT', $clientDbUser);
$clientDbPassSupport= cfgValue('db_pass_support', 'DB_PASS_SUPPORT', $clientDbPass);

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_stats';
$type = $_GET['type'] ?? $_POST['type'] ?? 'birth';

// Establish PDO Connection Helper — returns ['pdo'=>PDO|null, 'error'=>string, 'charset'=>string|null]
// PhilCRIS often runs older MySQL that does not support utf8mb4 (SQLSTATE 42000 / 1115).
function getDatabaseConnectionResult($host, $port, $dbname, $user, $pass, $connectTimeout = 3) {
    $attempts = [
        ['charset' => 'utf8', 'dsnCharset' => true],      // prefer utf8 first for PhilCRIS age
        ['charset' => 'utf8mb4', 'dsnCharset' => true],
        ['charset' => 'utf8', 'dsnCharset' => false],     // bare DSN + SET NAMES
    ];
    $lastError = '';

    foreach ($attempts as $attempt) {
        $charset = $attempt['charset'];
        try {
            $dsn = $attempt['dsnCharset']
                ? "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}"
                : "mysql:host={$host};port={$port};dbname={$dbname}";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => $connectTimeout,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
            ];
            if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = $connectTimeout;
            }

            $pdo = new PDO($dsn, $user, $pass, $options);
            return ['pdo' => $pdo, 'error' => '', 'charset' => $charset];
        } catch (PDOException $e) {
            $lastError = $e->getMessage();
            // Keep trying charset fallbacks for 1115 / unknown charset only
            if (
                stripos($lastError, 'utf8mb4') !== false ||
                stripos($lastError, '1115') !== false ||
                stripos($lastError, 'Unknown character set') !== false
            ) {
                continue;
            }
            // Host blocked / access denied / unknown DB — stop immediately (do not retry charsets;
            // retries inflate max_connect_errors and cause MySQL 1129 host blocks)
            if (
                stripos($lastError, '1129') !== false ||
                stripos($lastError, 'blocked because of many connection errors') !== false
            ) {
                return [
                    'pdo' => null,
                    'error' => $lastError . ' — on the MySQL server run: mysqladmin flush-hosts  (or: FLUSH HOSTS;). Then point Bridge API Path at the clinic local registry_api.php so Synology stops hammering this MySQL.',
                    'charset' => null,
                ];
            }
            return ['pdo' => null, 'error' => $lastError, 'charset' => null];
        }
    }

    return ['pdo' => null, 'error' => $lastError, 'charset' => null];
}

function getDatabaseConnection($host, $port, $dbname, $user, $pass, $connectTimeout = 3) {
    $r = getDatabaseConnectionResult($host, $port, $dbname, $user, $pass, $connectTimeout);
    return $r['pdo'];
}

/** Fast TCP probe — avoids 20–30s PDO hang when MySQL is unreachable from Synology */
function probeTcpPort($host, $port, $timeoutSec = 1) {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, (int)$port, $errno, $errstr, $timeoutSec);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

function countTableSafe($pdo, $table) {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

function countUnregSafe($pdo, $table) {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE RegistryNum LIKE '!%'")->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

/** True when PDO is connected to a database that has legacy registry_*_log tables. */
function lcrSupportDatabaseHasLogTable($pdo) {
    foreach (['registry_birth_log', 'registry_death_log', 'registry_marriage_log'] as $table) {
        try {
            $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
            return true;
        } catch (Exception $e) {
            // try next
        }
    }
    return false;
}

/**
 * Connect to phcris_support — legacy eTransmission uses port 3307; many sites use 3306.
 * Auto-detects phcris_support / phsupport when db_name_support is omitted from the request.
 */
function lcrConnectSupportDatabase($host, $portMain, $portSupport, $userMain, $passMain, $userSupport, $passSupport, $explicitName) {
    $result = [
        'pdo' => null,
        'name' => trim((string)$explicitName),
        'port' => (int)$portSupport,
        'error' => '',
        'autoDetected' => false,
    ];

    if (trim((string)$host) === '') {
        $result['error'] = 'Missing db_host';
        return $result;
    }

    $names = [];
    $explicit = trim((string)$explicitName);
    if ($explicit !== '') {
        $names[] = $explicit;
    }
    foreach (['phcris_support', 'phsupport', 'phcris_sup'] as $candidate) {
        if (!in_array($candidate, $names, true)) {
            $names[] = $candidate;
        }
    }

    $ports = [];
    foreach ([(int)$portSupport, (int)$portMain, 3307, 3306] as $p) {
        if ($p > 0 && !in_array($p, $ports, true)) {
            $ports[] = $p;
        }
    }

    $credPairs = [];
    foreach ([[$userSupport, $passSupport], [$userMain, $passMain]] as $pair) {
        $u = trim((string)$pair[0]);
        if ($u === '') {
            continue;
        }
        $key = $u . "\0" . (string)$pair[1];
        if (!isset($credPairs[$key])) {
            $credPairs[$key] = [$u, $pair[1]];
        }
    }
    if (empty($credPairs)) {
        $result['error'] = 'Missing database username/password for support DB';
        return $result;
    }

    $lastError = '';
    foreach ($ports as $port) {
        if (!probeTcpPort($host, $port, 1)) {
            continue;
        }
        foreach ($names as $name) {
            foreach ($credPairs as $cred) {
                $res = getDatabaseConnectionResult($host, $port, $name, $cred[0], $cred[1], 3);
                if (!$res['pdo']) {
                    $lastError = $res['error'] ?: "Cannot connect to {$name}@{$host}:{$port}";
                    continue;
                }
                if (!lcrSupportDatabaseHasLogTable($res['pdo'])) {
                    $lastError = "Database {$name} on port {$port} has no registry_*_log tables";
                    continue;
                }
                $result['pdo'] = $res['pdo'];
                $result['name'] = $name;
                $result['port'] = $port;
                $result['autoDetected'] = ($explicit === '' || strcasecmp($explicit, $name) !== 0);
                return $result;
            }
        }
    }

    $result['error'] = $lastError !== ''
        ? $lastError
        : ('Support DB unreachable on ' . $host . ' (ports ' . implode(', ', $ports) . ')');
    return $result;
}

// Ping/health: do NOT open PDO first (can hang). Probe TCP then optional quick connect.
$isPing = ($action === 'ping' || $action === 'health' || $action === 'diagnose');
$pdoMain = null;
$pdoSupport = null;
$mainConnectError = '';
$mainCharset = '';
$supportPortUsed = (int)$clientDbPortSupport;
$supportConnectNote = '';
$supportConnectError = '';

if ($isPing || true) {
    // Always use the same connect path (TCP probe + PDO) so ping/stats/list behave alike
    $mainReachable = probeTcpPort($clientDbHost, $clientDbPort, 2);
    if (!$mainReachable) {
        $mainConnectError = "TCP port {$clientDbPort} closed/unreachable from PHP bridge host to {$clientDbHost}";
    } else {
        $mainRes = getDatabaseConnectionResult($clientDbHost, $clientDbPort, $clientDbNameMain, $clientDbUser, $clientDbPass, 5);
        $pdoMain = $mainRes['pdo'];
        $mainConnectError = $mainRes['error'];
        $mainCharset = $mainRes['charset'] ?? '';
    }

    $supportReachable = false;
    $pdoSupport = null;
    $supportConnectNote = '';
    $supportConnectError = '';
    // Connect support DB whenever main DB works OR name was sent — auto-detect phcris_support like legacy eTransmission
    if ($pdoMain || trim($clientDbNameSupport) !== '' || trim($clientDbHost) !== '') {
        $sup = lcrConnectSupportDatabase(
            $clientDbHost,
            $clientDbPort,
            $clientDbPortSupport,
            $clientDbUser,
            $clientDbPass,
            $clientDbUserSupport,
            $clientDbPassSupport,
            $clientDbNameSupport
        );
        $pdoSupport = $sup['pdo'];
        $supportPortUsed = (int)$sup['port'];
        $supportConnectError = $sup['error'];
        $supportReachable = ($pdoSupport !== null);
        if ($pdoSupport) {
            $clientDbNameSupport = $sup['name'];
            if (!empty($sup['autoDetected'])) {
                $supportConnectNote = "Auto-detected support DB {$sup['name']} on port {$sup['port']}";
            }
        } else {
            $supportConnectNote = $sup['error'];
        }
    }
}

// -------------------------------------------------------------
// LCR Transmission Helpers (dmslcr004.php reverse proxy — eTransmission parity)
// -------------------------------------------------------------

/**
 * Resolve outbound LCR URL. When this bridge runs on the MIS/XAMPP box
 * (192.168.108.89), sakata often times out (Fortinet DNS) — use LAN IIS.
 * Remote clinics / Synology keep the public dmslcr004.php URL untouched.
 */
function lcrResolveApiUrl($url) {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return $url;
    }
    $host = strtolower((string) $parts['host']);
    if ($host !== 'sakatamalaybalay.com' && $host !== 'www.sakatamalaybalay.com') {
        return $url;
    }

    $serverIp = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    $forceLan = getenv('ET_LCR_FORCE_LAN');
    $onMisBox = ($serverIp === '192.168.108.89')
        || ($forceLan === '1' || strcasecmp((string) $forceLan, 'true') === 0);
    if ($onMisBox) {
        return 'https://192.168.108.89/dmslcr004/execsql';
    }
    return $url;
}

/**
 * PHP proxy scripts (…/dmslcr004.php) accept POST at the script URL.
 * Windows IIS API expects POST at …/dmslcr004/execsql.
 */
function lcrApiExecsqlUrl($url) {
    $url = lcrResolveApiUrl($url);
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if (preg_match('#/execsql/?$#i', $path)) {
        return $url;
    }
    if (preg_match('#\.php$#i', $path)) {
        return $url;
    }
    return rtrim($url, '/') . '/execsql';
}

function lcrApiProbeUrl($url) {
    $url = lcrResolveApiUrl($url);
    if (strpos($url, '?') !== false) {
        return $url;
    }
    return rtrim($url, '/') . '?test=ping';
}

function lcrIsApiBlockResponse($httpCode, $body) {
    if ((int) $httpCode !== 403 || $body === '') {
        return false;
    }
    $snippet = strtolower(substr($body, 0, 1200));
    return (
        strpos($snippet, 'fortinet') !== false ||
        strpos($snippet, 'web page blocked') !== false ||
        strpos($snippet, 'access denied') !== false ||
        (strpos($snippet, '<html') !== false && strpos($snippet, '"status"') === false)
    );
}

function lcrGetTableNames($type) {
    $tables = [
        'birth' => [
            'main' => 'birthdocument',
            'log' => 'registry_birth_log',
            'history' => 'registry_birth_history',
        ],
        'death' => [
            'main' => 'deathdocument',
            'log' => 'registry_death_log',
            'history' => 'registry_death_history',
        ],
        'marriage' => [
            'main' => 'marriagedocument',
            'log' => 'registry_marriage_log',
            'history' => 'registry_marriage_history',
        ],
    ];
    return $tables[$type] ?? $tables['birth'];
}

/** Match legacy eTransmission audit_log writes (phcris_support). */
function lcrSupportAuditLog($pdoSupport, $action, $details, $registryNum = null, $username = 'EVITAL_BRIDGE', $userId = 0) {
    if (!$pdoSupport) {
        return;
    }
    try {
        $stmt = $pdoSupport->prepare(
            'INSERT INTO audit_log (user_id, username, action, details, registry_num, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $username,
            $action,
            $details,
            $registryNum,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);
    } catch (Exception $e) {
        // Non-fatal — support schema may differ per site
    }
}

/** Chunked BLOB into registry_*_log.Attachment (legacy eTransmission). */
function lcrUploadBlobToMySQL($pdoSupport, $tableName, $registryNum, $blobData) {
    $chunkSize = 256 * 1024;
    $totalSize = strlen($blobData);
    $offset = 0;
    $chunkNumber = 0;

    while ($offset < $totalSize) {
        $chunk = substr($blobData, $offset, $chunkSize);
        $hexChunk = bin2hex($chunk);

        if ($chunkNumber === 0) {
            $sql = "UPDATE {$tableName} SET Attachment = 0x{$hexChunk} WHERE TRIM(RegistryNum) = ?";
        } else {
            $sql = "UPDATE {$tableName} SET Attachment = CONCAT(COALESCE(Attachment, ''), 0x{$hexChunk}) WHERE TRIM(RegistryNum) = ?";
        }

        $pdoSupport->prepare($sql)->execute([trim($registryNum)]);

        $offset += $chunkSize;
        $chunkNumber++;
        unset($chunk, $hexChunk);
    }
}

function lcrGetAttachTempPath($type, $registryNum) {
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $registryNum);
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evital_attach_' . $type . '_' . $safe . '.pdf';
}

function lcrAppendAttachChunkTemp($type, $registryNum, $chunkBinary, $isFirst) {
    $path = lcrGetAttachTempPath($type, $registryNum);
    if ($isFirst) {
        file_put_contents($path, $chunkBinary);
    } else {
        file_put_contents($path, $chunkBinary, FILE_APPEND | LOCK_EX);
    }
    return $path;
}

/** Store PDF copy beside bridge (disk first, MySQL BLOB fallback) — legacy parity. */
function lcrStoreAttachmentLocally($pdoSupport, $logTable, $registryNum, $pdfData) {
    $storageDir = dirname(__DIR__) . '/storage/attachments';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0775, true);
    }
    $safeRegNum = preg_replace('/[^a-zA-Z0-9_-]/', '_', $registryNum);
    $newPath = $storageDir . '/' . $safeRegNum . '_' . uniqid('', true) . '.pdf';
    if (@file_put_contents($newPath, $pdfData)) {
        try {
            $stmt = $pdoSupport->prepare(
                "UPDATE {$logTable} SET Attachment = ?, AttachmentType = 'YES-DISK' WHERE TRIM(RegistryNum) = ?"
            );
            $stmt->execute([$newPath, trim($registryNum)]);
            return;
        } catch (Exception $e) {
            // Fall through to BLOB storage
        }
    }
    lcrUploadBlobToMySQL($pdoSupport, $logTable, $registryNum, $pdfData);
}

/**
 * Write registry_*_log + registry_*_history after successful LCR send (legacy eTransmission sendRecord).
 *
 * @param array{registryNum:string,action?:string,mobileNumber?:string,pdfSize?:int,attachmentType?:string,performedBy?:string,remarks?:string,pdfData?:string|null,auditUsername?:string} $ctx
 */
function lcrWriteTransmissionSupportLog($pdoSupport, $type, array $ctx) {
    if (!$pdoSupport) {
        return ['ok' => false, 'error' => 'Support DB not connected (db_name_support missing or port 3307 unreachable)'];
    }

    $registryNum = trim((string)($ctx['registryNum'] ?? ''));
    if ($registryNum === '') {
        return ['ok' => false, 'error' => 'Missing RegistryNum'];
    }

    $action = (string)($ctx['action'] ?? 'SEND');
    $mobileNumber = (string)($ctx['mobileNumber'] ?? '');
    $pdfSize = (int)($ctx['pdfSize'] ?? 0);
    $attachmentType = (string)($ctx['attachmentType'] ?? ($pdfSize > 0 ? 'YES-DB' : 'NO'));
    $performedBy = (string)($ctx['performedBy'] ?? 'EVITAL_BRIDGE');
    $remarks = (string)($ctx['remarks'] ?? '');
    $pdfData = $ctx['pdfData'] ?? null;
    $auditUsername = (string)($ctx['auditUsername'] ?? $performedBy);

    $tables = lcrGetTableNames($type);
    $logTable = $tables['log'];
    $historyTable = $tables['history'];
    $logExists = false;

    try {
        $checkStmt = $pdoSupport->prepare("SELECT COUNT(*) FROM {$logTable} WHERE TRIM(RegistryNum) = ?");
        $checkStmt->execute([$registryNum]);
        $logExists = (int)$checkStmt->fetchColumn() > 0;

        if ($logExists) {
            $updateSql = "UPDATE {$logTable}
                          SET Action = ?, ContactNumber = ?, AttachmentSize = ?,
                              AttachmentType = ?, ActionDate = NOW()
                          WHERE TRIM(RegistryNum) = ?";
            $pdoSupport->prepare($updateSql)->execute([
                $action, $mobileNumber, $pdfSize, $attachmentType, $registryNum,
            ]);
        } else {
            $insertSql = "INSERT INTO {$logTable}
                          (RegistryNum, Action, Performed_By, ActionDate, ContactNumber,
                           AttachmentSize, AttachmentType)
                          VALUES (?, ?, ?, NOW(), ?, ?, ?)";
            $pdoSupport->prepare($insertSql)->execute([
                $registryNum, $action, $performedBy, $mobileNumber, $pdfSize, $attachmentType,
            ]);
        }
    } catch (Exception $e) {
        $firstError = $e->getMessage();
        try {
            if ($logExists) {
                $pdoSupport->prepare(
                    "UPDATE {$logTable} SET Action = ?, ContactNumber = ?, ActionDate = NOW() WHERE TRIM(RegistryNum) = ?"
                )->execute([$action, $mobileNumber, $registryNum]);
            } else {
                $pdoSupport->prepare(
                    "INSERT INTO {$logTable} (RegistryNum, Action, Performed_By, ActionDate, ContactNumber)
                     VALUES (?, ?, ?, NOW(), ?)"
                )->execute([$registryNum, $action, $performedBy, $mobileNumber]);
            }
        } catch (Exception $e2) {
            return ['ok' => false, 'error' => $e2->getMessage() . ' (extended: ' . $firstError . ')'];
        }
    }

    if ($attachmentType === 'YES-DB' && is_string($pdfData) && $pdfData !== '') {
        lcrStoreAttachmentLocally($pdoSupport, $logTable, $registryNum, $pdfData);
    }

    $historyDetails = "Action: {$action} | Mobile: {$mobileNumber} | User: {$performedBy}";
    if ($remarks !== '') {
        $historyDetails .= " | Remarks: {$remarks}";
    }

    $historyError = '';
    try {
        // Legacy schema requires Modified_Date (see MySQL_DatabaseManager.php)
        $historySql = "INSERT INTO {$historyTable}
                       (RegistryNum, Details, Modified_By, Modified_Date, MobileNo)
                       VALUES (?, ?, ?, NOW(), ?)";
        $pdoSupport->prepare($historySql)->execute([$registryNum, $historyDetails, $performedBy, $mobileNumber]);
    } catch (Exception $e) {
        $historyError = $e->getMessage();
        try {
            $historySql = "INSERT INTO {$historyTable} (RegistryNum, Details, Modified_By, MobileNo) VALUES (?, ?, ?, ?)";
            $pdoSupport->prepare($historySql)->execute([$registryNum, $historyDetails, $performedBy, $mobileNumber]);
            $historyError = '';
        } catch (Exception $e2) {
            $historyError = $e2->getMessage();
        }
    }

    try {
        $verify = $pdoSupport->prepare("SELECT COUNT(*) FROM {$logTable} WHERE TRIM(RegistryNum) = ?");
        $verify->execute([$registryNum]);
        if ((int)$verify->fetchColumn() === 0) {
            return ['ok' => false, 'error' => 'registry log INSERT did not persist — check MySQL user INSERT privilege on phcris_support'];
        }
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Could not verify log row: ' . $e->getMessage()];
    }

    lcrSupportAuditLog(
        $pdoSupport,
        $action . '_SUCCESS',
        "Type: {$type}, Mobile: {$mobileNumber}",
        $registryNum,
        $auditUsername
    );

    if ($historyError !== '') {
        return ['ok' => true, 'error' => 'Log saved but history failed: ' . $historyError];
    }

    return ['ok' => true, 'error' => ''];
}

/** COUNT DISTINCT RegistryNum in registry_*_log for unregistered (!) partner records. */
function lcrCountTransmittedFromLog($pdoSupport, $type) {
    if (!$pdoSupport) {
        return 0;
    }
    $logTable = lcrGetTableNames($type)['log'];
    try {
        $stmt = $pdoSupport->query(
            "SELECT COUNT(DISTINCT RegistryNum) FROM {$logTable} WHERE RegistryNum LIKE '!%'"
        );
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Enrich list rows with sent_count / date_sent / attachment flags from support DB
 * (legacy eTransmission listRecords batch join).
 *
 * @param array<int,array<string,mixed>> $records
 * @return array<int,array<string,mixed>>
 */
function lcrEnrichRecordsWithSentStatus($pdoSupport, $type, array $records) {
    if (!$pdoSupport || empty($records)) {
        foreach ($records as &$row) {
            $row['sent_count'] = (int)($row['sent_count'] ?? 0);
            $row['date_sent'] = $row['date_sent'] ?? null;
            $row['has_attachment'] = !empty($row['has_attachment']);
            $row['attachment_type'] = $row['attachment_type'] ?? 'NO';
        }
        unset($row);
        return $records;
    }

    $tables = lcrGetTableNames($type);
    $logTable = $tables['log'];
    $historyTable = $tables['history'];
    $regNums = [];
    foreach ($records as $row) {
        $rn = trim((string)($row['RegistryNum'] ?? ''));
        if ($rn !== '') {
            $regNums[$rn] = true;
        }
    }
    $regList = array_keys($regNums);
    if (empty($regList)) {
        return $records;
    }

    $placeholders = implode(',', array_fill(0, count($regList), '?'));
    $logMap = [];
    $histMap = [];

    try {
        $sql = "SELECT RegistryNum, MAX(ActionDate) AS ActionDate,
                       MAX(CASE WHEN AttachmentType IS NOT NULL AND AttachmentType != '' AND AttachmentType != 'NO'
                           THEN AttachmentType ELSE NULL END) AS AttachmentType
                FROM {$logTable}
                WHERE RegistryNum IN ({$placeholders})
                GROUP BY RegistryNum";
        $stmt = $pdoSupport->prepare($sql);
        $stmt->execute($regList);
        foreach ($stmt->fetchAll() as $r) {
            $key = trim((string)$r['RegistryNum']);
            $logMap[$key] = $r;
        }
    } catch (Exception $e) {
        // ignore — leave maps empty
    }

    try {
        $sql = "SELECT RegistryNum, COUNT(*) AS cnt FROM {$historyTable}
                WHERE RegistryNum IN ({$placeholders})
                GROUP BY RegistryNum";
        $stmt = $pdoSupport->prepare($sql);
        $stmt->execute($regList);
        foreach ($stmt->fetchAll() as $r) {
            $histMap[trim((string)$r['RegistryNum'])] = (int)$r['cnt'];
        }
    } catch (Exception $e) {
        // ignore
    }

    foreach ($records as &$row) {
        $key = trim((string)($row['RegistryNum'] ?? ''));
        $sentCount = $histMap[$key] ?? 0;
        if ($sentCount === 0 && isset($logMap[$key])) {
            $sentCount = 1;
        }
        $attachType = $logMap[$key]['AttachmentType'] ?? 'NO';
        if ($attachType === null || $attachType === '') {
            $attachType = 'NO';
        }
        $row['sent_count'] = $sentCount;
        $row['date_sent'] = $logMap[$key]['ActionDate'] ?? null;
        $row['attachment_type'] = $attachType;
        $row['has_attachment'] = ($attachType !== 'NO');
    }
    unset($row);

    return $records;
}

/**
 * Probe INSERT permission on phcris_support (SELECT works for ping even when INSERT is denied).
 */
function lcrTestSupportWrite($pdoSupport, $type = 'birth') {
    if (!$pdoSupport) {
        return ['ok' => false, 'error' => 'Support DB not connected', 'steps' => []];
    }

    $tables = lcrGetTableNames($type);
    $logTable = $tables['log'];
    $historyTable = $tables['history'];
    $testReg = '!EVITAL_WRITE_TEST_' . date('YmdHis');
    $steps = [];

    try {
        $pdoSupport->beginTransaction();

        $pdoSupport->prepare(
            "INSERT INTO {$logTable} (RegistryNum, Action, Performed_By, ActionDate, ContactNumber, AttachmentSize, AttachmentType)
             VALUES (?, 'TEST', 'EVITAL_DIAG', NOW(), '0000000000', 0, 'NO')"
        )->execute([$testReg]);
        $steps[] = 'registry_log INSERT ok';

        try {
            $pdoSupport->prepare(
                "INSERT INTO {$historyTable} (RegistryNum, Details, Modified_By, Modified_Date, MobileNo)
                 VALUES (?, 'eVital write test', 'EVITAL_DIAG', NOW(), '0000000000')"
            )->execute([$testReg]);
            $steps[] = 'registry_history INSERT ok';
        } catch (Exception $e) {
            $pdoSupport->prepare(
                "INSERT INTO {$historyTable} (RegistryNum, Details, Modified_By, MobileNo)
                 VALUES (?, 'eVital write test', 'EVITAL_DIAG', '0000000000')"
            )->execute([$testReg]);
            $steps[] = 'registry_history INSERT ok (no Modified_Date column)';
        }

        try {
            $pdoSupport->prepare(
                "INSERT INTO audit_log (user_id, username, action, details, registry_num, ip_address, user_agent, created_at)
                 VALUES (0, 'EVITAL_DIAG', 'WRITE_TEST', 'Support DB write probe', ?, '127.0.0.1', 'eVital', NOW())"
            )->execute([$testReg]);
            $steps[] = 'audit_log INSERT ok';
        } catch (Exception $e) {
            $steps[] = 'audit_log skipped: ' . $e->getMessage();
        }

        $pdoSupport->rollBack();
        $steps[] = 'transaction rolled back (no test data kept)';

        return ['ok' => true, 'error' => '', 'steps' => $steps];
    } catch (Exception $e) {
        if ($pdoSupport->inTransaction()) {
            $pdoSupport->rollBack();
        }
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'steps' => $steps,
            'hint' => 'Grant INSERT on phcris_support.* to the bridge MySQL user (or set Support DB Username in token).',
        ];
    }
}

/** After chunked PDF upload — update log attachment fields (log/history already written on transmit). */
function lcrUpdateTransmissionAttachment($pdoSupport, $type, $registryNum, $pdfSize, $pdfData) {
    if (!$pdoSupport) {
        return ['ok' => false, 'error' => 'Support DB not connected'];
    }
    $registryNum = trim($registryNum);
    if ($registryNum === '' || $pdfSize <= 0) {
        return ['ok' => false, 'error' => 'Invalid attachment size'];
    }

    $logTable = lcrGetTableNames($type)['log'];
    try {
        $stmt = $pdoSupport->prepare(
            "UPDATE {$logTable} SET AttachmentSize = ?, AttachmentType = 'YES-DB', ActionDate = NOW()
             WHERE TRIM(RegistryNum) = ?"
        );
        $stmt->execute([$pdfSize, $registryNum]);
        if ((int)$stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'registry log row not found for ' . $registryNum];
        }
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (is_string($pdfData) && $pdfData !== '') {
        try {
            lcrStoreAttachmentLocally($pdoSupport, $logTable, $registryNum, $pdfData);
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Attachment metadata saved but file store failed: ' . $e->getMessage()];
        }
    }

    return ['ok' => true, 'error' => ''];
}

function lcrGetSQLServerTable($type) {
    return [
        'birth' => 'online_birthdocument',
        'death' => 'online_deathdocument',
        'marriage' => 'online_marriagedocument',
    ][$type] ?? 'online_birthdocument';
}

function lcrBuildInsertSql($table, $params) {
    $keys = array_keys($params);
    $values = array_map(function ($k) use ($params) {
        return ($params[$k] === null || $params[$k] === '') ? 'NULL' : "@$k";
    }, $keys);
    return "INSERT INTO $table (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $values) . ")";
}

function lcrBuildUpdateSql($table, $params) {
    $sets = [];
    foreach ($params as $key => $value) {
        if ($key !== 'RegistryNum') {
            $sets[] = "$key = " . (($value === null || $value === '') ? 'NULL' : "@$key");
        }
    }
    return "UPDATE $table SET " . implode(', ', $sets) . " WHERE RegistryNum = @RegistryNum";
}

function lcrSanitizeParams($params) {
    $out = [];
    foreach ($params as $key => $value) {
        // Skip nested UI objects / arrays — LCR Params must be scalars
        if (is_array($value) || is_object($value)) {
            continue;
        }
        if ($value === null || $value === '') {
            $out[$key] = null;
            continue;
        }
        if (is_resource($value)) {
            $data = stream_get_contents($value);
            $out[$key] = ($data === false || $data === '') ? null : base64_encode($data);
            continue;
        }
        if (is_string($value)) {
            // AttachFile / ChunkData are already base64 (legacy eTransmission) — never double-encode
            if (strcasecmp($key, 'AttachFile') === 0 || strcasecmp($key, 'ChunkData') === 0) {
                $out[$key] = $value;
                continue;
            }
            $looksBinary = stripos($key, 'attach') !== false
                || stripos($key, 'signature') !== false
                || stripos($key, 'photo') !== false
                || stripos($key, 'image') !== false
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)
                || (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8'));
            if ($looksBinary) {
                $out[$key] = base64_encode($value);
            } else {
                $out[$key] = $value;
            }
            continue;
        }
        $out[$key] = $value;
    }
    return $out;
}

function lcrSendRequest($url, $payload, $clientToken, $connectTimeout = 10, $timeout = 180) {
    $url = lcrApiExecsqlUrl($url);

    try {
        $body = PayloadCrypto::wrapJson($payload, PayloadCrypto::secretFromToken($clientToken));
    } catch (Throwable $e) {
        return ['ok' => false, 'http' => 0, 'error' => 'Payload encryption failed: ' . $e->getMessage(), 'timeMs' => 0];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client-Token: ' . $clientToken,
            'ngrok-skip-browser-warning: true',
            'User-Agent: eRegPartner/rv21',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $start = microtime(true);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $elapsed = (int)round((microtime(true) - $start) * 1000);

    if ($curlError) {
        return ['ok' => false, 'http' => 0, 'error' => $curlError, 'timeMs' => $elapsed];
    }
    if (lcrIsApiBlockResponse($httpCode, (string) $response)) {
        return ['ok' => false, 'http' => $httpCode, 'error' => 'Blocked by firewall/DNS filter', 'timeMs' => $elapsed];
    }
    if ($httpCode !== 200) {
        return ['ok' => false, 'http' => $httpCode, 'error' => "HTTP $httpCode", 'timeMs' => $elapsed, 'body' => substr($response ?: '', 0, 200)];
    }
    $result = json_decode($response, true);
    if ($result === null) {
        return ['ok' => false, 'http' => $httpCode, 'error' => 'Invalid JSON response', 'timeMs' => $elapsed];
    }
    if (isset($result['error']) && $result['error'] !== null && $result['error'] !== false && $result['error'] !== '') {
        return ['ok' => false, 'http' => $httpCode, 'error' => (string)$result['error'], 'timeMs' => $elapsed];
    }
    return ['ok' => true, 'http' => $httpCode, 'timeMs' => $elapsed, 'result' => $result, 'resolvedUrl' => $url];
}

function lcrProbeEndpoint($url, $clientToken) {
    $probeUrl = lcrApiProbeUrl($url);
    $ch = curl_init($probeUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [
            'Client-Token: ' . $clientToken,
            'Accept: application/json',
            'ngrok-skip-browser-warning: true',
            'User-Agent: eRegPartner/rv21',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $start = microtime(true);
    $body = (string) curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $elapsed = (int)round((microtime(true) - $start) * 1000);

    if (!empty($curlError)) {
        return ['ok' => false, 'http' => 0, 'error' => $curlError, 'timeMs' => $elapsed];
    }
    if (lcrIsApiBlockResponse($httpCode, $body)) {
        return ['ok' => false, 'http' => $httpCode, 'error' => 'Blocked by firewall/DNS filter', 'timeMs' => $elapsed];
    }

    // 405 = GET not allowed but server is up → confirm with encrypted POST (eTransmission parity)
    if ($httpCode === 405) {
        $execUrl = lcrApiExecsqlUrl($url);
        try {
            $probeBody = PayloadCrypto::wrapJson(
                ['Sql' => 'SELECT 1', 'Database' => 'LCRDbase_Online', 'QueryType' => 'SELECT', 'Params' => []],
                PayloadCrypto::secretFromToken($clientToken)
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'http' => 0, 'error' => $e->getMessage(), 'timeMs' => $elapsed];
        }
        $ch2 = curl_init($execUrl);
        curl_setopt_array($ch2, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $probeBody,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Client-Token: ' . $clientToken,
                'ngrok-skip-browser-warning: true',
                'User-Agent: eRegPartner/rv21',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = (string) curl_exec($ch2);
        $httpCode = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch2);
        curl_close($ch2);
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        if (!empty($curlError)) {
            return ['ok' => false, 'http' => 0, 'error' => $curlError, 'timeMs' => $elapsed];
        }
        if (lcrIsApiBlockResponse($httpCode, $body)) {
            return ['ok' => false, 'http' => $httpCode, 'error' => 'Blocked by firewall/DNS filter', 'timeMs' => $elapsed];
        }
    }

    // 200 = healthy; 401/403 JSON = auth issue but host reachable (same as eTransmission)
    $ok = ($httpCode === 200) || ($httpCode >= 401 && $httpCode <= 403 && strpos($body, '{') !== false);
    return [
        'ok' => $ok,
        'http' => $httpCode,
        'error' => $ok ? '' : ("HTTP $httpCode"),
        'timeMs' => $elapsed,
    ];
}

function lcrTransmitWithFallback($endpoints, $clientToken, $sql, $database, $queryType, $params) {
    $cleanedParams = lcrSanitizeParams($params);
    $payload = [
        'Sql'       => $sql,
        'Database'  => $database,
        'QueryType' => strtoupper($queryType),
        'Params'    => $cleanedParams,
    ];

    $attempts = [];
    usort($endpoints, function ($a, $b) {
        return ($a['priority'] ?? $a['slot'] ?? 99) <=> ($b['priority'] ?? $b['slot'] ?? 99);
    });

    foreach ($endpoints as $ep) {
        if (isset($ep['enabled']) && !$ep['enabled']) {
            continue;
        }
        $url = trim($ep['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $slot = (int)($ep['slot'] ?? 0);

        $probe = lcrProbeEndpoint($url, $clientToken);
        if (!$probe['ok']) {
            $attempts[] = [
                'slot' => $slot,
                'url' => $url,
                'status' => $probe['http'] ?: 504,
                'error' => $probe['error'] ?: ('Endpoint unreachable (HTTP ' . ($probe['http'] ?: 0) . ')'),
                'timeMs' => $probe['timeMs'],
            ];
            continue;
        }

        $send = lcrSendRequest($url, $payload, $clientToken);
        if ($send['ok']) {
            $usedUrl = $send['resolvedUrl'] ?? $url;
            $attempts[] = [
                'slot' => $slot,
                'url' => $usedUrl,
                'status' => 200,
                'timeMs' => $send['timeMs'],
            ];
            return [
                'success' => true,
                'usedSlot' => $slot,
                'usedUrl' => $usedUrl,
                'attempts' => $attempts,
                'httpStatus' => 200,
            ];
        }

        $attempts[] = [
            'slot' => $slot,
            'url' => $url,
            'status' => $send['http'] ?: 500,
            'error' => $send['error'] ?? 'Transmission failed',
            'timeMs' => $send['timeMs'],
        ];
    }

    $firstErr = '';
    foreach ($attempts as $a) {
        if (!empty($a['error'])) {
            $firstErr = $a['error'];
            break;
        }
    }

    return [
        'success' => false,
        'usedSlot' => -1,
        'usedUrl' => '',
        'attempts' => $attempts,
        'httpStatus' => 500,
        'message' => $firstErr
            ? ("Transmission failed: {$firstErr}")
            : 'All transmission endpoints failed to respond',
    ];
}

function lcrSendAttachChunkToLcr($endpoints, $clientToken, $type, $registryNum, $chunkBinary, $isFirst, $preferredUrl = '') {
    $table = lcrGetSQLServerTable($type);
    $b64 = base64_encode($chunkBinary);
    if ($isFirst) {
        $sql = "UPDATE $table SET AttachFile = @ChunkData WHERE RegistryNum = @RegistryNum";
    } else {
        $sql = "UPDATE $table SET AttachFile = ISNULL(AttachFile, 0x) + @ChunkData WHERE RegistryNum = @RegistryNum";
    }
    $params = lcrSanitizeParams([
        'RegistryNum' => $registryNum,
        'ChunkData' => $b64,
    ]);
    $payload = [
        'Sql' => $sql,
        'Database' => 'LCRDbase_Online',
        'QueryType' => 'UPDATE',
        'Params' => $params,
    ];

    if ($preferredUrl !== '') {
        $send = lcrSendRequest($preferredUrl, $payload, $clientToken, 15, 600);
        if ($send['ok']) {
            return [
                'success' => true,
                'usedUrl' => $preferredUrl,
                'usedSlot' => -1,
                'httpStatus' => 200,
                'attempts' => [['slot' => -1, 'url' => $preferredUrl, 'status' => 200, 'timeMs' => $send['timeMs']]],
            ];
        }
        // Fall through to endpoint list if preferred URL failed
    }

    return lcrTransmitWithFallback($endpoints, $clientToken, $sql, 'LCRDbase_Online', 'UPDATE', [
        'RegistryNum' => $registryNum,
        'ChunkData' => $b64,
    ]);
}

function lcrUploadRecord($endpoints, $clientToken, $type, $parameters, $pdfData = null) {
    $table = lcrGetSQLServerTable($type);
    $registryNum = $parameters['RegistryNum'] ?? '';
    $isResend = $registryNum !== '' && strpos($registryNum, '!') !== 0;
    $pdfSize = is_string($pdfData) ? strlen($pdfData) : 0;

    // Always chunk above LCR_PDF_CHUNK_BYTES so proxy/JSON body stays small (hex/base64 inflate)
    if ($pdfData && $pdfSize > LCR_PDF_CHUNK_BYTES) {
        $initParams = $parameters;
        $initParams['AttachFile'] = null;
        if ($isResend) {
            $sql = lcrBuildUpdateSql($table, $initParams);
            $queryType = 'UPDATE';
        } else {
            $sql = lcrBuildInsertSql($table, $initParams);
            $queryType = 'INSERT';
        }
        $initResult = lcrTransmitWithFallback($endpoints, $clientToken, $sql, 'LCRDbase_Online', $queryType, $initParams);
        if (!$initResult['success']) {
            return $initResult;
        }

        $usedUrl = $initResult['usedUrl'];
        $usedSlot = $initResult['usedSlot'];
        $chunkSize = LCR_PDF_CHUNK_BYTES;
        $totalChunks = (int)ceil($pdfSize / $chunkSize);
        $attempts = $initResult['attempts'];

        $firstChunk = substr($pdfData, 0, min($chunkSize, $pdfSize));
        $sendFirst = lcrSendAttachChunkToLcr(
            $endpoints,
            $clientToken,
            $type,
            $registryNum,
            $firstChunk,
            true,
            $usedUrl
        );
        if (!$sendFirst['success']) {
            return [
                'success' => false,
                'usedSlot' => $usedSlot,
                'usedUrl' => $usedUrl,
                'attempts' => array_merge($attempts, $sendFirst['attempts'] ?? []),
                'httpStatus' => $sendFirst['httpStatus'] ?? 500,
                'message' => 'Record saved but PDF attachment upload failed (first chunk of '
                    . $totalChunks . '). ' . ($sendFirst['message'] ?? ''),
            ];
        }

        for ($i = 1; $i < $totalChunks; $i++) {
            $offset = $i * $chunkSize;
            $chunk = substr($pdfData, $offset, min($chunkSize, $pdfSize - $offset));
            $sendChunk = lcrSendAttachChunkToLcr(
                $endpoints,
                $clientToken,
                $type,
                $registryNum,
                $chunk,
                false,
                $usedUrl
            );
            if (!$sendChunk['success']) {
                return [
                    'success' => false,
                    'usedSlot' => $usedSlot,
                    'usedUrl' => $usedUrl,
                    'attempts' => array_merge($attempts, $sendChunk['attempts'] ?? []),
                    'httpStatus' => $sendChunk['httpStatus'] ?? 500,
                    'message' => "Record saved but PDF attachment upload failed at chunk "
                        . ($i + 1) . "/$totalChunks. " . ($sendChunk['message'] ?? ''),
                ];
            }
        }

        $initResult['attachmentIncluded'] = true;
        $initResult['attachmentSize'] = $pdfSize;
        $initResult['attachmentMode'] = 'chunked';
        $initResult['attachmentChunks'] = $totalChunks;
        return $initResult;
    }

    // Small PDF or no PDF: single INSERT/UPDATE with AttachFile base64 (legacy)
    if ($pdfData) {
        $parameters['AttachFile'] = base64_encode($pdfData);
    } elseif (!array_key_exists('AttachFile', $parameters)) {
        $parameters['AttachFile'] = null;
    }

    if ($isResend) {
        $sql = lcrBuildUpdateSql($table, $parameters);
        $queryType = 'UPDATE';
    } else {
        $sql = lcrBuildInsertSql($table, $parameters);
        $queryType = 'INSERT';
    }

    $result = lcrTransmitWithFallback($endpoints, $clientToken, $sql, 'LCRDbase_Online', $queryType, $parameters);
    if ($result['success'] && $pdfData) {
        $result['attachmentIncluded'] = true;
        $result['attachmentSize'] = $pdfSize;
        $result['attachmentMode'] = 'inline';
    } else {
        $result['attachmentIncluded'] = false;
        $result['attachmentSize'] = 0;
    }
    return $result;
}

function lcrReadUploadedPdf() {
    if (!isset($_FILES['pdf_file'])) {
        return null;
    }

    $err = (int)($_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        $iniUpload = ini_get('upload_max_filesize') ?: '?';
        $iniPost = ini_get('post_max_size') ?: '?';
        $messages = [
            UPLOAD_ERR_INI_SIZE => "PDF exceeds PHP upload_max_filesize ({$iniUpload}). Set upload_max_filesize=20M and post_max_size=25M (see dist/.user.ini), then restart PHP.",
            UPLOAD_ERR_FORM_SIZE => 'PDF exceeds HTML form MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL => 'PDF upload was incomplete — try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write uploaded PDF to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the PDF upload.',
        ];
        throw new Exception($messages[$err] ?? ("PDF upload error code {$err}. PHP limits: upload_max_filesize={$iniUpload}, post_max_size={$iniPost}"));
    }

    $pdfSize = (int)($_FILES['pdf_file']['size'] ?? 0);
    if ($pdfSize <= 0) {
        // Common when post_max_size was exceeded — PHP empties $_POST/$_FILES
        $iniPost = ini_get('post_max_size') ?: '?';
        throw new Exception("PDF upload empty (size 0). Often post_max_size ({$iniPost}) is too small — set post_max_size=25M and upload_max_filesize=20M.");
    }
    if ($pdfSize > LCR_PDF_MAX_BYTES) {
        throw new Exception('PDF exceeds 20 MB maximum (' . round($pdfSize / 1048576, 2) . ' MB).');
    }

    $pdfData = @file_get_contents($_FILES['pdf_file']['tmp_name']);
    if (!$pdfData || strlen($pdfData) < 4) {
        throw new Exception('PDF attachment could not be read');
    }
    // %PDF magic
    if (!(ord($pdfData[0]) === 0x25 && ord($pdfData[1]) === 0x50 && ord($pdfData[2]) === 0x44 && ord($pdfData[3]) === 0x46)) {
        throw new Exception('Invalid PDF file — attachment must start with %PDF');
    }
    return $pdfData;
}

// Clean buffer before emitting response
ob_end_clean();

// -------------------------------------------------------------
// Route Action Handling
// -------------------------------------------------------------
try {
    switch ($action) {
        case 'ping':
        case 'health':
            $liveCounts = null;
            $countError = '';
            if ($pdoMain) {
                $b = countTableSafe($pdoMain, 'birthdocument');
                $d = countTableSafe($pdoMain, 'deathdocument');
                $m = countTableSafe($pdoMain, 'marriagedocument');
                if ($b === null && $d === null && $m === null) {
                    $countError = 'Tables birthdocument/deathdocument/marriagedocument not found or not readable in this database. Open action=diagnose.';
                } else {
                    $liveCounts = [
                        'birthdocument' => $b ?? 0,
                        'birth_unregistered' => countUnregSafe($pdoMain, 'birthdocument') ?? 0,
                        'deathdocument' => $d ?? 0,
                        'death_unregistered' => countUnregSafe($pdoMain, 'deathdocument') ?? 0,
                        'marriagedocument' => $m ?? 0,
                        'marriage_unregistered' => countUnregSafe($pdoMain, 'marriagedocument') ?? 0,
                    ];
                }
            }
            $supportCounts = null;
            $supportWriteTest = null;
            if ($pdoSupport) {
                $supportCounts = [
                    'registry_birth_log' => countTableSafe($pdoSupport, 'registry_birth_log') ?? 0,
                    'registry_birth_history' => countTableSafe($pdoSupport, 'registry_birth_history') ?? 0,
                    'audit_log' => countTableSafe($pdoSupport, 'audit_log') ?? 0,
                ];
                if (($_GET['test_support_write'] ?? $_POST['test_support_write'] ?? '') === '1') {
                    $supportWriteTest = lcrTestSupportWrite($pdoSupport, $type);
                }
            }
            echo json_encode([
                'success' => true,
                'status' => 'ONLINE',
                'server' => php_uname('s') . ' ' . php_uname('r'),
                'php_version' => PHP_VERSION,
                'mysql_main' => $pdoMain ? 'CONNECTED' : 'DISCONNECTED',
                'mysql_support' => $pdoSupport ? 'CONNECTED' : ($pdoMain ? 'ERROR' : 'SKIPPED'),
                'main_error' => $mainConnectError,
                'count_error' => $countError,
                'charset' => $mainCharset,
                'support_port_used' => $supportPortUsed,
                'support_note' => $supportConnectNote,
                'support_error' => $supportConnectError,
                'host' => $clientDbHost,
                'port' => $clientDbPort,
                'database' => $clientDbNameMain,
                'database_support' => $clientDbNameSupport,
                'user' => $clientDbUser,
                'counts' => $liveCounts,
                'support_counts' => $supportCounts,
                'support_write_test' => $supportWriteTest,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'test_support_write':
            $writeTest = lcrTestSupportWrite($pdoSupport, $type);
            echo json_encode([
                'success' => !empty($writeTest['ok']),
                'message' => !empty($writeTest['ok'])
                    ? 'Support DB write test passed (rolled back — no test rows kept).'
                    : ('Support DB write test FAILED: ' . ($writeTest['error'] ?? 'unknown')),
                'data' => array_merge($writeTest, [
                    'mysql_support' => $pdoSupport ? 'CONNECTED' : 'ERROR',
                    'database_support' => $clientDbNameSupport,
                    'support_port_used' => $supportPortUsed,
                    'support_error' => $supportConnectError,
                    'support_note' => $supportConnectNote,
                ]),
            ]);
            break;

        case 'diagnose':
            $diag = [
                'success' => true,
                'php_version' => PHP_VERSION,
                'config' => [
                    'host' => $clientDbHost,
                    'port' => $clientDbPort,
                    'database' => $clientDbNameMain,
                    'user' => $clientDbUser,
                    'support_db' => $clientDbNameSupport,
                    'support_port' => $clientDbPortSupport,
                ],
                'tcp_3306' => probeTcpPort($clientDbHost, $clientDbPort, 2),
                'tcp_support' => probeTcpPort($clientDbHost, $clientDbPortSupport, 1),
                'mysql_main' => $pdoMain ? 'CONNECTED' : 'DISCONNECTED',
                'main_error' => $mainConnectError,
                'charset' => $mainCharset,
                'mysql_support' => $pdoSupport ? 'CONNECTED' : 'SKIPPED',
                'support_note' => $supportConnectNote,
                'current_database' => null,
                'tables' => [],
                'philcris_tables' => [],
                'sample_registry' => null,
            ];
            if ($pdoMain) {
                try {
                    $diag['current_database'] = $pdoMain->query('SELECT DATABASE()')->fetchColumn();
                    $tables = $pdoMain->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                    $diag['tables'] = $tables;
                    foreach (['birthdocument', 'deathdocument', 'marriagedocument'] as $t) {
                        $found = false;
                        foreach ($tables as $tbl) {
                            if (strcasecmp($tbl, $t) === 0) {
                                $found = $tbl;
                                break;
                            }
                        }
                        $diag['philcris_tables'][$t] = [
                            'found_as' => $found ?: null,
                            'count' => $found ? countTableSafe($pdoMain, $found) : null,
                            'unregistered' => $found ? countUnregSafe($pdoMain, $found) : null,
                        ];
                    }
                    if (!empty($diag['philcris_tables']['birthdocument']['found_as'])) {
                        $bt = $diag['philcris_tables']['birthdocument']['found_as'];
                        $stmt = $pdoMain->query("SELECT RegistryNum, CFirstName, CLastName FROM `{$bt}` ORDER BY RegistryNum DESC LIMIT 3");
                        $diag['sample_registry'] = $stmt ? $stmt->fetchAll() : [];
                    }
                } catch (Exception $e) {
                    $diag['query_error'] = $e->getMessage();
                }
            }
            echo json_encode($diag, JSON_PRETTY_PRINT);
            break;

        case 'get_stats':
        case 'stats':
            // Live PhilCRIS only — never invent “cache” totals when MySQL is down
            $stats = [
                'birth' => [
                    'total' => 0,
                    'registered' => 0,
                    'unregistered' => 0,
                    'transmitted' => 0,
                    'pending_transmission' => 0
                ],
                'death' => [
                    'total' => 0,
                    'registered' => 0,
                    'unregistered' => 0,
                    'transmitted' => 0,
                    'pending_transmission' => 0
                ],
                'marriage' => [
                    'total' => 0,
                    'registered' => 0,
                    'unregistered' => 0,
                    'transmitted' => 0,
                    'pending_transmission' => 0
                ]
            ];
            $statsLive = false;
            $statsError = $mainConnectError;

            if ($pdoMain) {
                try {
                    $resBTotal = $pdoMain->query("SELECT COUNT(*) as cnt FROM birthdocument")->fetch();
                    $resBUnreg = $pdoMain->query("SELECT COUNT(*) as cnt FROM birthdocument WHERE RegistryNum LIKE '!%'")->fetch();
                    if ($resBTotal) {
                        $totalB = (int)$resBTotal['cnt'];
                        $unregB = (int)($resBUnreg['cnt'] ?? 0);
                        $txB = lcrCountTransmittedFromLog($pdoSupport, 'birth');
                        $stats['birth'] = [
                            'total' => $totalB,
                            'registered' => max(0, $totalB - $unregB),
                            'unregistered' => $unregB,
                            'transmitted' => $txB,
                            'pending_transmission' => max(0, $unregB - $txB)
                        ];
                    }

                    $resDTotal = $pdoMain->query("SELECT COUNT(*) as cnt FROM deathdocument")->fetch();
                    $resDUnreg = $pdoMain->query("SELECT COUNT(*) as cnt FROM deathdocument WHERE RegistryNum LIKE '!%'")->fetch();
                    if ($resDTotal) {
                        $totalD = (int)$resDTotal['cnt'];
                        $unregD = (int)($resDUnreg['cnt'] ?? 0);
                        $txD = lcrCountTransmittedFromLog($pdoSupport, 'death');
                        $stats['death'] = [
                            'total' => $totalD,
                            'registered' => max(0, $totalD - $unregD),
                            'unregistered' => $unregD,
                            'transmitted' => $txD,
                            'pending_transmission' => max(0, $unregD - $txD)
                        ];
                    }

                    $resMTotal = $pdoMain->query("SELECT COUNT(*) as cnt FROM marriagedocument")->fetch();
                    $resMUnreg = $pdoMain->query("SELECT COUNT(*) as cnt FROM marriagedocument WHERE RegistryNum LIKE '!%'")->fetch();
                    if ($resMTotal) {
                        $totalM = (int)$resMTotal['cnt'];
                        $unregM = (int)($resMUnreg['cnt'] ?? 0);
                        $txM = lcrCountTransmittedFromLog($pdoSupport, 'marriage');
                        $stats['marriage'] = [
                            'total' => $totalM,
                            'registered' => max(0, $totalM - $unregM),
                            'unregistered' => $unregM,
                            'transmitted' => $txM,
                            'pending_transmission' => max(0, $unregM - $txM)
                        ];
                    }
                    $statsLive = true;
                    $statsError = '';
                } catch (Exception $e) {
                    $statsError = $e->getMessage();
                    $statsLive = false;
                }
            } elseif ($statsError === '') {
                $statsError = 'Main MySQL not connected — check Bridge host + Database Host (reachable from the PHP bridge machine)';
            }

            echo json_encode([
                'success' => $statsLive,
                'data' => $stats,
                'source' => $statsLive ? 'Live MySQL Database' : 'disconnected',
                'main_error' => $statsError,
                'mysql_support' => $pdoSupport ? 'CONNECTED' : 'SKIPPED',
                'host' => $clientDbHost,
                'port' => $clientDbPort,
                'database' => $clientDbNameMain,
                'user' => $clientDbUser,
            ]);
            break;

        case 'list_records':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(500, max(10, (int)($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $perPage;
            $search = trim($_GET['search'] ?? '');
            $filter = $_GET['filter'] ?? 'pending'; // pending = RegistryNum LIKE '!%' (unregistered)

            $table = 'birthdocument';
            if ($type === 'death') $table = 'deathdocument';
            if ($type === 'marriage') $table = 'marriagedocument';

            $records = [];
            $totalCount = 0;
            $listError = null;

            if ($pdoMain) {
                try {
                    $where = [];
                    $params = [];

                    if ($filter === 'pending' || $filter === 'unregistered') {
                        $where[] = "RegistryNum LIKE '!%'";
                    } elseif ($filter === 'registered') {
                        $where[] = "RegistryNum NOT LIKE '!%'";
                    }

                    if (!empty($search)) {
                        if ($type === 'marriage') {
                            $where[] = "(RegistryNum LIKE ? OR HLastName LIKE ? OR HFirstName LIKE ? OR WLastName LIKE ? OR WFirstName LIKE ?)";
                            $params[] = "%{$search}%";
                            $params[] = "%{$search}%";
                            $params[] = "%{$search}%";
                            $params[] = "%{$search}%";
                            $params[] = "%{$search}%";
                        } else {
                            $where[] = "(RegistryNum LIKE ? OR CLastName LIKE ? OR CFirstName LIKE ?)";
                            $params[] = "%{$search}%";
                            $params[] = "%{$search}%";
                            $params[] = "%{$search}%";
                        }
                    }

                    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

                    $countStmt = $pdoMain->prepare("SELECT COUNT(*) as cnt FROM {$table} {$whereSql}");
                    $countStmt->execute($params);
                    $totalCount = (int)$countStmt->fetchColumn();

                    // Lean columns only — NEVER SELECT * (full PhilCRIS rows are 200+ cols and break browser sync)
                    if ($type === 'marriage') {
                        $selectCols = "RegistryNum, HFirstName, HMiddleName, HLastName, HBirthDate, WFirstName, WMiddleName, WLastName, WBirthDate, MarriageDate";
                    } elseif ($type === 'death') {
                        $selectCols = "RegistryNum, CFirstName, CMiddleName, CLastName, CBirthDate, CDeathDate, CSexId, MFirstName, MMiddleName, MLastName, FFirstName, FMiddleName, FLastName";
                    } else {
                        $selectCols = "RegistryNum, CFirstName, CMiddleName, CLastName, CBirthDate, CSexId, CBirthTime, CWeight, CBirthMunicipality, CBirthProvince, CBirthAddress, MFirstName, MMiddleName, MLastName, MReligion, MCitizenship, MAge, FFirstName, FMiddleName, FLastName, FReligion, FCitizenship, FAge, MarriageDate, MarriageMunicipality, AttendantName";
                    }

                    try {
                        $stmt = $pdoMain->prepare("SELECT {$selectCols} FROM {$table} {$whereSql} ORDER BY RegistryNum DESC LIMIT {$perPage} OFFSET {$offset}");
                        $stmt->execute($params);
                        $records = $stmt->fetchAll();
                    } catch (Exception $colErr) {
                        // Minimal safe fallback (still lean — do not SELECT *)
                        $minimal = "RegistryNum, CFirstName, CMiddleName, CLastName";
                        if ($type === 'marriage') {
                            $minimal = "RegistryNum, HFirstName, HLastName, WFirstName, WLastName, MarriageDate";
                        } elseif ($type === 'death') {
                            $minimal = "RegistryNum, CFirstName, CMiddleName, CLastName, CDeathDate";
                        }
                        try {
                            $stmt = $pdoMain->prepare("SELECT {$minimal} FROM {$table} {$whereSql} ORDER BY RegistryNum DESC LIMIT {$perPage} OFFSET {$offset}");
                            $stmt->execute($params);
                            $records = $stmt->fetchAll();
                            $listError = 'Used minimal columns: ' . $colErr->getMessage();
                        } catch (Exception $e2) {
                            $records = [];
                            $listError = $e2->getMessage();
                        }
                    }
                } catch (Exception $e) {
                    $records = [];
                    $listError = $e->getMessage();
                }
            } else {
                $listError = 'Main MySQL not connected — check db_host / credentials';
            }

            if (!empty($records)) {
                $records = lcrEnrichRecordsWithSentStatus($pdoSupport, $type, $records);
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'records' => $records,
                    'filter' => $filter,
                    'source' => $pdoMain ? 'Live MySQL Database' : 'none',
                    'error' => $listError,
                    'mysql_support' => $pdoSupport ? 'CONNECTED' : 'SKIPPED',
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $totalCount,
                        'total_pages' => $perPage > 0 ? (int)ceil($totalCount / $perPage) : 0
                    ]
                ]
            ]);
            break;

        case 'send_record':
            $registryNum = $_POST['registry_num'] ?? '';
            $mobileNumber = $_POST['mobile_number'] ?? '';
            $sendDate = $_POST['send_date'] ?? date('Y-m-d H:i:s');

            if (empty($registryNum)) {
                throw new Exception('Missing RegistryNum for transmission');
            }

            // If LCR endpoints provided, perform real transmission
            $endpointsJson = $_POST['endpoints'] ?? '';
            $clientToken = $_POST['client_token'] ?? '';
            if (!empty($endpointsJson) && !empty($clientToken)) {
                $endpoints = json_decode($endpointsJson, true) ?: [];
                $clientId = (int)($_POST['client_id'] ?? 0);
                $clientEstablishmentName = $_POST['client_establishment_name'] ?? '';
                $tables = lcrGetTableNames($type);
                $record = null;
                if ($pdoMain) {
                    $stmt = $pdoMain->prepare("SELECT * FROM {$tables['main']} WHERE RegistryNum = ? LIMIT 1");
                    $stmt->execute([$registryNum]);
                    $record = $stmt->fetch();
                }
                if ($record) {
                    $parameters = $record;
                    $parameters['MobileNumber'] = $mobileNumber;
                    $parameters['DateSend'] = $sendDate;
                    // Do not inject ClientID / ClientEstablishmentName — not columns on LCR online_* tables
                    unset($parameters['ClientID'], $parameters['ClientEstablishmentName'], $parameters['clientId'], $parameters['clientEstablishmentName']);
                    $lcrResult = lcrUploadRecord($endpoints, $clientToken, $type, $parameters);
                    if (!$lcrResult['success']) {
                        throw new Exception($lcrResult['message'] ?? 'LCR transmission failed');
                    }
                }
            }

            $remarks = $_POST['remarks'] ?? '';
            $sendAction = $_POST['send_action'] ?? 'SEND';
            $performedBy = $_POST['performed_by'] ?? 'EVITAL_BRIDGE';

            if ($pdoSupport) {
                lcrWriteTransmissionSupportLog($pdoSupport, $type, [
                    'registryNum' => $registryNum,
                    'action' => $sendAction,
                    'mobileNumber' => $mobileNumber,
                    'pdfSize' => 0,
                    'attachmentType' => 'NO',
                    'performedBy' => $performedBy,
                    'remarks' => $remarks,
                    'auditUsername' => $performedBy,
                ]);
            }

            echo json_encode([
                'success' => true,
                'message' => "Record {$registryNum} transmitted successfully to central registry endpoint.",
                'data' => [
                    'registry_num' => $registryNum,
                    'status' => 'Transmitted',
                    'timestamp' => $sendDate
                ]
            ]);
            break;

        case 'attach_file_chunk':
            // Browser sends base64 text chunks (~120KB) — bypasses PHP upload_max_filesize=2M
            $registryNum = $_POST['registry_num'] ?? '';
            $clientToken = $_POST['client_token'] ?? '';
            $endpointsJson = $_POST['endpoints'] ?? '[]';
            $chunkB64 = $_POST['chunk_b64'] ?? '';
            $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
            $totalChunks = (int)($_POST['total_chunks'] ?? 1);
            $totalBytes = (int)($_POST['total_bytes'] ?? 0);
            $preferredUrl = trim($_POST['preferred_url'] ?? '');

            if ($registryNum === '') {
                throw new Exception('Missing registry_num');
            }
            if ($clientToken === '') {
                throw new Exception('Missing client_token');
            }
            if ($chunkB64 === '') {
                throw new Exception('Missing chunk_b64');
            }
            if ($totalBytes > LCR_PDF_MAX_BYTES) {
                throw new Exception('PDF exceeds 20 MB maximum');
            }

            $endpoints = json_decode($endpointsJson, true) ?: [];
            if (empty($endpoints)) {
                throw new Exception('No transmission endpoints configured');
            }

            $chunkBinary = base64_decode(preg_replace('/\s+/', '', $chunkB64) ?? '', true);
            if ($chunkBinary === false || $chunkBinary === '') {
                throw new Exception('Invalid chunk_b64 (base64 decode failed)');
            }
            // First chunk should look like a PDF when starting a new AttachFile
            if ($chunkIndex === 0 && strlen($chunkBinary) >= 4) {
                if (!(ord($chunkBinary[0]) === 0x25 && ord($chunkBinary[1]) === 0x50
                    && ord($chunkBinary[2]) === 0x44 && ord($chunkBinary[3]) === 0x46)) {
                    throw new Exception('First PDF chunk must start with %PDF');
                }
            }

            $isFirst = $chunkIndex === 0;
            lcrAppendAttachChunkTemp($type, $registryNum, $chunkBinary, $isFirst);

            $result = lcrSendAttachChunkToLcr(
                $endpoints,
                $clientToken,
                $type,
                $registryNum,
                $chunkBinary,
                $isFirst,
                $preferredUrl
            );

            if (!$result['success']) {
                lcrSupportAuditLog(
                    $pdoSupport,
                    ($_POST['send_action'] ?? 'SEND') . '_FAILED',
                    'AttachFile chunk ' . ($chunkIndex + 1) . "/{$totalChunks} failed: "
                        . ($result['message'] ?? 'unknown'),
                    $registryNum,
                    $_POST['performed_by'] ?? 'EVITAL_BRIDGE'
                );
                $failTemp = lcrGetAttachTempPath($type, $registryNum);
                if (is_readable($failTemp)) {
                    @unlink($failTemp);
                }
                echo json_encode([
                    'success' => false,
                    'message' => $result['message'] ?? ("AttachFile chunk " . ($chunkIndex + 1) . "/$totalChunks failed"),
                    'data' => $result,
                ]);
                break;
            }

            $isComplete = ($chunkIndex + 1) >= $totalChunks;
            $supportLogged = false;
            $supportLogError = '';

            if ($isComplete && $pdoSupport) {
                $mobileNumber = $_POST['mobile_number'] ?? '';
                $remarks = $_POST['remarks'] ?? '';
                $performedBy = $_POST['performed_by'] ?? 'EVITAL_BRIDGE';
                $sendAction = $_POST['send_action'] ?? 'SEND';
                $tempPath = lcrGetAttachTempPath($type, $registryNum);
                $pdfData = (is_readable($tempPath) && filesize($tempPath) > 0) ? @file_get_contents($tempPath) : null;
                $pdfSize = $totalBytes > 0 ? $totalBytes : (is_string($pdfData) ? strlen($pdfData) : 0);
                $attachType = $pdfSize > 0 ? 'YES-DB' : 'NO';

                $supportResult = lcrUpdateTransmissionAttachment($pdoSupport, $type, $registryNum, $pdfSize, $pdfData);
                if (empty($supportResult['ok'])) {
                    $supportResult = lcrWriteTransmissionSupportLog($pdoSupport, $type, [
                        'registryNum' => $registryNum,
                        'action' => $sendAction,
                        'mobileNumber' => $mobileNumber,
                        'pdfSize' => $pdfSize,
                        'attachmentType' => $attachType,
                        'performedBy' => $performedBy,
                        'remarks' => $remarks,
                        'pdfData' => $pdfData,
                        'auditUsername' => $performedBy,
                    ]);
                }
                $supportLogged = !empty($supportResult['ok']);
                $supportLogError = $supportResult['error'] ?? '';

                if (is_readable($tempPath)) {
                    @unlink($tempPath);
                }
            } elseif ($isComplete && !$pdoSupport) {
                $supportLogError = 'Support DB not connected — set db_name_support (phcris_support) in token';
            }

            echo json_encode([
                'success' => true,
                'message' => "AttachFile chunk " . ($chunkIndex + 1) . "/$totalChunks stored",
                'data' => [
                    'chunkIndex' => $chunkIndex,
                    'totalChunks' => $totalChunks,
                    'chunkBytes' => strlen($chunkBinary),
                    'totalBytes' => $totalBytes,
                    'usedUrl' => $result['usedUrl'] ?? $preferredUrl,
                    'usedSlot' => $result['usedSlot'] ?? -1,
                    'complete' => $isComplete,
                    'supportLogged' => $supportLogged,
                    'supportLogError' => $supportLogError ?: null,
                ],
            ]);
            break;

        case 'transmit_record':
            $registryNum = $_POST['registry_num'] ?? '';
            $mobileNumber = $_POST['mobile_number'] ?? '';
            $sendDate = $_POST['send_date'] ?? date('Y-m-d H:i:s');
            $remarks = $_POST['remarks'] ?? '';
            $clientToken = $_POST['client_token'] ?? '';
            $clientId = (int)($_POST['client_id'] ?? 0);
            $clientEstablishmentName = $_POST['client_establishment_name'] ?? '';
            $endpointsJson = $_POST['endpoints'] ?? '[]';
            $recordDataJson = $_POST['record_data'] ?? '';
            $simulationMode = ($_POST['simulation_mode'] ?? '0') === '1';
            $sendAction = $_POST['send_action'] ?? 'SEND';
            $performedBy = $_POST['performed_by'] ?? 'EVITAL_BRIDGE';
            // Prefer browser-chunked PDF (avoids Synology 2M upload_max_filesize). Optional legacy multipart still works under 2M.
            $deferAttachment = ($_POST['defer_attachment'] ?? '0') === '1';
            $pdfData = $deferAttachment ? null : lcrReadUploadedPdf();
            // Force empty AttachFile when PDF will follow via attach_file_chunk
            if ($deferAttachment) {
                // no-op here; parameters set AttachFile null below after load
            }

            if (empty($registryNum)) {
                throw new Exception('Missing registry_num');
            }
            if (empty($clientToken)) {
                throw new Exception('Missing client_token — configure in System Setup');
            }

            $endpoints = json_decode($endpointsJson, true) ?: [];
            if (empty($endpoints)) {
                throw new Exception('No transmission endpoints configured');
            }

            $tables = lcrGetTableNames($type);
            $record = null;
            if ($pdoMain) {
                $stmt = $pdoMain->prepare("SELECT * FROM {$tables['main']} WHERE RegistryNum = ? LIMIT 1");
                $stmt->execute([$registryNum]);
                $record = $stmt->fetch();
            }
            if (!$record && !empty($recordDataJson)) {
                $record = json_decode($recordDataJson, true);
            }
            if (!$record) {
                throw new Exception('Record not found in local PhilCRIS database');
            }

            // UI-shaped records (camelCase) cannot be inserted into LCR SQL Server tables
            if (!isset($record['CFirstName']) && !isset($record['HFirstName']) && !isset($record['CLastName'])) {
                throw new Exception('PhilCRIS row missing for this RegistryNum — cannot transmit UI-only payload. Check Main DB connection.');
            }

            if (!isset($record['RegistryNum'])) {
                $record['RegistryNum'] = $record['registryNumber'] ?? $registryNum;
            }

            $parameters = $record;
            $parameters['MobileNumber'] = $mobileNumber ?: ($parameters['MobileNumber'] ?? '');
            $parameters['DateSend'] = $sendDate ?: date('Y-m-d H:i:s');
            $parameters['RemarksSend'] = $remarks;
            if ($deferAttachment) {
                $parameters['AttachFile'] = null;
            }
            // Match legacy eTransmission: MobileNumber / DateSend / RemarksSend / AttachFile.
            // ClientID and ClientEstablishmentName are NOT columns on online_birthdocument.
            unset(
                $parameters['ClientID'],
                $parameters['ClientEstablishmentName'],
                $parameters['clientId'],
                $parameters['clientEstablishmentName'],
                $parameters['attachments'],
                $parameters['ausfDetails'],
                $parameters['islamDetails'],
                $parameters['ipsDetails']
            );
            foreach (array_keys($parameters) as $k) {
                if (is_string($k) && $k !== '' && ctype_lower($k[0])) {
                    unset($parameters[$k]);
                }
            }

            if (!$simulationMode) {
                lcrSupportAuditLog(
                    $pdoSupport,
                    $sendAction . '_START',
                    "Type: {$type}, Mobile: {$mobileNumber}",
                    $registryNum,
                    $performedBy
                );
            }

            if ($simulationMode) {
                echo json_encode([
                    'success' => true,
                    'message' => "Record {$registryNum} simulated transmission (simulation mode enabled).",
                    'data' => [
                        'usedSlot' => 0,
                        'usedUrl' => $endpoints[0]['url'] ?? '',
                        'httpStatus' => 200,
                        'attempts' => [['slot' => 0, 'url' => $endpoints[0]['url'] ?? '', 'status' => 200, 'timeMs' => 50]],
                        'simulated' => true,
                        'attachmentIncluded' => !!$pdfData,
                        'attachmentSize' => $pdfData ? strlen($pdfData) : 0,
                    ],
                ]);
                break;
            }

            $lcrResult = lcrUploadRecord($endpoints, $clientToken, $type, $parameters, $pdfData);
            $supportResult = ['ok' => false, 'error' => ''];

            if (!$lcrResult['success']) {
                lcrSupportAuditLog(
                    $pdoSupport,
                    $sendAction . '_FAILED',
                    'SQL Server error: ' . ($lcrResult['message'] ?? 'LCR transmission failed'),
                    $registryNum,
                    $performedBy
                );
            } elseif ($pdoSupport && $lcrResult['success']) {
                $attachSize = ($pdfData && !$deferAttachment) ? strlen($pdfData) : 0;
                $attachType = $attachSize > 0 ? 'YES-DB' : 'NO';
                $supportResult = lcrWriteTransmissionSupportLog($pdoSupport, $type, [
                    'registryNum' => $registryNum,
                    'action' => $sendAction,
                    'mobileNumber' => $mobileNumber,
                    'pdfSize' => $attachSize,
                    'attachmentType' => $attachType,
                    'performedBy' => $performedBy,
                    'remarks' => $remarks,
                    'pdfData' => $deferAttachment ? null : $pdfData,
                    'auditUsername' => $performedBy,
                ]);
            } elseif ($lcrResult['success']) {
                $supportResult = [
                    'ok' => false,
                    'error' => $supportConnectError ?: 'Support DB not connected',
                ];
            }

            $attachOk = !empty($lcrResult['attachmentIncluded']);
            $msg = $lcrResult['success']
                ? ("Record {$registryNum} transmitted via Endpoint Slot {$lcrResult['usedSlot']}.")
                : ($lcrResult['message'] ?? 'Transmission failed');
            if ($lcrResult['success'] && empty($supportResult['ok']) && !empty($supportResult['error'])) {
                $msg .= ' Support DB warning: ' . $supportResult['error'];
            }
            if ($lcrResult['success'] && $pdfData && !$deferAttachment) {
                $msg .= $attachOk
                    ? (' PDF attachment stored on LCR (' . number_format(strlen($pdfData)) . ' bytes).')
                    : ' PDF was provided but AttachFile storage was not confirmed.';
            }

            echo json_encode([
                'success' => $lcrResult['success'],
                'message' => $msg,
                'data' => [
                    'usedSlot' => $lcrResult['usedSlot'],
                    'usedUrl' => $lcrResult['usedUrl'],
                    'httpStatus' => $lcrResult['httpStatus'],
                    'attempts' => $lcrResult['attempts'],
                    'clientId' => $clientId,
                    'clientEstablishmentName' => $clientEstablishmentName,
                    'simulated' => false,
                    'attachmentIncluded' => $attachOk,
                    'attachmentSize' => $pdfData ? strlen($pdfData) : 0,
                    'attachmentMode' => $lcrResult['attachmentMode'] ?? null,
                    'mysql_support' => $pdoSupport ? 'CONNECTED' : 'ERROR',
                    'support_database' => $pdoSupport ? $clientDbNameSupport : null,
                    'support_port_used' => $supportPortUsed,
                    'supportLogged' => !empty($supportResult['ok']),
                    'supportLogError' => ($supportResult['error'] ?? '') ?: null,
                    'deferSupportLog' => $deferAttachment && (bool)$pdoSupport,
                ],
            ]);
            break;

        case 'ping_endpoint':
            $endpointUrl = $_POST['endpoint_url'] ?? $_GET['endpoint_url'] ?? '';
            $clientToken = $_POST['client_token'] ?? $_GET['client_token'] ?? '';
            if (empty($endpointUrl)) {
                throw new Exception('Missing endpoint_url');
            }
            $probe = lcrProbeEndpoint($endpointUrl, $clientToken ?: 'ping');
            echo json_encode([
                'success' => true,
                'data' => [
                    'url' => $endpointUrl,
                    'status' => $probe['ok'] ? 'online' : ($probe['http'] >= 400 ? 'offline' : 'degraded'),
                    'latencyMs' => $probe['timeMs'],
                    'httpCode' => $probe['http'],
                    'error' => $probe['error'] ?: null,
                ],
            ]);
            break;

        case 'serve_attachment':
            // Legacy eTransmission: serve PDF from registry_*_log (BLOB or disk path)
            $registryNum = trim($_GET['registry_num'] ?? $_POST['registry_num'] ?? '');
            if ($registryNum === '') {
                throw new Exception('Missing registry_num');
            }
            if (!$pdoSupport) {
                throw new Exception('Support DB not connected — cannot serve attachment');
            }
            $logTable = lcrGetTableNames($type)['log'];
            $stmt = $pdoSupport->prepare(
                "SELECT Attachment, AttachmentType, AttachmentSize FROM {$logTable}
                 WHERE TRIM(RegistryNum) = ? LIMIT 1"
            );
            $stmt->execute([$registryNum]);
            $row = $stmt->fetch();
            if (!$row || empty($row['Attachment']) || ($row['AttachmentType'] ?? 'NO') === 'NO') {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'No attachment']);
                break;
            }
            $pdfData = $row['Attachment'];
            $attachType = (string)($row['AttachmentType'] ?? '');
            // YES-DISK stores filesystem path in Attachment column
            if ($attachType === 'YES-DISK' || (is_string($pdfData) && strlen($pdfData) < 1024 && is_file($pdfData))) {
                $path = is_string($pdfData) ? $pdfData : '';
                if ($path === '' || !is_readable($path)) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Attachment file missing on disk']);
                    break;
                }
                $pdfData = file_get_contents($path);
            }
            if (!is_string($pdfData) || strlen($pdfData) < 4) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Empty attachment']);
                break;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="attachment_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $registryNum) . '.pdf"');
            header('Content-Length: ' . strlen($pdfData));
            header('Cache-Control: private, max-age=300');
            echo $pdfData;
            exit;

        case 'export_statistics':
            // Lightweight CSV from main PhilCRIS for year filter (partner !% by default)
            if (!$pdoMain) {
                throw new Exception('Main MySQL not connected');
            }
            $year = preg_replace('/\D/', '', $_GET['year'] ?? $_POST['year'] ?? date('Y'));
            if (strlen($year) !== 4) {
                $year = date('Y');
            }
            $source = $_GET['source'] ?? $_POST['source'] ?? 'partner'; // partner | all
            $tables = lcrGetTableNames($type);
            $main = $tables['main'];
            $dateCol = $type === 'death' ? 'CDeathDate' : ($type === 'marriage' ? 'MarriageDate' : 'CBirthDate');
            $where = ["YEAR(`{$dateCol}`) = ?"];
            $params = [$year];
            if ($source === 'partner') {
                $where[] = "RegistryNum LIKE '!%'";
            }
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $nameCols = $type === 'marriage'
                ? 'RegistryNum, HLastName, HFirstName, WLastName, WFirstName, MarriageDate'
                : 'RegistryNum, CLastName, CFirstName, CMiddleName, ' . $dateCol;
            try {
                $stmt = $pdoMain->prepare("SELECT {$nameCols} FROM `{$main}` {$whereSql} ORDER BY RegistryNum DESC LIMIT 20000");
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Fallback without YEAR() if date column missing
                $stmt = $pdoMain->prepare("SELECT RegistryNum FROM `{$main}` WHERE RegistryNum LIKE '!%' ORDER BY RegistryNum DESC LIMIT 5000");
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Enrich with sent status when support DB available
            $rows = lcrEnrichRecordsWithSentStatus($pdoSupport, $type, $rows);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $type . '_statistics_' . $year . '.csv"');
            $out = fopen('php://output', 'w');
            if (!empty($rows)) {
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $r) {
                    fputcsv($out, $r);
                }
            } else {
                fputcsv($out, ['RegistryNum', 'note']);
                fputcsv($out, ['', 'No rows for year ' . $year]);
            }
            fclose($out);
            exit;

        default:
            echo json_encode([
                'success' => false,
                'message' => "Unknown action '{$action}'"
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
