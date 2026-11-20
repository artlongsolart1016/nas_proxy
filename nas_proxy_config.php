<?php
declare(strict_types=1);
/**
 * PMES Single Reverse Proxy Configuration
 * Location on sakatamalaybalay.com: /api/lcr/nas_proxy/nas_proxy_config.php
 * Location on NAS: /volume1/web/lcr/1NAS_reverse_proxy/nas_proxy_config.php
 */

if (!defined('NAS_PROXY_CHANNEL_KEY')) {
    define('NAS_PROXY_CHANNEL_KEY', 'aa95ebc859b94bab457719ceac285fd681d11a423dbe936a');
}
if (!defined('NAS_PROXY_CHANNEL_HMAC_SECRET')) {
    define('NAS_PROXY_CHANNEL_HMAC_SECRET', '845d8be9417cca5ffb8ace1237399ada7700a63133d1e23bee1e9c5ef59988ba');
}
if (!defined('NAS_PROXY_URL_PRIMARY')) {
    define('NAS_PROXY_URL_PRIMARY', 'https://192.168.108.44/lcr/1NAS_api/nas_db_config.php');
}

return [
    // Same-host in-process dispatch when 1NAS_api resides on the same NAS host (F-17)
    // On the NAS, this automatically runs in-process. On sakatamalaybalay.com, localEntryPath does not exist so it forwards via tunnel.
    'enable_local_dispatch' => true,
    'local_nas_entry_path'  => getenv('NAS_LOCAL_ENTRY_PATH') ?: (dirname(__DIR__) . '/1NAS_api/nas_db_config.php'),

    // Upstream selection: 'lan_only' | 'lan_first' | 'tunnel_only' | 'tunnel_first'
    'upstream_mode'       => getenv('NAS_UPSTREAM_MODE') ?: 'lan_only',
    'lan_upstream_url'    => getenv('NAS_LAN_UPSTREAM_URL') ?: 'https://192.168.108.44/lcr/1NAS_api/nas_db_config.php',
    'tunnel_upstream_url' => getenv('NAS_TUNNEL_UPSTREAM_URL') ?: '',

    // Optional Cloudflare Access Service Token headers when using Named Tunnel fallback
    'cf_access_client_id'     => getenv('CF_ACCESS_CLIENT_ID') ?: '',
    'cf_access_client_secret' => getenv('CF_ACCESS_CLIENT_SECRET') ?: '',

    // Shared 256-bit HMAC secret (must match 1NAS_api/config/nas_db_config_secrets.php)
    'shared_hmac_secret' => getenv('NAS_SHARED_HMAC_SECRET') ?: '845d8be9417cca5ffb8ace1237399ada7700a63133d1e23bee1e9c5ef59988ba',

    // Client token verification
    'require_client_token' => false,
    'client_tokens' => [
        'pmes' => getenv('NAS_PMES_CLIENT_TOKEN') ?: 'CHANGE_ME_PMES_CLIENT_TOKEN',
    ],

    // Origin handling:
    // When allow_all_origins is true, the proxy dynamically allows any browser Origin (CORS)
    // and forwards the caller's Origin to 1NAS_api via X-Forwarded-Origin.
    // This allows you to manage whitelists centrally on your NAS (1NAS_api/config/nas_db_config_apps.json.php)
    // without ever having to re-upload or edit files on sakatamalaybalay.com.
    'allow_all_origins' => true,

    // Optional static fallback list if allow_all_origins is set to false:
    'allowed_origins' => [
        'https://epmes.ccromalaybalaybuk.workers.dev',
        'https://pmesmlybly.ai.studio',
        'https://sakatamalaybalay.com',
        'https://www.sakatamalaybalay.com',
        'http://localhost:3000',
        'http://localhost:5173',
    ],
    'allowed_origin_suffixes' => [
        '.workers.dev',
    ],

    // Per-route limits, methods, and timeouts
    'routes' => [
        'api' => [
            'methods'            => ['POST'],
            'max_body_bytes'     => 26214400,  // 25 MB
            'timeout_sec'        => 60,
            'rate_limit_per_min' => 120,
        ],
        'health' => [
            'methods'            => ['GET', 'POST'],
            'max_body_bytes'     => 65536,
            'timeout_sec'        => 10,
            'rate_limit_per_min' => 60,
        ],
        'sync' => [
            'methods'            => ['POST'],
            'max_body_bytes'     => 104857600, // 100 MB (project_proof ceiling)
            'timeout_sec'        => 180,
            'rate_limit_per_min' => 30,
        ],
        'report' => [
            'methods'            => ['POST'],
            'max_body_bytes'     => 1048576,   // 1 MB JSON request body
            'timeout_sec'        => 300,       // 5 min for large DevExpress .repx PDF/XLSX/DOCX
            'rate_limit_per_min' => 20,
        ],
        'file' => [
            'methods'            => ['POST'],
            'max_body_bytes'     => 1048576,   // 1 MB JSON request body
            'timeout_sec'        => 60,
            'rate_limit_per_min' => 60,
        ],
    ],

    // Per-kind upload byte ceilings enforced on op=sync
    'upload_limits_by_kind' => [
        'project_proof'         => 104857600, // 100 MB
        'inspection_attachment' => 52428800,  // 50 MB
        'billing_attachment'    => 26214400,  // 25 MB
    ],

    // Trusted reverse proxies allowed to supply CF-Connecting-IP / X-Forwarded-For (F-12)
    'trusted_proxies' => getenv('NAS_TRUSTED_PROXIES')
        ? array_filter(array_map('trim', explode(',', (string)getenv('NAS_TRUSTED_PROXIES'))))
        : [
            // Loopback & RFC1918 LAN proxies
            '127.0.0.1/8',
            '::1/128',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            // Cloudflare IPv4 CIDRs
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // Cloudflare IPv6 CIDRs
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ],

    'connect_timeout_sec' => 6,
    'verify_tls'          => false, // false prevents cURL 60 SSL issuer errors with trycloudflare tunnels
];
