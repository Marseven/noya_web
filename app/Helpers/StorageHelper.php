<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class StorageHelper
{
    /**
     * Upload file to temporary storage
     */
    public static function uploadToTemp(UploadedFile $file): array
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        
        // Generate unique filename
        $uniqueId = Str::uuid();
        $filename = $uniqueId . '.' . $extension;
        
        // Store in temp directory
        $tempPath = 'temp/' . $filename;
        
        // Store file
        $disk = self::getActiveDisk();
        $path = $file->storeAs('temp', $filename, $disk);
        
        return [
            'url' => $tempPath,
            'original_name' => $originalName,
            'filename' => $filename,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
            'disk' => $disk,
            'uploaded_at' => now()->toISOString()
        ];
    }

    /**
     * Move file from temp to permanent location
     */
    public static function moveFromTemp(string $tempUrl, string $permanentPath): string
    {
        $disk = self::getActiveDisk();
        
        // Extract filename from temp URL
        $tempFilename = basename($tempUrl);
        $tempPath = 'temp/' . $tempFilename;
        
        // Check if temp file exists
        if (!Storage::disk($disk)->exists($tempPath)) {
            throw new \Exception('Temporary file not found: ' . $tempPath);
        }
        
        // Ensure directory exists
        $directory = dirname($permanentPath);
        if (!Storage::disk($disk)->exists($directory)) {
            Storage::disk($disk)->makeDirectory($directory);
        }
        
        // Move file
        Storage::disk($disk)->move($tempPath, $permanentPath);
        
        return $permanentPath;
    }

    /**
     * Get file URL for database storage
     */
    public static function getStorageUrl(string $path): string
    {
        // Always return the same format regardless of storage type
        // This ensures database URLs remain consistent
        return $path;
    }

    /**
     * Get full URL for file access
     */
    public static function getAccessUrl(string $storagePath): string
    {
        $disk = self::getActiveDisk();
        
        if ($disk === 's3') {
            return Storage::disk('s3')->url($storagePath);
        }
        
        // For local storage, return route-based URL
        return url('/api/files/' . $storagePath);
    }

    /**
     * Check if file exists
     */
    public static function exists(string $path): bool
    {
        $disk = self::getActiveDisk();
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Get file stream
     */
    public static function getStream(string $path)
    {
        $disk = self::getActiveDisk();
        
        if (!Storage::disk($disk)->exists($path)) {
            // If S3 is active but file doesn't exist, check local
            if ($disk === 's3' && Storage::disk('local')->exists($path)) {
                return Storage::disk('local')->readStream($path);
            }
            return null;
        }
        
        return Storage::disk($disk)->readStream($path);
    }

    /**
     * Delete file
     */
    public static function delete(string $path): bool
    {
        $disk = self::getActiveDisk();
        return Storage::disk($disk)->delete($path);
    }

    /**
     * Get file size
     */
    public static function size(string $path): int
    {
        $disk = self::getActiveDisk();
        return Storage::disk($disk)->size($path);
    }

    /**
     * Get file last modified time
     */
    public static function lastModified(string $path): int
    {
        $disk = self::getActiveDisk();
        return Storage::disk($disk)->lastModified($path);
    }

    /**
     * Copy file from local to S3
     */
    public static function syncToS3(string $path): bool
    {
        if (!Storage::disk('local')->exists($path)) {
            return false;
        }
        
        $content = Storage::disk('local')->get($path);
        return Storage::disk('s3')->put($path, $content);
    }

    /**
     * Get all temp files older than specified minutes
     */
    public static function getOldTempFiles(int $minutes = 10): array
    {
        $disk = self::getActiveDisk();
        $cutoffTime = Carbon::now()->subMinutes($minutes);
        
        $files = Storage::disk($disk)->files('temp');
        $oldFiles = [];
        
        foreach ($files as $file) {
            $lastModified = Storage::disk($disk)->lastModified($file);
            if ($lastModified < $cutoffTime->timestamp) {
                $oldFiles[] = $file;
            }
        }
        
        return $oldFiles;
    }

    /**
     * Clean up old temp files
     */
    public static function cleanupTempFiles(int $minutes = 10): int
    {
        $oldFiles = self::getOldTempFiles($minutes);
        $disk = self::getActiveDisk();
        $deletedCount = 0;
        
        foreach ($oldFiles as $file) {
            if (Storage::disk($disk)->delete($file)) {
                $deletedCount++;
            }
        }
        
        return $deletedCount;
    }

    /**
     * Validate file
     */
    public static function validateFile(UploadedFile $file, array $rules = []): array
    {
        $errors = [];
        
        // Default rules
        $maxSize = $rules['max_size'] ?? 10 * 1024 * 1024; // 10MB
        $allowedExtensions = $rules['extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
        $allowedMimeTypes = $rules['mime_types'] ?? [
            'image/jpeg', 'image/png', 'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        
        // Check file size
        if ($file->getSize() > $maxSize) {
            $errors[] = 'File size exceeds maximum allowed size of ' . ($maxSize / 1024 / 1024) . 'MB';
        }
        
        // Check extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, array_map('strtolower', $allowedExtensions))) {
            $errors[] = 'File extension not allowed. Allowed: ' . implode(', ', $allowedExtensions);
        }
        
        // Check MIME type
        if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
            $errors[] = 'File type not allowed';
        }
        
        return $errors;
    }

    /**
     * Get active storage disk
     */
    private static function getActiveDisk(): string
    {
        return config('filesystems.default', 'local');
    }

    /**
     * Generate unique path for permanent storage
     */
    public static function generatePath(string $category, int $entityId, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $uniqueId = Str::uuid();
        
        return $category . '/' . $entityId . '/' . $uniqueId . '.' . $extension;
    }

    /**
     * Get MIME type from file path
     */
    public static function getMimeType(string $path): string
    {
        $disk = self::getActiveDisk();
        
        if (!Storage::disk($disk)->exists($path)) {
            return 'application/octet-stream';
        }
        
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        
        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }
}
