<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\API\V1\Concerns\InteractsWithMerchantScope;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\Privilege;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoleController extends BaseController
{
    use InteractsWithMerchantScope;

    private function isSuperAdminRoleName(?string $roleName): bool
    {
        return str_contains(strtolower((string) $roleName), 'super admin');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/roles",
     *      operationId="getRolesList",
     *      tags={"Roles"},
     *      summary="Get list of roles",
     *      description="Returns list of roles with pagination",
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
     *          name="is_active",
     *          description="Filter by active status",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="boolean")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Roles retrieved successfully"),
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
        
        $query = Role::with(['privileges']);

        if (!$this->isSuperAdmin($request)) {
            $query->whereRaw('LOWER(name) NOT LIKE ?', ['%super admin%']);
        }
        
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        
        $roles = $query->paginate($perPage);
        
        return $this->sendPaginated(RoleResource::collection($roles), 'Roles retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/roles",
     *      operationId="storeRole",
     *      tags={"Roles"},
     *      summary="Create new role",
     *      description="Create a new role",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"name"},
     *              @OA\Property(property="name", type="string", example="Manager"),
     *              @OA\Property(property="description", type="string", example="Manager role with specific permissions"),
     *              @OA\Property(property="is_active", type="boolean", example=true)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Role created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Role created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        if (
            !$this->isSuperAdmin($request)
            && $this->isSuperAdminRoleName($request->input('name'))
        ) {
            return $this->sendForbidden('Seul un super admin peut gérer ce rôle');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $role = Role::create([
            'name' => $request->name,
            'description' => $request->description,
            'is_active' => $request->get('is_active', true)
        ]);

        return $this->sendCreated(new RoleResource($role->load('privileges')), 'Role created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/roles/{id}",
     *      operationId="getRoleById",
     *      tags={"Roles"},
     *      summary="Get role information",
     *      description="Returns role data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Role id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Role retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Role not found"
     *      )
     * )
     */
    public function show($id)
    {
        $role = Role::with(['privileges', 'users'])->find($id);
        
        if (!$role) {
            return $this->sendNotFound('Role not found');
        }

        if (
            !$this->isSuperAdmin(request())
            && $this->isSuperAdminRoleName($role->name)
        ) {
            return $this->sendForbidden('Accès refusé à ce rôle');
        }
        
        return $this->sendResponse(new RoleResource($role), 'Role retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/roles/{id}",
     *      operationId="updateRole",
     *      tags={"Roles"},
     *      summary="Update existing role",
     *      description="Update role data",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Role id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="name", type="string", example="Manager"),
     *              @OA\Property(property="description", type="string", example="Updated manager role"),
     *              @OA\Property(property="is_active", type="boolean", example=true)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Role updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Role updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);
        
        if (!$role) {
            return $this->sendNotFound('Role not found');
        }

        if (
            !$this->isSuperAdmin($request)
            && $this->isSuperAdminRoleName($role->name)
        ) {
            return $this->sendForbidden('Seul un super admin peut gérer ce rôle');
        }

        if (
            !$this->isSuperAdmin($request)
            && $this->isSuperAdminRoleName($request->input('name'))
        ) {
            return $this->sendForbidden('Seul un super admin peut gérer ce rôle');
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:roles,name,' . $id,
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $role->update($request->only(['name', 'description', 'is_active']));

        return $this->sendUpdated(new RoleResource($role->load('privileges')), 'Role updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/roles/{id}",
     *      operationId="deleteRole",
     *      tags={"Roles"},
     *      summary="Delete role",
     *      description="Soft delete role",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Role id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Role deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Role deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $request = request();
        if (!$this->isSuperAdmin($request)) {
            return $this->sendForbidden('Seul le super admin peut supprimer un rôle');
        }

        $role = Role::find($id);
        
        if (!$role) {
            return $this->sendNotFound('Role not found');
        }

        $role->delete();
        
        return $this->sendDeleted('Role deleted successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/roles/{role}/privileges",
     *      operationId="attachPrivileges",
     *      tags={"Roles"},
     *      summary="Attach privileges to role",
     *      description="Attach one or more privileges to a role",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="role",
     *          description="Role id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"privilege_ids"},
     *              @OA\Property(property="privilege_ids", type="array", @OA\Items(type="integer"), example={1, 2, 3})
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Privileges attached successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Privileges attached successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function attachPrivileges(Request $request, $roleId)
    {
        $role = Role::find($roleId);
        
        if (!$role) {
            return $this->sendNotFound('Role not found');
        }

        if (
            !$this->isSuperAdmin($request)
            && $this->isSuperAdminRoleName($role->name)
        ) {
            return $this->sendForbidden('Seul un super admin peut gérer ce rôle');
        }

        $validator = Validator::make($request->all(), [
            'privilege_ids' => 'required|array',
            'privilege_ids.*' => 'exists:privileges,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $role->privileges()->syncWithoutDetaching($request->privilege_ids);

        return $this->sendResponse(new RoleResource($role->load('privileges')), 'Privileges attached successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/roles/{role}/privileges",
     *      operationId="detachPrivileges",
     *      tags={"Roles"},
     *      summary="Detach privileges from role",
     *      description="Detach one or more privileges from a role",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="role",
     *          description="Role id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"privilege_ids"},
     *              @OA\Property(property="privilege_ids", type="array", @OA\Items(type="integer"), example={1, 2, 3})
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Privileges detached successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Privileges detached successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function detachPrivileges(Request $request, $roleId)
    {
        $role = Role::find($roleId);
        
        if (!$role) {
            return $this->sendNotFound('Role not found');
        }

        if (
            !$this->isSuperAdmin($request)
            && $this->isSuperAdminRoleName($role->name)
        ) {
            return $this->sendForbidden('Seul un super admin peut gérer ce rôle');
        }

        $validator = Validator::make($request->all(), [
            'privilege_ids' => 'required|array',
            'privilege_ids.*' => 'exists:privileges,id'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $role->privileges()->detach($request->privilege_ids);

        return $this->sendResponse(new RoleResource($role->load('privileges')), 'Privileges detached successfully');
    }
}
