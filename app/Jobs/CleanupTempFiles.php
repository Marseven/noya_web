<?php

namespace App\Jobs;

use App\Helpers\StorageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupTempFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $minutes;

    /**
     * Create a new job instance.
     */
    public function __construct(int $minutes = 10)
    {
        $this->minutes = $minutes;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $deletedCount = StorageHelper::cleanupTempFiles($this->minutes);
            
            Log::info("Cleaned up {$deletedCount} temporary files older than {$this->minutes} minutes");
            
        } catch (\Exception $e) {
            Log::error('Failed to cleanup temp files: ' . $e->getMessage());
            throw $e;
        }
    }
}