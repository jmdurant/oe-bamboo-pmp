<?php
namespace Juggernaut\Module\Bamboo\Controllers;

/** Exact vendor origins; no browser-controlled host configuration or suffix match. */
final class ReportDestination
{
    // https://ssltls.bamboohealth.com/pmpgateway-cert-update-2025.html
    private const HOSTS = ['mutualauth.pmpgateway.net', 'secure.prep.pmpgateway.net',
        'mtls.pmpgateway.net', 'mtls.prep.pmpgateway.net', 'pmpgateway.net', 'prep.pmpgateway.net'];

    public static function validate($url): string
    {
        if (!is_string($url) || strlen($url) > 8192
            || preg_match('/[^\x21-\x7e]/', $url) || str_contains($url, '\\')
            || preg_match('/%(?![0-9a-f]{2})|%(?:0[0-9a-f]|1[0-9a-f]|20|7f|5c)/i', $url)) {
            throw new \InvalidArgumentException('Unsupported report destination');
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || !preg_match('~^https://[a-z0-9.-]+(?::443)?(?:/|\?|$)~iD', $url)) {
            throw new \InvalidArgumentException('Unsupported report destination');
        }
        return $url;
    }

    public static function configure($curl): void
    {
        curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    }
}
