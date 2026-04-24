<?php

namespace App\Http\Controllers\API\V1;

use App\Exports\ArticlesExport;
use App\Exports\MerchantsExport;
use App\Exports\OrdersExport;
use App\Exports\UsersExport;
use App\Models\ExportToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends BaseController
{
    /**
     * @OA\Post(
     *      path="/api/v1/exports/generate",
     *      operationId="generateExport",
     *      tags={"Exports"},
     *      summary="Generate export file and get download link",
     *      description="Generate an Excel export for specified data type with filters and return a one-time download link",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"type"},
     *              @OA\Property(
     *                  property="type",
     *                  type="string",
     *                  enum={"users", "merchants", "orders", "articles"},
     *                  description="Type of data to export",
     *                  example="users"
     *              ),
     *              @OA\Property(
     *                  property="from_date",
     *                  type="string",
     *                  format="date",
     *                  description="Start date for filtering (YYYY-MM-DD)",
     *                  example="2024-01-01"
     *              ),
     *              @OA\Property(
     *                  property="to_date",
     *                  type="string",
     *                  format="date",
     *                  description="End date for filtering (YYYY-MM-DD)",
     *                  example="2024-12-31"
     *              ),
     *              @OA\Property(
     *                  property="status",
     *                  type="string",
     *                  description="Filter by status (for users/merchants/orders)",
     *                  example="APPROVED"
     *              ),
     *              @OA\Property(
     *                  property="merchant_id",
     *                  type="integer",
     *                  description="Filter by merchant ID (for orders/articles)",
     *                  example=1
     *              ),
     *              @OA\Property(
     *                  property="type_filter",
     *                  type="string",
     *                  description="Filter by type (for merchants)",
     *                  example="Distributor"
     *              ),
     *              @OA\Property(
     *                  property="is_active",
     *                  type="boolean",
     *                  description="Filter by active status (for articles)",
     *                  example=true
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Export generated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Export generated successfully"),
     *              @OA\Property(
     *                  property="data",
     *                  type="object",
     *                  @OA\Property(property="download_url", type="string", example="https://domain.com/api/v1/exports/download/abc123token"),
     *                  @OA\Property(property="expires_at", type="string", example="2024-01-01T13:00:00.000000Z"),
     *                  @OA\Property(property="file_name", type="string", example="users_export_2024-01-01.xlsx"),
     *                  @OA\Property(property="type", type="string", example="users")
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Validation error",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=false),
     *              @OA\Property(property="message", type="string", example="Validation Error"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      ),
     *      @OA\Response(
     *          response=403,
     *          description="Insufficient privileges",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=false),
     *              @OA\Property(property="message", type="string", example="Insufficient privileges")
     *          )
     *      )
     * )
     */
    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:users,merchants,orders,articles',
            'from_date' => 'sometimes|date',
            'to_date' => 'sometimes|date|after_or_equal:from_date',
            'status' => 'sometimes|string',
            'merchant_id' => 'sometimes|integer|exists:merchants,id',
            'type_filter' => 'sometimes|string',
            'is_active' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $type = $request->type;
        $user = auth()->user();

        // Check privileges based on export type
        $requiredPrivilege = $type . '.export';
        if (!$user->hasPrivilege($requiredPrivilege)) {
            return $this->sendError('Insufficient privileges to export ' . $type, [], 403);
        }

        try {
            // Generate filename
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "{$type}_export_{$timestamp}.xlsx";
            
            // Create export based on type
            $export = $this->createExport($type, $request->all());
            
            // Generate file path in exports directory
            $filePath = "exports/{$filename}";
            
            // Store the Excel file
            Excel::store($export, $filePath, 'local');
            
            // Create one-time download token
            $exportToken = ExportToken::createToken(
                $user->id,
                $type,
                $filePath,
                $request->only(['from_date', 'to_date', 'status', 'merchant_id', 'type_filter', 'is_active']),
                60 // Expires in 60 minutes
            );
            
            return $this->sendResponse([
                'download_url' => url("/api/v1/exports/download/{$exportToken->token}"),
                'expires_at' => $exportToken->expires_at->toISOString(),
                'file_name' => $filename,
                'type' => $type
            ], 'Export generated successfully');
            
        } catch (\Exception $e) {
            return $this->sendError('Failed to generate export: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * @OA\Get(
     *      path="/api/v1/exports/download/{token}",
     *      operationId="downloadExport",
     *      tags={"Exports"},
     *      summary="Download export file using one-time token",
     *      description="Download the generated export file using a one-time download token. No authentication required - token provides access.",
     *      @OA\Parameter(
     *          name="token",
     *          description="One-time download token received from generate export API",
     *          required=true,
     *          in="path",
     *          @OA\Schema(type="string", example="abc123def456ghi789jkl012mno345pqr678stu901vwx234yzab567cdef890")
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Excel file download",
     *          @OA\MediaType(
     *              mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
     *              @OA\Schema(
     *                  type="string",
     *                  format="binary"
     *              )
     *          ),
     *          @OA\Header(
     *              header="Content-Disposition",
     *              description="Attachment filename",
     *              @OA\Schema(type="string", example="attachment; filename=users_export_2024-01-01.xlsx")
     *          )
     *      ),
     *      @OA\Response(
     *          response=404,
     *          description="Invalid token",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=false),
     *              @OA\Property(property="message", type="string", example="Invalid download token")
     *          )
     *      ),
     *      @OA\Response(
     *          response=410,
     *          description="Token expired or already used",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=false),
     *              @OA\Property(property="message", type="string", example="Download token has already been used")
     *          )
     *      )
     * )
     */
    public function download(Request $request, $token)
    {
        $exportToken = ExportToken::where('token', $token)->first();
        
        if (!$exportToken) {
            return $this->sendError('Invalid download token', [], 404);
        }
        
        if (!$exportToken->isValid()) {
            if ($exportToken->used) {
                return $this->sendError('Download token has already been used', [], 410);
            } else {
                return $this->sendError('Download token has expired', [], 410);
            }
        }
        
        try {
            // Check if file exists
            if (!\Storage::disk('local')->exists($exportToken->file_path)) {
                return $this->sendError('Export file not found', [], 404);
            }
            
            // Mark token as used
            $exportToken->markAsUsed();
            
            // Get file content and info
            $fileContent = \Storage::disk('local')->get($exportToken->file_path);
            $fileName = basename($exportToken->file_path);
            
            // Clean up the file after download
            \Storage::disk('local')->delete($exportToken->file_path);
            
            return response($fileContent)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
                
        } catch (\Exception $e) {
            return $this->sendError('Failed to download file: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * @OA\Get(
     *      path="/api/v1/exports/history",
     *      operationId="getExportHistory",
     *      tags={"Exports"},
     *      summary="Get user's export history",
     *      description="Get the authenticated user's export history with pagination",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Parameter(
     *          name="per_page",
     *          description="Number of items per page (default: 15)",
     *          required=false,
     *          in="query",
     *          @OA\Schema(type="integer", default=15, minimum=1, maximum=100)
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Export history retrieved successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Export history retrieved successfully"),
     *              @OA\Property(
     *                  property="data",
     *                  type="array",
     *                  @OA\Items(
     *                      type="object",
     *                      @OA\Property(property="id", type="integer", example=1),
     *                      @OA\Property(property="export_type", type="string", example="users"),
     *                      @OA\Property(
     *                          property="parameters",
     *                          type="object",
     *                          @OA\Property(property="from_date", type="string", example="2024-01-01"),
     *                          @OA\Property(property="to_date", type="string", example="2024-12-31"),
     *                          @OA\Property(property="status", type="string", example="APPROVED")
     *                      ),
     *                      @OA\Property(property="used", type="boolean", example=true),
     *                      @OA\Property(property="expires_at", type="string", example="2024-01-01T13:00:00.000000Z"),
     *                      @OA\Property(property="used_at", type="string", example="2024-01-01T12:30:00.000000Z"),
     *                      @OA\Property(property="created_at", type="string", example="2024-01-01T12:00:00.000000Z")
     *                  )
     *              ),
     *              @OA\Property(
     *                  property="meta",
     *                  type="object",
     *                  @OA\Property(property="current_page", type="integer", example=1),
     *                  @OA\Property(property="per_page", type="integer", example=15),
     *                  @OA\Property(property="total", type="integer", example=25)
     *              )
     *          )
     *      )
     * )
     */
    public function history(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $user = auth()->user();
        
        $exports = ExportToken::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
            
        $data = $exports->map(function ($export) {
            $isDownloadAvailable = $export->isValid();

            return [
                'id' => $export->id,
                'export_type' => $export->export_type,
                'file_name' => basename($export->file_path),
                'parameters' => $export->parameters,
                'used' => $export->used,
                'expires_at' => $export->expires_at->toISOString(),
                'used_at' => $export->used_at ? $export->used_at->toISOString() : null,
                'created_at' => $export->created_at->toISOString(),
                'token' => $isDownloadAvailable ? $export->token : null,
                'download_url' => $isDownloadAvailable ? url("/api/v1/exports/download/{$export->token}") : null
            ];
        });
        
        return $this->sendPaginated($data, 'Export history retrieved successfully');
    }

    public function destroyHistory(Request $request, int $id)
    {
        $user = auth()->user();

        $export = ExportToken::where('user_id', $user->id)->find($id);
        if (!$export) {
            return $this->sendError('Export history entry not found', [], 404);
        }

        $export->delete();

        return $this->sendResponse([
            'id' => $id
        ], 'Export history entry deleted successfully');
    }

    /**
     * Create export instance based on type
     */
    private function createExport(string $type, array $parameters)
    {
        switch ($type) {
            case 'users':
                return new UsersExport(
                    $parameters['from_date'] ?? null,
                    $parameters['to_date'] ?? null,
                    $parameters['status'] ?? null
                );
                
            case 'merchants':
                return new MerchantsExport(
                    $parameters['from_date'] ?? null,
                    $parameters['to_date'] ?? null,
                    $parameters['status'] ?? null,
                    $parameters['type_filter'] ?? null
                );
                
            case 'orders':
                return new OrdersExport(
                    $parameters['from_date'] ?? null,
                    $parameters['to_date'] ?? null,
                    $parameters['status'] ?? null,
                    $parameters['merchant_id'] ?? null
                );
                
            case 'articles':
                return new ArticlesExport(
                    $parameters['from_date'] ?? null,
                    $parameters['to_date'] ?? null,
                    $parameters['merchant_id'] ?? null,
                    $parameters['is_active'] ?? null
                );
                
            default:
                throw new \InvalidArgumentException("Unsupported export type: {$type}");
        }
    }
}
