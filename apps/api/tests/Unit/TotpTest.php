<?php

use App\Security\RecoveryCodes;
use App\Security\Totp;
use Tests\TestCase;

uses(TestCase::class);

// RFC 6238 Appendix B, SHA-1, secret "12345678901234567890", 8 digits.
dataset('rfc6238', [
    [59, '94287082'],
    [1111111109, '07081804'],
    [1111111111, '14050471'],
    [1234567890, '89005924'],
    [2000000000, '69279037'],
    [20000000000, '65353130'],
]);

it('matches the RFC 6238 test vectors', function (int $time, string $expected) {
    $secret = Totp::base32Encode('12345678901234567890');
    expect($secret)->toBe('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ')
        ->and(Totp::code($secret, $time, 8))->toBe($expected);
})->with('rfc6238');

it('round-trips base32', function () {
    $bytes = random_bytes(20);
    expect(Totp::base32Decode(Totp::base32Encode($bytes)))->toBe($bytes);
});

it('accepts the current code and one step either side, and nothing further', function () {
    $secret = Totp::generateSecret();
    $now = 1_800_000_000;

    expect(Totp::verify($secret, Totp::code($secret, $now), $now))->toBe(intdiv($now, 30))
        ->and(Totp::verify($secret, Totp::code($secret, $now - 30), $now))->not->toBeNull()
        ->and(Totp::verify($secret, Totp::code($secret, $now + 30), $now))->not->toBeNull()
        ->and(Totp::verify($secret, Totp::code($secret, $now - 90), $now))->toBeNull();
});

it('rejects a code from an already used time step (replay)', function () {
    $secret = Totp::generateSecret();
    $now = 1_800_000_000;
    $step = Totp::verify($secret, Totp::code($secret, $now), $now);

    expect(Totp::verify($secret, Totp::code($secret, $now), $now, lastUsedStep: $step))->toBeNull();
});

it('rejects malformed codes', function () {
    $secret = Totp::generateSecret();
    expect(Totp::verify($secret, 'abcdef'))->toBeNull()
        ->and(Totp::verify($secret, '12345'))->toBeNull();
});

it('builds an authenticator provisioning URI', function () {
    $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'pat@example.test');
    expect($uri)->toStartWith('otpauth://totp/RuleRadar:pat%40example.test?')
        ->toContain('secret=JBSWY3DPEHPK3PXP')
        ->toContain('issuer=RuleRadar');
});

it('consumes each recovery code exactly once', function () {
    $codes = RecoveryCodes::generate();
    expect($codes['plain'])->toHaveCount(RecoveryCodes::COUNT);

    $remaining = RecoveryCodes::consume($codes['hashes'], $codes['plain'][0]);
    expect($remaining)->toHaveCount(RecoveryCodes::COUNT - 1)
        ->and(RecoveryCodes::consume($remaining, $codes['plain'][0]))->toBeNull()
        ->and(RecoveryCodes::consume($remaining, strtoupper($codes['plain'][1])))->not->toBeNull();
});
