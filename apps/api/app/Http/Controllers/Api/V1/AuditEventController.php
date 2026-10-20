<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('audit.view');
        $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        $page = AuditEvent::query()
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->input('limit', 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'actor' => $e->actor?->name,
                'subject_type' => $e->subject_type,
                'subject_id' => $e->subject_id,
                'before' => $e->before,
                'after' => $e->after,
                'created_at' => $e->created_at->toIso8601String(),
                'hash' => $e->hash,
            ])->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function verify(): JsonResponse
    {
        $this->authorizePermission('audit.view');

        return response()->json(AuditLogger::verify((int) $this->tenant()->organizationId()));
    }
}
