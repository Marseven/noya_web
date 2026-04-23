<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\API\V1\Concerns\InteractsWithMerchantScope;
use App\Helpers\StorageHelper;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MerchantController extends BaseController
{
    use InteractsWithMerchantScope;

    /**
     * @OA\Get(
     *      path="/api/v1/merchants",
     *      operationId="getMerchantsList",
     *      tags={"Merchants"},
     *      summary="Get list of merchants",
     *      description="Returns list of merchants with pagination",
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
     *          @OA\Schema(type="string", enum={"PENDING", "BLOCKED", "APPROVED", "SUSPENDED"})
     *      ),
     *      @OA\Parameter(
     *          name="type",
     *          description="Filter by type",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="string", enum={"Distributor", "Wholesaler", "Subwholesaler", "PointOfSell"})
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Merchants retrieved successfully"),
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

        $query = Merchant::with(['parent', 'children', 'users']);

        $this->applyMerchantScope($query, $request, 'id');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('tel', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $merchants = $query->paginate($perPage);

        return $this->sendPaginated(MerchantResource::collection($merchants), 'Merchants retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/merchants",
     *      operationId="storeMerchant",
     *      tags={"Merchants"},
     *      summary="Create new merchant",
     *      description="Create a new merchant",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"address","type"},
     *              @OA\Property(property="name", type="string", example="ABC Store"),
     *              @OA\Property(property="address", type="string", example="123 Main St"),
     *              @OA\Property(property="entity_file", type="string", example="entity.pdf"),
     *              @OA\Property(property="other_document_file", type="string", example="document.pdf"),
     *              @OA\Property(property="tel", type="string", example="+1234567890"),
     *              @OA\Property(property="email", type="string", format="email", example="merchant@example.com"),
     *              @OA\Property(property="merchant_parent_id", type="integer", example=1),
     *              @OA\Property(property="status", type="string", enum={"PENDING", "BLOCKED", "APPROVED", "SUSPENDED"}, example="PENDING"),
     *              @OA\Property(property="type", type="string", enum={"Distributor", "Wholesaler", "Subwholesaler", "PointOfSell"}, example="PointOfSell"),
     *              @OA\Property(property="lat", type="number", format="float", example=40.7128),
     *              @OA\Property(property="long", type="number", format="float", example=-74.0060)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Merchant created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Merchant created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'address' => 'required|string|max:255',
            'entity_file' => 'nullable|string|max:255',
            'other_document_file' => 'nullable|string|max:255',
            'tel' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'merchant_parent_id' => 'nullable|exists:merchants,id',
            'status' => 'sometimes|in:PENDING,BLOCKED,APPROVED,SUSPENDED',
            'type' => 'required|in:Distributor,Wholesaler,Subwholesaler,PointOfSell',
            'lat' => 'nullable|numeric|between:-90,90',
            'long' => 'nullable|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        if (!$this->isSuperAdmin($request)) {
            $accessibleIds = $this->accessibleMerchantIds($request);
            if (empty($accessibleIds)) {
                return $this->sendForbidden('No actor scope assigned to current user');
            }

            if ($request->filled('merchant_parent_id')) {
                if (!$this->hasMerchantScopeAccess($request, (int) $request->merchant_parent_id)) {
                    return $this->sendForbidden('You are not allowed to attach this actor outside your scope');
                }
            } else {
                // Default child creation under user's direct actor scope when parent is omitted.
                $primaryDirectMerchantId = $this->primaryDirectMerchantId($request);
                if ($primaryDirectMerchantId === null) {
                    return $this->sendForbidden('No actor scope assigned to current user');
                }
                $request->merge(['merchant_parent_id' => $primaryDirectMerchantId]);
            }

            $parentMerchant = Merchant::find((int) $request->merchant_parent_id);
            $hierarchyError = $this->validateChildTypeAgainstParent($parentMerchant, (string) $request->type);
            if ($hierarchyError !== null) {
                return $hierarchyError;
            }
        }

        $merchantData = array_merge(
            $request->only([
                'name', 'address', 'tel', 'email', 'merchant_parent_id', 'type', 'lat', 'long'
            ]),
            ['status' => $request->get('status', 'PENDING')]
        );

        // Create merchant first to get ID
        $merchant = Merchant::create($merchantData);

        // Handle file uploads
        $filesToMove = ['entity_file', 'other_document_file'];
        foreach ($filesToMove as $fileField) {
            if ($request->has($fileField) && $request->$fileField) {
                try {
                    $permanentPath = StorageHelper::moveFromTemp(
                        $request->$fileField,
                        StorageHelper::generatePath('merchants', $merchant->id, basename($request->$fileField))
                    );
                    $merchant->update([$fileField => StorageHelper::getStorageUrl($permanentPath)]);
                } catch (\Exception $e) {
                    \Log::warning("Failed to move merchant {$fileField}: " . $e->getMessage());
                }
            }
        }

        AuditLogger::log($request, 'merchant.created', $merchant, [
            'type' => $merchant->type,
            'status' => $merchant->status,
            'parent_id' => $merchant->merchant_parent_id,
        ]);

        return $this->sendCreated(new MerchantResource($merchant->load('parent')), 'Merchant created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/merchants/{id}",
     *      operationId="getMerchantById",
     *      tags={"Merchants"},
     *      summary="Get merchant information",
     *      description="Returns merchant data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Merchant id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Merchant retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Merchant not found"
     *      )
     * )
     */
    public function show($id)
    {
        $merchant = Merchant::with(['parent', 'children', 'users', 'articles', 'stocks', 'orders'])->find($id);
        
        if (!$merchant) {
            return $this->sendNotFound('Merchant not found');
        }

        if (!$this->hasMerchantScopeAccess(request(), (int) $merchant->id)) {
            return $this->sendForbidden('You are not allowed to access this actor');
        }
        
        return $this->sendResponse(new MerchantResource($merchant), 'Merchant retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/merchants/{id}",
     *      operationId="updateMerchant",
     *      tags={"Merchants"},
     *      summary="Update existing merchant",
     *      description="Update merchant data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Merchant id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="name", type="string", example="ABC Store Updated"),
     *              @OA\Property(property="address", type="string", example="456 Updated St"),
     *              @OA\Property(property="entity_file", type="string", example="updated_entity.pdf"),
     *              @OA\Property(property="other_document_file", type="string", example="updated_document.pdf"),
     *              @OA\Property(property="tel", type="string", example="+1234567891"),
     *              @OA\Property(property="email", type="string", format="email", example="updated@example.com"),
     *              @OA\Property(property="merchant_parent_id", type="integer", example=2),
     *              @OA\Property(property="status", type="string", enum={"PENDING", "BLOCKED", "APPROVED", "SUSPENDED"}, example="APPROVED"),
     *              @OA\Property(property="type", type="string", enum={"Distributor", "Wholesaler", "Subwholesaler", "PointOfSell"}, example="Wholesaler"),
     *              @OA\Property(property="lat", type="number", format="float", example=40.7589),
     *              @OA\Property(property="long", type="number", format="float", example=-73.9851)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Merchant updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Merchant updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $merchant = Merchant::find($id);
        
        if (!$merchant) {
            return $this->sendNotFound('Merchant not found');
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $merchant->id)) {
            return $this->sendForbidden('You are not allowed to update this actor');
        }

        if (!$this->isSuperAdmin($request) && $this->isDirectMerchantScope($request, (int) $merchant->id)) {
            return $this->sendForbidden('Vous ne pouvez pas modifier votre acteur direct');
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|string|max:255',
            'entity_file' => 'sometimes|nullable|string|max:255',
            'other_document_file' => 'sometimes|nullable|string|max:255',
            'tel' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|nullable|email|max:255',
            'merchant_parent_id' => 'sometimes|nullable|exists:merchants,id',
            'status' => 'sometimes|in:PENDING,BLOCKED,APPROVED,SUSPENDED',
            'type' => 'sometimes|in:Distributor,Wholesaler,Subwholesaler,PointOfSell',
            'lat' => 'sometimes|nullable|numeric|between:-90,90',
            'long' => 'sometimes|nullable|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        if ($request->filled('merchant_parent_id') && !$this->hasMerchantScopeAccess($request, (int) $request->merchant_parent_id)) {
            return $this->sendForbidden('You are not allowed to set this parent actor');
        }

        if (!$this->isSuperAdmin($request)) {
            if ($request->has('merchant_parent_id') && $request->input('merchant_parent_id') === null) {
                return $this->sendForbidden('Vous ne pouvez pas retirer l’acteur parent');
            }

            $targetParentId = $request->filled('merchant_parent_id')
                ? (int) $request->merchant_parent_id
                : (int) ($merchant->merchant_parent_id ?? 0);
            $targetParent = $targetParentId > 0 ? Merchant::find($targetParentId) : null;
            $targetType = (string) ($request->input('type', $merchant->type));

            if ($targetParent !== null) {
                $hierarchyError = $this->validateChildTypeAgainstParent($targetParent, $targetType);
                if ($hierarchyError !== null) {
                    return $hierarchyError;
                }
            }
        }

        $merchant->update($request->only([
            'name', 'address', 'entity_file', 'other_document_file', 
            'tel', 'email', 'merchant_parent_id', 'status', 'type', 'lat', 'long'
        ]));

        AuditLogger::log($request, 'merchant.updated', $merchant, [
            'type' => $merchant->type,
            'status' => $merchant->status,
            'parent_id' => $merchant->merchant_parent_id,
        ]);

        return $this->sendUpdated(new MerchantResource($merchant->load('parent')), 'Merchant updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/merchants/{id}",
     *      operationId="deleteMerchant",
     *      tags={"Merchants"},
     *      summary="Delete merchant",
     *      description="Soft delete merchant",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Merchant id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Merchant deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Merchant deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $request = request();

        $merchant = Merchant::find($id);
        
        if (!$merchant) {
            return $this->sendNotFound('Merchant not found');
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $merchant->id)) {
            return $this->sendForbidden('You are not allowed to delete this actor');
        }

        if (!$this->isSuperAdmin($request) && $this->isDirectMerchantScope($request, (int) $merchant->id)) {
            return $this->sendForbidden('Vous ne pouvez pas supprimer votre acteur direct');
        }

        AuditLogger::log($request, 'merchant.deleted', $merchant, [
            'type' => $merchant->type,
            'status' => $merchant->status,
        ]);
        
        $merchant->delete();
        
        return $this->sendDeleted('Merchant deleted successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/merchants/{merchant}/users",
     *      operationId="attachUsers",
     *      tags={"Merchants"},
     *      summary="Attach users to merchant",
     *      description="Attach one or more users to a merchant",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="merchant",
     *          description="Merchant id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"user_ids"},
     *              @OA\Property(property="user_ids", type="array", @OA\Items(type="integer"), example={1, 2, 3})
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Users attached successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Users attached successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function attachUsers(Request $request, $merchantId)
    {
        $merchant = Merchant::find($merchantId);
        
        if (!$merchant) {
            return $this->sendNotFound('Merchant not found');
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $merchant->id)) {
            return $this->sendForbidden('You are not allowed to manage users for this actor');
        }

        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $users = User::query()->whereIn('id', $request->user_ids)->with('role')->get();
        foreach ($users as $user) {
            if ($this->isUserSuperAdmin($user)) {
                continue;
            }

            $hasOtherActor = $user->merchants()
                ->where('merchants.id', '!=', (int) $merchant->id)
                ->exists();
            if ($hasOtherActor) {
                return $this->sendValidationError([
                    'user_ids' => ["User {$user->id} already belongs to another actor. Non-super-admin users must be isolated to one actor."],
                ]);
            }
        }

        $merchant->users()->syncWithoutDetaching($request->user_ids);

        AuditLogger::log($request, 'merchant.users.attached', $merchant, [
            'user_ids' => collect($request->user_ids)->map(fn ($id) => (int) $id)->values()->all(),
        ]);

        return $this->sendResponse(new MerchantResource($merchant->load('users')), 'Users attached successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/merchants/{merchant}/users",
     *      operationId="detachUsers",
     *      tags={"Merchants"},
     *      summary="Detach users from merchant",
     *      description="Detach one or more users from a merchant",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="merchant",
     *          description="Merchant id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"user_ids"},
     *              @OA\Property(property="user_ids", type="array", @OA\Items(type="integer"), example={1, 2, 3})
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Users detached successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Users detached successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function detachUsers(Request $request, $merchantId)
    {
        $merchant = Merchant::find($merchantId);
        
        if (!$merchant) {
            return $this->sendNotFound('Merchant not found');
        }

        if (!$this->hasMerchantScopeAccess($request, (int) $merchant->id)) {
            return $this->sendForbidden('You are not allowed to manage users for this actor');
        }

        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $users = User::query()->whereIn('id', $request->user_ids)->with('role')->get();
        foreach ($users as $user) {
            if ($this->isUserSuperAdmin($user)) {
                continue;
            }

            $currentActorCount = $user->merchants()->count();
            $isAttachedToCurrent = $user->merchants()->where('merchants.id', (int) $merchant->id)->exists();
            if ($isAttachedToCurrent && $currentActorCount <= 1) {
                return $this->sendValidationError([
                    'user_ids' => ["User {$user->id} must keep at least one actor assignment."],
                ]);
            }
        }

        $merchant->users()->detach($request->user_ids);

        AuditLogger::log($request, 'merchant.users.detached', $merchant, [
            'user_ids' => collect($request->user_ids)->map(fn ($id) => (int) $id)->values()->all(),
        ]);

        return $this->sendResponse(new MerchantResource($merchant->load('users')), 'Users detached successfully');
    }

    private function merchantTypeRank(?string $type): int
    {
        $normalized = strtolower(trim((string) $type));

        return match ($normalized) {
            'distributor' => 400,
            'wholesaler' => 300,
            'subwholesaler' => 200,
            'pointofsell' => 100,
            default => 0,
        };
    }

    private function validateChildTypeAgainstParent(?Merchant $parentMerchant, ?string $childType)
    {
        if (!$parentMerchant) {
            return $this->sendValidationError([
                'merchant_parent_id' => ['Le parent acteur est requis pour ce profil.'],
            ]);
        }

        $parentRank = $this->merchantTypeRank($parentMerchant->type);
        $childRank = $this->merchantTypeRank($childType);
        if ($parentRank === 0 || $childRank === 0) {
            return $this->sendValidationError([
                'type' => ['Type d’acteur invalide pour la hiérarchie.'],
            ]);
        }

        if ($childRank >= $parentRank) {
            return $this->sendValidationError([
                'type' => ['Le type choisi doit être inférieur au type de l’acteur parent.'],
            ]);
        }

        return null;
    }

    private function isUserSuperAdmin(User $user): bool
    {
        $roleName = strtolower((string) ($user->role?->name ?? ''));
        return str_contains($roleName, 'super admin');
    }
}
