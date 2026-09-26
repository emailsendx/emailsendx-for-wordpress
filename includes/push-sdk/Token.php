<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/**
 * Verifies Ed25519-signed tokens from the licence server (compact JWS,
 * alg EdDSA). Uses libsodium; WordPress bundles sodium_compat, so this works
 * even where the PHP extension is missing.
 */
final class Token
{
    /**
     * @return array<string,mixed>|null The payload, or null when the signature,
     *                                  audience or lifetime is wrong.
     */
    public static function verify(string $jws, string $publicKeyB64, string $audience, bool $allowExpired = false): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64url($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'EdDSA') {
            return null;
        }
        $pub = base64_decode($publicKeyB64, true);
        $sig = self::b64url($s);
        if ($pub === false || strlen($pub) !== 32 || strlen($sig) !== 64) {
            return null;
        }
        try {
            if (!sodium_crypto_sign_verify_detached($sig, $h . '.' . $p, $pub)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        $payload = json_decode(self::b64url($p), true);
        if (!is_array($payload) || ($payload['aud'] ?? null) !== $audience) {
            return null;
        }
        $now = time();
        if (isset($payload['iat']) && (int) $payload['iat'] > $now + 300) {
            return null; // issued in the future: clock skew beyond tolerance or forged
        }
        if (!$allowExpired && (!isset($payload['exp']) || (int) $payload['exp'] < $now)) {
            return null;
        }
        return $payload;
    }

    private static function b64url(string $v): string
    {
        $pad = strlen($v) % 4;
        $decoded = base64_decode(strtr($v, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : ''), true);
        return $decoded === false ? '' : $decoded;
    }
}
