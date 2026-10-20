<?php

namespace App\Security;

use Illuminate\Support\Str;

/**
 * One-time recovery codes. Only keyed hashes are stored; the plain codes
 * are shown to the user once.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    /** @return array{plain: list<string>, hashes: list<string>} */
    public static function generate(): array
    {
        $plain = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $plain[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return ['plain' => $plain, 'hashes' => array_map(self::hash(...), $plain)];
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', Str::lower(trim($code)), (string) config('app.key'));
    }

    /**
     * @param  list<string>  $hashes
     * @return list<string>|null remaining hashes if the code matched, else null
     */
    public static function consume(array $hashes, string $code): ?array
    {
        $candidate = self::hash($code);
        foreach ($hashes as $i => $hash) {
            if (hash_equals($hash, $candidate)) {
                unset($hashes[$i]);

                return array_values($hashes);
            }
        }

        return null;
    }
}
