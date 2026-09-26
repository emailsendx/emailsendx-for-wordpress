<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/**
 * Per-plugin persisted state in one non-autoloaded option.
 *
 * The site's bearer token is encrypted with a key derived from this site's
 * auth salt, so a copied database (or a leaked backup) does not carry a
 * usable token to another site.
 */
final class Store
{
    /** @var string */
    private $option;

    public function __construct(string $slug)
    {
        $this->option = 'tdgpush_' . str_replace('-', '_', $slug);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        $v = get_option($this->option, []);
        return is_array($v) ? $v : [];
    }

    /** @param mixed $default @return mixed */
    public function get(string $key, $default = null)
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @param array<string,mixed> $values */
    public function merge(array $values): void
    {
        update_option($this->option, array_merge($this->all(), $values), false);
    }

    public function clear(): void
    {
        delete_option($this->option);
    }

    public function setToken(string $token): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($token, $nonce, $this->key());
        $this->merge(['token' => 'v1.' . base64_encode($nonce . $box)]);
    }

    public function token(): ?string
    {
        $stored = $this->get('token');
        if (!is_string($stored) || strpos($stored, 'v1.') !== 0) {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );
        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        return sodium_crypto_generichash('tdgpush-token|' . $this->option . '|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
