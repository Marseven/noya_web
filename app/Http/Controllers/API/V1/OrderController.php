<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\API\V1\Concerns\InteractsWithMerchantScope;
use App\Http\Resources\DeliveryHistoryResource;
use App\Http\Resources\OrderResource;
use App\Models\DeliveryHistory;
use App\Models\Order;
use App\Support\AuditLogger;
use App\Support\NotificationPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderController extends BaseController
{
    use InteractsWithMerchantScope;

    /**
     * @OA\Get(
     *      path="/api/v1/orders",
     *      operationId="getOrdersList",
     *      tags={"Orders"},
     *      summary="Get list of orders",
     *      description="Returns list of orders with pagination",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="page",
     *          description="Page number",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Parameter(
     *          name="per_page",
     *          description="Items per page",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="integer", maximum=100)
     *      ),
     *      @OA\Parameter(
     *          name="status",
     *          description="Filter by status",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="string", enum={"INIT", "PAID", "PARTIALY_PAID", "CANCELLED", "REJECTED", "DELIVERED"})
     *      ),
     *      @OA\Parameter(
     *          name="merchant_id",
     *          description="Filter by merchant",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Orders retrieved successfully"),
     *              @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *              @OA\Property(property="meta", type="object"),
     *              @OA\Property(property="links", type="object")
     *          )
     *      )
     * )
     */
    public function index(Request $request)
    {
        $perPage = min($request->get('per_page', 15), 100);
        
        $query = Order::with([
            'merchant',
            'sourceMerchant',
            'destinationMerchant',
            'carts',
            'payments',
            'deliveryHistories',
        ]);

        $this->applyMerchantScope($query, $request, 'merchant_id');
        
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('merchant', function ($merchantQuery) use ($search) {
                        $merchantQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('destinationMerchant', function ($merchantQuery) use ($search) {
                        $merchantQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $statuses = array_filter(array_map('trim', explode(',', (string) $request->status)));
            if (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            } elseif (count($statuses) === 1) {
                $query->where('status', $statuses[0]);
            }
        }
        
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        if ($request->has('destination_merchant_id')) {
            $query->where('destination_merchant_id', $request->destination_merchant_id);
        }
        
        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);
        
        return $this->sendPaginated(OrderResource::collection($orders), 'Orders retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/orders",
     *      operationId="storeOrder",
     *      tags={"Orders"},
     *      summary="Create new order",
     *      description="Create a new order",
     *      security={{"bearerAuth": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="order_number", type="string", example="ORD-123456"),
     *              @OA\Property(property="amount", type="number", format="float", example=299.99),
     *              @OA\Property(property="merchant_id", type="integer", example=1),
     *              @OA\Property(property="status", type="string", enum={"INIT", "PAID", "PARTIALY_PAID", "CANCELLED", "REJECTED", "DELIVERED"}, example="INIT")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Order created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Order created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_number' => 'sometimes|string|max:255|unique:orders,order_number',
            'amount' => 'nullable|numeric|min:0',
            'merchant_id' => 'nullable|exists:merchants,id',
            'source_merchant_id' => 'nullable|exists:merchants,id',
            'destination_merchant_id' => 'nullable|exists:merchants,id',
            'status' => 'sometimes|in:INIT,VALIDATED,PAID,PARTIALY_PAID,CANCELLED,REJECTED,DELIVERED'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $destinationMerchantId = $request->filled('destination_merchant_id')
            ? (int) $request->destination_merchant_id
            : ($request->filled('merchant_id') ? (int) $request->merchant_id : null);

        $sourceMerchantId = $request->filled('source_merchant_id')
            ? (int) $request->source_merchant_id
            : null;

        if (!$this->isSuperAdmin($request)) {
            $accessibleIds = $this->accessibleMerchantIds($request);
            if (empty($accessibleIds)) {
                return $this->sendForbidden('No actor scope assigned to current user');
            }

            if ($destinationMerchantId !== null && !$this->hasMerchantScopeAccess($request, $destinationMerchantId)) {
                return $this->sendForbidden('You are not allowed to create order for this actor');
            }
            if ($sourceMerchantId !== null && !$this->hasMerchantScopeAccess($request, $sourceMerchantId)) {
                return $this->sendForbidden('You are not allowed to set this source actor');
            }

            $primaryDirectMerchantId = $this->primaryDirectMerchantId($request);
            if ($primaryDirectMerchantId === null) {
                return $this->sendForbidden('No actor scope assigned to current user');
            }

            $destinationMerchantId = $destinationMerchantId ?? $primaryDirectMerchantId;
            $sourceMerchantId = $sourceMerchantId ?? $primaryDirectMerchantId;
        }

        if ($destinationMerchantId === null) {
            return $this->sendValidationError([
                'destination_merchant_id' => ['Destination actor is required.'],
            ]);
        }

        $sourceMerchantId = $sourceMerchantId ?? $destinationMerchantId;

        $order = Order::create(array_merge(
            $request->only(['order_number', 'amount']),
            [
                'merchant_id' => $destinationMerchantId,
                'source_merchant_id' => $sourceMerchantId,
                'destination_merchant_id' => $destinationMerchantId,
                'status' => $request->get('status', 'INIT'),
            ]
        ));

        $this->createDeliveryHistory($order, null, (string) $order->status, $request->user()?->id);
        $this->notifyOrderState($order, null, (string) $order->status);
        AuditLogger::log($request, 'order.created', $order, [
            'status' => $order->status,
            'source_merchant_id' => $order->source_merchant_id,
            'destination_merchant_id' => $order->destination_merchant_id,
        ]);

        return $this->sendCreated(
            new OrderResource($order->load(['merchant', 'sourceMerchant', 'destinationMerchant', 'deliveryHistories'])),
            'Order created successfully'
        );
    }

    /**
     * @OA\Get(
     *      path="/api/v1/orders/{id}",
     *      operationId="getOrderById",
     *      tags={"Orders"},
     *      summary="Get order information",
     *      description="Returns order data",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Order id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Order retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Order not found"
     *      )
     * )
     */
    public function show($id)
    {
        $order = Order::with([
            'merchant',
            'sourceMerchant',
            'destinationMerchant',
            'carts.article',
            'payments',
            'deliveryHistories.merchant',
            'deliveryHistories.changedBy',
        ])->find($id);
        
        if (!$order) {
            return $this->sendNotFound('Order not found');
        }

        $scopeMerchantId = $order->destination_merchant_id ?? $order->merchant_id;
        if ($scopeMerchantId && !$this->hasMerchantScopeAccess(request(), (int) $scopeMerchantId)) {
            return $this->sendForbidden('You are not allowed to access this order');
        }
        
        return $this->sendResponse(new OrderResource($order), 'Order retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/orders/{id}",
     *      operationId="updateOrder",
     *      tags={"Orders"},
     *      summary="Update existing order",
     *      description="Update order data",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Order id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="order_number", type="string", example="ORD-123456-UPDATED"),
     *              @OA\Property(property="amount", type="number", format="float", example=399.99),
     *              @OA\Property(property="merchant_id", type="integer", example=2),
     *              @OA\Property(property="status", type="string", enum={"INIT", "PAID", "PARTIALY_PAID", "CANCELLED", "REJECTED", "DELIVERED"}, example="PAID")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Order updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Order updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $order = Order::find($id);
        
        if (!$order) {
            return $this->sendNotFound('Order not found');
        }

        $scopeMerchantId = $order->destination_merchant_id ?? $order->merchant_id;
        if ($scopeMerchantId && !$this->hasMerchantScopeAccess($request, (int) $scopeMerchantId)) {
            return $this->sendForbidden('You are not allowed to update this order');
        }
        
        $validator = Validator::make($request->all(), [
            'order_number' => 'sometimes|string|max:255|unique:orders,order_number,' . $id,
            'amount' => 'sometimes|nullable|numeric|min:0',
            'merchant_id' => 'sometimes|nullable|exists:merchants,id',
            'source_merchant_id' => 'sometimes|nullable|exists:merchants,id',
            'destination_merchant_id' => 'sometimes|nullable|exists:merchants,id',
            'status' => 'sometimes|in:INIT,VALIDATED,PAID,PARTIALY_PAID,CANCELLED,REJECTED,DELIVERED'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $targetDestinationMerchantId = $request->filled('destination_merchant_id')
            ? (int) $request->destination_merchant_id
            : ($request->filled('merchant_id') ? (int) $request->merchant_id : null);
        $targetSourceMerchantId = $request->filled('source_merchant_id')
            ? (int) $request->source_merchant_id
            : null;

        if ($targetDestinationMerchantId !== null && !$this->hasMerchantScopeAccess($request, $targetDestinationMerchantId)) {
            return $this->sendForbidden('You are not allowed to move this order outside your actor scope');
        }
        if ($targetSourceMerchantId !== null && !$this->hasMerchantScopeAccess($request, $targetSourceMerchantId)) {
            return $this->sendForbidden('You are not allowed to set this source actor');
        }

        $oldStatus = (string) $order->status;
        $newStatus = $request->filled('status') ? (string) $request->status : $oldStatus;

        $updateData = $request->only(['order_number', 'amount', 'status']);

        if ($targetDestinationMerchantId !== null) {
            $updateData['destination_merchant_id'] = $targetDestinationMerchantId;
            $updateData['merchant_id'] = $targetDestinationMerchantId; // legacy compatibility
        }

        if ($request->has('source_merchant_id')) {
            $updateData['source_merchant_id'] = $targetSourceMerchantId;
        }

        $order->update($updateData);

        if ($oldStatus !== $newStatus) {
            $this->createDeliveryHistory($order, $oldStatus, $newStatus, $request->user()?->id);
            $this->notifyOrderState($order, $oldStatus, $newStatus);
        }

        AuditLogger::log($request, 'order.updated', $order, [
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'destination_merchant_id' => $order->destination_merchant_id,
            'source_merchant_id' => $order->source_merchant_id,
        ]);

        return $this->sendUpdated(
            new OrderResource($order->load(['merchant', 'sourceMerchant', 'destinationMerchant', 'deliveryHistories'])),
            'Order updated successfully'
        );
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/orders/{id}",
     *      operationId="deleteOrder",
     *      tags={"Orders"},
     *      summary="Delete order",
     *      description="Soft delete order",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Order id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Order deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Order deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $order = Order::find($id);
        
        if (!$order) {
            return $this->sendNotFound('Order not found');
        }

        $scopeMerchantId = $order->destination_merchant_id ?? $order->merchant_id;
        if ($scopeMerchantId && !$this->hasMerchantScopeAccess(request(), (int) $scopeMerchantId)) {
            return $this->sendForbidden('You are not allowed to delete this order');
        }

        AuditLogger::log(request(), 'order.deleted', $order, [
            'status' => $order->status,
            'destination_merchant_id' => $order->destination_merchant_id,
        ]);
        
        $order->delete();
        
        return $this->sendDeleted('Order deleted successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/orders/{order}/calculate",
     *      operationId="calculateOrderAmount",
     *      tags={"Orders"},
     *      summary="Calculate order amount",
     *      description="Calculate total amount based on cart items",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="order",
     *          description="Order id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Order amount calculated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Order amount calculated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function calculateAmount($orderId)
    {
        $order = Order::find($orderId);
        
        if (!$order) {
            return $this->sendNotFound('Order not found');
        }

        $scopeMerchantId = $order->destination_merchant_id ?? $order->merchant_id;
        if ($scopeMerchantId && !$this->hasMerchantScopeAccess(request(), (int) $scopeMerchantId)) {
            return $this->sendForbidden('You are not allowed to calculate this order');
        }

        $totalAmount = $order->calculateAmount();

        AuditLogger::log(request(), 'order.calculated', $order, [
            'calculated_amount' => $totalAmount,
        ]);

        return $this->sendResponse([
            'order' => new OrderResource($order->load(['merchant', 'sourceMerchant', 'destinationMerchant', 'carts.article'])),
            'calculated_amount' => $totalAmount
        ], 'Order amount calculated successfully');
    }

    /**
     * Get delivery/status history for one order.
     */
    public function history(Request $request, $orderId)
    {
        $order = Order::find($orderId);
        if (!$order) {
            return $this->sendNotFound('Order not found');
        }

        $scopeMerchantId = $order->destination_merchant_id ?? $order->merchant_id;
        if ($scopeMerchantId && !$this->hasMerchantScopeAccess($request, (int) $scopeMerchantId)) {
            return $this->sendForbidden('You are not allowed to access this order history');
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $histories = $order->deliveryHistories()
            ->with(['merchant', 'changedBy'])
            ->orderByDesc('changed_at')
            ->paginate($perPage);

        return $this->sendPaginated(DeliveryHistoryResource::collection($histories), 'Order history retrieved successfully');
    }

    private function createDeliveryHistory(Order $order, ?string $fromStatus, string $toStatus, ?int $userId = null): void
    {
        DeliveryHistory::create([
            'order_id' => (int) $order->id,
            'merchant_id' => (int) ($order->destination_merchant_id ?? $order->merchant_id ?? 0) ?: null,
            'changed_by' => $userId ? (int) $userId : null,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_at' => now(),
        ]);
    }

    private function notifyOrderState(Order $order, ?string $fromStatus, string $toStatus): void
    {
        $merchantIds = array_values(array_filter([
            $order->source_merchant_id,
            $order->destination_merchant_id,
            $order->merchant_id,
        ]));

        $orderNumber = (string) $order->order_number;
        $type = 'preorder';
        $title = 'Nouvelle précommande';
        $message = "La commande {$orderNumber} a été créée.";

        if ($toStatus === 'VALIDATED') {
            $type = 'order_validated';
            $title = 'Précommande validée';
            $message = "La commande {$orderNumber} est validée.";
        } elseif ($toStatus === 'DELIVERED') {
            $type = 'delivery';
            $title = 'Livraison confirmée';
            $message = "La commande {$orderNumber} est marquée livrée.";
        } elseif ($toStatus === 'CANCELLED') {
            $type = 'order_cancelled';
            $title = 'Précommande annulée';
            $message = "La commande {$orderNumber} est annulée.";
        } elseif ($fromStatus !== null && $fromStatus !== $toStatus) {
            $type = 'order_status';
            $title = 'Statut de commande mis à jour';
            $message = "La commande {$orderNumber} est passée de {$fromStatus} à {$toStatus}.";
        }

        NotificationPublisher::publishForMerchants(
            $merchantIds,
            $type,
            $title,
            $message,
            (int) $order->id
        );
    }
}
