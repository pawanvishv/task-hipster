<?php

namespace App\Jobs;

use App\Models\Upload;
use Illuminate\Bus\Queueable;
use App\Events\ImagesGenerated;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Contracts\ImageProcessingServiceInterface;

class GenerateImageVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600; // 10 minutes

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 30;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param string $uploadId
     * @param array $variants
     * @param array $metadata
     */
    public function __construct(
        private readonly string $uploadId,
        private readonly array $variants = [],
        private readonly array $metadata = []
    ) {
    }

    /**
     * Execute the job.
     *
     * @param \App\Contracts\ImageProcessingServiceInterface $imageProcessingService
     * @return void
     */
    public function handle(ImageProcessingServiceInterface $imageProcessingService): void
    {
        Log::info('Processing image variant generation job', [
            'upload_id' => $this->uploadId,
            'variants' => $this->variants,
        ]);

        try {
            // Load upload model
            $upload = Upload::find($this->uploadId);

            if (!$upload) {
                Log::error('Upload not found for image variant generation', [
                    'upload_id' => $this->uploadId,
                ]);
                throw new \Exception('Upload not found: ' . $this->uploadId);
            }

            // Check if upload is completed
            if (!$upload->isCompleted()) {
                Log::warning('Upload not completed, cannot generate variants', [
                    'upload_id' => $this->uploadId,
                    'status' => $upload->status,
                ]);
                throw new \Exception('Upload must be completed before generating variants');
            }

            // Generate variants
            $images = $imageProcessingService->generateVariants($upload);

            if ($images->isEmpty()) {
                Log::warning('No images generated', [
                    'upload_id' => $this->uploadId,
                ]);
                return;
            }

            // Dispatch event
            ImagesGenerated::dispatch($upload, $images, $this->metadata);

            Log::info('Image variants generated successfully', [
                'upload_id' => $this->uploadId,
                'images_count' => $images->count(),
                'variants' => $images->pluck('variant')->toArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('Image variant generation job failed', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark upload as failed if needed
            $upload = Upload::find($this->uploadId);
            if ($upload) {
                $upload->markAsFailed('Image variant generation failed: ' . $e->getMessage());
                $upload->save();
            }

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
        Log::error('Image variant generation job failed after all retries', [
            'upload_id' => $this->uploadId,
            'variants' => $this->variants,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Mark upload as failed
        $upload = Upload::find($this->uploadId);
        if ($upload) {
            $upload->markAsFailed('Image variant generation failed after retries: ' . $exception->getMessage());
            $upload->save();
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return int
     */
    public function backoff(): int
    {
        // Exponential backoff: 30s, 60s, 120s
        return $this->backoff * (2 ** ($this->attempts() - 1));
    }

    /**
     * Get tags for queue monitoring.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'image-processing',
            'image-variants',
            'upload:' . $this->uploadId,
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     *
     * @return \DateTime
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }
}
