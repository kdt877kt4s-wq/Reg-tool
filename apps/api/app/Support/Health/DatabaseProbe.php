<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseProbe
{
    /** @return array{status: string, detail?: string} */
    public function check(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable) {
            // Never leak connection strings or driver errors.
            return ['status' => 'error', 'detail' => 'database unreachable'];
        }
    }
}
