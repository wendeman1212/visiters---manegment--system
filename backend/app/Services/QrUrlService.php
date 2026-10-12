<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Central factory for the absolute URLs that are encoded inside QR codes.
 *
 * Why this exists:
 *  A QR code is scanned by ANOTHER device (a phone, a tablet, another PC).
 *  The host name embedded in the QR payload is resolved by that device, so
 *  "localhost" inside a QR code points at the phone itself and never at the
 *  computer running the VMS. Every QR URL must therefore be built from a
 *  real, reachable address — never from the current browser request host.
 *
 * Resolution order:
 *   1. QR_BASE_URL            (explicit override, works for any environment)
 *   2. APP_URL                (when it points at a real host/domain — production)
 *   3. Auto-detected LAN IPv4 (local development, php artisan serve)
 *
 * The result never contains "localhost" or "127.0.0.1" unless the machine
 * genuinely has no network address at all.
 */
class QrUrlService
{
    protected static ?string $baseUrl = null;

    /**
     * Build the absolute URL encoded inside a QR code for the given token.
     */
    public static function generate(string $token): string
    {
        $path = trim((string) config('qr.path', 'qr'), '/');

        return self::baseUrl() . '/' . $path . '/' . rawurlencode($token);
    }

    /**
     * The base URL (scheme://host[:port]) used for every QR code.
     */
    public static function baseUrl(): string
    {
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }

        // 1) Explicit override — highest priority, environment agnostic.
        $explicit = trim((string) config('qr.base_url'));
        if ($explicit !== '') {
            return self::$baseUrl = self::normalize($explicit);
        }

        // 2) Vite dev server origin from Laravel's "hot" file.
        //
        //    When `npm run dev` is running, Laravel injects asset URLs and
        //    proxies every application route (including /qr) through the Vite
        //    dev server. That dev origin is the address other devices (phones
        //    scanning a badge) can actually reach, so QR links must use it too —
        //    otherwise they point at APP_URL (often a loopback-bound
        //    `php artisan serve` on :8000) which a phone cannot open.
        $hot = @file_get_contents(public_path('hot'));
        if ($hot !== false) {
            $origin = trim($hot);
            if ($origin !== '' && preg_match('#^https?://#i', $origin)) {
                return self::$baseUrl = self::normalize($origin);
            }
        }

        // 3) APP_URL when it already points at a real host (production domain,
        //    a static server IP, ...). Loopback hosts are skipped on purpose.
        $appUrl = trim((string) config('app.url'));
        $host = $appUrl !== '' ? parse_url($appUrl, PHP_URL_HOST) : null;

        if (is_string($host) && $host !== '' && !self::isLoopback($host)) {
            $isIpLiteral = (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

            // Production domains, hostnames and non-local addresses: trust them.
            if (!$isIpLiteral || config('app.env') === 'production') {
                return self::$baseUrl = self::normalize($appUrl);
            }

            // Local development configured with a literal IP (APP_URL=http://192.168.x.x:8000).
            // Network addressing can change (DHCP / different Wi-Fi), so the live
            // address wins when it differs from the configured one — QR codes must
            // always point at an address this machine actually owns right now.
            $detected = self::detectLanIp();
            if (self::isLoopback($detected)) {
                // Detection unavailable — keep the configured address.
                return self::$baseUrl = self::normalize($appUrl);
            }

            if ($detected === $host) {
                return self::$baseUrl = self::normalize($appUrl);
            }

            return self::$baseUrl = self::rebuild($appUrl, $detected);
        }

        // 4) Local development — build from the detected LAN address so other
        //    devices on the same Wi-Fi/LAN can open the QR link.
        return self::$baseUrl = self::localBaseUrl();
    }

