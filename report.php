<?php
/**
 * PMES Universal DevExpress .repx Dynamic Reporting Gateway
 *
 * 1. Supports ANY .repx file in `Reports/` (auto-discovers templates, parameters, calculated fields, and DataMembers).
 * 2. Connects to the live DevExpress .repx service (NAS Docker port 18080 or live watcher port 18085)
 *    which natively executes `XtraReport.FromFile('<ReportName>.repx')`.
 * 3. Compiles exact filtered datasets (`ids`, `projectType`, `year`, `barangay`, `status`, `contractor`, `projectId`, `inspectionId`, `search`, or custom params)
 *    into the authoritative `.repx` template without HTML conversion.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action       = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : 'preview';
$rawName      = isset($_GET['name']) ? basename(trim($_GET['name'])) : 'Project_Summary_List.repx';
if ($rawName === '' || $rawName === '.') {
    $rawName = 'Project_Summary_List.repx';
}
if (strtolower(substr($rawName, -5)) !== '.repx') {
    $rawName .= '.repx';
}
$name         = $rawName;
$baseName     = pathinfo($name, PATHINFO_FILENAME);

$year         = isset($_GET['year']) ? trim($_GET['year']) : 'all';
$barangay     = isset($_GET['barangay']) ? trim($_GET['barangay']) : 'all';
$status       = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$projectType  = isset($_GET['projectType']) ? trim($_GET['projectType']) : 'all';
$contractor   = isset($_GET['contractor']) ? trim($_GET['contractor']) : 'all';
$projectId    = isset($_GET['projectId']) ? trim($_GET['projectId']) : '';
$inspectionId = isset($_GET['inspectionId']) ? trim($_GET['inspectionId']) : '';
$sortBy       = isset($_GET['sortBy']) ? trim($_GET['sortBy']) : '';
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$ids          = isset($_GET['ids']) ? trim($_GET['ids']) : '';

$reportsDir = realpath(__DIR__ . '/../Reports');
if (!$reportsDir) {
    $reportsDir = __DIR__ . '/../Reports';
}
$cacheDir = $reportsDir . '/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}

$candidateHosts = [
    'http://127.0.0.1:18080',
    'http://192.168.108.44:18080',
];

function fetchEngineEndpoint($baseUrl, $path, &$httpCode, &$contentType, $timeout = 10) {
    $url = $baseUrl . $path;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return $body;
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        $contentType = '';
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#i', $hdr, $m)) {
                    $httpCode = (int)$m[1];
                } elseif (stripos($hdr, 'Content-Type:') === 0) {
                    $contentType = trim(substr($hdr, 13));
                }
            }
        }
        return $body;
    }
}

function inspectRepxXml($filePath) {
    $fileName = basename($filePath);
    $bName = pathinfo($fileName, PATHINFO_FILENAME);
    $displayName = str_replace(['_', '-'], ' ', $bName);
    $dataMember = 'projects';
    $paperKind = 'Legal';
    $landscape = true;
    $parameters = [];
    $calculatedFields = [];

    if (file_exists($filePath)) {
        $xml = @simplexml_load_file($filePath);
        if ($xml !== false) {
            if (isset($xml['DataMember'])) $dataMember = (string)$xml['DataMember'];
            if (isset($xml['PaperKind'])) $paperKind = (string)$xml['PaperKind'];
            if (isset($xml['Landscape'])) $landscape = strtolower((string)$xml['Landscape']) === 'true';

            if (isset($xml->Parameters)) {
                foreach ($xml->Parameters->children() as $item) {
                    $parameters[] = [
                        'name'         => isset($item['Name']) ? (string)$item['Name'] : '',
                        'description'  => isset($item['Description']) ? (string)$item['Description'] : '',
                        'defaultValue' => isset($item['ValueInfo']) ? (string)$item['ValueInfo'] : '',
                        'visible'      => !isset($item['Visible']) || strtolower((string)$item['Visible']) !== 'false',
                    ];
                }
            }
            if (isset($xml->CalculatedFields)) {
                foreach ($xml->CalculatedFields->children() as $item) {
                    $calculatedFields[] = [
                        'name'        => isset($item['Name']) ? (string)$item['Name'] : '',
                        'displayName' => isset($item['DisplayName']) ? (string)$item['DisplayName'] : '',
                        'expression'  => isset($item['Expression']) ? (string)$item['Expression'] : '',
                        'dataMember'  => isset($item['DataMember']) ? (string)$item['DataMember'] : $dataMember,
                    ];
                }
            }
        }
    }

    return [
        'fileName'         => $fileName,
        'displayName'      => $displayName,
        'fullPath'         => $filePath,
        'dataMember'       => $dataMember,
        'paperKind'        => $paperKind,
        'landscape'        => $landscape,
        'parameters'       => $parameters,
        'calculatedFields' => $calculatedFields,
        'lastModified'     => file_exists($filePath) ? gmdate('Y-m-d\TH:i:s\Z', filemtime($filePath)) : gmdate('Y-m-d\TH:i:s\Z'),
        'sizeBytes'        => file_exists($filePath) ? filesize($filePath) : 0,
    ];
}

function sendReportHeaders($action, $filenamePrefix) {
    if ($action === 'preview') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filenamePrefix . '.pdf"');
    } elseif ($action === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filenamePrefix . '.pdf"');
    } elseif ($action === 'xlsx') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filenamePrefix . '.xlsx"');
    } elseif ($action === 'docx') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filenamePrefix . '.docx"');
    }
}

function clearReportCacheDir($dirPath, $onlyOlderThanSeconds = 0) {
    if (!is_dir($dirPath)) return 0;
    $deleted = 0;
    $now = time();
    $files = glob($dirPath . '/*') ?: [];
    foreach ($files as $f) {
        if (is_file($f)) {
            if ($onlyOlderThanSeconds <= 0 || ($now - @filemtime($f)) >= $onlyOlderThanSeconds) {
                if (@unlink($f)) {
                    $deleted++;
                }
            }
        }
    }
    return $deleted;
}

// =========================================================================
// ACTION: CLEAR CACHE & SAVED GENERATED REPORT FILES
// =========================================================================
if ($action === 'clear_cache') {
    header('Content-Type: application/json; charset=utf-8');
    $deletedLocal = clearReportCacheDir($cacheDir, 0);
    foreach ($candidateHosts as $host) {
        $code = 0;
        $ctype = '';
        fetchEngineEndpoint($host, '/api/reports/clear-cache', $code, $ctype, 1);
    }
    echo json_encode([
        'status'  => 'cleared',
        'deleted' => $deletedLocal,
    ]);
    exit;
}

// =========================================================================
// ACTION: LIST ALL .REPX TEMPLATES IN REPORTS/
// =========================================================================
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    foreach ($candidateHosts as $host) {
        $code = 0;
        $ctype = '';
        $res = fetchEngineEndpoint($host, '/api/reports/list', $code, $ctype, 1);
        if ($code === 200 && $res) {
            $json = @json_decode($res, true);
            if (is_array($json)) {
                echo json_encode($json);
                exit;
            }
        }
    }

    $files = glob($reportsDir . '/*.repx') ?: [];
    usort($files, function($a, $b) {
        $ba = basename($a);
        $bb = basename($b);
        if (strcasecmp($ba, 'Project_Summary_List.repx') === 0) return -1;
        if (strcasecmp($bb, 'Project_Summary_List.repx') === 0) return 1;
        return strcasecmp($ba, $bb);
    });

    $list = [];
    foreach ($files as $f) {
        $list[] = inspectRepxXml($f);
    }
    echo json_encode($list);
    exit;
}

// =========================================================================
// ACTION: CREATE / CLONE NEW .REPX TEMPLATE WITH PMESDB FIELD LIST
// =========================================================================
if ($action === 'create_template') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;
    $newName = isset($input['name']) ? basename(trim($input['name'])) : '';
    $baseTpl = isset($input['baseTemplate']) ? basename(trim($input['baseTemplate'])) : 'Project_Summary_List.repx';
    $dataMember = isset($input['dataMember']) ? trim($input['dataMember']) : 'projects';
    $reportTitle = isset($input['title']) ? trim($input['title']) : '';

    if ($newName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Template name is required.']);
        exit;
    }
    if (strtolower(substr($newName, -5)) !== '.repx') {
        $newName .= '.repx';
    }
    $newName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $newName);

    $sourcePath = $reportsDir . '/' . $baseTpl;
    if (!file_exists($sourcePath)) {
        $sourcePath = $reportsDir . '/Project_Summary_List.repx';
    }
    if (!file_exists($sourcePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Base .repx template not found in Reports/.']);
        exit;
    }

    $destPath = $reportsDir . '/' . $newName;
    if (file_exists($destPath)) {
        echo json_encode(inspectRepxXml($destPath));
        exit;
    }

    $xmlContent = file_get_contents($sourcePath);
    if ($dataMember !== '') {
        $xmlContent = preg_replace('/DataMember="[^"]*"/', 'DataMember="' . htmlspecialchars($dataMember, ENT_QUOTES) . '"', $xmlContent, 1);
    }
    if ($reportTitle !== '') {
        $xmlContent = preg_replace(
            '/(Name="label13"[^>]*Text=")[^"]*(")/',
            '${1}' . htmlspecialchars($reportTitle, ENT_QUOTES) . '${2}',
            $xmlContent,
            1
        );
    }

    @file_put_contents($destPath, $xmlContent);
    echo json_encode(inspectRepxXml($destPath));
    exit;
}

// =========================================================================
// ACTION: HEALTH CHECK
// =========================================================================
if ($action === 'health') {
    header('Content-Type: application/json; charset=utf-8');
    foreach ($candidateHosts as $host) {
        $code = 0;
        $ctype = '';
        $res = fetchEngineEndpoint($host, '/health?name=' . urlencode($name), $code, $ctype, 1);
        if ($code === 200 && $res) {
            $json = @json_decode($res, true);
            if (is_array($json) && isset($json['status']) && $json['status'] === 'healthy') {
                $json['mode'] = 'live-devexpress-engine';
                $json['endpoint'] = $host;
                echo json_encode($json);
                exit;
            }
        }
    }

    $repxFile = $reportsDir . '/' . $name;
    if (!file_exists($repxFile)) {
        $repxFile = $reportsDir . '/Project_Summary_List.repx';
    }
    if (file_exists($repxFile)) {
        $allTemplates = array_map('basename', glob($reportsDir . '/*.repx') ?: []);
        echo json_encode([
            'status'           => 'healthy',
            'mode'             => 'devexpress-repx-ready',
            'engine'           => 'DevExpress XtraReports .repx Dynamic Engine',
            'version'          => '23.2.3',
            'platform'         => 'Synology NAS (DevExpress v23.2)',
            'activeTemplate'   => $repxFile,
            'reportsDirectory' => $reportsDir,
            'lastModifiedUtc'  => gmdate('Y-m-d\TH:i:s\Z', filemtime($repxFile)),
            'reportsCount'     => count($allTemplates),
            'templates'        => $allTemplates,
        ]);
        exit;
    }

    http_response_code(503);
    echo json_encode([
        'status'  => 'awaiting_restart',
        'message' => 'DevExpress Docker container on port 18080 needs to be built/restarted in Synology Container Manager.'
    ]);
    exit;
}

// Collect all query parameters to pass through dynamically
$extraParams = $_GET;
unset($extraParams['action'], $extraParams['_t']);

$query = http_build_query(array_merge($extraParams, [
    'name'         => $name,
    'year'         => $year,
    'barangay'     => $barangay,
    'status'       => $status,
    'projectType'  => $projectType,
    'contractor'   => $contractor,
    'projectId'    => $projectId,
    'inspectionId' => $inspectionId,
    'sortBy'       => $sortBy,
    'search'       => $search,
    'ids'          => $ids,
]));

$endpointMap = [
    'preview' => '/api/reports/preview',
    'pdf'     => '/api/reports/export/pdf',
    'xlsx'    => '/api/reports/export/xlsx',
    'docx'    => '/api/reports/export/docx',
];
$targetEndpoint = isset($endpointMap[$action]) ? $endpointMap[$action] : '/api/reports/preview';

// 1. Try direct HTTP call to live DevExpress service (in-memory stream, no disk save)
foreach ($candidateHosts as $host) {
    $code = 0;
    $ctype = '';
    // Generous 90s timeout for multi-page photo rendering on large inspection reports
    $responseBody = fetchEngineEndpoint($host, $targetEndpoint . '?' . $query, $code, $ctype, 90);
    if ($code === 200 && $responseBody !== false && strlen($responseBody) > 100 && stripos($ctype, 'text/html') === false) {
        clearReportCacheDir($cacheDir, 0);
        sendReportHeaders($action, $baseName . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $year));
        header('Content-Length: ' . strlen($responseBody));
        echo $responseBody;
        exit;
    }
}

$extMap = [
    'preview' => 'pdf',
    'pdf'     => 'pdf',
    'xlsx'    => 'xlsx',
    'docx'    => 'docx',
];
$ext = isset($extMap[$action]) ? $extMap[$action] : 'pdf';

// 2. Compile via shared DevExpress queue, stream output to client, and IMMEDIATELY delete generated cache files
$repxFile = $reportsDir . '/' . $name;
$repxMtime = file_exists($repxFile) ? (string)filemtime($repxFile) : '0';
$hashPayload = json_encode($extraParams);
$uniqueToken = uniqid('', true);
$hash = md5('v6|' . $uniqueToken . '|' . $name . '|' . $repxMtime . '|' . $ext . '|' . $year . '|' . $barangay . '|' . $status . '|' . $projectType . '|' . $contractor . '|' . $projectId . '|' . $inspectionId . '|' . $sortBy . '|' . $search . '|' . $ids . '|' . $hashPayload);
$customFile = $cacheDir . '/custom_' . $hash . '.' . $ext;
$reqFile = $cacheDir . '/req_' . $hash . '.json';

$reqPayload = json_encode([
    'hash'         => $hash,
    'action'       => $action,
    'name'         => $name,
    'year'         => $year,
    'barangay'     => $barangay,
    'status'       => $status,
    'projectType'  => $projectType,
    'contractor'   => $contractor,
    'projectId'    => $projectId,
    'inspectionId' => $inspectionId,
    'sortBy'       => $sortBy,
    'search'       => $search,
    'ids'          => $ids,
    'params'       => $extraParams,
]);
@file_put_contents($reqFile, $reqPayload);

// Wait up to 6 seconds (polling every 100ms) for the live DevExpress .repx watcher to compile custom_<hash>.<ext>
$maxTries = 60;
for ($i = 0; $i < $maxTries; $i++) {
    clearstatcache(true, $customFile);
    if (file_exists($customFile) && filesize($customFile) > 100) {
        break;
    }
    usleep(100000);
}

if (file_exists($customFile) && filesize($customFile) > 100) {
    $fileBytes = @file_get_contents($customFile);
    // Immediately delete the generated file and any leftover cache files after reading into memory
    @unlink($customFile);
    @unlink($reqFile);
    clearReportCacheDir($cacheDir, 5);

    if ($fileBytes !== false) {
        sendReportHeaders($action, $baseName . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $year));
        header('Content-Length: ' . strlen($fileBytes));
        echo $fileBytes;
        exit;
    }
}

// Cleanup if failed or timed out
@unlink($reqFile);
@unlink($customFile);
clearReportCacheDir($cacheDir, 5);

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error'  => 'Report template or DevExpress output not found.',
    'report' => $name
]);
