<?php

declare(strict_types=1);

namespace App;

class AccessControl
{
    public static function getAccessLevel(string $clientIP, array $adminCIDRs, array $viewCIDRs): string
    {
        if (self::ipMatchesCIDRs($clientIP, $adminCIDRs)) {
            return 'admin';
        }
        if (self::ipMatchesCIDRs($clientIP, $viewCIDRs)) {
            return 'view';
        }
        return 'none';
    }

    public static function ipMatchesCIDRs(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipInCIDR($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function ipInCIDR(string $ip, string $cidr): bool
    {
        if (str_contains($cidr, '/')) {
            [$subnet, $bits] = explode('/', $cidr, 2);
            $bits = (int) $bits;

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipLong = ip2long($ip);
                $subnetLong = ip2long($subnet);
                if ($ipLong === false || $subnetLong === false) return false;
                $mask = -1 << (32 - $bits);
                return ($ipLong & $mask) === ($subnetLong & $mask);
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ipBin = inet_pton($ip);
                $subnetBin = inet_pton($subnet);
                if ($ipBin === false || $subnetBin === false) return false;
                $fullBytes = intdiv($bits, 8);
                if (substr_compare($ipBin, $subnetBin, 0, $fullBytes) !== 0) return false;
                $remainBits = $bits % 8;
                if ($remainBits === 0) return true;
                $maskByte = 0xFF << (8 - $remainBits);
                return (ord($ipBin[$fullBytes]) & $maskByte) === (ord($subnetBin[$fullBytes]) & $maskByte);
            }

            return false;
        }

        return $ip === $cidr;
    }
}
