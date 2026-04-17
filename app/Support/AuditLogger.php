<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

class AuditLogger
{
    /**
     * Best-effort audit log (never blocks business flow).
     */
    public static function log(
        ?Request $request,
        string $action,
        ?Model $auditable = null,
        array $metadata = []
    ): void {
        try {
            $user = $request?->user();
            $merchantId = null;
            if ($user && method_exists($user, 'merchants')) {
                $merchantId = $user->merchants()->pluck('merchants.id')->first();
                $merchantId = $merchantId ? (int) $merchantId : null;
            }

            AuditLog::create([
                'user_id' => $user?->id,
                'merchant_id' => $merchantId,
                'action' => $action,
                'auditable_type' => $auditable ? $auditable::class : null,
                'auditable_id' => $auditable?->getKey(),
                'metadata' => $metadata,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (Throwable $e) {
            // Ignore logging failures to preserve main transaction flow.
        }
    }
}

