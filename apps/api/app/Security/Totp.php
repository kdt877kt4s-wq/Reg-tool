<?php

namespace App\Security;

use InvalidArgumentException;

/**
 * Time-based one-time passwords (RFC 6238 over RFC 4226 HOTP), compatible
 * with standard authenticator apps. Secrets are base32 encoded.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, int $timestamp, int $digits = self::DIGITS, string $algorithm = 'sha1'): string
    {
        return self::hotp(self::base32Decode($secret), intdiv($timestamp, self::PERIOD), $digits, $algorithm);
    }

    /**
     * Verify a code within +/- $window steps. Returns the matched time step
     * (store it to reject replays), or null when the code does not match.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1, ?int $lastUsedStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $key = self::base32Decode($secret);
        $step = intdiv($timestamp ?? time(), self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = $step + $offset;
            if ($lastUsedStep !== null && $candidate <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::hotp($key, $candidate, self::DIGITS, 'sha1'), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer = 'RuleRadar'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer),
            rawurlencode($account),
            http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986),
        );
    }

    private static function hotp(string $key, int $counter, int $digits, string $algorithm): string
    {
        $hash = hash_hmac($algorithm, pack('J', $counter), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim(preg_replace('/\s+/', '', $secret) ?? '', '='));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
