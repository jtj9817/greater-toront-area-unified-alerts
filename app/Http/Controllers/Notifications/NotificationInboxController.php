<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class NotificationInboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $includeDismissed = $request->boolean('include_dismissed');
        $perPage = max(1, min(100, $request->integer('per_page', 25)));

        $query = NotificationLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('sent_at')
            ->orderByDesc('id');

        if (! $includeDismissed) {
            $query->whereNull('dismissed_at');
        }

        $logs = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $logs->getCollection()->map(
                fn (NotificationLog $log): array => $this->serializeLog($log),
            )->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'unread_count' => $this->unreadCount($userId),
            ],
            'links' => [
                'next' => $logs->nextPageUrl(),
                'prev' => $logs->previousPageUrl(),
            ],
        ]);
    }

    public function markRead(Request $request, int $notificationLog): JsonResponse
    {
        $log = $this->ownedLog(
            userId: $request->user()->id,
            logId: $notificationLog,
        );

        if ($log->read_at === null) {
            $log->read_at = now();
        }

        if ($log->status !== 'dismissed') {
            $log->status = 'read';
        }

        $log->save();

        return response()->json([
            'data' => $this->serializeLog($log->fresh()),
        ]);
    }

    public function dismiss(Request $request, int $notificationLog): JsonResponse
    {
        $log = $this->ownedLog(
            userId: $request->user()->id,
            logId: $notificationLog,
        );

        if ($log->dismissed_at === null) {
            $log->dismissed_at = now();
        }

        if ($log->read_at === null) {
            $log->read_at = $log->dismissed_at;
        }

        $log->status = 'dismissed';
        $log->save();

        return response()->json([
            'data' => $this->serializeLog($log->fresh()),
        ]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $now = now();

        $dismissedCount = NotificationLog::query()
            ->where('user_id', $userId)
            ->whereNull('dismissed_at')
            ->update([
                'read_at' => DB::raw('COALESCE(read_at, CURRENT_TIMESTAMP)'),
                'dismissed_at' => $now,
                'status' => 'dismissed',
            ]);

        return response()->json([
            'meta' => [
                'dismissed_count' => $dismissedCount,
                'unread_count' => $this->unreadCount($userId),
            ],
        ]);
    }

    private function ownedLog(int $userId, int $logId): NotificationLog
    {
        return NotificationLog::query()
            ->where('user_id', $userId)
            ->whereKey($logId)
            ->firstOrFail();
    }

    private function unreadCount(int $userId): int
    {
        return NotificationLog::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLog(NotificationLog $log): array
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $metadataType = Arr::get($metadata, 'type');
        $isDigest = $log->delivery_method === 'in_app_digest' || $metadataType === 'daily_digest';

        return [
            'id' => $log->id,
            'alert_id' => $log->alert_id,
            'type' => $isDigest ? 'digest' : 'alert',
            'delivery_method' => $log->delivery_method,
            'status' => $log->status,
            'sent_at' => $log->sent_at?->toIso8601String(),
            'read_at' => $log->read_at?->toIso8601String(),
            'dismissed_at' => $log->dismissed_at?->toIso8601String(),
            'metadata' => $metadata,
        ];
    }
}
