<?php

namespace App\Services;

use App\Models\Upload;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Contracts\UploadServiceInterface;
use App\DataTransferObjects\UploadChunkDTO;

class UploadService implements UploadServiceInterface
{
    private const CHUNK_STORAGE_PATH = 'chunks';
    private const UPLOAD_STORAGE_PATH = 'uploads';
    private const STORAGE_DISK = 'local';

    public function initializeUpload(
        string $originalFilename,
        int $totalChunks,
        int $totalSize,
        string $checksumSha256,
        ?string $mimeType = null
    ): Upload {
        return DB::transaction(function () use (
            $originalFilename,
            $totalChunks,
            $totalSize,
            $checksumSha256,
            $mimeType
        ) {
            // Check if upload with same checksum already exists and is completed
            $existingUpload = Upload::where('checksum_sha256', $checksumSha256)
                ->where('status', 'completed')
                ->first();

            if ($existingUpload) {
                Log::info('Reusing existing completed upload', [
                    'upload_id' => $existingUpload->id,
                    'checksum' => $checksumSha256,
                ]);

                return $existingUpload;
            }

            // Generate unique stored filename
            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $storedFilename = Str::uuid() . ($extension ? ".{$extension}" : '');

            $upload = Upload::create([
                'original_filename' => $originalFilename,
                'stored_filename' => $storedFilename,
                'mime_type' => $mimeType ?? 'application/octet-stream',
                'total_size' => $totalSize,
                'total_chunks' => $totalChunks,
                'uploaded_chunks' => 0,
                'checksum_sha256' => $checksumSha256,
                'status' => 'pending',
                'upload_metadata' => [
                    'uploaded_chunks' => [],
                    'initialized_at' => now()->toIso8601String(),
                ],
            ]);

            Log::info('Upload initialized', [
                'upload_id' => $upload->id,
                'original_filename' => $originalFilename,
                'total_chunks' => $totalChunks,
                'total_size' => $totalSize,
            ]);

            return $upload;
        });
    }

