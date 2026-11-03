<?php

namespace App\Support;

/**
 * Helpers for fetching attacker-influenced URLs (the editor's image rehost,
 * export-time image embedding) without opening an SSRF hole. Every external
 * fetch in the app must validate through here (CLAUDE.md rule 6):
 *
 *   $ips = Ssrf::assertPublicUrl($url);                       // throws on private hosts
 *   Http::withOptions(Ssrf::fetchOptions($url, $ips))->get($url);
 */
class Ssrf
{
    /**
     * Reject a URL unless it's an http(s) URL whose host resolves only to
     * public IPs. Blocks the SSRF classics: loopback, link-local (cloud
     * metadata), and private ranges, for both IPv4 and IPv6.
     *
     * Returns the validated public IPs so the caller can pin the connection to
     * them (see resolvePin) and avoid a second, unchecked DNS lookup.
     *
     * Failures abort with a 422 HttpException — web callers surface it as a
     * validation response; queue callers catch it like any other Throwable.
     *
     * @return string[]
     */
    public static function assertPublicUrl(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        abort_unless(in_array($scheme, ['http', 'https'], true), 422, 'Only http(s) image URLs are allowed.');

        $host = parse_url($url, PHP_URL_HOST);
        abort_if($host === null || $host === '', 422, 'Invalid image URL.');

        // A bracketed/raw IP host is checked directly; a name is resolved to
        // every A/AAAA record so one private answer can't sneak through.
        $literal = trim($host, '[]');
        $ips = filter_var($literal, FILTER_VALIDATE_IP)
            ? [$literal]
            : array_merge(
                array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            );

        abort_if(empty($ips), 422, 'Could not resolve the image host.');

        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            abort_unless($public !== false, 422, 'That host is not allowed.');
        }

        return $ips;
    }

    /**
     * HTTP client options for a guarded fetch: no redirects (a 30x could point
     * at an internal host the up-front check never saw) and the connection
     * pinned to the validated IPs (see resolvePin).
     *
     * @param  string[]  $ips  public IPs from assertPublicUrl()
     */
    public static function fetchOptions(string $url, array $ips): array
    {
        return [
            'allow_redirects' => false,
            'curl' => extension_loaded('curl')
                ? [CURLOPT_RESOLVE => self::resolvePin($url, $ips)]
                : [],
        ];
    }

    /**
     * cURL CURLOPT_RESOLVE entries that pin a URL's host to IPs we already
     * validated as public — so the connection uses those addresses instead of
     * re-resolving DNS, closing the rebinding/TOCTOU window where a hostname
     * answers "public" on the safety check and "internal" on the actual fetch.
     * TLS still validates against the original hostname.
     *
     * Empty when the host is already an IP literal: there's no DNS step to pin.
     *
     * @param  string[]  $ips  public IPs the host resolved to during validation
     * @return string[]  e.g. ['example.com:443:93.184.216.34,2606:2800::1']
     */
    public static function resolvePin(string $url, array $ips): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $literal = trim($host, '[]');

        if ($host === '' || $ips === [] || filter_var($literal, FILTER_VALIDATE_IP)) {
            return [];
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        return ["{$host}:{$port}:".implode(',', $ips)];
    }
}
