<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fail loudly on lazy loading, silently discarded attributes and
        // missing attributes outside production, so data-access bugs show
        // up in tests rather than in a compliance record.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