    public function processChunk(UploadChunkDTO $chunkDTO): array
    {
        return DB::transaction(function () use ($chunkDTO) {
            // Get upload with lock to prevent race conditions
            $upload = Upload::where('id', $chunkDTO->uploadId)
                ->lockForUpdate()
                ->first();

            if (!$upload) {
                return [
                    'success' => false,
                    'message' => 'Upload not found',
                    'upload' => null,
                ];
            }

            // Check if upload is already completed
            if ($upload->isCompleted()) {
                return [
                    'success' => true,
                    'message' => 'Upload already completed',
                    'upload' => $upload,
                ];
            }

            // Check if upload has failed
            if ($upload->hasFailed()) {
                return [
                    'success' => false,
                    'message' => 'Upload has failed and cannot accept chunks',
                    'upload' => $upload,
                ];
            }

            // Validate chunk checksum
            if (!$chunkDTO->validateChecksum()) {
                Log::warning('Chunk checksum validation failed', [
                    'upload_id' => $upload->id,
                    'chunk_index' => $chunkDTO->chunkIndex,
                ]);

                return [
                    'success' => false,
                    'message' => 'Chunk checksum validation failed',
                    'upload' => $upload,
                ];
            }

            // Check if chunk already uploaded (idempotent)
            if ($upload->isChunkUploaded($chunkDTO->chunkIndex)) {
                Log::info('Chunk already uploaded (idempotent)', [
                    'upload_id' => $upload->id,
                    'chunk_index' => $chunkDTO->chunkIndex,
                ]);

                return [
                    'success' => true,
                    'message' => 'Chunk already uploaded',
                    'upload' => $upload,
                ];
            }

            // Store chunk
            $chunkPath = $this->getChunkPath($upload->id, $chunkDTO->chunkIndex);

            try {
                Storage::disk(self::STORAGE_DISK)->put(
                    $chunkPath,
                    base64_decode($chunkDTO->chunkData)
                );
            } catch (\Exception $e) {
                Log::error('Failed to store chunk', [
                    'upload_id' => $upload->id,
                    'chunk_index' => $chunkDTO->chunkIndex,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to store chunk: ' . $e->getMessage(),
                    'upload' => $upload,
                ];
            }

            // Mark chunk as uploaded
            $upload->markChunkUploaded($chunkDTO->chunkIndex);
            $upload->save();

            Log::info('Chunk uploaded successfully', [
                'upload_id' => $upload->id,
                'chunk_index' => $chunkDTO->chunkIndex,
                'progress' => $upload->getProgressPercentage() . '%',
            ]);

            return [
                'success' => true,
                'message' => 'Chunk uploaded successfully',
                'upload' => $upload->fresh(),
            ];
        });
    }

    public function completeUpload(string $uploadId): array
    {
        return DB::transaction(function () use ($uploadId) {
            // Get upload with lock
            $upload = Upload::where('id', $uploadId)
                ->lockForUpdate()
                ->first();

            if (!$upload) {
                return [
                    'success' => false,
                    'message' => 'Upload not found',
                    'upload' => null,
                ];
            }

            // Check if already completed (idempotent)
            if ($upload->isCompleted()) {
                return [
                    'success' => true,
                    'message' => 'Upload already completed',
                    'upload' => $upload,
                ];
            }

            // Verify all chunks are uploaded
            if ($upload->uploaded_chunks !== $upload->total_chunks) {
                return [
                    'success' => false,
                    'message' => "Missing chunks. Expected {$upload->total_chunks}, got {$upload->uploaded_chunks}",
                    'upload' => $upload,
                ];
            }

            try {
                // Assemble chunks
                $finalPath = $this->assembleChunks($upload);

                // Verify final file checksum
                $finalChecksum = hash_file('sha256', Storage::disk(self::STORAGE_DISK)->path($finalPath));

                if (!hash_equals($upload->checksum_sha256, $finalChecksum)) {
                    // Cleanup on failure
                    Storage::disk(self::STORAGE_DISK)->delete($finalPath);
                    $upload->markAsFailed('Checksum mismatch after assembly');
                    $upload->save();

                    Log::error('Final file checksum mismatch', [
                        'upload_id' => $upload->id,
                        'expected' => $upload->checksum_sha256,
                        'actual' => $finalChecksum,
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Checksum verification failed',
                        'upload' => $upload,
                    ];
                }

                // Mark as completed
                $upload->markAsCompleted();
                $upload->save();

                // Cleanup chunks
                $this->cleanupChunks($upload);

                Log::info('Upload completed successfully', [
                    'upload_id' => $upload->id,
                    'final_path' => $finalPath,
                ]);

                return [
                    'success' => true,
                    'message' => 'Upload completed successfully',
                    'upload' => $upload->fresh(),
                ];
            } catch (\Exception $e) {
                $upload->markAsFailed($e->getMessage());
                $upload->save();

                Log::error('Failed to complete upload', [
                    'upload_id' => $upload->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to complete upload: ' . $e->getMessage(),
                    'upload' => $upload,
                ];
            }
        });
    }

    public function getUploadStatus(string $uploadId): array
    {
        $upload = Upload::find($uploadId);

        if (!$upload) {
            return [
                'found' => false,
                'message' => 'Upload not found',
            ];
        }

        return [
            'found' => true,
            'upload_id' => $upload->id,
            'status' => $upload->status,
            'progress' => $upload->getProgressPercentage(),
            'uploaded_chunks' => $upload->uploaded_chunks,
            'total_chunks' => $upload->total_chunks,
            'is_completed' => $upload->isCompleted(),
            'completed_at' => $upload->completed_at?->toIso8601String(),
        ];
    }

    public function cancelUpload(string $uploadId): bool
    {
        return DB::transaction(function () use ($uploadId) {
            $upload = Upload::where('id', $uploadId)
                ->lockForUpdate()
                ->first();

            if (!$upload) {
                return false;
            }

            // Don't cancel completed uploads
            if ($upload->isCompleted()) {
                return false;
            }

            // Cleanup chunks
            $this->cleanupChunks($upload);

            // Mark as failed
            $upload->markAsFailed('Cancelled by user');
            $upload->save();

            Log::info('Upload cancelled', [
                'upload_id' => $upload->id,
            ]);

            return true;
        });
    }

    public function verifyChecksum(string $uploadId): bool
    {
        $upload = Upload::find($uploadId);

        if (!$upload || !$upload->isCompleted()) {
            return false;
        }

        $filePath = $this->getUploadPath($upload);

        if (!Storage::disk(self::STORAGE_DISK)->exists($filePath)) {
            return false;
        }

        $actualChecksum = hash_file(
            'sha256',
            Storage::disk(self::STORAGE_DISK)->path($filePath)
        );

        return hash_equals($upload->checksum_sha256, $actualChecksum);
    }

    public function resumeUpload(string $uploadId): array
    {
        $upload = Upload::find($uploadId);

        if (!$upload) {
            return [
                'can_resume' => false,
                'message' => 'Upload not found',
                'uploaded_chunks' => [],
                'missing_chunks' => [],
            ];
        }

        if ($upload->isCompleted()) {
            return [
                'can_resume' => false,
                'message' => 'Upload already completed',
                'uploaded_chunks' => range(0, $upload->total_chunks - 1),
                'missing_chunks' => [],
            ];
        }

        if ($upload->hasFailed()) {
            return [
                'can_resume' => false,
                'message' => 'Upload has failed',
                'uploaded_chunks' => [],
                'missing_chunks' => [],
            ];
        }

        $metadata = $upload->upload_metadata ?? [];
        $uploadedChunks = $metadata['uploaded_chunks'] ?? [];
        $allChunks = range(0, $upload->total_chunks - 1);
        $missingChunks = array_values(array_diff($allChunks, $uploadedChunks));

        return [
            'can_resume' => true,
            'message' => 'Upload can be resumed',
            'uploaded_chunks' => $uploadedChunks,
            'missing_chunks' => $missingChunks,
            'progress' => $upload->getProgressPercentage(),
        ];
    }

    private function assembleChunks(Upload $upload): string
    {
        $finalPath = $this->getUploadPath($upload);
        $disk = Storage::disk(self::STORAGE_DISK);

        // Create temporary file for assembly
        $tempPath = storage_path('app/temp/' . Str::uuid());
        $tempHandle = fopen($tempPath, 'wb');

        if (!$tempHandle) {
            throw new \Exception('Failed to create temporary file for assembly');
        }

        try {
            // Append chunks in order
            for ($i = 0; $i < $upload->total_chunks; $i++) {
                $chunkPath = $this->getChunkPath($upload->id, $i);

                if (!$disk->exists($chunkPath)) {
                    fclose($tempHandle);
                    unlink($tempPath);
                    throw new \Exception("Chunk {$i} not found");
                }

                $chunkContent = $disk->get($chunkPath);
                fwrite($tempHandle, $chunkContent);
            }

            fclose($tempHandle);

            // Move assembled file to final location
            $disk->put($finalPath, file_get_contents($tempPath));
            unlink($tempPath);

            return $finalPath;
        } catch (\Exception $e) {
            if (is_resource($tempHandle)) {
                fclose($tempHandle);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            throw $e;
        }
    }

    private function cleanupChunks(Upload $upload): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);

        for ($i = 0; $i < $upload->total_chunks; $i++) {
            $chunkPath = $this->getChunkPath($upload->id, $i);

            if ($disk->exists($chunkPath)) {
                $disk->delete($chunkPath);
            }
        }

        // Remove upload chunk directory if empty
        $chunkDir = self::CHUNK_STORAGE_PATH . '/' . $upload->id;
        if ($disk->exists($chunkDir)) {
            $disk->deleteDirectory($chunkDir);
        }
    }

    private function getChunkPath(string $uploadId, int $chunkIndex): string
    {
        return self::CHUNK_STORAGE_PATH . "/{$uploadId}/chunk_{$chunkIndex}";
    }

    private function getUploadPath(Upload $upload): string
    {
        return self::UPLOAD_STORAGE_PATH . '/' . $upload->stored_filename;
    }
}
