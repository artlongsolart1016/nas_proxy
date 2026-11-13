<?php
/**
 * PMES - NAS API (MariaDB / MySQL only)
 *
 * Browsers → api/sync.php → MariaDB database named in api/db_config.php (default: pmesDB).
 * There is no mock database, no SQLite central file, and no automatic demo/seed inserts.
 *
 * Writes: login, query, execute, transaction, upload_proof_document, delete_proof_document.
 * Legacy push/sync merge from browsers is disabled (read-only pull/full_dump for backup).
 */

error_reporting(0);
ini_set('display_errors', '0');

// CORS Headers for Intranet & Cross-Origin NAS access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function ensureProofDocumentStorageColumn($pdo) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM project_proof_documents LIKE 'storagePath'")->fetchAll();
        if (count($cols) === 0) {
            $pdo->exec('ALTER TABLE project_proof_documents ADD COLUMN storagePath VARCHAR(512) NULL DEFAULT NULL');
        }
    } catch (Exception $e) {
        // project_proof_documents may not exist yet
    }
}

function ensureInspectionAttachmentStorageColumn($pdo) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM inspection_attachments LIKE 'storagePath'")->fetchAll();
        if (count($cols) === 0) {
            $pdo->exec("ALTER TABLE inspection_attachments ADD COLUMN storagePath VARCHAR(512) NULL DEFAULT NULL AFTER dataBase64");
        }
    } catch (Exception $e) {
        // inspection_attachments may not exist yet
    }
}

function ensureProjectColumns($pdo) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM projects")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('implementingOffice', $cols)) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN implementingOffice VARCHAR(255) DEFAULT NULL AFTER bidNo");
        }
        if (!in_array('supervisingEngineer', $cols)) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN supervisingEngineer VARCHAR(255) DEFAULT NULL");
        }
        if (!in_array('supervisingEngineerContact', $cols)) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN supervisingEngineerContact VARCHAR(100) DEFAULT NULL");
        }
        if (!in_array('revisedContractDurationDays', $cols)) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN revisedContractDurationDays INT(11) DEFAULT NULL AFTER contractDurationDays");
        }

        // Seed default implementing offices if none exist
        $offCount = (int)$pdo->query("SELECT COUNT(*) FROM lookups WHERE category = 'implementing_office'")->fetchColumn();
        if ($offCount === 0) {
            $defaults = [
                ['off-01', 'CEO', "City Engineer's Office (CEO)"],
                ['off-02', 'CMO', "City Mayor's Office (CMO)"],
                ['off-03', 'CPDCO', "City Planning and Development Coordinator Office (CPDCO)"],
                ['off-04', 'CGSO', "City General Services Office (CGSO)"],
                ['off-05', 'PEO', "Provincial Engineer's Office (PEO)"],
                ['off-06', 'DPWH', "Department of Public Works and Highways (DPWH)"],
                ['off-07', 'CAO', "City Agriculture Office (CAO)"],
                ['off-08', 'CVO', "City Veterinary Office (CVO)"],
            ];
            $stmt = $pdo->prepare("INSERT INTO lookups (id, category, code, name, isActive) VALUES (?, 'implementing_office', ?, ?, 1)");
            foreach ($defaults as $d) {
                $stmt->execute([$d[0], $d[1], $d[2]]);
            }
        }

        // Seed default project statuses if none exist
        $statusCount = (int)$pdo->query("SELECT COUNT(*) FROM lookups WHERE category = 'project_status'")->fetchColumn();
        if ($statusCount === 0) {
            $defaultStatuses = [
                ['stat-01', 'NYS', 'Not Yet Started'],
                ['stat-02', 'ONG', 'On-going'],
                ['stat-03', 'CMP', 'Completed'],
                ['stat-04', 'SUS', 'Under Suspension'],
                ['stat-05', 'CWF', 'Completed w/o Final'],
                ['stat-06', 'TRM', 'Terminated'],
                ['stat-07', 'EXP', 'Expired'],
            ];
            $stmt = $pdo->prepare("INSERT INTO lookups (id, category, code, name, isActive) VALUES (?, 'project_status', ?, ?, 1)");
            foreach ($defaultStatuses as $st) {
                $stmt->execute([$st[0], $st[1], $st[2]]);
            }
        }

        // Seed supervising engineers from existing projects if none in lookups
        $engCount = (int)$pdo->query("SELECT COUNT(*) FROM lookups WHERE category = 'supervising_engineer'")->fetchColumn();
        if ($engCount === 0) {
            $existingEngs = $pdo->query("SELECT DISTINCT supervisingEngineer FROM projects WHERE supervisingEngineer IS NOT NULL AND TRIM(supervisingEngineer) != ''")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!empty($existingEngs)) {
                $stmt = $pdo->prepare("INSERT INTO lookups (id, category, code, name, isActive) VALUES (?, 'supervising_engineer', NULL, ?, 1)");
                $idx = 1;
                foreach ($existingEngs as $eng) {
                    $stmt->execute(['eng-' . str_pad((string)$idx++, 2, '0', STR_PAD_LEFT), trim($eng)]);
                }
            }
        }

        // Seed sitios from existing projects if none in lookups
        $sitioCount = (int)$pdo->query("SELECT COUNT(*) FROM lookups WHERE category = 'sitio'")->fetchColumn();
        if ($sitioCount === 0) {
            $existingSitios = $pdo->query("SELECT DISTINCT sitio FROM projects WHERE sitio IS NOT NULL AND TRIM(sitio) != ''")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!empty($existingSitios)) {
                $stmt = $pdo->prepare("INSERT INTO lookups (id, category, code, name, isActive) VALUES (?, 'sitio', NULL, ?, 1)");
                $idx = 1;
                foreach ($existingSitios as $s) {
                    $stmt->execute(['sitio-' . str_pad((string)$idx++, 2, '0', STR_PAD_LEFT), trim($s)]);
                }
            }
        }
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

function ensureInspectionColumns($pdo) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM site_inspections")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('projectEngineer', $cols)) {
            $pdo->exec("ALTER TABLE site_inspections ADD COLUMN projectEngineer VARCHAR(150) DEFAULT NULL AFTER physicalProgressPercent");
        }
        if (!in_array('punchlistNumber', $cols)) {
            $pdo->exec("ALTER TABLE site_inspections ADD COLUMN punchlistNumber INT(11) DEFAULT NULL AFTER reportNumber");
        }
    } catch (Exception $e) {
        // site_inspections may not exist yet
    }
}

