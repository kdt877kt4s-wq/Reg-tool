<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

it('gives the same answer whether or not the account exists', function () {
    Notification::fake();
    $user = createUser('pat@example.test');

    $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'pat@example.test'])->assertOk()->json('message');
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])->assertOk()->json('message');

    expect($known)->toBe($unknown);
    Notification::assertSentTo($user, ResetPassword::class);
});

it('resets the password with the emailed token and signs out other sessions', function () {
    Notification::fake();
    $user = createUser('pat@example.test');
    $user->createToken('web');

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'pat@example.test']);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'pat@example.test',
        'password' => 'a brand new long passphrase',
        'password_confirmation' => 'a brand new long passphrase',
    ])->assertOk();

    expect(PersonalAccessToken::count())->toBe(0);
    $this->postJson('/api/v1/auth/login', ['email' => 'pat@example.test', 'password' => 'a brand new long passphrase'])->assertOk();
});

it('rejects an invalid reset token', function () {
    createUser('pat@example.test');

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => 'not-a-real-token',
        'email' => 'pat@example.test',
        'password' => 'a brand new long passphrase',
        'password_confirmation' => 'a brand new long passphrase',
    ])->assertUnprocessable();
});
