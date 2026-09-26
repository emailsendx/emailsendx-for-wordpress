<?php
namespace EmailSendX\Push;

defined('ABSPATH') || exit;

/** Mirrors the server's normalizeSiteUrl(), so both sides agree on "this site". */
final class Site
{
    public static function origin(?string $url = null): string
    {
        $url = $url ?? home_url();
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = rtrim(strtolower($parts['host']), '.');
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        $port = isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true) ? ':' . (int) $parts['port'] : '';
        return 'https://' . $host . $port;
    }
}
