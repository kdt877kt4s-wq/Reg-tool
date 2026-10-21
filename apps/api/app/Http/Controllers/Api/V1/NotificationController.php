<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\UserNotification;
use App\Notifications\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A person's own notifications in the current organization. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $unreadOnly = $request->boolean('unread');
        $rows = $this->mine()
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')
            ->limit(min(100, max(1, (int) $request->query('limit', 50))))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (UserNotification $n) => [
                'id' => $n->id,
                'kind' => $n->kind,
                'title' => $n->title,
                'body' => $n->body,
                'path' => $n->url,
                'read_at' => $n->read_at?->toIso8601String(),
                'emailed' => $n->emailed_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ])->values(),
            'unread_count' => $this->mine()->whereNull('read_at')->count(),
        ]);
    }

    public function read(int $notificationId): JsonResponse
    {
        $n = $this->mine()->findOrFail($notificationId);
        $n->read_at ??= now();
        $n->save();

        return response()->json(['unread_count' => $this->mine()->whereNull('read_at')->count()]);
    }

    public function readAll(): JsonResponse
    {
        $this->mine()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function preferences(): JsonResponse
    {
        return response()->json(['data' => $this->presentPreferences()]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'array'],
            'email.*' => ['boolean'],
        ]);
        $unknown = array_diff(array_keys($data['email']), array_keys(Notifier::KINDS));
        if ($unknown !== []) {
            return response()->json(['message' => 'Unknown notification type.', 'errors' => ['email' => ['Unknown notification type.']]], 422);
        }

        $userId = (int) $this->tenant()->userId();
        foreach ($data['email'] as $kind => $on) {
            NotificationPreference::query()->updateOrCreate(['user_id' => $userId, 'kind' => $kind], ['email' => (bool) $on]);
        }

        return response()->json(['data' => $this->presentPreferences()]);
    }

    /** @return Builder<UserNotification> */
    private function mine(): Builder
    {
        return UserNotification::query()->where('user_id', $this->tenant()->userId());
    }

    /** @return list<array<string, mixed>> */
    private function presentPreferences(): array
    {
        $saved = NotificationPreference::query()->where('user_id', $this->tenant()->userId())->pluck('email', 'kind');

        return collect(Notifier::KINDS)->map(fn ($label, $kind) => [
            'kind' => $kind,
            'label' => $label,
            'email' => (bool) ($saved[$kind] ?? true),
            'in_app' => true,
        ])->values()->all();
    }
}
