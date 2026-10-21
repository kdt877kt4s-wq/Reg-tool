<?php

use App\Ai\AnthropicModel;
use App\Ai\LanguageModel;
use App\Ai\ScriptedModel;
use Tests\TestCase;

uses(TestCase::class);

it('refuses to stand in for the model outside the e2e environment', function () {
    expect(fn () => (new ScriptedModel)->callTool('m', 's', '<passage ref="P1">x</passage>', [], 100))
        ->toThrow(RuntimeException::class, 'ScriptedModel runs only in the e2e environment.');
});

it('uses the real model client in every environment but e2e', function () {
    expect(app(LanguageModel::class))->toBeInstanceOf(AnthropicModel::class);
});
