<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\API\V1\Concerns\InteractsWithMerchantScope;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends BaseController
{
    use InteractsWithMerchantScope;

    private function normalizeRoleName(?string $roleName): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $roleName)));
    }

    private function roleRank(?string $roleName): int
    {
        $normalized = $this->normalizeRoleName($roleName);

        if (str_contains($normalized, 'super admin')) {
            return 400;
        }
        if ($normalized === 'admin') {
            return 300;
        }
        if ($normalized === 'manager') {
            return 200;
        }
        if ($normalized === 'user') {
            return 100;
        }

        return 0;
    }

    private function canManageRoleName(?string $actingRoleName, ?string $targetRoleName): bool
    {
        if ($this->isSuperAdminRoleName($actingRoleName)) {
            return true;
        }

        $actingRank = $this->roleRank($actingRoleName);
        $targetRank = $this->roleRank($targetRoleName);

        if ($actingRank === 300) { // Admin
            return in_array($targetRank, [200, 100], true); // Manager, User
        }

        if ($actingRank === 200) { // Manager
            return $targetRank === 100; // User only
        }

        return false;
    }

    private function canAssignRoleName(?string $actingRoleName, ?string $targetRoleName): bool
    {
        if ($this->isSuperAdminRoleName($actingRoleName)) {
            return true;
        }

        $actingRank = $this->roleRank($actingRoleName);
        $targetRank = $this->roleRank($targetRoleName);

        if ($actingRank === 300) { // Admin
            return in_array($targetRank, [300, 200, 100], true); // Admin, Manager, User
        }

        if ($actingRank === 200) { // Manager
            return in_array($targetRank, [200, 100], true); // Manager, User
        }

        return false;
    }

    private function isSuperAdminRoleName(?string $roleName): bool
    {
        return str_contains(strtolower((string) $roleName), 'super admin');
    }

    private function isActorLeadershipRoleName(?string $roleName): bool
    {
        return in_array($this->normalizeRoleName($roleName), ['admin', 'manager'], true);
    }

    private function actorRoleLabel(?string $roleName): string
    {
        $normalized = $this->normalizeRoleName($roleName);
        if ($normalized === 'admin') {
            return 'admin';
        }
        if ($normalized === 'manager') {
            return 'manager';
        }

        return 'utilisateur';
    }

    /**
     * Count active users with the same role as target user, inside target user's actor(s).
     */
    private function activeSameRoleCountInUserActors(User $user, ?int $excludedUserId = null): int
    {
        $merchantIds = $user->merchants()
            ->pluck('merchants.id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($merchantIds)) {
            return 0;
        }

        return User::query()
            ->where('role_id', (int) $user->role_id)
            ->where('status', 'APPROVED')
            ->whereHas('merchants', function ($query) use ($merchantIds) {
                $query->whereIn('merchants.id', $merchantIds);
            })
            ->when($excludedUserId !== null, function ($query) use ($excludedUserId) {
                $query->where('users.id', '!=', $excludedUserId);
            })
            ->count();
    }

    private function activeSuperAdminCountExcluding(?int $excludedUserId = null): int
    {
        return User::query()
            ->whereHas('role', function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%super admin%']);
            })
            ->where('status', 'APPROVED')
            ->when($excludedUserId !== null, function ($query) use ($excludedUserId) {
                $query->where('users.id', '!=', $excludedUserId);
            })
            ->count();
    }

    /**
     * @OA\Get(
     *      path="/api/v1/users",
     *      operationId="getUsersList",
     *      tags={"Users"},
     *      summary="Get list of users",
     *      description="Returns list of users with pagination",
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
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Users retrieved successfully"),
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
        
        $query = User::with(['role.privileges', 'merchants']);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if (!$this->isSuperAdmin($request)) {
            $merchantIds = $this->accessibleMerchantIds($request);
            $currentUserId = (int) $request->user()->id;
            $query->where(function ($q) use ($merchantIds, $currentUserId) {
                $q->where('id', $currentUserId);
                if (!empty($merchantIds)) {
                    $q->orWhereHas('merchants', function ($merchantQuery) use ($merchantIds) {
                        $merchantQuery->whereIn('merchants.id', $merchantIds);
                    });
                }
            });
        }
        
        $users = $query->paginate($perPage);
        
        return $this->sendPaginated(UserResource::collection($users), 'Users retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/users",
     *      operationId="storeUser",
     *      tags={"Users"},
     *      summary="Create new user",
     *      description="Create a new user",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"first_name","last_name","email","password"},
     *              @OA\Property(property="first_name", type="string", example="John"),
     *              @OA\Property(property="last_name", type="string", example="Doe"),
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *              @OA\Property(property="password", type="string", format="password", example="password123"),
     *              @OA\Property(property="role_id", type="integer", example=1),
     *              @OA\Property(property="status", type="string", enum={"PENDING", "BLOCKED", "APPROVED", "SUSPENDED"}, example="PENDING")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="User created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="User created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'status' => 'sometimes|in:PENDING,BLOCKED,APPROVED,SUSPENDED',
            'merchant_ids' => 'sometimes|array',
            'merchant_ids.*' => 'exists:merchants,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $role = Role::find((int) $request->role_id);
        $targetIsSuperAdmin = $this->isSuperAdminRoleName($role?->name);
        if ($targetIsSuperAdmin && !$this->isSuperAdmin($request)) {
            return $this->sendForbidden('Only a super admin can assign the Super Admin role');
        }

        if (
            !$this->isSuperAdmin($request)
            && !$this->canAssignRoleName($request->user()?->role?->name, $role?->name)
        ) {
            return $this->sendForbidden('Vous ne pouvez pas attribuer ce rôle');
        }

        $merchantIds = collect($request->input('merchant_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($targetIsSuperAdmin) {
            // Super admin account must stay neutral (no actor assignment).
            if (!empty($merchantIds)) {
                return $this->sendValidationError([
                    'merchant_ids' => ['Un super admin ne peut être rattaché à aucun acteur.'],
                ]);
            }
            $merchantIds = [];
        } else {
            if (count($merchantIds) > 1) {
                return $this->sendValidationError([
                    'merchant_ids' => ['Only one actor can be assigned to non-super-admin roles.'],
                ]);
            }

            if (!$this->isSuperAdmin($request)) {
                $accessible = $this->accessibleMerchantIds($request);
                if (empty($accessible)) {
                    return $this->sendForbidden('No actor scope assigned to current user');
                }
                if (!empty($merchantIds)) {
                    $invalid = array_diff($merchantIds, $accessible);
                    if (!empty($invalid)) {
                        return $this->sendForbidden('You are not allowed to assign user outside your actor scope');
                    }
                } else {
                    $primaryDirectMerchantId = $this->primaryDirectMerchantId($request);
                    if ($primaryDirectMerchantId === null) {
                        return $this->sendForbidden('No actor scope assigned to current user');
                    }
                    $merchantIds = [$primaryDirectMerchantId];
                }
            } else {
                if (empty($merchantIds)) {
                    return $this->sendValidationError([
                        'merchant_ids' => ['Actor assignment is required for non-super-admin roles.'],
                    ]);
                }
            }
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $request->role_id,
            'status' => $request->get('status', 'PENDING')
        ]);

        if (!empty($merchantIds)) {
            $user->merchants()->sync($merchantIds);
        }

        AuditLogger::log($request, 'user.created', $user, [
            'role_id' => $user->role_id,
            'merchant_ids' => $merchantIds,
            'status' => $user->status,
        ]);

        return $this->sendCreated(new UserResource($user->load(['role.privileges', 'merchants'])), 'User created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/users/{id}",
     *      operationId="getUserById",
     *      tags={"Users"},
     *      summary="Get user information",
     *      description="Returns user data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="User id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="User retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="User not found"
     *      )
     * )
     */
    public function show($id)
    {
        $user = User::with(['role.privileges', 'merchants'])->find($id);
        
        if (!$user) {
            return $this->sendNotFound('User not found');
        }

        if (
            !$this->isSuperAdmin(request())
            && (int) request()->user()->id !== (int) $user->id
        ) {
            $accessible = $this->accessibleMerchantIds(request());
            $hasAccess = !empty($accessible) && $user->merchants()->whereIn('merchants.id', $accessible)->exists();
            if (!$hasAccess) {
                return $this->sendForbidden('You are not allowed to access this user');
            }
        }
        
        return $this->sendResponse(new UserResource($user), 'User retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/users/{id}",
     *      operationId="updateUser",
     *      tags={"Users"},
     *      summary="Update existing user",
     *      description="Update user data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="User id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="first_name", type="string", example="John"),
     *              @OA\Property(property="last_name", type="string", example="Doe"),
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *              @OA\Property(property="role_id", type="integer", example=1),
     *              @OA\Property(property="status", type="string", enum={"PENDING", "BLOCKED", "APPROVED", "SUSPENDED"}, example="APPROVED")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="User updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="User updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);
        
        if (!$user) {
            return $this->sendNotFound('User not found');
        }

        $actingUser = $request->user();
        $actingIsSuperAdmin = $this->isSuperAdmin($request);
        $actingRoleName = $actingUser?->role?->name;
        $targetCurrentRoleName = $user->role?->name;
        $isSelfUpdate = (int) $actingUser?->id === (int) $user->id;
        $actingRank = $this->roleRank($actingRoleName);
        $targetCurrentRank = $this->roleRank($targetCurrentRoleName);

        if (
            !$actingIsSuperAdmin
            && !$isSelfUpdate
        ) {
            $accessible = $this->accessibleMerchantIds($request);
            $hasAccess = !empty($accessible) && $user->merchants()->whereIn('merchants.id', $accessible)->exists();
            if (!$hasAccess) {
                return $this->sendForbidden('You are not allowed to update this user');
            }
        }

        if (
            !$isSelfUpdate
            && !($actingRank > 0 && $targetCurrentRank > 0 && $targetCurrentRank < $actingRank)
        ) {
            return $this->sendForbidden('Vous ne pouvez pas modifier cet utilisateur');
        }
        
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:8',
            'role_id' => 'sometimes|nullable|exists:roles,id',
            'status' => 'sometimes|in:PENDING,BLOCKED,APPROVED,SUSPENDED',
            'merchant_ids' => 'sometimes|array',
            'merchant_ids.*' => 'exists:merchants,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $targetRoleId = $request->filled('role_id') ? (int) $request->role_id : (int) $user->role_id;
        $targetRole = $targetRoleId ? Role::find($targetRoleId) : $user->role;
        $targetIsSuperAdmin = $this->isSuperAdminRoleName($targetRole?->name);
        $currentIsSuperAdmin = $this->isSuperAdminRoleName($user->role?->name);
        $targetStatus = strtoupper((string) ($request->input('status', $user->status)));
        $currentRoleName = $user->role?->name;
        $currentRoleNormalized = $this->normalizeRoleName($currentRoleName);
        $targetRoleNormalized = $this->normalizeRoleName($targetRole?->name);
        $currentMerchantIds = $user->merchants()
            ->pluck('merchants.id')
            ->map(fn ($merchantId) => (int) $merchantId)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $currentIsLeadershipRole = $this->isActorLeadershipRoleName($currentRoleName);
        $isLastLeadershipInActor = $currentIsLeadershipRole
            && strtoupper((string) $user->status) === 'APPROVED'
            && $this->activeSameRoleCountInUserActors($user, (int) $user->id) === 0;

        if (
            !$actingIsSuperAdmin
            && $isSelfUpdate
            && $request->has('merchant_ids')
        ) {
            $requestedMerchantIds = collect($request->input('merchant_ids', []))
                ->map(fn ($merchantId) => (int) $merchantId)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $currentSortedMerchantIds = collect($currentMerchantIds)
                ->sort()
                ->values()
                ->all();

            if ($requestedMerchantIds !== $currentSortedMerchantIds) {
                return $this->sendForbidden('Vous ne pouvez pas modifier votre propre acteur');
            }
        }

        if ($targetIsSuperAdmin && !$this->isSuperAdmin($request)) {
            return $this->sendForbidden('Only a super admin can assign the Super Admin role');
        }

        if (
            !$actingIsSuperAdmin
            && !$this->canAssignRoleName($actingRoleName, $targetRole?->name)
        ) {
            return $this->sendForbidden('Vous ne pouvez pas attribuer ce rôle');
        }

        if (!$actingIsSuperAdmin && $isSelfUpdate) {
            if ($currentRoleNormalized === 'user' && $request->has('status')) {
                return $this->sendForbidden('Vous ne pouvez pas changer votre statut');
            }

            if ($isLastLeadershipInActor) {
                $roleLabel = $this->actorRoleLabel($currentRoleName);

                if ($request->has('role_id') && $targetRoleNormalized !== $currentRoleNormalized) {
                    return $this->sendForbidden("Vous êtes le dernier {$roleLabel} actif de cet acteur. Ajoutez d'abord un autre {$roleLabel} avant de changer votre rôle");
                }

                if ($request->has('status') && $targetStatus !== 'APPROVED') {
                    return $this->sendForbidden("Vous êtes le dernier {$roleLabel} actif de cet acteur. Vous ne pouvez pas changer votre statut");
                }
            }
        }

        if (
            $currentIsSuperAdmin
            && !$targetIsSuperAdmin
            && $this->activeSuperAdminCountExcluding((int) $user->id) === 0
        ) {
            return $this->sendForbidden('Cannot downgrade the last Super Admin account');
        }

        $data = $request->only(['first_name', 'last_name', 'email', 'role_id', 'status']);
        
        if ($request->has('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $shouldSyncMerchants = false;
        $merchantIds = [];

        if ($request->has('merchant_ids')) {
            $merchantIds = collect($request->input('merchant_ids', []))
                ->map(fn ($merchantId) => (int) $merchantId)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($targetIsSuperAdmin) {
                if (!empty($merchantIds)) {
                    return $this->sendValidationError([
                        'merchant_ids' => ['Un super admin ne peut être rattaché à aucun acteur.'],
                    ]);
                }
                $merchantIds = [];
                $shouldSyncMerchants = true;
            } else {
                if (count($merchantIds) > 1) {
                    return $this->sendValidationError([
                        'merchant_ids' => ['Only one actor can be assigned to non-super-admin roles.'],
                    ]);
                }

                if (empty($merchantIds)) {
                    return $this->sendValidationError([
                        'merchant_ids' => ['Actor assignment is required for non-super-admin roles.'],
                    ]);
                }

                if (!$this->isSuperAdmin($request)) {
                    $accessible = $this->accessibleMerchantIds($request);
                    $invalid = array_diff($merchantIds, $accessible);
                    if (!empty($invalid)) {
                        return $this->sendForbidden('You are not allowed to assign user outside your actor scope');
                    }
                }
                $shouldSyncMerchants = true;
            }
        } elseif (!$targetIsSuperAdmin) {
            $existingMerchantIds = $currentMerchantIds;
            if (empty($existingMerchantIds)) {
                if ($this->isSuperAdmin($request)) {
                    return $this->sendValidationError([
                        'merchant_ids' => ['Actor assignment is required for non-super-admin roles.'],
                    ]);
                }
                $accessible = $this->accessibleMerchantIds($request);
                if (empty($accessible)) {
                    return $this->sendForbidden('No actor scope assigned to current user');
                }
                $primaryDirectMerchantId = $this->primaryDirectMerchantId($request);
                if ($primaryDirectMerchantId === null) {
                    return $this->sendForbidden('No actor scope assigned to current user');
                }
                $merchantIds = [$primaryDirectMerchantId];
                $shouldSyncMerchants = true;
            } elseif (count($existingMerchantIds) > 1) {
                // Self-heal legacy multi-actor assignment for non-super-admin users.
                $merchantIds = [(int) $existingMerchantIds[0]];
                $shouldSyncMerchants = true;
            }
        } else {
            // Super admin must never keep actor associations.
            $merchantIds = [];
            $shouldSyncMerchants = true;
        }

        $user->update($data);

        if ($shouldSyncMerchants) {
            $user->merchants()->sync($merchantIds);
        }

        AuditLogger::log($request, 'user.updated', $user, [
            'role_id' => $user->role_id,
            'status' => $user->status,
            'merchant_ids' => $user->merchants()->pluck('merchants.id')->map(fn ($id) => (int) $id)->all(),
        ]);

        return $this->sendUpdated(new UserResource($user->load(['role.privileges', 'merchants'])), 'User updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/users/{id}",
     *      operationId="deleteUser",
     *      tags={"Users"},
     *      summary="Delete user",
     *      description="Soft delete user",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="User id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="User deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="User deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $user = User::find($id);
        
        if (!$user) {
            return $this->sendNotFound('User not found');
        }

        $request = request();
        $actingUser = $request->user();
        $actingIsSuperAdmin = $this->isSuperAdmin($request);
        $actingRoleName = $actingUser?->role?->name;

        $targetRoleName = $user->role?->name;
        $isTargetSuperAdmin = $this->isSuperAdminRoleName($targetRoleName);
        $isTargetLeadershipRole = $this->isActorLeadershipRoleName($targetRoleName);
        $isLastActiveSuperAdmin = $isTargetSuperAdmin
            && $this->activeSuperAdminCountExcluding((int) $user->id) === 0;
        $isLastActiveLeadershipInActor = $isTargetLeadershipRole
            && strtoupper((string) $user->status) === 'APPROVED'
            && $this->activeSameRoleCountInUserActors($user, (int) $user->id) === 0;
        $isSelfDelete = $actingUser && (int) $actingUser->id === (int) $user->id;

        if ($isLastActiveSuperAdmin) {
            return $this->sendForbidden('Impossible de supprimer le dernier super admin actif');
        }

        if ($isLastActiveLeadershipInActor && !$actingIsSuperAdmin && $isSelfDelete) {
            $roleLabel = $this->actorRoleLabel($targetRoleName);
            return $this->sendForbidden("Impossible de supprimer le dernier {$roleLabel} actif de cet acteur");
        }

        if (
            !$isSelfDelete
            && $actingIsSuperAdmin
            && $isTargetSuperAdmin
        ) {
            return $this->sendForbidden('Vous ne pouvez pas supprimer un autre super admin');
        }

        if (!$isSelfDelete) {
            if (!$actingIsSuperAdmin) {
                $actingRank = $this->roleRank($actingRoleName);
                $targetRank = $this->roleRank($user->role?->name);
                $canDeleteLowerRole = $actingRank > 0 && $targetRank > 0 && $targetRank < $actingRank;
                if (!$canDeleteLowerRole) {
                    return $this->sendForbidden('Vous ne pouvez supprimer que votre propre compte ou un profil de niveau inférieur');
                }
            }
        }

        if (
            !$actingIsSuperAdmin
            && !$isSelfDelete
        ) {
            $accessible = $this->accessibleMerchantIds($request);
            $hasAccess = !empty($accessible) && $user->merchants()->whereIn('merchants.id', $accessible)->exists();
            if (!$hasAccess) {
                return $this->sendForbidden('You are not allowed to delete this user');
            }
        }
        
        $user->delete();

        AuditLogger::log($request, 'user.deleted', $user, [
            'status' => $user->status,
        ]);
        
        return $this->sendDeleted('User deleted successfully');
    }
}
