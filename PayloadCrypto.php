<?php
/**
 * Encrypts SQL payloads before they cross the Internet (same as eTransmission).
 * dmslcr004.php is unchanged: it still requires Database + Sql keys present.
 * The real query lives in Data (AES-256-CBC + HMAC-SHA256).
 * Windows IIS unwraps Data back to the original JSON.
 */
class PayloadCrypto
{
    private const PURPOSE = 'eTrans_Payload_v1:';
    private const QUERY_TYPE = 'ENCRYPTED';

    public static function wrap(array $payload, string $secret): array
    {
        $secret = trim($secret);
        if ($secret === '') {
            throw new RuntimeException('Payload encryption secret is missing.');
        }

        $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($plain === false) {
            throw new RuntimeException('Payload JSON encode failed.');
        }

        $key = hash('sha256', self::PURPOSE . $secret, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Payload encryption failed.');
        }

        $hmac = hash_hmac('sha256', $iv . $cipher, $key, true);

        return [
            'Database'  => 'enc',
            'Sql'       => 'enc',
            'QueryType' => self::QUERY_TYPE,
            'EncV'      => 1,
            'Data'      => base64_encode($hmac . $iv . $cipher),
        ];
    }

    public static function wrapJson(array $payload, string $secret): string
    {
        $json = json_encode(self::wrap($payload, $secret), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Encrypted payload JSON encode failed.');
        }
        return $json;
    }

    public static function secretFromToken(string $token): string
    {
        if (defined('PAYLOAD_ENCRYPTION_KEY') && trim((string) PAYLOAD_ENCRYPTION_KEY) !== '') {
            return trim((string) PAYLOAD_ENCRYPTION_KEY);
        }
        return trim($token);
    }
}