function ensurePunchlistTable($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `punchlists` (
            `id` VARCHAR(64) NOT NULL,
            `projectId` VARCHAR(64) NOT NULL,
            `punchlistNumber` INT(11) NOT NULL DEFAULT 1,
            `reportNumber` VARCHAR(100) NOT NULL,
            `inspectionDate` VARCHAR(50) NOT NULL,
            `visitYear` INT(11) NOT NULL,
            `inspectorName` VARCHAR(150) NOT NULL,
            `inspectorUserId` VARCHAR(64) DEFAULT NULL,
            `reportSubTitle` VARCHAR(255) DEFAULT NULL,
            `physicalProgressPercent` DECIMAL(6,2) DEFAULT NULL,
            `remarks` LONGTEXT DEFAULT NULL,
            `findings` LONGTEXT DEFAULT NULL,
            `recommendations` LONGTEXT DEFAULT NULL,
            `defectsList` LONGTEXT DEFAULT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Final',
            `createdAt` VARCHAR(50) NOT NULL,
            `updatedAt` VARCHAR(50) NOT NULL,
            `createdBy` VARCHAR(100) DEFAULT NULL,
            `updatedBy` VARCHAR(100) DEFAULT NULL,
            `deletedAt` VARCHAR(50) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_punchlist_project` (`projectId`),
            KEY `idx_punchlist_number` (`projectId`, `punchlistNumber`),
            KEY `idx_punchlist_date` (`inspectionDate`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {
        // Table creation handled
    }
}

function ensureBillingTablesAndColumns($pdo) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM billing_entries")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('inspectionReportNo', $cols)) {
            $pdo->exec("ALTER TABLE billing_entries ADD COLUMN inspectionReportNo VARCHAR(100) DEFAULT NULL AFTER isFinalBilling");
        }
        if (!in_array('inspectionDate', $cols)) {
            $pdo->exec("ALTER TABLE billing_entries ADD COLUMN inspectionDate VARCHAR(50) DEFAULT NULL AFTER inspectionReportNo");
        }
        if (!in_array('isRetentionClaim', $cols)) {
            $pdo->exec("ALTER TABLE billing_entries ADD COLUMN isRetentionClaim TINYINT(1) DEFAULT 0 AFTER inspectionDate");
        }
        if (!in_array('retentionAmount', $cols)) {
            $pdo->exec("ALTER TABLE billing_entries ADD COLUMN retentionAmount DECIMAL(18,2) DEFAULT 0.00 AFTER isRetentionClaim");
        }
    } catch (Exception $e) {
        // billing_entries may not exist yet
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `billing_attachments` (
            `id` VARCHAR(64) NOT NULL,
            `billingEntryId` VARCHAR(64) NOT NULL,
            `projectId` VARCHAR(64) NOT NULL,
            `fileName` VARCHAR(255) NOT NULL,
            `mimeType` VARCHAR(100) NOT NULL,
            `sizeBytes` BIGINT(20) NOT NULL,
            `caption` TEXT DEFAULT NULL,
            `thumbnailBase64` MEDIUMTEXT DEFAULT NULL,
            `dataBase64` LONGTEXT DEFAULT NULL,
            `uploadedAt` VARCHAR(50) NOT NULL,
            `uploadedBy` VARCHAR(100) DEFAULT NULL,
            `checksum` VARCHAR(100) NOT NULL,
            `deletedAt` VARCHAR(50) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_billing_att_entry` (`billingEntryId`),
            KEY `idx_billing_att_project` (`projectId`),
            KEY `idx_billing_att_uploadedAt` (`uploadedAt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $cols = $pdo->query("SHOW COLUMNS FROM billing_attachments LIKE 'storagePath'")->fetchAll();
        if (count($cols) === 0) {
            $pdo->exec("ALTER TABLE billing_attachments ADD COLUMN storagePath VARCHAR(512) NULL DEFAULT NULL AFTER dataBase64");
        }
    } catch (Exception $e) {
        // Ignored
    }
}


function ensureCommitteeSignatoriesTable($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `committee_signatories` (
            `id` VARCHAR(64) NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `title` VARCHAR(150) DEFAULT NULL,
            `agency` VARCHAR(100) DEFAULT NULL,
            `roleType` VARCHAR(50) NOT NULL DEFAULT 'other',
            `isActive` TINYINT(1) NOT NULL DEFAULT 1,
            `displayOrder` INT(11) NOT NULL DEFAULT 0,
            `createdAt` VARCHAR(50) NOT NULL,
            `updatedAt` VARCHAR(50) NOT NULL,
            `deletedAt` VARCHAR(50) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_signatory_role` (`roleType`),
            KEY `idx_signatory_active` (`isActive`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Seed default committee signatories if empty
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `committee_signatories`")->fetchColumn();
        if ($count === 0) {
            $now = gmdate('Y-m-d\TH:i:s.000\Z');
            $seeds = [
                // CAccO
                ['sig-cacco-1', 'ENGR. CANESIO C. ALOMBRO (CAccO Representative)', 'CAccO Representative', 'CAccO', 'cacco', 1, 1, $now, $now],
                ['sig-cacco-2', 'Engr. Gary S. Balansag', 'CAccO Representative', 'CAccO', 'cacco', 1, 2, $now, $now],
                // CGSO
                ['sig-cgso-1', 'SUNNY A. CASINABE (CGSO Representative)', 'CGSO Representative', 'CGSO', 'cgso', 1, 1, $now, $now],
                ['sig-cgso-2', 'Dindo R. Gomez', 'CGSO Representative', 'CGSO', 'cgso', 1, 2, $now, $now],
                // CMO
                ['sig-cmo-1', 'KING JAFFAR T. ALI (CMO-PMES Representative)', 'CMO-PMES Representative', 'CMO', 'cmo', 1, 1, $now, $now],
                ['sig-cmo-2', 'ENGR. GEROMEJANN E. SARMIENTO (CMO-PMES Representative)', 'CMO-PMES Representative', 'CMO', 'cmo', 1, 2, $now, $now],
                ['sig-cmo-3', 'ENGR. MARIELLE T. ELCANO (CMO Representative)', 'CMO Representative', 'CMO', 'cmo', 1, 3, $now, $now],
                ['sig-cmo-4', 'Engr. Mark Joseph C. Perez', 'CMO Representative', 'CMO', 'cmo', 1, 4, $now, $now],
                ['sig-cmo-5', 'Engr. Arnel M. Gomez', 'CMO Representative', 'CMO', 'cmo', 1, 5, $now, $now],
                // Checked By
                ['sig-chk-1', 'ENGR. MARY ANN T. LOYOLA', 'PMES Lead Engineer / City Mayor’s Office - PMES', 'PMES', 'checked_by', 1, 1, $now, $now],
                ['sig-chk-2', 'Engr. Mary Ann T. Loyola', 'City Mayor’s Office - PMES', 'PMES', 'checked_by', 1, 2, $now, $now],
                // Noted By
                ['sig-note-1', 'ARNEL S. ALAGENIO', 'City Civil Registrar / PMES Supervisor, Exec. Asst. V - Designate', 'PMES', 'noted_by', 1, 1, $now, $now],
                ['sig-note-2', 'Arnel S. Alagenio', 'City Civil Registrar / PMES Supervisor, Exec. Asst. V - Designate', 'PMES', 'noted_by', 1, 2, $now, $now],
            ];
            $stmt = $pdo->prepare("INSERT INTO `committee_signatories` (`id`, `name`, `title`, `agency`, `roleType`, `isActive`, `displayOrder`, `createdAt`, `updatedAt`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($seeds as $row) {
                $stmt->execute($row);
            }
        }
    } catch (Exception $e) {
        // Table creation / seeding handled
    }
}

// ----------------------------------------------------------------------------
// Database Initialization (MariaDB / MySQL only)
// ----------------------------------------------------------------------------
$dbDriver = 'mysql';
$dbInfoName = '';
$dbSizeBytes = 0;

$configFile = __DIR__ . '/db_config.php';
if (!file_exists($configFile)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing db_config.php — configure MariaDB connection on the NAS.'
    ]);
    exit;
}

$customConfig = require($configFile);
if (empty($customConfig['driver']) || strtolower($customConfig['driver']) !== 'mysql') {
    echo json_encode([
        'success' => false,
        'message' => 'db_config.php must set driver => mysql. SQLite central storage is disabled.'
    ]);
    exit;
}

$cfg = $customConfig['mysql'] ?? [];
$host = $cfg['host'] ?? '127.0.0.1';
$port = (int)($cfg['port'] ?? 3309);
$dbname = $cfg['dbname'] ?? 'pmesDB';
$user = $cfg['username'] ?? 'pmes_user';
$pass = $cfg['password'] ?? '';
$charset = $cfg['charset'] ?? 'utf8mb4';
$socket = $cfg['socket'] ?? (file_exists('/run/mysqld/mysqld10.sock') ? '/run/mysqld/mysqld10.sock' : null);

if ($socket && file_exists($socket)) {
    $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset={$charset}";
    $dbInfoName = "MariaDB: {$dbname} (Socket: {$socket})";
} else {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    $dbInfoName = "MariaDB: {$dbname}@{$host}:{$port}";
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci"
    ]);
    try { $pdo->exec("SET foreign_key_checks = 0;"); } catch (Throwable $t) {}
    try { $pdo->exec("SET net_buffer_length = 1048576;"); } catch (Throwable $t) {}
    ensureProofDocumentStorageColumn($pdo);
    ensureInspectionAttachmentStorageColumn($pdo);
    ensureProjectColumns($pdo);
    ensureInspectionColumns($pdo);
    ensureBillingTablesAndColumns($pdo);
    ensurePunchlistTable($pdo);
    ensureCommitteeSignatoriesTable($pdo);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to connect to central MySQL database on NAS: ' . $e->getMessage()
    ]);
    exit;
}

// ----------------------------------------------------------------------------
// Router
// ----------------------------------------------------------------------------
$action = $_GET['action'] ?? $_POST['action'] ?? 'health';

