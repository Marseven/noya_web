<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderController extends BaseController
{
    /**
     * @OA\Get(
     *      path="/api/v1/orders",
     *      operationId="getOrdersList",
     *      tags={"Orders"},
     *      summary="Get list of orders",
     *      description="Returns list of orders with pagination",
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
        
        $query = Order::with(['merchant', 'carts', 'payments']);
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
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
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
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
            'status' => 'sometimes|in:INIT,PAID,PARTIALY_PAID,CANCELLED,REJECTED,DELIVERED'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $order = Order::create(array_merge(
            $request->only(['order_number', 'amount', 'merchant_id']),
            ['status' => $request->get('status', 'INIT')]
        ));

        return $this->sendCreated(new OrderResource($order->load('merchant')), 'Order created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/orders/{id}",
     *      operationId="getOrderById",
     *      tags={"Orders"},
     *      summary="Get order information",
     *      description="Returns order data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
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
        $order = Order::with(['merchant', 'carts.article', 'payments'])->find($id);
        
        if (!$order) {
            return $this->sendNotFound('Order not found');
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
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
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
        
        $validator = Validator::make($request->all(), [
            'order_number' => 'sometimes|string|max:255|unique:orders,order_number,' . $id,
            'amount' => 'sometimes|nullable|numeric|min:0',
            'merchant_id' => 'sometimes|nullable|exists:merchants,id',
            'status' => 'sometimes|in:INIT,PAID,PARTIALY_PAID,CANCELLED,REJECTED,DELIVERED'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $order->update($request->only(['order_number', 'amount', 'merchant_id', 'status']));

        return $this->sendUpdated(new OrderResource($order->load('merchant')), 'Order updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/orders/{id}",
     *      operationId="deleteOrder",
     *      tags={"Orders"},
     *      summary="Delete order",
     *      description="Soft delete order",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
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
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
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

        $totalAmount = $order->calculateAmount();

        return $this->sendResponse([
            'order' => new OrderResource($order->load(['merchant', 'carts.article'])),
            'calculated_amount' => $totalAmount
        ], 'Order amount calculated successfully');
    }
}
