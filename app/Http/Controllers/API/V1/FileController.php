<?php

namespace App\Http\Controllers\API\V1;

use App\Helpers\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FileController extends BaseController
{
    /**
     * @OA\Post(
     *      path="/api/v1/files/upload",
     *      operationId="uploadFile",
     *      tags={"Files"},
     *      summary="Upload file to temporary storage",
     *      description="Upload a file to temporary storage and get a temporary URL",
     *      security={{"bearerAuth": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="file",
     *                      type="string",
     *                      format="binary",
     *                      description="File to upload"
     *                  )
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="File uploaded successfully"
     *      )
     * )
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file',
            'category' => 'sometimes|string|max:50',
            'max_size' => 'sometimes|integer|min:1',
            'allowed_extensions' => 'sometimes|array',
            'allowed_extensions.*' => 'string'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $file = $request->file('file');
        
        // Build validation rules
        $validationRules = [];
        
        if ($request->has('max_size')) {
            $validationRules['max_size'] = $request->max_size;
        }
        
        if ($request->has('allowed_extensions')) {
            $validationRules['extensions'] = $request->allowed_extensions;
        }
        
        // Validate file
        $validationErrors = StorageHelper::validateFile($file, $validationRules);
        
        if (!empty($validationErrors)) {
            return $this->sendValidationError(['file' => $validationErrors]);
        }

        try {
            $uploadResult = StorageHelper::uploadToTemp($file);
            
            return $this->sendResponse($uploadResult, 'File uploaded successfully');
            
        } catch (\Exception $e) {
            return $this->sendError('Failed to upload file: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Serve file from storage
     */
    public function serve(Request $request, $path = null)
    {
        // Reconstruct full path from route parameters
        $segments = $request->segments();
        $fileSegments = array_slice($segments, 2); // Remove 'api' and 'files'
        $fullPath = implode('/', $fileSegments);
        
        if (!$fullPath) {
            return response()->json(['error' => 'File path required'], 400);
        }

        try {
            // Try to get file stream
            $stream = StorageHelper::getStream($fullPath);
            
            if (!$stream) {
                return response()->json(['error' => 'File not found'], 404);
            }

            // Get MIME type
            $mimeType = StorageHelper::getMimeType($fullPath);
            
            // If file is in local but S3 is active, dispatch job to sync
            if (config('filesystems.default') === 's3' && 
                !StorageHelper::exists($fullPath) && 
                \Storage::disk('local')->exists($fullPath)) {
                
                // Dispatch job to sync to S3 (we'll create this job later)
                // \App\Jobs\SyncFileToS3::dispatch($fullPath);
            }

            return response()->stream(function () use ($stream) {
                fpassthru($stream);
                fclose($stream);
            }, 200, [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'public, max-age=3600',
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error serving file'], 500);
        }
    }

    /**
     * Move file from temporary to permanent storage
     */
    public function move(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'temp_url' => 'required|string',
            'category' => 'required|string|max:50',
            'entity_id' => 'required|integer',
            'filename' => 'sometimes|string|max:255'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        try {
            $tempUrl = $request->temp_url;
            $category = $request->category;
            $entityId = $request->entity_id;
            $filename = $request->filename ?? basename($tempUrl);
            
            // Generate permanent path
            $permanentPath = StorageHelper::generatePath($category, $entityId, $filename);
            
            // Move file
            $finalPath = StorageHelper::moveFromTemp($tempUrl, $permanentPath);
            
            return $this->sendResponse([
                'url' => StorageHelper::getStorageUrl($finalPath),
                'access_url' => StorageHelper::getAccessUrl($finalPath)
            ], 'File moved successfully');
            
        } catch (\Exception $e) {
            return $this->sendError('Failed to move file: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Delete file from storage
     */
    public function delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'url' => 'required|string'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        try {
            $deleted = StorageHelper::delete($request->url);
            
            if ($deleted) {
                return $this->sendResponse(null, 'File deleted successfully');
            } else {
                return $this->sendError('File not found or could not be deleted', [], 404);
            }
            
        } catch (\Exception $e) {
            return $this->sendError('Failed to delete file: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Get file information
     */
    public function info(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'url' => 'required|string'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        try {
            $url = $request->url;
            $exists = StorageHelper::exists($url);
            
            $info = [
                'exists' => $exists,
                'access_url' => StorageHelper::getAccessUrl($url)
            ];
            
            if ($exists) {
                $info['size'] = StorageHelper::size($url);
                $info['last_modified'] = date('c', StorageHelper::lastModified($url));
                $info['mime_type'] = StorageHelper::getMimeType($url);
            }
            
            return $this->sendResponse($info, 'File information retrieved');
            
        } catch (\Exception $e) {
            return $this->sendError('Failed to get file info: ' . $e->getMessage(), [], 500);
        }
    }
}
