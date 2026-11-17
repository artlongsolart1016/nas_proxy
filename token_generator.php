<?php
/**
 * eVital client_token.dat Generator
 *
 * Compatible with the SAME encrypted format as:
 *   https://192.168.108.89/ePhp/token_generator/index.php
 *   (TokenManager AES-256-CBC + HMAC-SHA256, labels: API Client Token, Primary Endpoint, DB Host, …)
 *
 * Import that official client_token.dat into eVital System Setup / Wizard — no re-keying needed.
 * Primary Endpoint = LCR dmslcr004.php (transmission). PhilCRIS uses registry_api.php (Bridge Path).
 *
 * URL (example):
 *   https://ccromlybly.synology.me/lcr/newEtransmission/dist/token_generator.php
 *
 * Protect this page on production (IP allowlist / HTTP auth).
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

const EVITAL_TOKEN_APP_SECRET = 'CCRO_eRegPartner_SharedKey_2024_LonArt';
const EVITAL_TOKEN_SALT = 'eRegPartner_SALT_2024:';

const EVITAL_FIELD_LABELS = [
    'token_value' => 'API Client Token',
    'token_expiry' => 'Expiry date',
    'api_endpoint_0' => 'Primary Endpoint',
    'api_endpoint_1' => 'Fallback 1',
    'api_endpoint_2' => 'Fallback 2',
    'api_endpoint_3' => 'Fallback 3',
    'api_endpoint_4' => 'Fallback 4',
    'db_host' => 'DB Host',
    'db_port' => 'Main DB Port',
    'db_name_main' => 'Main DB Name',
    'db_user_main' => 'Main DB Username',
    'db_pass_main' => 'Main DB Password',
    'db_port_support' => 'Support DB Port',
    'db_name_support' => 'Support DB Name',
    'db_user_support' => 'Support DB Username',
    'db_pass_support' => 'Support DB Password',
    'api_sql_server_database' => 'SQL Server Database',
    'bridge_path' => 'PHP Bridge Path',
    'bridge_ip' => 'Bridge Host IP',
    'bridge_port' => 'Bridge Port',
    'facility_name' => 'Facility Name',
    'partner_code' => 'Partner Code',
    'lcr_code' => 'LCR Code',
];

const EVITAL_FIELD_ORDER = [
    'token_value',
    'token_expiry',
    'api_endpoint_0',
    'api_endpoint_1',
    'api_endpoint_2',
    'api_endpoint_3',
    'api_endpoint_4',
    'db_host',
    'db_port',
    'db_name_main',
    'db_user_main',
    'db_pass_main',
    'db_port_support',
    'db_name_support',
    'db_user_support',
    'db_pass_support',
    'api_sql_server_database',
    'bridge_path',
    'bridge_ip',
    'bridge_port',
    'facility_name',
    'partner_code',
    'lcr_code',
];

function evital_derived_key(): string
{
    return hash('sha256', EVITAL_TOKEN_SALT . EVITAL_TOKEN_APP_SECRET, true);
}

function evital_build_blob(array $data): string
{
    $lines = [];
    foreach (EVITAL_FIELD_ORDER as $key) {
        if (!isset(EVITAL_FIELD_LABELS[$key])) {
            continue;
        }
        $value = isset($data[$key]) ? trim((string) $data[$key]) : '';
        if ($value === '') {
            continue;
        }
        $lines[] = EVITAL_FIELD_LABELS[$key] . ' = ' . $value;
    }
    return implode("\n", $lines);
}

function evital_encrypt_blob(string $plainBlob): string
{
    $key = evital_derived_key();
    $iv = random_bytes(16);
    $enc = openssl_encrypt(trim($plainBlob), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) {
        throw new RuntimeException('Encryption failed.');
    }
    $hmac = hash_hmac('sha256', $iv . $enc, $key, true);
    return base64_encode($hmac . $iv . $enc);
}

function evital_decrypt_payload(string $payload): string
{
    $raw = base64_decode(trim($payload), true);
    if ($raw === false || strlen($raw) < 49) {
        return '';
    }
    $key = evital_derived_key();
    $hmac = substr($raw, 0, 32);
    $iv = substr($raw, 32, 16);
    $enc = substr($raw, 48);
    $calc = hash_hmac('sha256', $iv . $enc, $key, true);
    if (!hash_equals($hmac, $calc)) {
        return '';
    }
    $plain = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return ($plain !== false) ? trim($plain) : '';
}

function evital_parse_blob(string $blob): array
{
    $labelToKey = array_flip(EVITAL_FIELD_LABELS);
    $data = [];
    foreach (preg_split('/\r\n|\r|\n/', $blob) as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '=') === false) {
            continue;
        }
        [$label, $value] = array_map('trim', explode('=', $line, 2));
        if (isset($labelToKey[$label])) {
            $data[$labelToKey[$label]] = $value;
        }
    }
    return $data;
}

function evital_norm_expiry(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        [$y, $m, $d] = explode('-', $raw);
        return $m . '-' . $d . '-' . $y;
    }
    return $raw;
}

function evital_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$defaults = [
    'token_value' => '',
    'token_expiry' => date('m-d-Y', strtotime('+2 years')),
    'facility_name' => '',
    'partner_code' => '',
    'lcr_code' => '',
    'bridge_path' => 'http://127.0.0.1/registry_api.php',
    'bridge_ip' => '127.0.0.1',
    'bridge_port' => '80',
    'db_host' => '', // intentionally empty — clinic fills on workstation
    'db_port' => '3306',
    'db_name_main' => '',
    'db_user_main' => '',
    'db_pass_main' => '',
    'db_port_support' => '3307',
    'db_name_support' => '',
    'db_user_support' => '',
    'db_pass_support' => '',
    'api_sql_server_database' => 'LCRDbase_Online',
    'api_endpoint_0' => 'https://sakatamalaybalay.com/api/lcr/dmslcr004.php',
    'api_endpoint_1' => '',
    'api_endpoint_2' => '',
    'api_endpoint_3' => '',
    'api_endpoint_4' => '',
];

$form = $defaults;
$message = '';
$error = '';
$previewBlob = '';
$decodedCheck = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'generate';

    foreach (EVITAL_FIELD_ORDER as $key) {
        if (isset($_POST[$key])) {
            $form[$key] = trim((string) $_POST[$key]);
        }
    }
    $form['token_expiry'] = evital_norm_expiry($form['token_expiry']);

    try {
        if ($action === 'inspect' && !empty($_FILES['inspect_file']['tmp_name'])) {
            $payload = file_get_contents($_FILES['inspect_file']['tmp_name']);
            $plain = evital_decrypt_payload((string) $payload);
            if ($plain === '') {
                throw new RuntimeException('Could not decrypt file (wrong key or corrupt client_token.dat).');
            }
            $decodedCheck = evital_parse_blob($plain);
            $previewBlob = $plain;
            $form = array_merge($form, $decodedCheck);
            $message = 'Token decrypted successfully. Fields loaded into the form.';
        } else {
            if ($form['token_value'] === '') {
                throw new RuntimeException('API Client Token is required.');
            }
            $blob = evital_build_blob($form);
            if ($blob === '') {
                throw new RuntimeException('Nothing to encrypt — fill at least the Client Token.');
            }
            $encrypted = evital_encrypt_blob($blob);
            $previewBlob = $blob;

            if ($action === 'download') {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="client_token.dat"');
                header('Cache-Control: no-store');
                echo $encrypted . "\n";
                exit;
            }

            if ($action === 'save_local') {
                $target = __DIR__ . '/client_token.dat';
                if (file_put_contents($target, $encrypted . "\n", LOCK_EX) === false) {
                    throw new RuntimeException('Cannot write ' . $target);
                }
                $message = 'Saved to ' . basename($target) . ' (same folder as this generator / React dist).';
            } else {
                $message = 'License encrypted. Use Download client_token.dat to save to your computer.';
            }

            // Round-trip verify
            $check = evital_decrypt_payload($encrypted);
            $decodedCheck = evital_parse_blob($check);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>eVital client_token.dat Generator</title>
  <style>
    :root {
      --bg: #0b1220;
      --card: #111827;
      --border: #1f2937;
      --text: #e5e7eb;
      --muted: #94a3b8;
      --accent: #2563eb;
      --ok: #059669;
      --warn: #d97706;
      --err: #e11d48;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: ui-sans-serif, system-ui, Segoe UI, Roboto, Arial, sans-serif;
      background: radial-gradient(1200px 600px at 10% -10%, #1e3a8a55, transparent), var(--bg);
      color: var(--text);
      min-height: 100vh;
      padding: 24px;
    }
    .wrap { max-width: 980px; margin: 0 auto; }
    h1 { margin: 0 0 6px; font-size: 1.4rem; }
    .sub { color: var(--muted); font-size: 0.85rem; margin-bottom: 18px; }
    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px;
      margin-bottom: 16px;
    }
    h2 { margin: 0 0 12px; font-size: 0.95rem; color: #93c5fd; }
    .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    label { display: block; font-size: 0.72rem; color: var(--muted); margin-bottom: 4px; font-weight: 600; }
    input, textarea {
      width: 100%;
      background: #020617;
      border: 1px solid #334155;
      color: var(--text);
      border-radius: 8px;
      padding: 9px 10px;
      font-size: 0.82rem;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    input:focus, textarea:focus { outline: none; border-color: var(--accent); }
    .full { grid-column: 1 / -1; }
    .hint { font-size: 0.7rem; color: #64748b; margin-top: 4px; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
    button, .btn {
      appearance: none;
      border: 0;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 0.8rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-ok { background: var(--ok); color: #fff; }
    .btn-muted { background: #1e293b; color: #e2e8f0; border: 1px solid #334155; }
    .msg { padding: 10px 12px; border-radius: 10px; font-size: 0.82rem; margin-bottom: 12px; }
    .msg.ok { background: #064e3b55; border: 1px solid #05966988; color: #a7f3d0; }
    .msg.err { background: #4c051955; border: 1px solid #e11d4888; color: #fecdd3; }
    pre {
      background: #020617;
      border: 1px solid #1e293b;
      border-radius: 10px;
      padding: 12px;
      overflow: auto;
      font-size: 0.75rem;
      color: #86efac;
      white-space: pre-wrap;
    }
    .badge {
      display: inline-block;
      font-size: 0.65rem;
      padding: 2px 7px;
      border-radius: 999px;
      border: 1px solid #334155;
      color: #93c5fd;
      background: #0f172a;
      margin-left: 6px;
      vertical-align: middle;
    }
    @media (max-width: 720px) {
      .grid, .grid-3 { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>eVital <code>client_token.dat</code> Generator <span class="badge">AES-256-CBC + HMAC</span></h1>
    <p class="sub">
      Same format as <code>https://192.168.108.89/ePhp/token_generator/</code> (eTransmission TokenManager).
      <strong>One encrypted file per client PC / facility</strong> — that file is the DB + bridge reference for that computer.
    </p>

    <div class="card" style="border-color:#1d4ed8;background:#0f172a;">
      <h2 style="margin-top:0;">Client PC outside network — how this works</h2>
      <ol style="margin:0;padding-left:1.2rem;color:var(--muted);font-size:0.85rem;line-height:1.55;">
        <li><strong style="color:#e2e8f0;">PhilCRIS MySQL stays on the client PC</strong> (or that clinic LAN). Synology/MIS only hosts the React UI — it does not open the remote clinic DB by itself.</li>
        <li><strong style="color:#e2e8f0;">Install <code>registry_api.php</code> on the client PC</strong> (XAMPP/IIS next to PhilCRIS) so PHP can reach MySQL (<code>127.0.0.1</code> or the clinic DB IP).</li>
        <li><strong style="color:#e2e8f0;">Create <code>client_token.dat</code> below</strong> with: Client Token, Primary Endpoint (<code>dmslcr004.php</code>), this PC’s Bridge Path, and this PC’s DB Host/user/pass.</li>
        <li><strong style="color:#e2e8f0;">Download</strong> the file → save on that PC as <code>C:\eVital\client_token.dat</code>.</li>
        <li>On that PC open eVital (Synology URL) → Setup Wizard / System Setup → <strong>Load client_token.dat</strong> from <code>C:\eVital\</code>. Config is stored in that browser’s LocalStorage and used when calling the local bridge.</li>
      </ol>
      <p class="hint" style="margin:12px 0 0;">
        Flow: <code>Browser on client PC → local registry_api.php → PhilCRIS MySQL</code>.
        Transmission: <code>registry_api.php → sakata dmslcr004.php</code> (encrypted). Do not put sakata URL in Bridge Path.
      </p>
    </div>

    <?php if ($message): ?>
      <div class="msg ok"><?php echo evital_h($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="msg err"><?php echo evital_h($error); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <div class="card">
        <h2>1. Client Token &amp; Facility</h2>
        <div class="grid">
          <div class="full">
            <label>API Client Token *</label>
            <input name="token_value" required value="<?php echo evital_h($form['token_value']); ?>" placeholder="Partner Client-Token string" />
          </div>
          <div>
            <label>Expiry date (mm-dd-YYYY)</label>
            <input name="token_expiry" value="<?php echo evital_h($form['token_expiry']); ?>" placeholder="12-31-2027" />
          </div>
          <div>
            <label>Partner Code</label>
            <input name="partner_code" value="<?php echo evital_h($form['partner_code']); ?>" placeholder="BPMC-01" />
          </div>
          <div>
            <label>Facility Name</label>
            <input name="facility_name" value="<?php echo evital_h($form['facility_name']); ?>" placeholder="Bukidnon Provincial Medical Center" />
          </div>
          <div>
            <label>LCR Code</label>
            <input name="lcr_code" value="<?php echo evital_h($form['lcr_code']); ?>" placeholder="LCR-XXXX" />
          </div>
        </div>
      </div>

      <div class="card">
        <h2>2. PHP Bridge Location (on the CLIENT PC)</h2>
        <div class="grid-3">
          <div class="full">
            <label>PHP Bridge Path</label>
            <input name="bridge_path" value="<?php echo evital_h($form['bridge_path']); ?>" placeholder="http://127.0.0.1/registry_api.php" />
            <div class="hint">URL of registry_api.php on THIS client computer (must reach PhilCRIS:3306). Not Synology, not sakata dmslcr004.php.</div>
          </div>
          <div>
            <label>Bridge Host IP (optional)</label>
            <input name="bridge_ip" value="<?php echo evital_h($form['bridge_ip']); ?>" placeholder="127.0.0.1" />
          </div>
          <div>
            <label>Bridge Port</label>
            <input name="bridge_port" value="<?php echo evital_h($form['bridge_port']); ?>" placeholder="80" />
          </div>
        </div>
      </div>

      <div class="card">
        <h2>3. Main Database (phcris on CLIENT PC)</h2>
        <div class="grid-3">
          <div>
            <label>DB Host</label>
            <input name="db_host" value="<?php echo evital_h($form['db_host']); ?>" placeholder="127.0.0.1" />
            <div class="hint">As seen by registry_api.php on that PC (usually 127.0.0.1 if MySQL is local).</div>
          </div>
          <div>
            <label>Main DB Port</label>
            <input name="db_port" value="<?php echo evital_h($form['db_port']); ?>" />
          </div>
          <div>
            <label>Main DB Name</label>
            <input name="db_name_main" value="<?php echo evital_h($form['db_name_main']); ?>" />
          </div>
          <div>
            <label>Main DB Username</label>
            <input name="db_user_main" value="<?php echo evital_h($form['db_user_main']); ?>" />
          </div>
          <div>
            <label>Main DB Password</label>
            <input type="password" name="db_pass_main" value="<?php echo evital_h($form['db_pass_main']); ?>" autocomplete="new-password" />
          </div>
        </div>
      </div>

      <div class="card">
        <h2>4. Support Database</h2>
        <div class="grid-3">
          <div>
            <label>Support DB Port</label>
            <input name="db_port_support" value="<?php echo evital_h($form['db_port_support']); ?>" />
          </div>
          <div>
            <label>Support DB Name</label>
            <input name="db_name_support" value="<?php echo evital_h($form['db_name_support']); ?>" />
          </div>
          <div>
            <label>Support DB Username</label>
            <input name="db_user_support" value="<?php echo evital_h($form['db_user_support']); ?>" />
          </div>
          <div>
            <label>Support DB Password</label>
            <input type="password" name="db_pass_support" value="<?php echo evital_h($form['db_pass_support']); ?>" autocomplete="new-password" />
          </div>
          <div>
            <label>SQL Server Database</label>
            <input name="api_sql_server_database" value="<?php echo evital_h($form['api_sql_server_database']); ?>" />
          </div>
        </div>
      </div>

      <div class="card">
        <h2>5. LGU Transmission Endpoints (0–4)</h2>
        <div class="grid">
          <?php for ($i = 0; $i <= 4; $i++): $k = 'api_endpoint_' . $i; ?>
            <div class="<?php echo $i === 0 ? 'full' : ''; ?>">
              <label><?php echo $i === 0 ? 'Primary Endpoint (0) — dmslcr004.php' : ('Fallback ' . $i); ?></label>
              <input name="<?php echo $k; ?>" value="<?php echo evital_h($form[$k]); ?>" placeholder="<?php echo $i === 0 ? 'https://sakatamalaybalay.com/api/lcr/dmslcr004.php' : 'https://192.168.108.89/dmslcr004'; ?>" />
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <div class="card">
        <h2>Actions</h2>
        <div class="actions">
          <button class="btn-primary" type="submit" name="action" value="download">Download client_token.dat → save on CLIENT PC as C:\eVital\client_token.dat</button>
          <button class="btn-ok" type="submit" name="action" value="save_local">Save next to this PHP (lab only)</button>
          <button class="btn-muted" type="submit" name="action" value="preview">Preview encrypted payload</button>
        </div>
        <div style="margin-top:14px;border-top:1px solid #1e293b;padding-top:14px;">
          <label>Inspect existing client_token.dat</label>
          <div class="actions">
            <input type="file" name="inspect_file" accept=".dat,text/plain,*/*" />
            <button class="btn-muted" type="submit" name="action" value="inspect">Decrypt &amp; load</button>
          </div>
        </div>
      </div>
    </form>

    <?php if ($previewBlob !== ''): ?>
      <div class="card">
        <h2>Plain license blob (before encryption)</h2>
        <pre><?php echo evital_h($previewBlob); ?></pre>
      </div>
    <?php endif; ?>

    <?php if (is_array($decodedCheck)): ?>
      <div class="card">
        <h2>Round-trip decode check</h2>
        <pre><?php echo evital_h(json_encode($decodedCheck, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
