<?php

namespace App\Console\Commands;

use App\Jobs\CleanupTempFiles;
use Illuminate\Console\Command;

class CleanupTempFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:cleanup-temp {--minutes=10 : Minutes after which temp files should be deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up temporary files older than specified minutes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $minutes = (int) $this->option('minutes');
        
        $this->info("Dispatching cleanup job for temp files older than {$minutes} minutes...");
        
        CleanupTempFiles::dispatch($minutes);
        
        $this->info('Cleanup job dispatched successfully.');
        
        return 0;
    }
}