<?php

namespace App\Jobs;

use App\Helpers\StorageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncFileToS3 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;

    /**
     * Create a new job instance.
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $synced = StorageHelper::syncToS3($this->filePath);
            
            if ($synced) {
                Log::info("Successfully synced file to S3: {$this->filePath}");
            } else {
                Log::warning("Failed to sync file to S3: {$this->filePath}");
            }
            
        } catch (\Exception $e) {
            Log::error("Error syncing file to S3 ({$this->filePath}): " . $e->getMessage());
            throw $e;
        }
    }
}