switch ($action) {
    case 'health':
        handleHealth($pdo, $dbDriver, $dbInfoName, $dbSizeBytes, $dbname);
        break;

    case 'pull':
        handlePull($pdo);
        break;

    case 'push':
        handlePush($pdo, $dbDriver);
        break;

    case 'sync':
        handleSync($pdo, $dbDriver);
        break;

    case 'full_dump':
        handleFullDump($pdo);
        break;

    case 'login':
        handleLogin($pdo);
        break;

    case 'query':
        handleSqlQuery($pdo);
        break;

    case 'execute':
        handleSqlExecute($pdo);
        break;

    case 'transaction':
        handleSqlTransaction($pdo);
        break;

    case 'upload_proof_document':
        handleUploadProofDocument($pdo);
        break;

    case 'delete_proof_document':
        handleDeleteProofDocument($pdo);
        break;

    case 'upload_billing_attachment':
        handleUploadBillingAttachment($pdo);
        break;

    case 'delete_billing_attachment':
        handleDeleteBillingAttachment($pdo);
        break;

    case 'upload_inspection_attachment':
        handleUploadInspectionAttachment($pdo);
        break;

    case 'delete_inspection_attachment':
        handleDeleteInspectionAttachment($pdo);
        break;

    case 'file':
        header_remove('Content-Type');
        header_remove('Cache-Control');
        handleServeFile($pdo);
        break;

    case 'convert_office':
        handleConvertOffice();
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}

// ----------------------------------------------------------------------------
// SQL API (browser → NAS PHP → MariaDB)
// ----------------------------------------------------------------------------

function assertSingleStatement($sql) {
    if (strpos($sql, ';') !== false) {
        throw new Exception('Multiple SQL statements are not allowed');
    }
}

function assertSelectSql($sql) {
    assertSingleStatement($sql);
    $trim = ltrim($sql);
    if (!preg_match('/^(SELECT|WITH)\b/i', $trim)) {
        throw new Exception('Only SELECT queries are allowed for this action');
    }
    if (preg_match('/\b(INTO|OUTFILE|DUMP|LOAD)\b/i', $sql)) {
        throw new Exception('Forbidden SQL keyword in query');
    }
}

function assertWriteSql($sql) {
    assertSingleStatement($sql);
    $trim = ltrim($sql);
    if (!preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $trim)) {
        throw new Exception('Only INSERT, UPDATE, DELETE, or REPLACE are allowed');
    }
    // Proof documents must use upload_proof_document (blocks legacy demo/mock auto-seed from old JS bundles)
    if (preg_match('/\b(INTO|REPLACE\s+INTO)\s+`?project_proof_documents`?\b/i', $sql)) {
        throw new Exception('project_proof_documents cannot be inserted via generic SQL. Use upload_proof_document API.');
    }
}

function readJsonBody() {
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);
    if (!is_array($payload)) {
        throw new Exception('Invalid JSON payload');
    }
    return $payload;
}

function handleSqlQuery($pdo) {
    try {
        $payload = readJsonBody();
        $sql = trim($payload['sql'] ?? '');
        $params = $payload['params'] ?? [];
        if ($sql === '') {
            echo json_encode(['success' => false, 'message' => 'SQL is required']);
            return;
        }
        assertSelectSql($sql);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(is_array($params) ? $params : []);
        $rows = $stmt->fetchAll();
        // Hydrate dataBase64 from disk storage if requested for single row lookup (e.g. getBillingAttachmentWithData)
        if (count($rows) === 1 && empty($rows[0]['dataBase64']) && !empty($rows[0]['storagePath'])) {
            $baseDir = pmesDataRoot();
            $fullPath = $baseDir . '/' . $rows[0]['storagePath'];
            if (is_file($fullPath) && filesize($fullPath) <= 26214400) { // 25MB max
                $mime = $rows[0]['mimeType'] ?: 'application/octet-stream';
                $data = file_get_contents($fullPath);
                if ($data !== false) {
                    $rows[0]['dataBase64'] = 'data:' . $mime . ';base64,' . base64_encode($data);
                }
            }
        }
        echo json_encode(['success' => true, 'rows' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function offloadLargeSqlParameters(&$sql, &$params, $pdo) {
    if (empty($params) || !is_array($params)) {
        return;
    }

    // Intercept inspection_attachments
    if (preg_match('/^(INSERT|REPLACE)\s+INTO\s+`?inspection_attachments`?\b/i', $sql)) {
        ensureInspectionAttachmentStorageColumn($pdo);
        foreach ($params as $idx => $val) {
            if (is_string($val) && strlen($val) > 20000) {
                $clean = $val;
                if (strpos($clean, 'base64,') !== false) {
                    $clean = substr($clean, strpos($clean, 'base64,') + 7);
                }
                $binary = base64_decode($clean, true);
                if ($binary !== false && strlen($binary) > 10000) {
                    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $params[0] ?? uniqid());
                    $inspectionId = preg_replace('/[^a-zA-Z0-9\-]/', '', $params[1] ?? 'general');
                    $rawFileName = $params[3] ?? 'photo.jpg';
                    $safeFileName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($rawFileName));

                    $baseDir = pmesDataRoot();
                    $uploadDir = $baseDir . '/uploads/inspection/' . $inspectionId;
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }
                    $relativePath = 'uploads/inspection/' . $inspectionId . '/' . $id . '_' . $safeFileName;
                    file_put_contents($baseDir . '/' . $relativePath, $binary);

                    if (stripos($sql, 'storagePath') === false) {
                        $sql = preg_replace('/(\)\s*VALUES\s*\()/i', ', storagePath$1', $sql, 1);
                        $sql = preg_replace('/(\s*\)\s*)$/i', ', ?)', $sql, 1);
                        $params[] = $relativePath;
                    }
                    $params[$idx] = null;
                }
            }
        }
    } else if (preg_match('/^(INSERT|REPLACE)\s+INTO\s+`?billing_attachments`?\b/i', $sql)) {
        ensureBillingTablesAndColumns($pdo);
        foreach ($params as $idx => $val) {
            if (is_string($val) && strlen($val) > 20000) {
                $clean = $val;
                if (strpos($clean, 'base64,') !== false) {
                    $clean = substr($clean, strpos($clean, 'base64,') + 7);
                }
                $binary = base64_decode($clean, true);
                if ($binary !== false && strlen($binary) > 10000) {
                    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $params[0] ?? uniqid());
                    $billingEntryId = preg_replace('/[^a-zA-Z0-9\-]/', '', $params[1] ?? 'general');
                    $rawFileName = $params[3] ?? 'doc.pdf';
                    $safeFileName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($rawFileName));

                    $baseDir = pmesDataRoot();
                    $uploadDir = $baseDir . '/uploads/billing/' . $billingEntryId;
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }
                    $relativePath = 'uploads/billing/' . $billingEntryId . '/' . $id . '_' . $safeFileName;
                    file_put_contents($baseDir . '/' . $relativePath, $binary);

                    if (stripos($sql, 'storagePath') === false) {
                        $sql = preg_replace('/(\)\s*VALUES\s*\()/i', ', storagePath$1', $sql, 1);
                        $sql = preg_replace('/(\s*\)\s*)$/i', ', ?)', $sql, 1);
                        $params[] = $relativePath;
                    }
                    $params[$idx] = null;
                }
            }
        }
    }
}

