<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Membership;
use App\Models\RegulatoryPassage;
use App\Models\StoredFile;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Tasks\TaskAccess;
use App\Tasks\TaskWorkflow;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    public function __construct(private TaskAccess $access, private TaskWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'view' => ['sometimes', Rule::in(['mine', 'visible'])],
            'status' => ['sometimes', 'nullable', Rule::in(['active', ...Task::ACTIVE, Task::DONE, Task::CANCELLED])],
            'overdue' => ['sometimes', 'boolean'],
            'unassigned' => ['sometimes', 'boolean'],
            'document_id' => ['sometimes', 'integer'],
        ]);

        $query = $this->access->scope(Task::query())
            ->with(['owner:id,name', 'department:id,name', 'document:id,external_id,title'])
            ->when(($filters['view'] ?? 'visible') === 'mine', fn ($q) => $q->where('owner_id', $this->tenant()->userId()))
            ->when(($filters['status'] ?? 'active') === 'active', fn ($q) => $q->whereIn('status', Task::ACTIVE))
            ->when(isset($filters['status']) && $filters['status'] !== 'active', fn ($q) => $q->where('status', $filters['status']))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', Task::ACTIVE)->whereDate('due_date', '<', today()))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('owner_id'))
            ->when($filters['document_id'] ?? null, fn ($q, $id) => $q->where('document_id', $id))
            ->orderByRaw('due_date IS NULL, due_date')
            ->orderByDesc('id');

        return response()->json([
            'data' => $query->limit(200)->get()->map(fn (Task $t) => $this->summary($t))->values(),
            'can_create' => $this->access->canCreate($this->tenant()->membership()->department_id) || $this->access->canCreate(null),
        ]);
    }

    /** People the current person may assign tasks to. */
    public function assignees(): JsonResponse
    {
        $membership = $this->tenant()->membership();
        abort_unless($this->access->canCreate(null) || $this->access->canCreate($membership->department_id), 403);

        $rows = $this->assignable()
            ->with(['user:id,name', 'department:id,name'])
            ->get()
            ->sortBy(fn (Membership $m) => mb_strtolower($m->user->name))
            ->map(fn (Membership $m) => [
                'id' => $m->user_id,
                'name' => $m->user->name,
                'role_label' => $m->role->label(),
                'department' => $m->department ? ['id' => $m->department->id, 'name' => $m->department->name] : null,
            ])->values();

        return response()->json([
            'data' => $rows,
            'departments' => Department::query()
                ->when(! $this->access->canCreate(null), fn ($q) => $q->whereKey($membership->department_id))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Active members; a department manager may assign only within their department. */
    private function assignable(): Builder
    {
        $membership = $this->tenant()->membership();

        return Membership::query()
            ->where('status', Membership::ACTIVE)
            ->when(! $this->access->canCreate(null), fn ($q) => $q->where('department_id', $membership->department_id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);
        abort_unless($this->access->canCreate($data['department_id'] ?? null), 403, 'You can create tasks only for your own department.');

        $task = $this->workflow->create($data);

        return response()->json(['data' => $this->detail($task->fresh())], 201);
    }

    public function show(int $taskId): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->find($taskId))]);
    }

    public function update(Request $request, int $taskId): JsonResponse
    {
        $task = $this->find($taskId);
        abort_unless($this->access->canManage($task) && in_array($task->status, Task::ACTIVE, true), 403, 'You cannot change this task.');

        $data = $this->validated($request, creating: false);
        if (array_key_exists('department_id', $data) && ! $this->access->canCreate($data['department_id'])) {
            abort(403, 'You can move tasks only within your own department.');
        }

        return response()->json(['data' => $this->detail($this->workflow->update($task, $data)->fresh())]);
    }

    public function transition(Request $request, int $taskId, string $action): JsonResponse
    {
        $task = $this->find($taskId);
        abort_unless(in_array($action, $this->access->allowedActions($task), true), 403, match (true) {
            $action === 'accept' && $this->access->canManage($task) => 'Someone other than the person who did the work must accept it.',
            default => 'You cannot do that with this task right now.',
        });

        $reason = fn () => $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']])['reason'];

        $task = match ($action) {
            'start' => $this->workflow->start($task),
            'submit' => $this->workflow->submit($task),
            'accept' => $this->workflow->accept($task),
            'return' => $this->workflow->returnToOwner($task, $reason()),
            'cancel' => $this->workflow->cancel($task, $reason()),
        };

        return response()->json(['data' => $this->detail($task->fresh())]);
    }

    public function addEvidence(Request $request, int $taskId, AuditLogger $audit): JsonResponse
    {
        $task = $this->find($taskId);
        abort_unless(in_array('add_evidence', $this->access->allowedActions($task), true), 403, 'You cannot add evidence to this task now.');

        $data = $request->validate([
            'file' => ['nullable', 'required_without:note', 'file', 'max:'.config('files.max_kb'), 'mimes:'.implode(',', config('files.mimes'))],
            'note' => ['nullable', 'required_without:file', 'string', 'max:5000'],
        ]);

        $evidence = DB::transaction(function () use ($request, $task, $data, $audit) {
            $file = null;
            if ($request->hasFile('file')) {
                $upload = $request->file('file');
                $disk = (string) config('files.disk');
                $key = $task->organization_id.'/'.now()->format('Y/m').'/'.Str::uuid()->toString();
                Storage::disk($disk)->putFileAs(dirname($key), $upload, basename($key));

                $file = StoredFile::create([
                    'disk' => $disk,
                    'storage_key' => $key,
                    'filename' => mb_substr(preg_replace('/[^\w.\- ()]+/u', '_', $upload->getClientOriginalName()) ?: 'evidence', 0, 255),
                    'mime' => $upload->getMimeType() ?? 'application/octet-stream',
                    'size' => $upload->getSize(),
                    'sha256' => hash_file('sha256', $upload->getRealPath()),
                    'uploaded_by' => $this->tenant()->userId(),
                ]);
            }

            $evidence = TaskEvidence::create([
                'task_id' => $task->id,
                'file_id' => $file?->id,
                'note' => isset($data['note']) ? trim($data['note']) : null,
                'submitted_by' => $this->tenant()->userId(),
                'created_at' => now(),
            ]);

            $this->workflow->event($task, 'evidence_added', array_filter(['file' => $file?->filename, 'sha256' => $file?->sha256, 'note' => $evidence->note ? true : null]));
            $audit->record('task.evidence_added', $task, null, array_filter(['file' => $file?->filename, 'sha256' => $file?->sha256, 'size' => $file?->size]));

            return $evidence;
        });

        return response()->json(['data' => $this->presentEvidence($evidence->load(['file', 'submitter:id,name']))], 201);
    }

    public function download(int $fileId, AuditLogger $audit): StreamedResponse|JsonResponse
    {
        $file = StoredFile::query()->findOrFail($fileId);
        $evidence = TaskEvidence::query()->where('file_id', $file->id)->firstOrFail();
        $task = Task::query()->findOrFail($evidence->task_id);
        abort_unless($this->access->canSee($task), 404);

        if ($file->scan_status === 'infected') {
            return response()->json(['message' => 'This file was blocked: the malware scan flagged it.', 'code' => 'file_infected'], 423);
        }
        if (config('files.require_scan') && $file->scan_status !== 'clean') {
            return response()->json(['message' => 'This file is not available until it passes a malware scan.', 'code' => 'file_not_scanned'], 423);
        }

        $audit->record('file.downloaded', $file, null, ['filename' => $file->filename, 'task_id' => $task->id]);

        return Storage::disk($file->disk)->download($file->storage_key, $file->filename, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function find(int $taskId): Task
    {
        $task = Task::query()->findOrFail($taskId);
        // Tasks a person cannot see do not exist for them.
        abort_unless($this->access->canSee($task), 404);

        return $task;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];
        $data = $request->validate([
            'title' => [...$sometimes, 'required', 'string', 'min:3', 'max:200'],
            'description' => [...$sometimes, 'nullable', 'string', 'max:5000'],
            'priority' => [...$sometimes, 'required', Rule::in(['high', 'medium', 'low'])],
            'owner_id' => ['sometimes', 'nullable', 'integer', $this->activeMember()],
            'department_id' => ['sometimes', 'nullable', 'integer', $this->department()],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date_type' => ['sometimes', 'nullable', 'required_with:due_date', Rule::in(['official', 'internal'])],
            'due_date_basis' => ['sometimes', 'nullable', 'string', 'max:200'],
            'document_id' => $creating ? ['sometimes', 'nullable', 'integer', Rule::exists('regulatory_documents', 'id')] : ['prohibited'],
        ]);

        if (($data['due_date'] ?? null) === null && array_key_exists('due_date', $data)) {
            $data['due_date_type'] = null;
            $data['due_date_basis'] = null;
        }
        // An official deadline must say where it comes from.
        if (($data['due_date_type'] ?? null) === 'official' && empty($data['due_date_basis'])) {
            throw ValidationException::withMessages(['due_date_basis' => 'Say which document or rule sets this official deadline.']);
        }

        return $data;
    }

    private function activeMember(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && ! $this->assignable()->where('user_id', $value)->exists()) {
                $fail($this->access->canCreate(null)
                    ? 'That person is not an active member of this organization.'
                    : 'You can assign tasks only to people in your department.');
            }
        };
    }

    private function department(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== null && ! Department::query()->whereKey($value)->exists()) {
                $fail('That department does not exist.');
            }
        };
    }

    /** @return array<string, mixed> */
    private function summary(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'status' => $t->status,
            'priority' => $t->priority,
            'owner' => $t->owner ? ['id' => $t->owner->id, 'name' => $t->owner->name] : null,
            'department' => $t->department ? ['id' => $t->department->id, 'name' => $t->department->name] : null,
            'due_date' => $t->due_date?->toDateString(),
            'due_date_type' => $t->due_date_type,
            'overdue' => $t->isOverdue(),
            'document' => $t->document ? ['id' => $t->document->id, 'document_number' => $t->document->external_id, 'title' => $t->document->title] : null,
            'updated_at' => $t->updated_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Task $t): array
    {
        $t->load(['owner:id,name', 'department:id,name', 'document:id,external_id,title,citation', 'action', 'events.actor:id,name', 'evidence.file', 'evidence.submitter:id,name']);

        $citations = $t->action?->citation_passage_ids ?? [];

        return $this->summary($t) + [
            'description' => $t->description,
            'due_date_basis' => $t->due_date_basis,
            'status_note' => $t->status_note,
            'assessment_id' => $t->assessment_id,
            'from_action' => $t->action_id !== null,
            'citations' => RegulatoryPassage::query()->whereIn('id', $citations)->get(['id', 'heading_path', 'page_start', 'page_end'])
                ->map(fn ($p) => ['id' => $p->id, 'heading_path' => $p->heading_path, 'page_start' => $p->page_start, 'page_end' => $p->page_end])->values(),
            'document_citation' => $t->document?->citation,
            'submitted_at' => $t->submitted_at?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'allowed_actions' => $this->access->allowedActions($t),
            'evidence' => $t->evidence->map(fn ($e) => $this->presentEvidence($e))->values(),
            'events' => $t->events->map(fn ($e) => [
                'type' => $e->type,
                'actor' => $e->actor?->name,
                'details' => $e->details,
                'created_at' => $e->created_at->toIso8601String(),
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentEvidence(TaskEvidence $e): array
    {
        return [
            'id' => $e->id,
            'note' => $e->note,
            'submitted_by' => $e->submitter?->name,
            'created_at' => $e->created_at->toIso8601String(),
            'file' => $e->file ? [
                'id' => $e->file->id,
                'filename' => $e->file->filename,
                'mime' => $e->file->mime,
                'size' => $e->file->size,
                'sha256' => $e->file->sha256,
                'scan_status' => $e->file->scan_status,
            ] : null,
        ];
    }
}
