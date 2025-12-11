<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use App\Events\ImportCompleted;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use App\Contracts\ImportServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 1800; // 30 minutes
    public $backoff = 60;
    public $deleteWhenMissingModels = true;
    public function __construct(
        private readonly string $filePath,
        private readonly string $originalFilename,
        private readonly array $options = [],
        private readonly ?int $userId = null
    ) {
    }

    public function handle(ImportServiceInterface $importService): void
    {
        Log::info('Processing product import job', [
            'file_path' => $this->filePath,
            'original_filename' => $this->originalFilename,
            'user_id' => $this->userId,
            'options' => $this->options,
        ]);

        try {
            // Check if file exists
            if (!Storage::exists($this->filePath)) {
                Log::error('Import file not found', [
                    'file_path' => $this->filePath,
                ]);
                throw new \Exception('Import file not found: ' . $this->filePath);
            }

            // Create a temporary UploadedFile instance
            $tempFile = $this->createTemporaryFile();

            // Perform import
            $result = $importService->import($tempFile, $this->options);

            // Dispatch import completed event
            ImportCompleted::dispatch($result->importLogId);

            Log::info('Product import job completed', [
                'import_log_id' => $result->importLogId,
                'total' => $result->total,
                'imported' => $result->imported,
                'updated' => $result->updated,
                'invalid' => $result->invalid,
                'duplicates' => $result->duplicates,
            ]);

            // Cleanup temporary file
            $this->cleanupTemporaryFile($tempFile->getRealPath());

            // Optionally cleanup the original file
            if ($this->options['cleanup_after_import'] ?? true) {
                Storage::delete($this->filePath);
            }

        } catch (\Exception $e) {
            Log::error('Product import job failed', [
                'file_path' => $this->filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Product import job failed after all retries', [
            'file_path' => $this->filePath,
            'original_filename' => $this->originalFilename,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Optionally notify user or send alert
        // Example: Mail::to($this->userId)->send(new ImportFailedMail($exception));
    }

    /**
     * Create a temporary file for import processing.
     *
     * @return \Illuminate\Http\UploadedFile
     */
    private function createTemporaryFile(): \Illuminate\Http\UploadedFile
    {
        $content = Storage::get($this->filePath);
        $tempPath = sys_get_temp_dir() . '/' . uniqid('import_') . '_' . $this->originalFilename;

        file_put_contents($tempPath, $content);

        return new \Illuminate\Http\UploadedFile(
            $tempPath,
            $this->originalFilename,
            'text/csv',
            null,
            true
        );
    }

    /**
     * Cleanup temporary file.
     *
     * @param string $path
     * @return void
     */
    private function cleanupTemporaryFile(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Get tags for queue monitoring.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'import',
            'product-import',
            'user:' . ($this->userId ?? 'guest'),
            'file:' . basename($this->filePath),
        ];
    }
}