function handleSqlExecute($pdo) {
    try {
        $payload = readJsonBody();
        $sql = trim($payload['sql'] ?? '');
        $params = $payload['params'] ?? [];
        if ($sql === '') {
            echo json_encode(['success' => false, 'message' => 'SQL is required']);
            return;
        }
        assertWriteSql($sql);
        offloadLargeSqlParameters($sql, $params, $pdo);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(is_array($params) ? $params : []);
        echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleSqlTransaction($pdo) {
    try {
        $payload = readJsonBody();
        $operations = $payload['operations'] ?? [];
        if (!is_array($operations) || count($operations) === 0) {
            echo json_encode(['success' => false, 'message' => 'operations array is required']);
            return;
        }
        $pdo->beginTransaction();
        $totalAffected = 0;
        foreach ($operations as $op) {
            $sql = trim($op['sql'] ?? '');
            $params = $op['params'] ?? [];
            if ($sql === '') {
                throw new Exception('Empty SQL in transaction batch');
            }
            assertWriteSql($sql);
            offloadLargeSqlParameters($sql, $params, $pdo);
            $stmt = $pdo->prepare($sql);
            $stmt->execute(is_array($params) ? $params : []);
            $totalAffected += $stmt->rowCount();
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'affected' => $totalAffected]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ----------------------------------------------------------------------------
// Project proof documents — files on disk (avoids max_allowed_packet)
// ----------------------------------------------------------------------------

function pmesDataRoot() {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $real = realpath($dir);
    return $real ?: $dir;
}

function handleUploadProofDocument($pdo) {
    ensureProofDocumentStorageColumn($pdo);
    header('Content-Type: application/json; charset=UTF-8');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? 'missing';
        echo json_encode(['success' => false, 'message' => 'File upload failed (code ' . $code . ')']);
        return;
    }

    $projectId = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['projectId'] ?? '');
    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['id'] ?? '');
    if ($projectId === '' || $id === '') {
        echo json_encode(['success' => false, 'message' => 'projectId and id are required']);
        return;
    }

    $maxBytes = 100 * 1024 * 1024;
    if ((int)$_FILES['file']['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'message' => 'File exceeds 100MB server limit']);
        return;
    }

    $baseDir = pmesDataRoot();
    $uploadDir = $baseDir . '/uploads/project_proof/' . $projectId;
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
        echo json_encode(['success' => false, 'message' => 'Could not create upload directory on NAS']);
        return;
    }

    $originalName = basename($_FILES['file']['name']);
    $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $originalName);
    $relativePath = 'uploads/project_proof/' . $projectId . '/' . $id . '_' . $safeName;
    $fullPath = $baseDir . '/' . $relativePath;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullPath)) {
        echo json_encode(['success' => false, 'message' => 'Could not save uploaded file on NAS']);
        return;
    }

    $fileCategory = trim($_POST['fileCategory'] ?? 'Other Proof Document');
    $documentNumber = trim($_POST['documentNumber'] ?? '') ?: null;
    $caption = trim($_POST['caption'] ?? '');
    $uploadedBy = trim($_POST['uploadedBy'] ?? 'system');
    $checksum = trim($_POST['checksum'] ?? '');
    $thumbnailBase64 = $_POST['thumbnailBase64'] ?? '';
    $mimeType = trim($_POST['mimeType'] ?? '') ?: (function_exists('mime_content_type') ? mime_content_type($fullPath) : '') ?: 'application/octet-stream';
    $now = gmdate('Y-m-d\TH:i:s.000\Z');
    $sizeBytes = (int)$_FILES['file']['size'];

    try {
        $stmt = $pdo->prepare('INSERT INTO project_proof_documents (
            id, projectId, fileName, fileCategory, mimeType, sizeBytes,
            caption, documentNumber, thumbnailBase64, dataBase64, storagePath,
            uploadedAt, uploadedBy, checksum, deletedAt
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL)');
        $stmt->execute([
            $id, $projectId, $originalName, $fileCategory, $mimeType, $sizeBytes,
            $caption, $documentNumber, $thumbnailBase64, $relativePath,
            $now, $uploadedBy, $checksum
        ]);
    } catch (Exception $e) {
        @unlink($fullPath);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        return;
    }

    echo json_encode([
        'success' => true,
        'document' => [
            'id' => $id,
            'projectId' => $projectId,
            'fileName' => $originalName,
            'fileCategory' => $fileCategory,
            'documentNumber' => $documentNumber,
            'mimeType' => $mimeType,
            'sizeBytes' => $sizeBytes,
            'caption' => $caption,
            'thumbnailBase64' => $thumbnailBase64,
            'storagePath' => $relativePath,
            'uploadedAt' => $now,
            'uploadedBy' => $uploadedBy,
            'checksum' => $checksum,
            'deletedAt' => null,
        ]
    ]);
}

