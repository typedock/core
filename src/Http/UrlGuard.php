<?php
declare(strict_types=1);

namespace TypeDock\Http;

/**
 * The single place that decides whether TypeDock may fetch a URL.
 *
 * Every feature that follows a user-supplied link — link cards, OGP preview,
 * generic JSON sources, importing images — is an SSRF vector: the server, not
 * the browser, makes the request, from inside whatever network the site is
 * hosted on. Three copies of "is this host private?" had grown up
 * independently before this class existed; one of them is all that should.
 *
 * inspect() returns the IP it validated. Callers must *pin* that IP for the
 * actual request (curl's CURLOPT_RESOLVE) rather than letting the transport
 * resolve the name again — otherwise a DNS entry that answers "public" during
 * the check and "127.0.0.1" a millisecond later walks straight through
 * (DNS rebinding).
 */
final class UrlGuard
{
    /** Names that never belong to a public host, whatever DNS says. */
    private const BLOCKED_SUFFIXES = ['.local', '.internal', '.localhost', '.home.arpa'];

    /**
     * Validate a URL and resolve it to one pinned address.
     *
     * @return array{url:string, scheme:string, host:string, port:int, ip:string}
     * @throws \RuntimeException when the URL must not be fetched.
     */
    public static function inspect(string $url): array
    {
        $parts  = parse_url($url);
        if ($parts === false) {
            throw new \RuntimeException('Malformed URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('Only http(s) URLs may be fetched.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('URLs with embedded credentials are not allowed.');
        }
        if (self::isBlockedName($host)) {
            throw new \RuntimeException("Host \"{$host}\" is not allowed.");
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            // A name that will not resolve cannot be fetched anyway, and
            // treating "unknown" as allowed is how guards get bypassed.
            throw new \RuntimeException("Host \"{$host}\" does not resolve.");
        }

        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                throw new \RuntimeException("Host \"{$host}\" resolves to a private or reserved address.");
            }
        }

        return [
            'url'    => $url,
            'scheme' => $scheme,
            'host'   => $host,
            'port'   => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'ip'     => $ips[0],
        ];
    }

    /** Reason the URL is refused, or null when it may be fetched. */
    public static function reject(string $url): ?string
    {
        try {
            self::inspect($url);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private static function isBlockedName(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function isPrivateIp(string $ip): bool
    {
        // The cloud metadata endpoint is the highest-value SSRF target there
        // is — it hands out instance credentials to anything that can reach
        // it. FILTER_FLAG_NO_RES_RANGE already covers 169.254.0.0/16, but a
        // regression there would fail open and silently, so it is also
        // checked by hand.
        if (str_starts_with($ip, '169.254.') || strtolower($ip) === 'fd00:ec2::254') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips     = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    if (isset($record[$key]) && is_string($record[$key])) {
                        $ips[] = $record[$key];
                    }
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            $ips      = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($ips));
    }
}
