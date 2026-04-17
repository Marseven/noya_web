<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;

class NotificationPublisher
{
    /**
     * Publish notification to users attached to one or more actors.
     * Super admins are always included for global visibility.
     *
     * @param array<int> $merchantIds
     */
    public static function publishForMerchants(
        array $merchantIds,
        string $type,
        string $title,
        string $message,
        ?int $relatedId = null
    ): void {
        $merchantIds = collect($merchantIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $actorUserIds = [];
        if (!empty($merchantIds)) {
            $actorUserIds = User::query()
                ->where('status', 'APPROVED')
                ->whereHas('merchants', function ($q) use ($merchantIds) {
                    $q->whereIn('merchants.id', $merchantIds);
                })
                ->pluck('users.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $superAdminIds = User::query()
            ->where('status', 'APPROVED')
            ->whereHas('role', function ($q) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%super admin%']);
            })
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $userIds = array_values(array_unique(array_merge($actorUserIds, $superAdminIds)));

        foreach ($userIds as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'related_id' => $relatedId,
                'is_read' => false,
            ]);
        }
    }
}
