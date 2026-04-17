<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends BaseController
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);
        $items = Notification::query()
            ->where('user_id', (int) $request->user()->id)
            ->latest('created_at')
            ->limit($perPage)
            ->get();

        return $this->sendResponse(NotificationResource::collection($items), 'Notifications retrieved successfully');
    }

    public function unreadCount(Request $request)
    {
        $count = Notification::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_read', false)
            ->count();

        return $this->sendResponse(['unread_count' => (int) $count], 'Unread notification count retrieved successfully');
    }

    public function readAll(Request $request)
    {
        Notification::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->sendResponse(null, 'All notifications marked as read');
    }

    public function markRead(Request $request, $notificationId)
    {
        $notification = Notification::query()
            ->where('id', (int) $notificationId)
            ->where('user_id', (int) $request->user()->id)
            ->first();

        if (!$notification) {
            return $this->sendNotFound('Notification not found');
        }

        if (!$notification->is_read) {
            $notification->is_read = true;
            $notification->read_at = now();
            $notification->save();
        }

        return $this->sendResponse(new NotificationResource($notification), 'Notification marked as read');
    }
}