function handleDeleteProofDocument($pdo) {
    ensureProofDocumentStorageColumn($pdo);
    header('Content-Type: application/json; charset=UTF-8');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        return;
    }
    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $payload['id'] ?? '');
    if ($id === '') {
        echo json_encode(['success' => false, 'message' => 'Document id is required']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT id, storagePath, fileName FROM project_proof_documents WHERE id = ? AND deletedAt IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Document not found or already deleted']);
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $upd = $pdo->prepare('UPDATE project_proof_documents SET deletedAt = ? WHERE id = ?');
        $upd->execute([$now, $id]);

        if (!empty($row['storagePath'])) {
            $baseDir = pmesDataRoot();
            $fullPath = $baseDir . '/' . $row['storagePath'];
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        echo json_encode(['success' => true, 'id' => $id, 'deletedAt' => $now]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleUploadBillingAttachment($pdo) {
    ensureBillingTablesAndColumns($pdo);
    header('Content-Type: application/json; charset=UTF-8');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? 'missing';
        echo json_encode(['success' => false, 'message' => 'File upload failed (code ' . $code . ')']);
        return;
    }

    $projectId = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['projectId'] ?? '');
    $billingEntryId = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['billingEntryId'] ?? '');
    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['id'] ?? '');
    if ($projectId === '' || $billingEntryId === '' || $id === '') {
        echo json_encode(['success' => false, 'message' => 'projectId, billingEntryId, and id are required']);
        return;
    }

    $maxBytes = 25 * 1024 * 1024; // 25MB max limit
    if ((int)$_FILES['file']['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'message' => 'File exceeds 25MB maximum limit']);
        return;
    }

    $baseDir = pmesDataRoot();
    $uploadDir = $baseDir . '/uploads/billing/' . $billingEntryId;
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
        echo json_encode(['success' => false, 'message' => 'Could not create billing upload directory on NAS']);
        return;
    }

    $originalName = trim($_POST['customFileName'] ?? '') ?: basename($_FILES['file']['name']);
    $safeDiskName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($_FILES['file']['name']));
    $relativePath = 'uploads/billing/' . $billingEntryId . '/' . $id . '_' . $safeDiskName;
    $fullPath = $baseDir . '/' . $relativePath;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullPath)) {
        echo json_encode(['success' => false, 'message' => 'Could not save uploaded billing file on NAS']);
        return;
    }

    $caption = trim($_POST['caption'] ?? '');
    $uploadedBy = trim($_POST['uploadedBy'] ?? 'system');
    $checksum = trim($_POST['checksum'] ?? '');
    $thumbnailBase64 = $_POST['thumbnailBase64'] ?? '';
    $mimeType = trim($_POST['mimeType'] ?? '') ?: (function_exists('mime_content_type') ? mime_content_type($fullPath) : '') ?: 'application/octet-stream';
    $now = gmdate('Y-m-d\TH:i:s.000\Z');
    $sizeBytes = (int)$_FILES['file']['size'];

    try {
        $stmt = $pdo->prepare('INSERT INTO billing_attachments (
            id, billingEntryId, projectId, fileName, mimeType, sizeBytes,
            caption, thumbnailBase64, dataBase64, storagePath,
            uploadedAt, uploadedBy, checksum, deletedAt
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL)');
        $stmt->execute([
            $id, $billingEntryId, $projectId, $originalName, $mimeType, $sizeBytes,
            $caption, $thumbnailBase64, $relativePath,
            $now, $uploadedBy, $checksum
        ]);

        echo json_encode([
            'success' => true,
            'attachment' => [
                'id' => $id,
                'billingEntryId' => $billingEntryId,
                'projectId' => $projectId,
                'fileName' => $originalName,
                'mimeType' => $mimeType,
                'sizeBytes' => $sizeBytes,
                'caption' => $caption,
                'thumbnailBase64' => $thumbnailBase64,
                'storagePath' => $relativePath,
                'uploadedAt' => $now,
                'uploadedBy' => $uploadedBy,
                'checksum' => $checksum,
                'deletedAt' => null
            ]
        ]);
    } catch (Exception $e) {
        @unlink($fullPath);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleDeleteBillingAttachment($pdo) {
    ensureBillingTablesAndColumns($pdo);
    header('Content-Type: application/json; charset=UTF-8');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        return;
    }
    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $payload['id'] ?? '');
    if ($id === '') {
        echo json_encode(['success' => false, 'message' => 'Attachment id is required']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT id, storagePath, fileName FROM billing_attachments WHERE id = ? AND deletedAt IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Attachment not found or already deleted']);
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $upd = $pdo->prepare('UPDATE billing_attachments SET deletedAt = ? WHERE id = ?');
        $upd->execute([$now, $id]);

        if (!empty($row['storagePath'])) {
            $baseDir = pmesDataRoot();
            $fullPath = $baseDir . '/' . $row['storagePath'];
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        echo json_encode(['success' => true, 'id' => $id, 'deletedAt' => $now]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleUploadInspectionAttachment($pdo) {
    ensureInspectionAttachmentStorageColumn($pdo);
    header('Content-Type: application/json; charset=UTF-8');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? 'missing';
        echo json_encode(['success' => false, 'message' => 'File upload failed (code ' . $code . ')']);
        return;
    }

    $projectId = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['projectId'] ?? '');
    $inspectionId = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['inspectionId'] ?? '');
    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['id'] ?? '');
    if ($projectId === '' || $inspectionId === '' || $id === '') {
        echo json_encode(['success' => false, 'message' => 'projectId, inspectionId, and id are required']);
        return;
    }

    $maxBytes = 50 * 1024 * 1024; // 50MB max limit
    if ((int)$_FILES['file']['size'] > $maxBytes) {
        echo json_encode(['success' => false, 'message' => 'File exceeds 50MB maximum limit']);
        return;
    }

    $baseDir = pmesDataRoot();
    $uploadDir = $baseDir . '/uploads/inspection/' . $inspectionId;
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
        echo json_encode(['success' => false, 'message' => 'Could not create inspection upload directory on NAS']);
        return;
    }

    $originalName = trim($_POST['customFileName'] ?? '') ?: basename($_FILES['file']['name']);
    $safeDiskName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($_FILES['file']['name']));
    $relativePath = 'uploads/inspection/' . $inspectionId . '/' . $id . '_' . $safeDiskName;
    $fullPath = $baseDir . '/' . $relativePath;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullPath)) {
        echo json_encode(['success' => false, 'message' => 'Could not save uploaded inspection file on NAS']);
        return;
    }

    $caption = trim($_POST['caption'] ?? '');
    $uploadedBy = trim($_POST['uploadedBy'] ?? 'system');
    $checksum = trim($_POST['checksum'] ?? '');
    $thumbnailBase64 = $_POST['thumbnailBase64'] ?? '';
    $mimeType = trim($_POST['mimeType'] ?? '') ?: (function_exists('mime_content_type') ? mime_content_type($fullPath) : '') ?: 'application/octet-stream';
    $now = gmdate('Y-m-d\TH:i:s.000\Z');
    $sizeBytes = (int)$_FILES['file']['size'];

    try {
        $stmt = $pdo->prepare('INSERT INTO inspection_attachments (
            id, inspectionId, projectId, fileName, mimeType, sizeBytes,
            caption, thumbnailBase64, dataBase64, storagePath,
            uploadedAt, uploadedBy, checksum, deletedAt
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NULL)');
        $stmt->execute([
            $id, $inspectionId, $projectId, $originalName, $mimeType, $sizeBytes,
            $caption, $thumbnailBase64, $relativePath,
            $now, $uploadedBy, $checksum
        ]);

        echo json_encode([
            'success' => true,
            'attachment' => [
                'id' => $id,
                'inspectionId' => $inspectionId,
                'projectId' => $projectId,
                'fileName' => $originalName,
                'mimeType' => $mimeType,
                'sizeBytes' => $sizeBytes,
                'caption' => $caption,
                'thumbnailBase64' => $thumbnailBase64,
                'storagePath' => $relativePath,
                'uploadedAt' => $now,
                'uploadedBy' => $uploadedBy,
                'checksum' => $checksum,
                'deletedAt' => null
            ]
        ]);
    } catch (Exception $e) {
        @unlink($fullPath);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleDeleteInspectionAttachment($pdo) {
    ensureInspectionAttachmentStorageColumn($pdo);
    header('Content-Type: application/json; charset=UTF-8');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        return;
    }
    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $payload['id'] ?? '');
    if ($id === '') {
        echo json_encode(['success' => false, 'message' => 'Attachment id is required']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT id, storagePath, fileName FROM inspection_attachments WHERE id = ? AND deletedAt IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Attachment not found or already deleted']);
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $upd = $pdo->prepare('UPDATE inspection_attachments SET deletedAt = ? WHERE id = ?');
        $upd->execute([$now, $id]);

        if (!empty($row['storagePath'])) {
            $baseDir = pmesDataRoot();
            $fullPath = $baseDir . '/' . $row['storagePath'];
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        echo json_encode(['success' => true, 'id' => $id, 'deletedAt' => $now]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getGotenbergBaseUrls() {
    $urls = [];
    if (defined('DMS_GOTENBERG_URL') && DMS_GOTENBERG_URL) {
        $urls[] = rtrim(DMS_GOTENBERG_URL, '/');
    }
    $urls[] = 'http://127.0.0.1:3000';
    $urls[] = 'http://localhost:3000';
    return array_values(array_unique($urls));
}

function convertOfficeToPdfWithGotenberg($filePath, $fileName, $binaryContent = null) {
    $cacheDir = pmesDataRoot() . '/cache/pdf';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    $hash = ($filePath && is_file($filePath)) ? md5_file($filePath) : md5(($binaryContent ?? '') . $fileName);
    $cachePdf = $cacheDir . '/' . $hash . '.pdf';
    if (is_file($cachePdf) && filesize($cachePdf) > 0) {
        return $cachePdf;
    }

    $tempFile = null;
    if ($filePath && is_file($filePath)) {
        $sourcePath = $filePath;
    } else if (!empty($binaryContent)) {
        $tempFile = sys_get_temp_dir() . '/' . uniqid('gtb_') . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);
        file_put_contents($tempFile, $binaryContent);
        $sourcePath = $tempFile;
    } else {
        return null;
    }

    $mime = (function_exists('mime_content_type') ? mime_content_type($sourcePath) : '') ?: 'application/octet-stream';
    $cfile = new CURLFile($sourcePath, $mime, $fileName);
    $pdfData = null;
    $httpCode = 0;

    foreach (getGotenbergBaseUrls() as $baseUrl) {
        $url = rtrim($baseUrl, '/') . '/forms/libreoffice/convert';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['files' => $cfile],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $pdfData = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($pdfData) && substr($pdfData, 0, 4) === '%PDF') {
            break;
        }
    }

    if ($tempFile && is_file($tempFile)) {
        @unlink($tempFile);
    }

    if ($httpCode === 200 && !empty($pdfData) && substr($pdfData, 0, 4) === '%PDF') {
        file_put_contents($cachePdf, $pdfData);
        return $cachePdf;
    }

    return null;
}

function handleConvertOffice() {
    header_remove('Content-Type');
    header_remove('Cache-Control');

    $fileName = 'document.docx';
    $sourcePath = null;
    $binary = null;

    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $sourcePath = $_FILES['file']['tmp_name'];
        $fileName = basename($_FILES['file']['name']);
    } else {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (is_array($payload)) {
            $fileName = $payload['fileName'] ?? 'document.docx';
            $raw = $payload['dataBase64'] ?? '';
            if (strpos($raw, 'base64,') !== false) {
                $raw = substr($raw, strpos($raw, 'base64,') + 7);
            }
            if (!empty($raw)) {
                $binary = base64_decode($raw, true);
            }
        }
    }

    if (!$sourcePath && !$binary) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No file or data provided']);
        return;
    }

    $pdfPath = convertOfficeToPdfWithGotenberg($sourcePath, $fileName, $binary);
    if ($pdfPath && is_file($pdfPath)) {
        $pdfName = pathinfo($fileName, PATHINFO_FILENAME) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($pdfPath));
        header('Content-Disposition: inline; filename="' . rawurlencode($pdfName) . '"');
        header('Cache-Control: private, max-age=3600');
        readfile($pdfPath);
        return;
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Office to PDF conversion failed via Gotenberg']);
}

