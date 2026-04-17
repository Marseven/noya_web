<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\API\V1\Concerns\InteractsWithMerchantScope;
use App\Http\Resources\StockResource;
use App\Http\Resources\StockHistoryResource;
use App\Models\Stock;
use App\Support\AuditLogger;
use App\Support\NotificationPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StockController extends BaseController
{
    use InteractsWithMerchantScope;

    /**
     * @OA\Get(
     *      path="/api/v1/stocks",
     *      operationId="getStocksList",
     *      tags={"Stocks"},
     *      summary="Get list of stocks",
     *      description="Returns list of stocks with pagination",
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
     *          name="merchant_id",
     *          description="Filter by merchant",
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
     *              @OA\Property(property="message", type="string", example="Stocks retrieved successfully"),
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
        
        $query = Stock::with(['merchant', 'article']);

        $this->applyMerchantScope($query, $request, 'merchant_id');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('merchant', function ($merchantQuery) use ($search) {
                    $merchantQuery->where('name', 'like', "%{$search}%");
                })->orWhereHas('article', function ($articleQuery) use ($search) {
                    $articleQuery->where('name', 'like', "%{$search}%");
                });
            });
        }
        
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }
        
        if ($request->has('article_id')) {
            $query->where('article_id', $request->article_id);
        }
        
        $stocks = $query->paginate($perPage);
        
        return $this->sendPaginated(StockResource::collection($stocks), 'Stocks retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/stocks",
     *      operationId="storeStock",
     *      tags={"Stocks"},
     *      summary="Create new stock",
     *      description="Create a new stock entry",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"merchant_id","article_id"},
     *              @OA\Property(property="merchant_id", type="integer", example=1),
     *              @OA\Property(property="article_id", type="integer", example=1),
     *              @OA\Property(property="stock", type="integer", example=100),
     *              @OA\Property(property="last_action_type", type="string", enum={"MANUALLY_ADD", "MANUALLY_WITHDRAW", "AUTO_ADD", "AUTO_WITHDRAW"}, example="MANUALLY_ADD")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Stock created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Stock created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|exists:merchants,id',
            'article_id' => 'required|exists:articles,id',
            'stock' => 'sometimes|integer|min:0',
            'last_action_type' => 'sometimes|in:MANUALLY_ADD,MANUALLY_WITHDRAW,AUTO_ADD,AUTO_WITHDRAW'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $request->merchant_id)) {
            return $this->sendForbidden('You are not allowed to create stock for this actor');
        }

        // Check if stock already exists for this merchant-article combination
        $existingStock = Stock::where('merchant_id', $request->merchant_id)
                             ->where('article_id', $request->article_id)
                             ->first();

        if ($existingStock) {
            return $this->sendError('Stock already exists for this merchant-article combination', [], 409);
        }

        $stock = Stock::create([
            'merchant_id' => $request->merchant_id,
            'article_id' => $request->article_id,
            'stock' => $request->get('stock', 0),
            'last_action_type' => $request->get('last_action_type', 'MANUALLY_ADD')
        ]);

        AuditLogger::log($request, 'stock.created', $stock, [
            'merchant_id' => $stock->merchant_id,
            'article_id' => $stock->article_id,
            'stock' => $stock->stock,
        ]);

        return $this->sendCreated(new StockResource($stock->load(['merchant', 'article'])), 'Stock created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/stocks/{id}",
     *      operationId="getStockById",
     *      tags={"Stocks"},
     *      summary="Get stock information",
     *      description="Returns stock data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Stock id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Stock retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Stock not found"
     *      )
     * )
     */
    public function show($id)
    {
        $stock = Stock::with(['merchant', 'article', 'histories'])->find($id);
        
        if (!$stock) {
            return $this->sendNotFound('Stock not found');
        }

        if (!$this->hasMerchantScopeAccess(request(), (int) $stock->merchant_id)) {
            return $this->sendForbidden('You are not allowed to access this stock');
        }
        
        return $this->sendResponse(new StockResource($stock), 'Stock retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/stocks/{id}",
     *      operationId="updateStock",
     *      tags={"Stocks"},
     *      summary="Update existing stock",
     *      description="Update stock data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Stock id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="stock", type="integer", example=150),
     *              @OA\Property(property="last_action_type", type="string", enum={"MANUALLY_ADD", "MANUALLY_WITHDRAW", "AUTO_ADD", "AUTO_WITHDRAW"}, example="MANUALLY_ADD")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Stock updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Stock updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $stock = Stock::find($id);
        
        if (!$stock) {
            return $this->sendNotFound('Stock not found');
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $stock->merchant_id)) {
            return $this->sendForbidden('You are not allowed to update this stock');
        }
        
        $validator = Validator::make($request->all(), [
            'stock' => 'sometimes|integer|min:0',
            'last_action_type' => 'sometimes|in:MANUALLY_ADD,MANUALLY_WITHDRAW,AUTO_ADD,AUTO_WITHDRAW'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $stock->update($request->only(['stock', 'last_action_type']));

        AuditLogger::log($request, 'stock.updated', $stock, [
            'merchant_id' => $stock->merchant_id,
            'article_id' => $stock->article_id,
            'stock' => $stock->stock,
        ]);
        $this->notifyLowStock($stock);

        return $this->sendUpdated(new StockResource($stock->load(['merchant', 'article'])), 'Stock updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/stocks/{id}",
     *      operationId="deleteStock",
     *      tags={"Stocks"},
     *      summary="Delete stock",
     *      description="Soft delete stock",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Stock id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Stock deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Stock deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $stock = Stock::find($id);
        
        if (!$stock) {
            return $this->sendNotFound('Stock not found');
        }

        if (!$this->hasMerchantScopeAccess(request(), (int) $stock->merchant_id)) {
            return $this->sendForbidden('You are not allowed to delete this stock');
        }
        
        $stock->delete();

        AuditLogger::log(request(), 'stock.deleted', $stock, [
            'merchant_id' => $stock->merchant_id,
            'article_id' => $stock->article_id,
        ]);
        
        return $this->sendDeleted('Stock deleted successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/stocks/{stock}/add",
     *      operationId="addStock",
     *      tags={"Stocks"},
     *      summary="Add stock quantity",
     *      description="Add quantity to existing stock with history tracking",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="stock",
     *          description="Stock id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"quantity"},
     *              @OA\Property(property="quantity", type="integer", example=50),
     *              @OA\Property(property="action_type", type="string", enum={"MANUALLY_ADD", "AUTO_ADD"}, example="MANUALLY_ADD")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Stock added successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Stock added successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function addStock(Request $request, $stockId)
    {
        $stock = Stock::find($stockId);
        
        if (!$stock) {
            return $this->sendNotFound('Stock not found');
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $stock->merchant_id)) {
            return $this->sendForbidden('You are not allowed to add stock for this actor');
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
            'action_type' => 'sometimes|in:MANUALLY_ADD,AUTO_ADD'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $actionType = $request->get('action_type', 'MANUALLY_ADD');
        $stock->addStock($request->quantity, $actionType);

        AuditLogger::log($request, 'stock.added', $stock, [
            'quantity' => (int) $request->quantity,
            'action_type' => $actionType,
            'new_stock' => $stock->stock,
        ]);

        return $this->sendResponse(new StockResource($stock->load(['merchant', 'article'])), 'Stock added successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/stocks/{stock}/withdraw",
     *      operationId="withdrawStock",
     *      tags={"Stocks"},
     *      summary="Withdraw stock quantity",
     *      description="Withdraw quantity from existing stock with history tracking",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="stock",
     *          description="Stock id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"quantity"},
     *              @OA\Property(property="quantity", type="integer", example=25),
     *              @OA\Property(property="action_type", type="string", enum={"MANUALLY_WITHDRAW", "AUTO_WITHDRAW"}, example="MANUALLY_WITHDRAW")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Stock withdrawn successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Stock withdrawn successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function withdrawStock(Request $request, $stockId)
    {
        $stock = Stock::find($stockId);
        
        if (!$stock) {
            return $this->sendNotFound('Stock not found');
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $stock->merchant_id)) {
            return $this->sendForbidden('You are not allowed to withdraw stock for this actor');
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
            'action_type' => 'sometimes|in:MANUALLY_WITHDRAW,AUTO_WITHDRAW'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $actionType = $request->get('action_type', 'MANUALLY_WITHDRAW');
        $stock->withdrawStock($request->quantity, $actionType);

        AuditLogger::log($request, 'stock.withdrawn', $stock, [
            'quantity' => (int) $request->quantity,
            'action_type' => $actionType,
            'new_stock' => $stock->stock,
        ]);
        $this->notifyLowStock($stock);

        return $this->sendResponse(new StockResource($stock->load(['merchant', 'article'])), 'Stock withdrawn successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/stocks/{stock}/history",
     *      operationId="getStockHistory",
     *      tags={"Stocks"},
     *      summary="Get stock history",
     *      description="Get history of stock movements",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="stock",
     *          description="Stock id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
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
     *      @OA\Response(
     *          response=200,
     *          description="Stock history retrieved successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Stock history retrieved successfully"),
     *              @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *              @OA\Property(property="meta", type="object"),
     *              @OA\Property(property="links", type="object")
     *          )
     *      )
     * )
     */
    public function history(Request $request, $stockId)
    {
        $stock = Stock::find($stockId);
        
        if (!$stock) {
            return $this->sendNotFound('Stock not found');
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $stock->merchant_id)) {
            return $this->sendForbidden('You are not allowed to view stock history for this actor');
        }

        $perPage = min($request->get('per_page', 15), 100);
        
        $histories = $stock->histories()
                          ->with(['article'])
                          ->orderBy('created_at', 'desc')
                          ->paginate($perPage);

        return $this->sendPaginated(StockHistoryResource::collection($histories), 'Stock history retrieved successfully');
    }

    private function notifyLowStock(Stock $stock): void
    {
        if ($stock->stock > 10) {
            return;
        }

        $articleName = $stock->article?->name ?? 'Article';
        NotificationPublisher::publishForMerchants(
            [(int) $stock->merchant_id],
            'stock_low',
            'Alerte stock faible',
            "Stock faible pour {$articleName} (niveau: {$stock->stock}).",
            (int) $stock->id
        );
    }
}
