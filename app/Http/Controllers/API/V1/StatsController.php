<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\API\V1\Concerns\InteractsWithMerchantScope;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Http\Request;

class StatsController extends BaseController
{
    use InteractsWithMerchantScope;

    public function overview(Request $request)
    {
        $merchantIds = $this->accessibleMerchantIds($request);

        $isSuperAdmin = $this->isSuperAdmin($request);

        $merchantQuery = Merchant::query();
        $orderQuery = Order::query();
        $stockQuery = Stock::query();
        $paymentQuery = Payment::query();
        $usersQuery = User::query();

        if (!$isSuperAdmin) {
            if (empty($merchantIds)) {
                $merchantQuery->whereRaw('1 = 0');
                $orderQuery->whereRaw('1 = 0');
                $stockQuery->whereRaw('1 = 0');
                $paymentQuery->whereRaw('1 = 0');
                $usersQuery->where('id', $request->user()->id);
            } else {
                $merchantQuery->whereIn('id', $merchantIds);
                $orderQuery->whereIn('merchant_id', $merchantIds);
                $stockQuery->whereIn('merchant_id', $merchantIds);
                $paymentQuery->whereHas('order', function ($q) use ($merchantIds) {
                    $q->whereIn('merchant_id', $merchantIds);
                });
                $usersQuery->where(function ($q) use ($merchantIds, $request) {
                    $q->where('id', $request->user()->id)
                        ->orWhereHas('merchants', function ($mq) use ($merchantIds) {
                            $mq->whereIn('merchants.id', $merchantIds);
                        });
                });
            }
        }

        $usersByStatus = (clone $usersQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $merchantsByType = (clone $merchantQuery)
            ->selectRaw('type, COUNT(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $ordersByStatus = (clone $orderQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $paymentsByStatus = (clone $paymentQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $overview = [
            'users' => [
                'total' => (clone $usersQuery)->count(),
                'approved' => (int) ($usersByStatus['APPROVED'] ?? 0),
                'pending' => (int) ($usersByStatus['PENDING'] ?? 0),
                'blocked' => (int) (($usersByStatus['REJECTED'] ?? 0) + ($usersByStatus['BLOCKED'] ?? 0)),
            ],
            'merchants' => [
                'total' => (clone $merchantQuery)->count(),
                'by_type' => $merchantsByType->map(fn ($count) => (int) $count)->all(),
                'approved' => (int) (clone $merchantQuery)->where('status', 'APPROVED')->count(),
            ],
            'orders' => [
                'total' => (clone $orderQuery)->count(),
                'by_status' => $ordersByStatus->map(fn ($count) => (int) $count)->all(),
                'total_revenue' => (float) (clone $paymentQuery)->where('status', 'PAID')->sum('amount'),
            ],
            'stocks' => [
                'total_items' => (int) (clone $stockQuery)->sum('stock'),
                'low_stock_count' => (int) (clone $stockQuery)->where('stock', '>', 0)->where('stock', '<=', 10)->count(),
                'out_of_stock_count' => (int) (clone $stockQuery)->where('stock', '<=', 0)->count(),
            ],
            'payments' => [
                'total' => (clone $paymentQuery)->count(),
                'total_amount' => (float) (clone $paymentQuery)->sum('amount'),
                'by_status' => $paymentsByStatus->map(fn ($count) => (int) $count)->all(),
            ],
        ];

        return $this->sendResponse($overview, 'Dashboard statistics retrieved successfully');
    }
}
