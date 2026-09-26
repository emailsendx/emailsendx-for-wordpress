<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/** JSON calls to the licence server. */
final class Api
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @return array<string,mixed>|null Decoded JSON of a public GET, or null on any failure. */
    public function get(string $pathAndQuery): ?array
    {
        $res = wp_remote_get($this->client->apiBase() . $pathAndQuery, [
            'timeout' => 10,
            'redirection' => 0,
            'user-agent' => $this->client->userAgent(),
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($res), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}|\WP_Error
     */
    public function post(string $path, array $body = [], ?string $token = null)
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $res = wp_remote_post($this->client->apiBase() . $path, [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => $headers,
            'user-agent' => $this->client->userAgent(),
            'body' => wp_json_encode($body + $this->client->environment()),
        ]);
        if (is_wp_error($res)) {
            return $res;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($decoded)) {
            return new \WP_Error('tdgpush_bad_response', 'The licence server sent an unreadable response.');
        }
        return ['status' => (int) wp_remote_retrieve_response_code($res), 'body' => $decoded];
    }
}
