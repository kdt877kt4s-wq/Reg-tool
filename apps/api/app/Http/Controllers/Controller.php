<?php

namespace App\Http\Controllers;

use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

abstract class Controller
{
    /**
     * Server-side permission check against the current membership's role.
     *
     * @throws AuthorizationException
     */
    protected function authorizePermission(string $permission): void
    {
        if (! Permissions::allows(app(TenantContext::class)->role(), $permission)) {
            throw new AuthorizationException('You do not have permission to do this.');
        }
    }

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }
}
