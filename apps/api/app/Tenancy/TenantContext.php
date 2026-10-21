<?php

namespace App\Tenancy;

use App\Models\Membership;
use App\Models\Organization;
use App\Support\Role;
use Illuminate\Support\Facades\DB;

/**
 * The tenant for the current unit of work (HTTP request, job, command).
 *
 * It is mirrored into PostgreSQL session settings so row-level security
 * can enforce the same boundary the application enforces. With no
 * organization set and no bypass, tenant tables return nothing.
 */
final class TenantContext
{
    private ?int $userId = null;

    private ?Organization $organization = null;

    private ?Membership $membership = null;

    private bool $bypass = false;

    public function reset(): void
    {
        $this->userId = null;
        $this->organization = null;
        $this->membership = null;
        $this->bypass = false;
        $this->sync();
    }

    public function setUser(?int $userId): void
    {
        $this->userId = $userId;
        $this->sync();
    }

    public function setOrganization(Organization $organization, Membership $membership): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
        $this->sync();
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function organizationId(): ?int
    {
        return $this->organization?->getKey();
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function role(): ?Role
    {
        return $this->membership?->role;
    }

    public function bypassing(): bool
    {
        return $this->bypass;
    }

    /** Turn system mode on or off for a whole command run. */
    public function setBypass(bool $on): void
    {
        $this->bypass = $on;
        $this->sync();
    }

    /**
     * Run trusted system work (registration, invitation lookup, seeding)
     * outside tenant isolation, then restore the previous state.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function bypass(callable $callback): mixed
    {
        $previous = $this->bypass;
        $this->setBypass(true);

        try {
            return $callback();
        } finally {
            $this->setBypass($previous);
        }
    }

    /** @return array{0: ?int, 1: ?Organization, 2: ?Membership, 3: bool} */
    public function snapshot(): array
    {
        return [$this->userId, $this->organization, $this->membership, $this->bypass];
    }

    /** @param array{0: ?int, 1: ?Organization, 2: ?Membership, 3: bool} $state */
    public function restore(array $state): void
    {
        [$this->userId, $this->organization, $this->membership, $this->bypass] = $state;
        $this->sync();
    }

    private function sync(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            "select set_config('app.user_id', ?, false),
                    set_config('app.organization_id', ?, false),
                    set_config('app.bypass_rls', ?, false)",
            [
                $this->userId === null ? '' : (string) $this->userId,
                $this->organizationId() === null ? '' : (string) $this->organizationId(),
                $this->bypass ? 'on' : 'off',
            ],
        );
    }
}
