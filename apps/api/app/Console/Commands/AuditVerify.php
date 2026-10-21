<?php

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Recompute every organization's audit hash chain; fail loudly if one breaks. */
class AuditVerify extends Command
{
    protected $signature = 'ruleradar:audit-verify';

    protected $description = 'Verify the audit log hash chain of every organization';

    public function handle(TenantContext $context): int
    {
        $broken = 0;
        $ids = $context->bypass(fn () => Organization::query()->orderBy('id')->pluck('id'));
        foreach ($ids as $id) {
            $result = $context->bypass(fn () => AuditLogger::verify($id));
            if (! $result['valid']) {
                $broken++;
                Log::critical('audit.chain_broken', ['organization' => $id] + $result);
                $this->error("Organization {$id}: chain breaks at entry {$result['broken_at_id']}");
            }
        }
        $this->line(json_encode(['organizations_checked' => $ids->count(), 'broken' => $broken]));

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }
}
