<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/**
 * Licence state for one plugin on this site.
 *
 * The source of truth is the signed licence token from the server. It is
 * re-verified on every read, so editing the database cannot unlock anything,
 * and it stays valid offline until its `exp` (30 days) — a server outage
 * never locks a customer out.
 */
final class License
{
    /** @var Client */
    private $client;
    /** @var Store */
    private $store;
    /** @var array<string,mixed>|null|false memo: false = not computed yet */
    private $payload = false;

    public function __construct(Client $client, Store $store)
    {
        $this->client = $client;
        $this->store = $store;
    }

    /** True when this site holds a valid, active licence for the plugin (always true for free plugins). */
    public function is_valid(): bool
    {
        if ($this->client->isFree()) {
            return true;
        }
        $p = $this->payload();
        if (!$p || ($p['status'] ?? '') !== 'active') {
            return false;
        }
        $expires = $p['license_expires_at'] ?? null;
        return $expires === null || (int) $expires > time();
    }

    /** @return array<string,mixed>|null Verified token payload for this site, if any. */
    public function payload(): ?array
    {
        if ($this->payload !== false) {
            return $this->payload;
        }
        $jws = $this->store->get('license');
        $p = is_string($jws) ? Token::verify($jws, $this->client->publicKey(), $this->client->slug()) : null;
        // A token issued to another site (cloned database) doesn't count here.
        if ($p && ($p['site'] ?? '') !== Site::origin()) {
            $p = null;
        }
        return $this->payload = $p;
    }

    /** "active" | "expired" | "suspended" | "revoked" | "inactive" (no licence on this site). */
    public function status(): string
    {
        $p = $this->payload();
        if (!$p) {
            return 'inactive';
        }
        $status = (string) ($p['status'] ?? 'inactive');
        if ($status === 'active' && isset($p['license_expires_at']) && (int) $p['license_expires_at'] <= time()) {
            return 'expired';
        }
        return $status;
    }

    public function is_activated(): bool
    {
        return $this->store->token() !== null && $this->payload() !== null;
    }

    /** @return true|\WP_Error */
    public function activate(string $key)
    {
        $key = trim($key);
        if ($key === '') {
            return new \WP_Error('tdgpush_empty', 'Enter your licence key.');
        }
        $res = $this->client->api()->post('/api/v1/activate', [
            'license_key' => $key,
            'product' => $this->client->slug(),
            'site_url' => home_url(),
        ]);
        return $this->store_activation($res, strtoupper(substr($key, -4)));
    }

    /**
     * Save a successful /activate or /connect/token response. Both return the
     * same shape; the signed licence is verified before anything is stored.
     *
     * @param array{status:int, body:array<string,mixed>}|\WP_Error $res
     * @return true|\WP_Error
     */
    public function store_activation($res, ?string $keyHint = null)
    {
        $error = $this->error($res);
        if ($error) {
            return $error;
        }
        /** @var array{body:array<string,mixed>} $res */
        $body = $res['body'];
        $payload = Token::verify((string) ($body['license'] ?? ''), $this->client->publicKey(), $this->client->slug());
        if (!$payload || empty($body['token'])) {
            return new \WP_Error('tdgpush_signature', 'The licence server response could not be verified. Check the plugin is up to date.');
        }
        $this->store->clear();
        $this->store->setToken((string) $body['token']);
        $this->store->merge([
            'license' => (string) $body['license'],
            'instance_id' => (string) $body['instance_id'],
            'key_hint' => strtoupper((string) ($keyHint ?? ($body['key_hint'] ?? ''))),
            'checked_at' => time(),
            'last_error' => null,
        ]);
        $this->payload = false;
        $this->client->updater()->flush();
        return true;
    }

    /**
     * Free plugins: register this site for updates, no key. Called
     * automatically from wp-admin; failures retry at most once an hour.
     *
     * @return true|\WP_Error
     */
    public function register()
    {
        $res = $this->client->api()->post('/api/v1/register', [
            'product' => $this->client->slug(),
            'site_url' => home_url(),
        ]);
        return $this->store_activation($res, 'FREE');
    }

    /** Release this site's seat. Local state is cleared even if the server can't be reached. */
    public function deactivate(): void
    {
        $token = $this->store->token();
        if ($token !== null) {
            $this->client->api()->post('/api/v1/deactivate', [], $token);
        }
        $this->store->clear();
        $this->payload = false;
        $this->client->updater()->flush();
    }

    /**
     * Check in with the server (licence + update offer in one call). Network
     * failures keep the current state; only a definite "not activated"
     * answer clears it.
     *
     * @return array<string,mixed>|\WP_Error The server body.
     */
    public function refresh()
    {
        $token = $this->store->token();
        if ($token === null) {
            return new \WP_Error('tdgpush_inactive', 'This site is not activated.');
        }
        $body = ['channel' => $this->store->get('beta') ? 'beta' : 'stable'];
        $res = $this->client->api()->post('/api/v1/update-check', $body, $token);
        if (is_wp_error($res)) {
            $this->store->merge(['last_error' => $res->get_error_message()]);
            return $res;
        }
        if ($res['status'] === 401) {
            // Deactivated from the dashboard or by an admin: forget the token.
            $this->store->clear();
            $this->payload = false;
            return new \WP_Error('tdgpush_deactivated', (string) ($res['body']['error']['message'] ?? 'This site is no longer activated.'));
        }
        $error = $this->error($res);
        if ($error) {
            $this->store->merge(['last_error' => $error->get_error_message()]);
            return $error;
        }
        $jws = (string) ($res['body']['license'] ?? '');
        if (!Token::verify($jws, $this->client->publicKey(), $this->client->slug())) {
            return new \WP_Error('tdgpush_signature', 'The licence server response could not be verified.');
        }
        $this->store->merge(['license' => $jws, 'checked_at' => time(), 'last_error' => null]);
        $this->payload = false;
        return $res['body'];
    }

    public function set_beta(bool $on): void
    {
        $this->store->merge(['beta' => $on]);
    }

    public function beta(): bool
    {
        return (bool) $this->store->get('beta');
    }

    /** @return array<string,mixed> For the admin screen. */
    public function details(): array
    {
        $p = $this->payload() ?? [];
        return [
            'status' => $this->status(),
            'plan' => $p['plan'] ?? null,
            'expires_at' => $p['license_expires_at'] ?? null,
            'max_sites' => $p['max_sites'] ?? null,
            'sites_used' => $p['sites_used'] ?? null,
            'valid_until' => $p['exp'] ?? null,
            'key_hint' => $this->store->get('key_hint'),
            'checked_at' => $this->store->get('checked_at'),
            'last_error' => $this->store->get('last_error'),
        ];
    }

    /** @param array{status:int, body:array<string,mixed>}|\WP_Error $res */
    private function error($res): ?\WP_Error
    {
        if (is_wp_error($res)) {
            return new \WP_Error('tdgpush_network', 'Could not reach the licence server: ' . $res->get_error_message());
        }
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($res['body']['ok'])) {
            return null;
        }
        $err = $res['body']['error'] ?? [];
        return new \WP_Error('tdgpush_' . ($err['code'] ?? 'error'), (string) ($err['message'] ?? 'The licence server returned an error.'));
    }
}
