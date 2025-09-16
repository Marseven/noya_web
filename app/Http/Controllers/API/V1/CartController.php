<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Resources\CartResource;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CartController extends BaseController
{
    /**
     * @OA\Get(
     *      path="/api/v1/carts",
     *      operationId="getCartsList",
     *      tags={"Carts"},
     *      summary="Get list of carts",
     *      description="Returns list of carts with pagination",
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
     *          name="order_id",
     *          description="Filter by order",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Parameter(
     *          name="article_id",
     *          description="Filter by article",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Carts retrieved successfully"),
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
        
        $query = Cart::with(['article', 'order']);
        
        if ($request->has('order_id')) {
            $query->where('order_id', $request->order_id);
        }
        
        if ($request->has('article_id')) {
            $query->where('article_id', $request->article_id);
        }
        
        $carts = $query->paginate($perPage);
        
        return $this->sendPaginated(CartResource::collection($carts), 'Carts retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/carts",
     *      operationId="storeCart",
     *      tags={"Carts"},
     *      summary="Create new cart item",
     *      description="Create a new cart item",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"article_id","quantity"},
     *              @OA\Property(property="article_id", type="integer", example=1),
     *              @OA\Property(property="quantity", type="integer", example=2),
     *              @OA\Property(property="order_id", type="integer", example=1)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Cart item created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Cart item created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'article_id' => 'required|exists:articles,id',
            'quantity' => 'required|integer|min:1',
            'order_id' => 'nullable|exists:orders,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        // Check if cart item already exists for this article-order combination
        if ($request->has('order_id') && $request->has('article_id')) {
            $existingCart = Cart::where('article_id', $request->article_id)
                               ->where('order_id', $request->order_id)
                               ->first();

            if ($existingCart) {
                // Update quantity instead of creating new
                $existingCart->updateQuantity($existingCart->quantity + $request->quantity);
                return $this->sendResponse(new CartResource($existingCart->load(['article', 'order'])), 'Cart item quantity updated');
            }
        }

        $cart = Cart::create([
            'article_id' => $request->article_id,
            'quantity' => $request->quantity,
            'order_id' => $request->order_id
        ]);

        // Recalculate order amount if order is specified
        if ($cart->order) {
            $cart->order->calculateAmount();
        }

        return $this->sendCreated(new CartResource($cart->load(['article', 'order'])), 'Cart item created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/carts/{id}",
     *      operationId="getCartById",
     *      tags={"Carts"},
     *      summary="Get cart information",
     *      description="Returns cart data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Cart id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Cart retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Cart not found"
     *      )
     * )
     */
    public function show($id)
    {
        $cart = Cart::with(['article', 'order'])->find($id);
        
        if (!$cart) {
            return $this->sendNotFound('Cart not found');
        }
        
        return $this->sendResponse(new CartResource($cart), 'Cart retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/carts/{id}",
     *      operationId="updateCart",
     *      tags={"Carts"},
     *      summary="Update existing cart item",
     *      description="Update cart item data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Cart id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="article_id", type="integer", example=2),
     *              @OA\Property(property="quantity", type="integer", example=3),
     *              @OA\Property(property="order_id", type="integer", example=2)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Cart updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Cart updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $cart = Cart::find($id);
        
        if (!$cart) {
            return $this->sendNotFound('Cart not found');
        }
        
        $validator = Validator::make($request->all(), [
            'article_id' => 'sometimes|exists:articles,id',
            'quantity' => 'sometimes|integer|min:1',
            'order_id' => 'sometimes|nullable|exists:orders,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        // If quantity is being updated, use the model method to handle recalculation
        if ($request->has('quantity')) {
            $cart->updateQuantity($request->quantity);
        }

        // Update other fields
        $cart->update($request->only(['article_id', 'order_id']));

        return $this->sendUpdated(new CartResource($cart->load(['article', 'order'])), 'Cart updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/carts/{id}",
     *      operationId="deleteCart",
     *      tags={"Carts"},
     *      summary="Delete cart item",
     *      description="Soft delete cart item",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Cart id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Cart deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Cart deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $cart = Cart::find($id);
        
        if (!$cart) {
            return $this->sendNotFound('Cart not found');
        }
        
        // Recalculate order amount before deleting
        if ($cart->order) {
            $order = $cart->order;
            $cart->delete();
            $order->calculateAmount();
        } else {
            $cart->delete();
        }
        
        return $this->sendDeleted('Cart deleted successfully');
    }
}
