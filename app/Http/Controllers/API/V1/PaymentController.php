<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\API\V1\Concerns\InteractsWithMerchantScope;
use App\Http\Resources\PaymentResource;
use App\Models\DeliveryHistory;
use App\Models\Order;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\NotificationPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController extends BaseController
{
    use InteractsWithMerchantScope;

    /**
     * @OA\Get(
     *      path="/api/v1/payments",
     *      operationId="getPaymentsList",
     *      tags={"Payments"},
     *      summary="Get list of payments",
     *      description="Returns list of payments with pagination",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
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
     *          @OA\Schema(type="string", enum={"PAID", "INIT"})
     *      ),
     *      @OA\Parameter(
     *          name="order_id",
     *          description="Filter by order",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Payments retrieved successfully"),
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
        
        $query = Payment::with(['order']);

        if (!$this->isSuperAdmin($request)) {
            $merchantIds = $this->accessibleMerchantIds($request);
            if (empty($merchantIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('order', function ($orderQuery) use ($merchantIds) {
                    $orderQuery->whereIn('merchant_id', $merchantIds);
                });
            }
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('order_id')) {
            $query->where('order_id', $request->order_id);
        }
        
        $payments = $query->orderBy('created_at', 'desc')->paginate($perPage);
        
        return $this->sendPaginated(PaymentResource::collection($payments), 'Payments retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/payments",
     *      operationId="storePayment",
     *      tags={"Payments"},
     *      summary="Create new payment",
     *      description="Create a new payment",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"amount","total_amount"},
     *              @OA\Property(property="order_id", type="integer", example=1),
     *              @OA\Property(property="amount", type="number", format="float", example=299.99),
     *              @OA\Property(property="partner_name", type="string", example="PayPal"),
     *              @OA\Property(property="partner_fees", type="number", format="float", example=8.99),
     *              @OA\Property(property="total_amount", type="number", format="float", example=308.98),
     *              @OA\Property(property="status", type="string", enum={"PAID", "INIT"}, example="INIT"),
     *              @OA\Property(property="partner_reference", type="string", example="PP-123456789"),
     *              @OA\Property(property="callback_data", type="object", example={"transaction_id": "TXN123", "gateway": "paypal"})
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Payment created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Payment created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'nullable|exists:orders,id',
            'amount' => 'required|numeric|min:0',
            'partner_name' => 'nullable|string|max:255',
            'partner_fees' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'status' => 'sometimes|in:PAID,INIT',
            'partner_reference' => 'nullable|string|max:255',
            'callback_data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        if (!$this->isSuperAdmin($request)) {
            if ($request->filled('order_id')) {
                $order = Order::find($request->order_id);
                if (!$order || !$this->hasMerchantScopeAccess($request, (int) $order->merchant_id)) {
                    return $this->sendForbidden('You are not allowed to create payment for this order');
                }
            } else {
                return $this->sendForbidden('Payment must be linked to an order in your actor scope');
            }
        }

        $payment = Payment::create(array_merge(
            $request->only([
                'order_id', 'amount', 'partner_name', 'partner_fees', 
                'total_amount', 'partner_reference', 'callback_data'
            ]),
            ['status' => $request->get('status', 'INIT')]
        ));

        AuditLogger::log($request, 'payment.created', $payment, [
            'order_id' => $payment->order_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
        ]);

        return $this->sendCreated(new PaymentResource($payment->load('order')), 'Payment created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/payments/{id}",
     *      operationId="getPaymentById",
     *      tags={"Payments"},
     *      summary="Get payment information",
     *      description="Returns payment data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Payment id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Payment retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Payment not found"
     *      )
     * )
     */
    public function show($id)
    {
        $payment = Payment::with(['order'])->find($id);
        
        if (!$payment) {
            return $this->sendNotFound('Payment not found');
        }

        if ($payment->order && !$this->hasMerchantScopeAccess(request(), (int) $payment->order->merchant_id)) {
            return $this->sendForbidden('You are not allowed to access this payment');
        }
        
        return $this->sendResponse(new PaymentResource($payment), 'Payment retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/payments/{id}",
     *      operationId="updatePayment",
     *      tags={"Payments"},
     *      summary="Update existing payment",
     *      description="Update payment data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Payment id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="order_id", type="integer", example=2),
     *              @OA\Property(property="amount", type="number", format="float", example=399.99),
     *              @OA\Property(property="partner_name", type="string", example="Stripe"),
     *              @OA\Property(property="partner_fees", type="number", format="float", example=11.99),
     *              @OA\Property(property="total_amount", type="number", format="float", example=411.98),
     *              @OA\Property(property="status", type="string", enum={"PAID", "INIT"}, example="PAID"),
     *              @OA\Property(property="partner_reference", type="string", example="ST-987654321"),
     *              @OA\Property(property="callback_data", type="object", example={"transaction_id": "TXN456", "gateway": "stripe"})
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Payment updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Payment updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $payment = Payment::with('order')->find($id);
        
        if (!$payment) {
            return $this->sendNotFound('Payment not found');
        }

        if ($payment->order && !$this->hasMerchantScopeAccess($request, (int) $payment->order->merchant_id)) {
            return $this->sendForbidden('You are not allowed to update this payment');
        }
        
        $validator = Validator::make($request->all(), [
            'order_id' => 'sometimes|nullable|exists:orders,id',
            'amount' => 'sometimes|numeric|min:0',
            'partner_name' => 'sometimes|nullable|string|max:255',
            'partner_fees' => 'sometimes|nullable|numeric|min:0',
            'total_amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:PAID,INIT',
            'partner_reference' => 'sometimes|nullable|string|max:255',
            'callback_data' => 'sometimes|nullable|array'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        if ($request->filled('order_id')) {
            $order = Order::find($request->order_id);
            if (!$order || !$this->hasMerchantScopeAccess($request, (int) $order->merchant_id)) {
                return $this->sendForbidden('You are not allowed to move this payment outside your actor scope');
            }
        }

        $payment->update($request->only([
            'order_id', 'amount', 'partner_name', 'partner_fees', 
            'total_amount', 'status', 'partner_reference', 'callback_data'
        ]));

        AuditLogger::log($request, 'payment.updated', $payment, [
            'order_id' => $payment->order_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
        ]);

        return $this->sendUpdated(new PaymentResource($payment->load('order')), 'Payment updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/payments/{id}",
     *      operationId="deletePayment",
     *      tags={"Payments"},
     *      summary="Delete payment",
     *      description="Soft delete payment",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Payment id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Payment deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Payment deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $payment = Payment::with('order')->find($id);
        
        if (!$payment) {
            return $this->sendNotFound('Payment not found');
        }

        if ($payment->order && !$this->hasMerchantScopeAccess(request(), (int) $payment->order->merchant_id)) {
            return $this->sendForbidden('You are not allowed to delete this payment');
        }
        
        $payment->delete();

        AuditLogger::log(request(), 'payment.deleted', $payment, [
            'order_id' => $payment->order_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
        ]);
        
        return $this->sendDeleted('Payment deleted successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/payments/{payment}/confirm",
     *      operationId="confirmPayment",
     *      tags={"Payments"},
     *      summary="Confirm payment",
     *      description="Mark payment as paid and update order status",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="payment",
     *          description="Payment id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=false,
     *          @OA\JsonContent(
     *              @OA\Property(property="partner_reference", type="string", example="CONFIRMED-123456"),
     *              @OA\Property(property="callback_data", type="object", example={"confirmation_code": "CONF123", "timestamp": "2024-01-01T12:00:00Z"})
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Payment confirmed successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Payment confirmed successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function confirmPayment(Request $request, $paymentId)
    {
        $payment = Payment::with('order')->find($paymentId);
        
        if (!$payment) {
            return $this->sendNotFound('Payment not found');
        }

        if ($payment->order && !$this->hasMerchantScopeAccess($request, (int) $payment->order->merchant_id)) {
            return $this->sendForbidden('You are not allowed to confirm this payment');
        }

        if ($payment->status === 'PAID') {
            return $this->sendError('Payment is already confirmed', [], 400);
        }

        $validator = Validator::make($request->all(), [
            'partner_reference' => 'sometimes|nullable|string|max:255',
            'callback_data' => 'sometimes|nullable|array'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        // Update payment with confirmation data if provided
        if ($request->has('partner_reference')) {
            $payment->partner_reference = $request->partner_reference;
        }

        if ($request->has('callback_data')) {
            $payment->callback_data = array_merge($payment->callback_data ?? [], $request->callback_data);
        }

        $oldOrderStatus = $payment->order?->status;

        // Mark payment as paid (this will also update order status)
        $payment->markAsPaid();

        $payment->refresh();
        $payment->load('order');

        if ($payment->order && $oldOrderStatus !== $payment->order->status) {
            DeliveryHistory::create([
                'order_id' => (int) $payment->order->id,
                'merchant_id' => (int) ($payment->order->destination_merchant_id ?? $payment->order->merchant_id ?? 0) ?: null,
                'changed_by' => (int) $request->user()->id,
                'from_status' => $oldOrderStatus,
                'to_status' => (string) $payment->order->status,
                'note' => 'Status updated by payment confirmation',
                'changed_at' => now(),
            ]);
        }

        if ($payment->order) {
            NotificationPublisher::publishForMerchants(
                array_values(array_filter([
                    $payment->order->source_merchant_id,
                    $payment->order->destination_merchant_id,
                    $payment->order->merchant_id,
                ])),
                'payment',
                'Paiement confirmé',
                "Paiement confirmé pour la commande {$payment->order->order_number}.",
                (int) $payment->id
            );
        }

        AuditLogger::log($request, 'payment.confirmed', $payment, [
            'order_id' => $payment->order_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
        ]);

        return $this->sendResponse(new PaymentResource($payment->load('order')), 'Payment confirmed successfully');
    }
}
