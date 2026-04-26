<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\API\V1\Concerns\InteractsWithMerchantScope;
use App\Helpers\StorageHelper;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ArticleController extends BaseController
{
    use InteractsWithMerchantScope;

    /**
     * @OA\Get(
     *      path="/api/v1/articles",
     *      operationId="getArticlesList",
     *      tags={"Articles"},
     *      summary="Get list of articles",
     *      description="Returns list of articles with pagination",
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
     *          name="is_active",
     *          description="Filter by active status",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="boolean")
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
     *              @OA\Property(property="message", type="string", example="Articles retrieved successfully"),
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
        
        $query = Article::with(['merchant', 'stocks']);

        $this->applyMerchantScope($query, $request, 'merchant_id');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('merchant', function ($merchantQuery) use ($search) {
                        $merchantQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }
        
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }
        
        $articles = $query->paginate($perPage);
        
        return $this->sendPaginated(ArticleResource::collection($articles), 'Articles retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/articles",
     *      operationId="storeArticle",
     *      tags={"Articles"},
     *      summary="Create new article",
     *      description="Create a new article",
     *      security={{"bearerAuth": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"name"},
     *              @OA\Property(property="name", type="string", example="Product Name"),
     *              @OA\Property(property="price", type="number", format="float", example=29.99),
     *              @OA\Property(property="photo_url", type="string", example="https://example.com/photo.jpg"),
     *              @OA\Property(property="merchant_id", type="integer", example=1),
     *              @OA\Property(property="is_active", type="boolean", example=true)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Article created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Article created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:articles,name',
            'price' => 'nullable|numeric|min:0',
            'photo_url' => 'nullable|string|max:255',
            'merchant_id' => 'nullable|exists:merchants,id',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        if (!$this->isSuperAdmin($request)) {
            if ($request->filled('merchant_id')) {
                if (!$this->hasMerchantScopeAccess($request, (int) $request->merchant_id)) {
                    return $this->sendForbidden('You are not allowed to create article for this actor');
                }
            } else {
                $primaryDirectMerchantId = $this->primaryDirectMerchantId($request);
                if ($primaryDirectMerchantId === null) {
                    return $this->sendForbidden('No actor scope assigned to current user');
                }
                $request->merge(['merchant_id' => $primaryDirectMerchantId]);
            }
        }

        $articleData = array_merge(
            $request->only(['name', 'price', 'merchant_id']),
            ['is_active' => $request->get('is_active', false)]
        );

        // Handle photo upload if provided
        if ($request->has('photo_url') && $request->photo_url) {
            try {
                // Create article first to get ID
                $article = Article::create($articleData);
                
                // Move photo from temp to permanent location
                $permanentPath = StorageHelper::moveFromTemp(
                    $request->photo_url,
                    StorageHelper::generatePath('articles', $article->id, basename($request->photo_url))
                );
                
                // Update article with permanent photo URL
                $article->update(['photo_url' => StorageHelper::getStorageUrl($permanentPath)]);
                
            } catch (\Exception $e) {
                // If file move fails, still create article but without photo
                if (!isset($article)) {
                    $article = Article::create($articleData);
                }
                // Log the error but don't fail the request
                \Log::warning('Failed to move article photo: ' . $e->getMessage());
            }
        } else {
            $article = Article::create($articleData);
        }

        return $this->sendCreated(new ArticleResource($article->load('merchant')), 'Article created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/articles/{id}",
     *      operationId="getArticleById",
     *      tags={"Articles"},
     *      summary="Get article information",
     *      description="Returns article data",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Article id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Article retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Article not found"
     *      )
     * )
     */
    public function show($id)
    {
        $article = Article::with(['merchant', 'stocks', 'stockHistories', 'carts'])->find($id);
        
        if (!$article) {
            return $this->sendNotFound('Article not found');
        }

        if ($article->merchant_id && !$this->hasMerchantScopeAccess(request(), (int) $article->merchant_id)) {
            return $this->sendForbidden('You are not allowed to access this article');
        }
        
        return $this->sendResponse(new ArticleResource($article), 'Article retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/articles/{id}",
     *      operationId="updateArticle",
     *      tags={"Articles"},
     *      summary="Update existing article",
     *      description="Update article data",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Article id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="name", type="string", example="Updated Product Name"),
     *              @OA\Property(property="price", type="number", format="float", example=39.99),
     *              @OA\Property(property="photo_url", type="string", example="https://example.com/updated_photo.jpg"),
     *              @OA\Property(property="merchant_id", type="integer", example=2),
     *              @OA\Property(property="is_active", type="boolean", example=true)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Article updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Article updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $article = Article::find($id);
        
        if (!$article) {
            return $this->sendNotFound('Article not found');
        }

        if ($article->merchant_id && !$this->hasMerchantScopeAccess($request, (int) $article->merchant_id)) {
            return $this->sendForbidden('You are not allowed to update this article');
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:articles,name,' . $id,
            'price' => 'sometimes|nullable|numeric|min:0',
            'photo_url' => 'sometimes|nullable|string|max:255',
            'merchant_id' => 'sometimes|nullable|exists:merchants,id',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        if ($request->filled('merchant_id') && !$this->hasMerchantScopeAccess($request, (int) $request->merchant_id)) {
            return $this->sendForbidden('You are not allowed to move this article to another actor outside your scope');
        }

        $updateData = $request->only(['name', 'price', 'merchant_id', 'is_active']);

        // Handle photo upload if provided
        if ($request->has('photo_url') && $request->photo_url) {
            try {
                // Delete old photo if exists
                if ($article->photo_url) {
                    StorageHelper::delete($article->photo_url);
                }
                
                // Move new photo from temp to permanent location
                $permanentPath = StorageHelper::moveFromTemp(
                    $request->photo_url,
                    StorageHelper::generatePath('articles', $article->id, basename($request->photo_url))
                );
                
                $updateData['photo_url'] = StorageHelper::getStorageUrl($permanentPath);
                
            } catch (\Exception $e) {
                // Log the error but don't fail the request
                \Log::warning('Failed to move article photo during update: ' . $e->getMessage());
            }
        } elseif ($request->has('photo_url') && $request->photo_url === null) {
            // Explicitly removing photo
            if ($article->photo_url) {
                StorageHelper::delete($article->photo_url);
            }
            $updateData['photo_url'] = null;
        }

        $article->update($updateData);

        return $this->sendUpdated(new ArticleResource($article->load('merchant')), 'Article updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/articles/{id}",
     *      operationId="deleteArticle",
     *      tags={"Articles"},
     *      summary="Delete article",
     *      description="Soft delete article",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Article id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Article deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Article deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $article = Article::find($id);
        
        if (!$article) {
            return $this->sendNotFound('Article not found');
        }

        if ($article->merchant_id && !$this->hasMerchantScopeAccess(request(), (int) $article->merchant_id)) {
            return $this->sendForbidden('You are not allowed to delete this article');
        }
        
        // Delete associated photo file
        if ($article->photo_url) {
            try {
                StorageHelper::delete($article->photo_url);
            } catch (\Exception $e) {
                \Log::warning('Failed to delete article photo file: ' . $e->getMessage());
            }
        }
        
        $article->delete();
        
        return $this->sendDeleted('Article deleted successfully');
    }
}