    /**
     * Detect the LAN IPv4 address of this machine.
     *
     * Public so it can be inspected/overridden by ops tooling and tests.
     */
    public static function detectLanIp(): string
    {
        $override = trim((string) config('qr.lan_ip'));
        if ($override !== '' && filter_var($override, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $override;
        }

        $candidates = [];

        // (a) UDP connect trick: the OS reports the source address it would
        //     use to reach the internet — i.e. the address of the active
        //     network interface. No packet is actually sent.
        $socket = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if (is_resource($socket)) {
            $name = @stream_socket_get_name($socket, false);
            if (is_string($name) && $name !== '') {
                // Local UDP socket names look like "192.168.1.100:54321".
                if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $name, $m)) {
                    $candidates[] = $m[1];
                } elseif (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $candidates[] = $name;
                }
            }
            fclose($socket);
        }

        // (b) Resolve the machine hostname.
        $hostname = gethostname();
        if (is_string($hostname) && $hostname !== '') {
            foreach ((array) @gethostbynamel($hostname) as $ip) {
                $candidates[] = $ip;
            }
        }

        foreach (self::pickBest($candidates) as $ip) {
            return $ip;
        }

        Log::warning('QrUrlService: no LAN IPv4 address detected; QR codes will fall back to 127.0.0.1.');

        return '127.0.0.1';
    }

    /**
     * Forget the memoized base URL (used by tests / after config changes).
     */
    public static function flush(): void
    {
        self::$baseUrl = null;
    }

    /**
     * QR URLs for local development: detected LAN IP + configured port.
     */
    protected static function localBaseUrl(): string
    {
        $appUrl = trim((string) config('app.url'));

        $scheme = 'http';
        if ($appUrl !== '') {
            $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
        }

        $port = config('qr.port');
        if (($port === null || $port === '') && $appUrl !== '') {
            $port = parse_url($appUrl, PHP_URL_PORT);
        }
        $port = (int) ($port ?: 8000);

        return $scheme . '://' . self::detectLanIp() . ':' . $port;
    }

    /**
     * Rebuild an existing URL around a different host, keeping scheme and port.
     */
    protected static function rebuild(string $url, string $host): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'http';
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    /**
     * Normalize an explicitly configured base URL.
     */
    protected static function normalize(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }

        return rtrim($url, '/');
    }

    /**
     * Rank candidate addresses: private LAN addresses first, then anything
     * else that is usable. Loopback / link-local / unusable values drop out.
     *
     * @return array<int, string>
     */
    protected static function pickBest(array $candidates): array
    {
        $usable = [];

        foreach (array_unique($candidates) as $ip) {
            if (!is_string($ip) || !self::isUsableIpv4($ip)) {
                continue;
            }

            $usable[] = [$ip, self::score($ip)];
        }

        usort($usable, fn ($a, $b) => $b[1] <=> $a[1]);

        return array_column($usable, 0);
    }

    protected static function isUsableIpv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $long = ip2long($ip);

        $excluded = [
            ['0.0.0.0', 8],      // "this network"
            ['127.0.0.0', 8],    // loopback
            ['169.254.0.0', 16], // link-local (APIPA)
            ['224.0.0.0', 4],    // multicast
            ['240.0.0.0', 4],    // reserved
        ];

        foreach ($excluded as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prefer RFC1918 private addresses (typical Wi-Fi/LAN), then CGNAT,
     * then any other routable address (e.g. a VPN with public addressing).
     */
    protected static function score(string $ip): int
    {
        $long = ip2long($ip);

        $private = [
            ['10.0.0.0', 8],
            ['172.16.0.0', 12],
            ['192.168.0.0', 16],
        ];

        foreach ($private as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return 3;
            }
        }

        $cgnat = ['100.64.0.0', 10];
        $mask = -1 << (32 - $cgnat[1]);
        if (($long & $mask) === (ip2long($cgnat[0]) & $mask)) {
            return 2;
        }

        return 1;
    }

    protected static function isLoopback(string $host): bool
    {
        $host = trim($host, '[]');

        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain', '0.0.0.0', '::1'], true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($host, '127.');
        }

        return false;
    }
}
