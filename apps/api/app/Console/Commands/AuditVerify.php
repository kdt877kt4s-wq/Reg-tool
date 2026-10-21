<?php

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Recompute every organization's audit hash chain; fail loudly if one breaks. */
class AuditVerify extends Command
{
    protected $signature = 'ruleradar:audit-verify';

    protected $description = 'Verify the audit log hash chain of every organization';

    public function handle(): int
    {
        $broken = 0;
        foreach (Organization::query()->orderBy('id')->pluck('id') as $id) {
            $result = AuditLogger::verify($id);
            if (! $result['valid']) {
                $broken++;
                Log::critical('audit.chain_broken', ['organization' => $id] + $result);
                $this->error("Organization {$id}: chain breaks at entry {$result['broken_at_id']}");
            }
        }
        $this->line(json_encode(['organizations_checked' => Organization::query()->count(), 'broken' => $broken]));

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }
}
