<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/**
 * One-click connect: OAuth-style authorisation code flow with PKCE.
 *
 * 1. start(): remember a random `state` and PKCE verifier for this admin
 *    user, send the browser to the licence server's /connect page.
 * 2. The customer signs in there and picks a licence.
 * 3. The server redirects back here with a one-time code.
 * 4. finish(): check `state`, exchange code + verifier for the same
 *    activation a pasted key gives.
 *
 * A stolen code is useless without the verifier, which never leaves this site.
 */
final class Connect
{
    /** @var Client */
    private $client;

    const TTL = 15 * MINUTE_IN_SECONDS;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @return string The URL to send the browser to. */
    public function start(string $returnUrl): string
    {
        $state = self::b64url(random_bytes(16));
        $verifier = self::b64url(random_bytes(32));
        set_transient($this->key(), ['state' => $state, 'verifier' => $verifier, 'return' => $returnUrl], self::TTL);

        return $this->client->portalBase() . '/connect?' . http_build_query([
            'product' => $this->client->slug(),
            'site' => home_url(),
            'redirect_uri' => $returnUrl,
            'state' => $state,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return true|\WP_Error */
    public function finish(string $code, string $state, string $error = '')
    {
        $pending = get_transient($this->key());
        delete_transient($this->key()); // one attempt per start()

        if (!is_array($pending) || $state === '' || !hash_equals((string) $pending['state'], $state)) {
            return new \WP_Error('tdgpush_state', 'That connect link has expired or was started from another session. Try again.');
        }
        if ($error !== '') {
            return new \WP_Error('tdgpush_denied', $error === 'access_denied' ? 'Connection cancelled.' : 'The licence server could not connect this site.');
        }
        if ($code === '') {
            return new \WP_Error('tdgpush_code', 'The licence server did not return a connection code.');
        }

        $res = $this->client->api()->post('/api/v1/connect/token', [
            'code' => $code,
            'code_verifier' => (string) $pending['verifier'],
            'redirect_uri' => (string) $pending['return'],
            'product' => $this->client->slug(),
            'site_url' => home_url(),
        ]);
        return $this->client->license()->store_activation($res);
    }

    private function key(): string
    {
        return 'tdgpush_connect_' . md5($this->client->slug() . '|' . get_current_user_id());
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