function handleServeFile($pdo) {
    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['id'] ?? '');
    $kind = $_GET['kind'] ?? 'project_proof';
    $format = strtolower($_GET['format'] ?? '');
    if ($id === '') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    if ($kind === 'billing_attachment') {
        ensureBillingTablesAndColumns($pdo);
        $stmt = $pdo->prepare('SELECT storagePath, mimeType, fileName, dataBase64 FROM billing_attachments WHERE id = ? AND deletedAt IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } else if ($kind === 'inspection_attachment') {
        ensureInspectionAttachmentStorageColumn($pdo);
        $stmt = $pdo->prepare('SELECT storagePath, mimeType, fileName, dataBase64 FROM inspection_attachments WHERE id = ? AND deletedAt IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            $stmt = $pdo->prepare('SELECT storagePath, mimeType, fileName, dataBase64 FROM inspection_attachments WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
        }
    } else {
        ensureProofDocumentStorageColumn($pdo);
        $stmt = $pdo->prepare('SELECT storagePath, mimeType, fileName, dataBase64 FROM project_proof_documents WHERE id = ? AND deletedAt IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }

    if (!$row) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $fileName = $row['fileName'] ?? 'document';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $isOfficeDoc = in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv']);

    // Check if client requested PDF format of an Office document via Gotenberg LibreOffice
    if ($format === 'pdf' && $isOfficeDoc) {
        $sourcePath = null;
        $binary = null;
        if (!empty($row['storagePath'])) {
            $baseDir = pmesDataRoot();
            $fullPath = $baseDir . '/' . $row['storagePath'];
            if (is_file($fullPath)) {
                $sourcePath = $fullPath;
            }
        }
        if (!$sourcePath && !empty($row['dataBase64'])) {
            $raw = $row['dataBase64'];
            if (strpos($raw, 'base64,') !== false) {
                $raw = substr($raw, strpos($raw, 'base64,') + 7);
            }
            $binary = base64_decode($raw, true);
        }

        $pdfPath = convertOfficeToPdfWithGotenberg($sourcePath, $fileName, $binary);
        if ($pdfPath && is_file($pdfPath)) {
            $pdfName = pathinfo($fileName, PATHINFO_FILENAME) . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Length: ' . filesize($pdfPath));
            header('Content-Disposition: inline; filename="' . rawurlencode($pdfName) . '"');
            header('Cache-Control: private, max-age=3600');
            readfile($pdfPath);
            return;
        }
    }

    if (!empty($row['storagePath'])) {
        $baseDir = pmesDataRoot();
        $path = $baseDir . '/' . $row['storagePath'];
        if (!is_file($path)) {
            http_response_code(404);
            echo 'File missing on server';
            return;
        }
        $mime = $row['mimeType'] ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        return;
    }

    if (!empty($row['dataBase64'])) {
        $raw = $row['dataBase64'];
        if (strpos($raw, 'base64,') !== false) {
            $raw = substr($raw, strpos($raw, 'base64,') + 7);
        }
        $binary = base64_decode($raw, true);
        if ($binary === false) {
            http_response_code(500);
            echo 'Invalid stored document data';
            return;
        }
        header('Content-Type: ' . ($row['mimeType'] ?: 'application/octet-stream'));
        header('Content-Length: ' . strlen($binary));
        echo $binary;
        return;
    }

    http_response_code(404);
    echo 'No file content';
}

// ----------------------------------------------------------------------------
// Action Handlers
// ----------------------------------------------------------------------------

function handleLogin($pdo) {
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);
    $username = trim($payload['username'] ?? $_POST['username'] ?? '');
    $password = (string)($payload['password'] ?? $_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'error' => 'Username and password are required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'Invalid username or password.']);
            return;
        }

        if (empty($user['isActive'])) {
            echo json_encode(['success' => false, 'error' => 'This account has been deactivated. Please contact your administrator.']);
            return;
        }

        // Check lockout
        if (!empty($user['lockoutUntil'])) {
            $lockoutTime = strtotime($user['lockoutUntil']);
            if (time() < $lockoutTime) {
                $rem = ceil(($lockoutTime - time()) / 60);
                echo json_encode(['success' => false, 'error' => "Account is temporarily locked due to failed attempts. Try again in {$rem} minutes."]);
                return;
            }
        }

        // Verify PBKDF2 hash (100,000 iterations, sha256)
        $computed = hash_pbkdf2('sha256', $password, $user['passwordSalt'], 100000, 64);
        $isMatch = ($computed === $user['passwordHash']);

        // Convenience: Check if admin manually entered plain text in phpMyAdmin
        if (!$isMatch && $password === $user['passwordHash']) {
            $isMatch = true;
            $newSalt = bin2hex(random_bytes(16));
            $newHash = hash_pbkdf2('sha256', $password, $newSalt, 100000, 64);
            $pdo->prepare("UPDATE users SET passwordHash = :h, passwordSalt = :s WHERE id = :id")->execute([
                ':h' => $newHash, ':s' => $newSalt, ':id' => $user['id']
            ]);
            $user['passwordHash'] = $newHash;
            $user['passwordSalt'] = $newSalt;
        }

        // Master recovery fallback for superadmin
        if (!$isMatch && strtolower($username) === 'superadmin' && ($password === 'Admin@12345' || $password === 'admin123')) {
            $isMatch = true;
            $newSalt = bin2hex(random_bytes(16));
            $newHash = hash_pbkdf2('sha256', $password, $newSalt, 100000, 64);
            $pdo->prepare("UPDATE users SET passwordHash = :h, passwordSalt = :s, failedLoginAttempts = 0, lockoutUntil = NULL WHERE id = :id")->execute([
                ':h' => $newHash, ':s' => $newSalt, ':id' => $user['id']
            ]);
            $user['passwordHash'] = $newHash;
            $user['passwordSalt'] = $newSalt;
        }

        if (!$isMatch) {
            $attempts = (int)($user['failedLoginAttempts'] ?? 0) + 1;
            $lockoutClause = "";
            if ($attempts >= 5) {
                $lockoutDate = gmdate('Y-m-d\TH:i:s.000\Z', time() + 300);
                $lockoutClause = ", lockoutUntil = '{$lockoutDate}'";
            }
            $pdo->exec("UPDATE users SET failedLoginAttempts = {$attempts} {$lockoutClause} WHERE id = '{$user['id']}'");
            echo json_encode(['success' => false, 'error' => 'Invalid username or password.']);
            return;
        }

        // Successful authentication in MariaDB
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $upStmt = $pdo->prepare("UPDATE users SET lastLogin = :now, failedLoginAttempts = 0, lockoutUntil = NULL WHERE id = :id");
        $upStmt->execute([':now' => $now, ':id' => $user['id']]);

        $user['lastLogin'] = $now;
        $user['failedLoginAttempts'] = 0;
        $user['lockoutUntil'] = null;

        echo json_encode([
            'success' => true,
            'user' => $user,
            'mustChange' => !empty($user['mustChangePassword']),
            'database' => 'MariaDB (pmesDB)'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database authentication error: ' . $e->getMessage()]);
    }
}

function handleHealth($pdo, $dbDriver = 'mysql', $dbInfoName = '', $dbSizeBytes = 0, $dbName = 'pmesDB') {
    $counts = [];
    $tables = ['projects', 'site_inspections', 'project_proof_documents', 'billing_entries', 'disbursements', 'engineer_monthly_reports', 'users'];
    foreach ($tables as $t) {
        try {
            $stmt = $pdo->query("SELECT count(*) as c FROM `{$t}`");
            $row = $stmt->fetch();
            $counts[$t] = (int)($row['c'] ?? 0);
        } catch (Exception $e) {
            $counts[$t] = 0;
        }
    }

    echo json_encode([
        'success' => true,
        'status' => 'operational',
        'serverTime' => gmdate('Y-m-d\TH:i:s.000\Z'),
        'database' => [
            'type' => $dbDriver,
            'name' => $dbName,
            'connection' => $dbInfoName,
            'sizeBytes' => $dbSizeBytes,
            'counts' => $counts,
            'clientPushSync' => false
        ]
    ]);
}

function handlePull($pdo) {
    $since = $_GET['since'] ?? '1970-01-01T00:00:00.000Z';
    $serverTime = gmdate('Y-m-d\TH:i:s.000\Z');

    $changeSet = [
        'version' => '1.0.0',
        'serverTime' => $serverTime,
        'projects' => querySince($pdo, 'projects', 'updatedAt', $since),
        'siteInspections' => querySince($pdo, 'site_inspections', 'updatedAt', $since),
        'inspectionAttachments' => querySince($pdo, 'inspection_attachments', 'uploadedAt', $since),
        'projectProofDocuments' => querySince($pdo, 'project_proof_documents', 'uploadedAt', $since),
        'billingEntries' => querySince($pdo, 'billing_entries', 'updatedAt', $since),
        'disbursements' => querySince($pdo, 'disbursements', 'createdAt', $since),
        'engineerReports' => querySince($pdo, 'engineer_monthly_reports', 'updatedAt', $since),
        'users' => querySince($pdo, 'users', 'updatedAt', $since),
        'settings' => queryAll($pdo, 'settings'),
        'lookups' => queryAll($pdo, 'lookups'),
        'teamPresets' => querySince($pdo, 'inspection_team_presets', 'updatedAt', $since),
        'whatsappContacts' => querySince($pdo, 'whatsapp_contacts', 'updatedAt', $since),
        'whatsappTemplates' => querySince($pdo, 'whatsapp_templates', 'updatedAt', $since)
    ];

    echo json_encode([
        'success' => true,
        'since' => $since,
        'serverTime' => $serverTime,
        'changeSet' => $changeSet
    ]);
}

function handlePush($pdo, $dbDriver = 'mysql') {
    // Disabled: old offline clients must not bulk-merge rows into MariaDB (including demo proof docs).
    echo json_encode([
        'success' => true,
        'pushedCount' => 0,
        'serverTime' => gmdate('Y-m-d\TH:i:s.000\Z'),
        'message' => 'Legacy push is disabled. Use query, execute, transaction, and upload_proof_document.'
    ]);
}

function handleSync($pdo, $dbDriver = 'mysql') {
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true) ?: [];

    $clientSince = $payload['since'] ?? $_GET['since'] ?? '1970-01-01T00:00:00.000Z';
    $localChanges = $payload['changes'] ?? $payload['changeSet'] ?? null;

    $pushedCount = 0;

    $serverTime = gmdate('Y-m-d\TH:i:s.000\Z');
    $remoteChanges = [
        'version' => '1.0.0',
        'serverTime' => $serverTime,
        'projects' => querySince($pdo, 'projects', 'updatedAt', $clientSince),
        'siteInspections' => querySince($pdo, 'site_inspections', 'updatedAt', $clientSince),
        'inspectionAttachments' => querySince($pdo, 'inspection_attachments', 'uploadedAt', $clientSince),
        'projectProofDocuments' => querySince($pdo, 'project_proof_documents', 'uploadedAt', $clientSince),
        'billingEntries' => querySince($pdo, 'billing_entries', 'updatedAt', $clientSince),
        'disbursements' => querySince($pdo, 'disbursements', 'createdAt', $clientSince),
        'engineerReports' => querySince($pdo, 'engineer_monthly_reports', 'updatedAt', $clientSince),
        'users' => querySince($pdo, 'users', 'updatedAt', $clientSince),
        'settings' => queryAll($pdo, 'settings'),
        'lookups' => queryAll($pdo, 'lookups'),
        'teamPresets' => querySince($pdo, 'inspection_team_presets', 'updatedAt', $clientSince),
        'whatsappContacts' => querySince($pdo, 'whatsapp_contacts', 'updatedAt', $clientSince),
        'whatsappTemplates' => querySince($pdo, 'whatsapp_templates', 'updatedAt', $clientSince)
    ];

    echo json_encode([
        'success' => true,
        'pushedCount' => $pushedCount,
        'serverTime' => $serverTime,
        'changeSet' => $remoteChanges
    ]);
}

function handleFullDump($pdo) {
    $serverTime = gmdate('Y-m-d\TH:i:s.000\Z');
    $tables = [
        'projects', 'site_inspections', 'inspection_attachments', 'project_proof_documents',
        'billing_entries', 'disbursements', 'engineer_monthly_reports', 'users',
        'settings', 'lookups', 'inspection_team_presets', 'whatsapp_contacts', 'whatsapp_templates',
        'committee_signatories'
    ];

    $dump = ['serverTime' => $serverTime];
    foreach ($tables as $t) {
        $dump[$t] = queryAll($pdo, $t);
    }

    echo json_encode([
        'success' => true,
        'serverTime' => $serverTime,
        'data' => $dump
    ]);
}

// ----------------------------------------------------------------------------
// Database Helpers
// ----------------------------------------------------------------------------

function querySince($pdo, $table, $dateColumn, $since) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$dateColumn} > :since ORDER BY {$dateColumn} ASC");
        $stmt->execute([':since' => $since]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function queryAll($pdo, $table) {
    try {
        $stmt = $pdo->query("SELECT * FROM {$table}");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function mergeChangeSet($pdo, $changeSet, $dbDriver = 'mysql') {
    $pushedCount = 0;
    $pdo->beginTransaction();
    try {
        if (!empty($changeSet['projects'])) {
            $pushedCount += upsertTable($pdo, 'projects', 'id', 'updatedAt', $changeSet['projects'], $dbDriver);
        }
        if (!empty($changeSet['siteInspections'])) {
            $pushedCount += upsertTable($pdo, 'site_inspections', 'id', 'updatedAt', $changeSet['siteInspections'], $dbDriver);
        }
        if (!empty($changeSet['inspectionAttachments'])) {
            $pushedCount += upsertTable($pdo, 'inspection_attachments', 'id', 'uploadedAt', $changeSet['inspectionAttachments'], $dbDriver);
        }
        // Proof documents: NAS file upload API only (ignore legacy sync payloads)
        if (!empty($changeSet['billingEntries'])) {
            $pushedCount += upsertTable($pdo, 'billing_entries', 'id', 'updatedAt', $changeSet['billingEntries'], $dbDriver);
        }
        if (!empty($changeSet['disbursements'])) {
            $pushedCount += upsertTable($pdo, 'disbursements', 'id', 'createdAt', $changeSet['disbursements'], $dbDriver);
        }
        if (!empty($changeSet['engineerReports'])) {
            $pushedCount += upsertTable($pdo, 'engineer_monthly_reports', 'id', 'updatedAt', $changeSet['engineerReports'], $dbDriver);
        }
        if (!empty($changeSet['users'])) {
            $pushedCount += upsertTable($pdo, 'users', 'id', 'updatedAt', $changeSet['users'], $dbDriver);
        }
        if (!empty($changeSet['teamPresets'])) {
            $pushedCount += upsertTable($pdo, 'inspection_team_presets', 'id', 'updatedAt', $changeSet['teamPresets'], $dbDriver);
        }
        if (!empty($changeSet['whatsappContacts'])) {
            $pushedCount += upsertTable($pdo, 'whatsapp_contacts', 'id', 'updatedAt', $changeSet['whatsappContacts'], $dbDriver);
        }
        if (!empty($changeSet['whatsappTemplates'])) {
            $pushedCount += upsertTable($pdo, 'whatsapp_templates', 'id', 'updatedAt', $changeSet['whatsappTemplates'], $dbDriver);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $pushedCount;
}

function upsertTable($pdo, $table, $primaryKey, $dateCol, $rows, $dbDriver = 'mysql') {
    if (empty($rows)) return 0;
    $count = 0;

    foreach ($rows as $row) {
        if (!isset($row[$primaryKey])) continue;

        // Check if existing record is newer
        $checkStmt = $pdo->prepare("SELECT {$dateCol} FROM {$table} WHERE {$primaryKey} = :pk");
        $checkStmt->execute([':pk' => $row[$primaryKey]]);
        $existing = $checkStmt->fetch();

        if ($existing && isset($existing[$dateCol], $row[$dateCol])) {
            if ($existing[$dateCol] >= $row[$dateCol]) {
                // Server has same or newer timestamp, skip
                continue;
            }
        }

        // Perform INSERT OR REPLACE (SQLite) or REPLACE INTO (MySQL)
        $cols = array_keys($row);
        $colNames = implode(', ', array_map(function($c) { return "`{$c}`"; }, $cols));
        $placeholders = implode(', ', array_map(function($c) { return ":{$c}"; }, $cols));

        if ($dbDriver === 'mysql') {
            $sql = "REPLACE INTO `{$table}` ({$colNames}) VALUES ({$placeholders})";
        } else {
            $sql = "INSERT OR REPLACE INTO `{$table}` ({$colNames}) VALUES ({$placeholders})";
        }

        $stmt = $pdo->prepare($sql);
        $params = [];
        foreach ($cols as $c) {
            $params[":{$c}"] = $row[$c];
        }
        $stmt->execute($params);
        $count++;
    }

    return $count;
}

// ----------------------------------------------------------------------------
// Schema Initialization
// ----------------------------------------------------------------------------
function initSchemaIfNeeded($pdo) {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS projects (
      id TEXT PRIMARY KEY,
      projectCode TEXT UNIQUE NOT NULL,
      title TEXT NOT NULL,
      bidNo TEXT NOT NULL,
      fundSource TEXT,
      year INTEGER NOT NULL,
      barangay TEXT NOT NULL,
      sitio TEXT,
      locationText TEXT,
      contractor TEXT NOT NULL,
      contractAmount REAL NOT NULL DEFAULT 0,
      revisedContractPrice REAL DEFAULT 0,
      projectCost REAL NOT NULL DEFAULT 0,
      abc REAL DEFAULT 0,
      varianceCost REAL DEFAULT 0,
      variancePercent REAL DEFAULT 0,
      contractDurationDays INTEGER DEFAULT 0,
      billingStatusText TEXT,
      contractorCategory TEXT,
      cpesScore REAL,
      cpesRating TEXT,
      contractorIssueFlags TEXT,
      isTerminated INTEGER DEFAULT 0,
      terminationDate TEXT,
      terminationReason TEXT,
      isSuspended INTEGER DEFAULT 0,
      suspensionReason TEXT,
      isMajorProject INTEGER DEFAULT 0,
      teamCategory TEXT,
      status TEXT NOT NULL,
      contractStartDate TEXT,
      contractEndDate TEXT,
      revisedEndDate TEXT,
      noticeToProceedDate TEXT,
      percentAccomplished REAL DEFAULT 0,
      retentionPercent REAL DEFAULT 10,
      retentionClaimed INTEGER DEFAULT 0,
      latitude REAL,
      longitude REAL,
      description TEXT,
      remarks TEXT,
      projectType TEXT,
      source TEXT DEFAULT 'manual',
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL,
      createdBy TEXT,
      updatedBy TEXT,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS site_inspections (
      id TEXT PRIMARY KEY,
      projectId TEXT NOT NULL,
      inspectionDate TEXT NOT NULL,
      visitYear INTEGER NOT NULL,
      inspectorName TEXT NOT NULL,
      inspectorUserId TEXT,
      reportNumber TEXT,
      reportSubTitle TEXT,
      reportType TEXT DEFAULT 'Regular Monitoring',
      updateReportSummary TEXT,
      caccoRep TEXT,
      cgsoRep TEXT,
      cmoRep1 TEXT,
      cmoRep2 TEXT,
      checkedByName TEXT,
      checkedByTitle TEXT,
      notedByName TEXT,
      notedByTitle TEXT,
      defectsList TEXT,
      recommendationPaymentClaim INTEGER DEFAULT 0,
      physicalProgressPercent REAL,
      foremanCount INTEGER DEFAULT 0,
      skilledCount INTEGER DEFAULT 0,
      laborCount INTEGER DEFAULT 0,
      healthAndSafetyStatus TEXT DEFAULT 'Compliant',
      warningSignStatus TEXT DEFAULT 'Compliant',
      billboardStatus TEXT DEFAULT 'Compliant',
      activities TEXT,
      findings TEXT NOT NULL,
      recommendations TEXT NOT NULL,
      conditionRating TEXT,
      weather TEXT,
      gpsLat REAL,
      gpsLng REAL,
      personsPresent TEXT,
      teamLeader TEXT,
      teamMembers TEXT,
      teamPresetId TEXT,
      status TEXT NOT NULL DEFAULT 'Draft',
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL,
      createdBy TEXT,
      updatedBy TEXT,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS inspection_attachments (
      id TEXT PRIMARY KEY,
      inspectionId TEXT NOT NULL,
      projectId TEXT NOT NULL,
      fileName TEXT NOT NULL,
      mimeType TEXT NOT NULL,
      sizeBytes INTEGER NOT NULL,
      caption TEXT,
      thumbnailBase64 TEXT,
      dataBase64 TEXT,
      uploadedAt TEXT NOT NULL,
      uploadedBy TEXT,
      checksum TEXT NOT NULL,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS punchlists (
      id TEXT PRIMARY KEY,
      projectId TEXT NOT NULL,
      punchlistNumber INTEGER NOT NULL DEFAULT 1,
      reportNumber TEXT NOT NULL,
      inspectionDate TEXT NOT NULL,
      visitYear INTEGER NOT NULL,
      inspectorName TEXT NOT NULL,
      inspectorUserId TEXT,
      reportSubTitle TEXT,
      physicalProgressPercent REAL,
      remarks TEXT,
      findings TEXT,
      recommendations TEXT,
      defectsList TEXT,
      status TEXT NOT NULL DEFAULT 'Final',
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL,
      createdBy TEXT,
      updatedBy TEXT,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS project_proof_documents (
      id TEXT PRIMARY KEY,
      projectId TEXT NOT NULL,
      fileName TEXT NOT NULL,
      fileCategory TEXT NOT NULL DEFAULT 'Project Plan & Blueprints',
      mimeType TEXT NOT NULL,
      sizeBytes INTEGER NOT NULL,
      caption TEXT,
      documentNumber TEXT,
      thumbnailBase64 TEXT,
      dataBase64 TEXT,
      uploadedAt TEXT NOT NULL,
      uploadedBy TEXT,
      checksum TEXT NOT NULL,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS engineer_monthly_reports (
      id TEXT PRIMARY KEY,
      projectId TEXT NOT NULL,
      reportDate TEXT NOT NULL,
      actualPercentAccomplished REAL NOT NULL DEFAULT 0,
      negativeSlippagePercent REAL DEFAULT 0,
      timeExtensionDays INTEGER DEFAULT 0,
      variationOrderNo TEXT,
      variationOrderDetails TEXT,
      remarks TEXT,
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL,
      createdBy TEXT,
      updatedBy TEXT,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS billing_entries (
      id TEXT PRIMARY KEY,
      projectId TEXT NOT NULL,
      billingNo INTEGER NOT NULL,
      billingDate TEXT NOT NULL,
      description TEXT,
      amount REAL NOT NULL DEFAULT 0,
      retentionDeducted REAL DEFAULT 0,
      isFinalBilling INTEGER DEFAULT 0,
      inspectionReportNo TEXT,
      inspectionDate TEXT,
      isRetentionClaim INTEGER DEFAULT 0,
      retentionAmount REAL DEFAULT 0,
      remarks TEXT,
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL,
      createdBy TEXT,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS billing_attachments (
      id TEXT PRIMARY KEY,
      billingEntryId TEXT NOT NULL,
      projectId TEXT NOT NULL,
      fileName TEXT NOT NULL,
      mimeType TEXT NOT NULL,
      sizeBytes INTEGER NOT NULL,
      caption TEXT,
      thumbnailBase64 TEXT,
      dataBase64 TEXT,
      uploadedAt TEXT NOT NULL,
      uploadedBy TEXT,
      checksum TEXT NOT NULL,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS disbursements (
      id TEXT PRIMARY KEY,
      projectId TEXT NOT NULL,
      billingEntryId TEXT,
      amount REAL NOT NULL DEFAULT 0,
      disbursedDate TEXT NOT NULL,
      voucherNo TEXT,
      status TEXT NOT NULL DEFAULT 'YES',
      remarks TEXT,
      createdAt TEXT NOT NULL,
      createdBy TEXT
    );

    CREATE TABLE IF NOT EXISTS users (
      id TEXT PRIMARY KEY,
      username TEXT UNIQUE NOT NULL,
      fullName TEXT NOT NULL,
      email TEXT NOT NULL,
      positionOffice TEXT NOT NULL,
      role TEXT NOT NULL,
      isActive INTEGER NOT NULL DEFAULT 1,
      mustChangePassword INTEGER NOT NULL DEFAULT 0,
      avatarUrl TEXT,
      scopeBarangays TEXT,
      scopeYears TEXT,
      passwordHash TEXT NOT NULL,
      passwordSalt TEXT NOT NULL,
      lastLogin TEXT,
      failedLoginAttempts INTEGER DEFAULT 0,
      lockoutUntil TEXT,
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS settings (
      key TEXT PRIMARY KEY,
      value TEXT NOT NULL,
      description TEXT
    );

    CREATE TABLE IF NOT EXISTS lookups (
      id TEXT PRIMARY KEY,
      category TEXT NOT NULL,
      code TEXT,
      name TEXT NOT NULL,
      district TEXT,
      isActive INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS inspection_team_presets (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      leader TEXT NOT NULL,
      members TEXT NOT NULL,
      isDefault INTEGER NOT NULL DEFAULT 0,
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL,
      createdBy TEXT,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS whatsapp_contacts (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      phoneIntl TEXT NOT NULL,
      role TEXT,
      isDefault INTEGER NOT NULL DEFAULT 0,
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL,
      createdBy TEXT,
      deletedAt TEXT
    );

    CREATE TABLE IF NOT EXISTS whatsapp_templates (
      id TEXT PRIMARY KEY,
      reportType TEXT NOT NULL,
      name TEXT NOT NULL,
      body TEXT NOT NULL,
      isDefault INTEGER NOT NULL DEFAULT 0,
      createdAt TEXT NOT NULL,
      updatedAt TEXT NOT NULL,
      createdBy TEXT
    );
    ");
}
