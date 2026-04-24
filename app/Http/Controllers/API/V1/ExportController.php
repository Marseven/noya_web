<?php

namespace App\Http\Controllers\API\V1;

use App\Exports\ArticlesExport;
use App\Exports\MerchantsExport;
use App\Exports\OrdersExport;
use App\Exports\UsersExport;
use App\Models\ExportToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelWriter;

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
            'format' => 'sometimes|string|in:xlsx,csv,pdf',
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
        $format = strtolower((string) $request->input('format', 'xlsx'));
        $user = auth()->user();

        // Check privileges based on export type
        $requiredPrivilege = $type . '.export';
        if (!$user->hasPrivilege($requiredPrivilege)) {
            return $this->sendError('Insufficient privileges to export ' . $type, [], 403);
        }

        try {
            // Generate filename
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "{$type}_export_{$timestamp}.{$format}";
            
            // Create export based on type
            $export = $this->createExport($type, $request->all());
            
            // Generate file path in exports directory
            $filePath = "exports/{$filename}";
            
            if ($format === 'pdf') {
                $pdfContent = $this->buildSimplePdfFromExport($export, strtoupper($type) . ' EXPORT');
                Storage::disk('local')->put($filePath, $pdfContent);
            } else {
                $writerType = $format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX;
                Excel::store($export, $filePath, 'local', $writerType);
            }
            
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
                'type' => $type,
                'format' => $format
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
            if (!Storage::disk('local')->exists($exportToken->file_path)) {
                return $this->sendError('Export file not found', [], 404);
            }
            
            // Mark token as used
            $exportToken->markAsUsed();
            
            // Get file content and info
            $fileContent = Storage::disk('local')->get($exportToken->file_path);
            $fileName = basename($exportToken->file_path);
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $contentType = $this->detectContentType($extension);
            
            // Clean up the file after download
            Storage::disk('local')->delete($exportToken->file_path);
            
            return response($fileContent)
                ->header('Content-Type', $contentType)
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
                'format' => strtolower((string) pathinfo($export->file_path, PATHINFO_EXTENSION)),
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

    private function detectContentType(string $extension): string
    {
        return match ($extension) {
            'csv' => 'text/csv; charset=UTF-8',
            'pdf' => 'application/pdf',
            default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    private function buildSimplePdfFromExport(object $export, string $title): string
    {
        if (!method_exists($export, 'query') || !method_exists($export, 'headings') || !method_exists($export, 'map')) {
            throw new \RuntimeException('Export object cannot be converted to PDF');
        }

        $headings = (array) $export->headings();
        $items = $export->query()->limit(80)->get();
        $rows = [];
        foreach ($items as $item) {
            $rows[] = (array) $export->map($item);
        }

        $lines = [];
        $lines[] = $title;
        $lines[] = 'Generated at: ' . now()->toDateTimeString();
        $lines[] = '';
        $lines[] = implode(' | ', $headings);
        $lines[] = str_repeat('-', 110);
        foreach ($rows as $row) {
            $line = implode(' | ', array_map(function ($value) {
                $str = trim((string) $value);
                return $str === '' ? '-' : $str;
            }, $row));
            $lines[] = substr($line, 0, 110);
        }

        if ($export->query()->count() > count($rows)) {
            $lines[] = '';
            $lines[] = '... output truncated. Please use XLSX/CSV for full data.';
        }

        return $this->renderSimplePdf($lines);
    }

    private function renderSimplePdf(array $lines): string
    {
        $lines = array_slice($lines, 0, 45);

        $content = "BT\n/F1 10 Tf\n50 800 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -16 Td\n";
            }
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $line);
            $content .= "({$safe}) Tj\n";
        }
        $content .= "ET";

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
        $objects[] = "2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj";
        $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj";
        $objects[] = "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream\nendobj";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj . "\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
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
