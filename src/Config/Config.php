<?php

namespace React\Dns\Config;

use RuntimeException;

final class Config
{
    /**
     * Loads the system DNS configuration
     *
     * Note that this method may block while loading its internal files and/or
     * commands and should thus be used with care! While this should be
     * relatively fast for most systems, it remains unknown if this may block
     * under certain circumstances. In particular, this method should only be
     * executed before the loop starts, not while it is running.
     *
     * Note that this method will try to access its files and/or commands and
     * try to parse its output. Currently, this will only parse valid nameserver
     * entries from its output and will ignore all other output without
     * complaining.
     *
     * Note that the previous section implies that this may return an empty
     * `Config` object if no valid nameserver entries can be found.
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function loadSystemConfigBlocking()
    {
        /* Use WMIC or PowerShell on Windows
         * WMIC is faster where available, but was removed in Windows 11 24H2+
         * PowerShell is slower, but is available on all Windows versions
         *
         * Only try WMIC if the executable actually exists, so we never spawn
         * a missing command (which would print an error to STDERR and could
         * be reported as a failure by the surrounding application).
         */
        if (DIRECTORY_SEPARATOR === '\\') {
            if (self::isWmicAvailable()) {
                $config = self::loadWmicBlocking();
                if ($config->nameservers) {
                    return $config;
                }
            }

            return self::loadPowershellBlocking();
        }

        // otherwise (try to) load from resolv.conf
        try {
            return self::loadResolvConfBlocking();
        } catch (RuntimeException $ignored) {
            // return empty config if parsing fails (file not found)
            return new self();
        }
    }

    /**
     * Loads a resolv.conf file (from the given path or default location)
     *
     * Note that this method blocks while loading the given path and should
     * thus be used with care! While this should be relatively fast for normal
     * resolv.conf files, this may be an issue if this file is located on a slow
     * device or contains an excessive number of entries. In particular, this
     * method should only be executed before the loop starts, not while it is
     * running.
     *
     * Note that this method will throw if the given file can not be loaded,
     * such as if it is not readable or does not exist. In particular, this file
     * is not available on Windows.
     *
     * Currently, this will only parse valid "nameserver X" lines from the
     * given file contents. Lines can be commented out with "#" and ";" and
     * invalid lines will be ignored without complaining. See also
     * `man resolv.conf` for more details.
     *
     * Note that the previous section implies that this may return an empty
     * `Config` object if no valid "nameserver X" lines can be found. See also
     * `man resolv.conf` which suggests that the DNS server on the localhost
     * should be used in this case. This is left up to higher level consumers
     * of this API.
     *
     * @param ?string $path (optional) path to resolv.conf file or null=load default location
     * @return self
     * @throws RuntimeException if the path can not be loaded (does not exist)
     */
    public static function loadResolvConfBlocking($path = null)
    {
        if ($path === null) {
            $path = '/etc/resolv.conf';
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to load resolv.conf file "' . $path . '"');
        }

        $matches = array();
        preg_match_all('/^nameserver\s+(\S+)\s*$/m', $contents, $matches);

        $config = new self();
        foreach ($matches[1] as $ip) {
            // remove IPv6 zone ID (`fe80::1%lo0` => `fe80:1`)
            if (strpos($ip, ':') !== false && ($pos = strpos($ip, '%')) !== false) {
                $ip = substr($ip, 0, $pos);
            }

            if (@inet_pton($ip) !== false) {
                $config->nameservers[] = $ip;
            }
        }

        return $config;
    }

    /**
     * Loads the DNS configurations using Windows PowerShell
     *
     * Note that this method blocks while loading the given command and should
     * thus be used with care! While this should be relatively fast for normal
     * PowerShell commands, it remains unknown if this may block under certain
     * circumstances. In particular, this method should only be executed before
     * the loop starts, not while it is running.
     *
     * Note that this method will only try to execute the given command and try to
     * parse its output, irrespective of whether this command exists. In
     * particular, this method requires the DnsClient module which is only available
     * on Windows 8/Server 2012 and later. Currently, this will only parse valid
     * nameserver entries from the command output and will ignore all other output
     * without complaining.
     *
     * Note that the previous section implies that this may return an empty
     * `Config` object if no valid nameserver entries can be found.
     *
     * @param ?string $command (advanced) should not be given (NULL) unless you know what you're doing
     * @return self
     * @link https://learn.microsoft.com/en-us/powershell/module/dnsclient/get-dnsclientserveraddress
     */
    public static function loadPowershellBlocking($command = null)
    {
        $contents = shell_exec($command === null ? 'powershell -NoLogo -NoProfile -NonInteractive -Command "Get-DnsClientServerAddress | Select-Object -ExpandProperty ServerAddresses" 2>nul' : $command);

        $config = new self();
        if (is_string($contents)) {
            foreach (explode("\n", $contents) as $line) {
                $ip = trim($line);
                if ($ip === '' || @inet_pton($ip) === false) {
                    continue;
                }

                // skip Windows placeholder DNS addresses (fec0:0:0:ffff::1, ::2, ::3)
                // these are added to interfaces without explicit DNS configuration and don't resolve anything
                if (preg_match('/^fec0:0:0:ffff::/i', $ip)) {
                    continue;
                }

                $config->nameservers[] = $ip;
            }
            $config->nameservers = array_values(array_unique($config->nameservers));
        }

        return $config;
    }

    /**
     * Loads the DNS configurations from Windows's WMIC (from the given command or default command)
     *
     * Note that this method blocks while loading the given command and should
     * thus be used with care! While this should be relatively fast for normal
     * WMIC commands, it remains unknown if this may block under certain
     * circumstances. In particular, this method should only be executed before
     * the loop starts, not while it is running.
     *
     * Note that this method will only try to execute the given command try to
     * parse its output, irrespective of whether this command exists. In
     * particular, this command is only available on Windows. Currently, this
     * will only parse valid nameserver entries from the command output and will
     * ignore all other output without complaining.
     *
     * Note that WMIC has been deprecated and removed in recent Windows versions
     * (Windows 11 24H2+). Consider using loadPowershellBlocking() instead.
     *
     * Note that the previous section implies that this may return an empty
     * `Config` object if no valid nameserver entries can be found.
     *
     * @param ?string $command (advanced) should not be given (NULL) unless you know what you're doing
     * @return self
     * @link https://ss64.com/nt/wmic.html
     * @deprecated WMIC is deprecated on Windows, use loadPowershellBlocking() instead
     */
    public static function loadWmicBlocking($command = null)
    {
        $contents = shell_exec($command === null ? 'wmic NICCONFIG get "DNSServerSearchOrder" /format:CSV 2>nul' : $command);
        preg_match_all('/(?<=[{;,"])([\da-f.:]{4,})(?=[};,"])/i', $contents ?? '', $matches);

        $config = new self();
        $config->nameservers = $matches[1];

        return $config;
    }

    /**
     * Checks whether the WMIC executable is available on this Windows system
     *
     * WMIC is a "Feature on Demand" since Windows 11 24H2 / Server 2025 and
     * is no longer installed by default. Spawning a missing command via
     * `shell_exec()` would make cmd.exe print an error message, so we check
     * for the executable first and simply skip WMIC if it is not present.
     *
     * @return bool
     */
    private static function isWmicAvailable()
    {
        $root = getenv('SystemRoot');
        if ($root === false || $root === '') {
            $root = getenv('WINDIR');
        }
        if ($root === false || $root === '') {
            $root = 'C:\\Windows';
        }

        return @is_file($root . '\\System32\\wbem\\wmic.exe');
    }

    public $nameservers = array();
}
