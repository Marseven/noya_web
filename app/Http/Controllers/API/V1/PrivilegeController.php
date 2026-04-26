<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Resources\PrivilegeResource;
use App\Models\Privilege;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrivilegeController extends BaseController
{
    /**
     * @OA\Get(
     *      path="/api/v1/privileges",
     *      operationId="getPrivilegesList",
     *      tags={"Privileges"},
     *      summary="Get list of privileges",
     *      description="Returns list of privileges with pagination",
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
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Privileges retrieved successfully"),
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
        
        $query = Privilege::with(['roles']);
        
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        
        $privileges = $query->paginate($perPage);
        
        return $this->sendPaginated(PrivilegeResource::collection($privileges), 'Privileges retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/privileges",
     *      operationId="storePrivilege",
     *      tags={"Privileges"},
     *      summary="Create new privilege",
     *      description="Create a new privilege",
     *      security={{"bearerAuth": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"nom"},
     *              @OA\Property(property="nom", type="string", example="users.create"),
     *              @OA\Property(property="description", type="string", example="Create users privilege"),
     *              @OA\Property(property="is_active", type="boolean", example=true)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Privilege created successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Privilege created successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255|unique:privileges,nom',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $privilege = Privilege::create([
            'nom' => $request->nom,
            'description' => $request->description,
            'is_active' => $request->get('is_active', true)
        ]);

        return $this->sendCreated(new PrivilegeResource($privilege), 'Privilege created successfully');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/privileges/{id}",
     *      operationId="getPrivilegeById",
     *      tags={"Privileges"},
     *      summary="Get privilege information",
     *      description="Returns privilege data",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Privilege id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Privilege retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Privilege not found"
     *      )
     * )
     */
    public function show($id)
    {
        $privilege = Privilege::with(['roles'])->find($id);
        
        if (!$privilege) {
            return $this->sendNotFound('Privilege not found');
        }
        
        return $this->sendResponse(new PrivilegeResource($privilege), 'Privilege retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/privileges/{id}",
     *      operationId="updatePrivilege",
     *      tags={"Privileges"},
     *      summary="Update existing privilege",
     *      description="Update privilege data",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Privilege id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="nom", type="string", example="users.create"),
     *              @OA\Property(property="description", type="string", example="Updated create users privilege"),
     *              @OA\Property(property="is_active", type="boolean", example=true)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Privilege updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Privilege updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function update(Request $request, $id)
    {
        $privilege = Privilege::find($id);
        
        if (!$privilege) {
            return $this->sendNotFound('Privilege not found');
        }
        
        $validator = Validator::make($request->all(), [
            'nom' => 'sometimes|string|max:255|unique:privileges,nom,' . $id,
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $privilege->update($request->only(['nom', 'description', 'is_active']));

        return $this->sendUpdated(new PrivilegeResource($privilege), 'Privilege updated successfully');
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/privileges/{id}",
     *      operationId="deletePrivilege",
     *      tags={"Privileges"},
     *      summary="Delete privilege",
     *      description="Soft delete privilege",
     *      security={{"bearerAuth": {}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Privilege id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="integer")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Privilege deleted successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Privilege deleted successfully")
     *          )
     *      )
     * )
     */
    public function destroy($id)
    {
        $privilege = Privilege::find($id);
        
        if (!$privilege) {
            return $this->sendNotFound('Privilege not found');
        }
        
        $privilege->delete();
        
        return $this->sendDeleted('Privilege deleted successfully');
    }
}
