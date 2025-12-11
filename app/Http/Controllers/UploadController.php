<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Contracts\UploadServiceInterface;
use App\Http\Requests\UploadChunkRequest;
use App\DataTransferObjects\UploadChunkDTO;
use App\Http\Requests\InitializeUploadRequest;
use App\Contracts\ImageProcessingServiceInterface;

class UploadController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \App\Contracts\UploadServiceInterface $uploadService
     * @param \App\Contracts\ImageProcessingServiceInterface $imageProcessingService
     */
    public function __construct(
        private readonly UploadServiceInterface $uploadService,
        private readonly ImageProcessingServiceInterface $imageProcessingService,
    ) {}

    /**
     * Initialize a new chunked upload.
     *
     * @param \App\Http\Requests\InitializeUploadRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initialize(InitializeUploadRequest $request): JsonResponse
    {
        try {
            $upload = $this->uploadService->initializeUpload(
                originalFilename: $request->input('original_filename'),
                totalChunks: $request->input('total_chunks'),
                totalSize: $request->input('total_size'),
                checksumSha256: $request->input('checksum_sha256'),
                mimeType: $request->input('mime_type')
            );

            Log::info('Upload initialized', [
                'upload_id' => $upload->id,
                'filename' => $upload->original_filename,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Upload initialized successfully',
                'data' => [
                    'upload_id' => $upload->id,
                    'status' => $upload->status,
                    'total_chunks' => $upload->total_chunks,
                    'uploaded_chunks' => $upload->uploaded_chunks,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Upload initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize upload',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload a chunk.
     *
     * @param \App\Http\Requests\UploadChunkRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadChunk(UploadChunkRequest $request)
    {
        try {
            $upload = Upload::lockForUpdate()->findOrFail($request->upload_id);

            // Check if chunk already uploaded (idempotent - prevents duplicates)
            if ($upload->isChunkUploaded($request->chunk_index)) {
                Log::info('Chunk already uploaded, skipping', [
                    'upload_id' => $upload->id,
                    'chunk_index' => $request->chunk_index,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Chunk already uploaded',
                    'data' => [
                        'upload_id' => $upload->id,
                        'chunk_index' => $request->chunk_index,
                        'uploaded_chunks' => $upload->uploaded_chunks,
                        'total_chunks' => $upload->total_chunks,
                        'progress' => round(($upload->uploaded_chunks / $upload->total_chunks) * 100, 2),
                        'status' => $upload->status,
                    ],
                ]);
            }

            // Decode base64 chunk data
            $chunkData = base64_decode($request->chunk_data, true);

            if ($chunkData === false) {
                throw new \Exception('Invalid base64 data');
            }

            // Calculate checksum of decoded data
            $calculatedChecksum = hash('sha256', $chunkData);

            // Log for debugging
            Log::debug('Chunk checksum comparison', [
                'upload_id' => $upload->id,
                'chunk_index' => $request->chunk_index,
                'received_checksum' => $request->checksum,
                'calculated_checksum' => $calculatedChecksum,
                'chunk_size' => strlen($chunkData),
            ]);

            // Validate checksum
            if ($calculatedChecksum !== $request->checksum) {
                Log::error('Chunk checksum mismatch', [
                    'upload_id' => $upload->id,
                    'chunk_index' => $request->chunk_index,
                    'expected' => $request->checksum,
                    'calculated' => $calculatedChecksum,
                    'chunk_size' => strlen($chunkData),
                ]);

                throw new \Exception('Chunk checksum validation failed');
            }

            // Store chunk to disk
            $chunkPath = "chunks/{$upload->id}/chunk_{$request->chunk_index}";
            Storage::put($chunkPath, $chunkData);

            // Verify chunk was written correctly
            $storedChecksum = hash('sha256', Storage::get($chunkPath));
            if ($storedChecksum !== $calculatedChecksum) {
                Storage::delete($chunkPath);
                throw new \Exception('Chunk storage verification failed');
            }

            // Mark chunk as uploaded
            $upload->markChunkUploaded($request->chunk_index);
            $upload->status = 'uploading';
            $upload->save();

            $progress = round(($upload->uploaded_chunks / $upload->total_chunks) * 100, 2);

            Log::info('Chunk uploaded successfully', [
                'upload_id' => $upload->id,
                'chunk_index' => $request->chunk_index,
                'progress' => $progress,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chunk uploaded successfully',
                'data' => [
                    'upload_id' => $upload->id,
                    'chunk_index' => $request->chunk_index,
                    'uploaded_chunks' => $upload->uploaded_chunks,
                    'total_chunks' => $upload->total_chunks,
                    'progress' => $progress,
                    'status' => $upload->status,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Chunk upload failed', [
                'upload_id' => $request->upload_id,
                'chunk_index' => $request->chunk_index,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete the upload.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $uploadId
     * @return \Illuminate\Http\JsonResponse
     */
    public function complete(Request $request, string $uploadId): JsonResponse
    {
        $request->validate([
            'generate_variants' => 'nullable|boolean',
        ]);

        try {
            $result = $this->uploadService->completeUpload($uploadId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

            $upload = $result['upload'];

            // Generate image variants if requested and file is an image
            $images = collect();
            if ($request->boolean('generate_variants', true)) {
                if ($this->imageProcessingService->isValidImage(
                    storage_path('app/uploads/' . $upload->stored_filename)
                )) {
                    $images = $this->imageProcessingService->generateVariants($upload);

                    Log::info('Image variants generated', [
                        'upload_id' => $upload->id,
                        'variants_count' => $images->count(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Upload completed successfully',
                'data' => [
                    'upload_id' => $upload->id,
                    'status' => $upload->status,
                    'completed_at' => $upload->completed_at?->toIso8601String(),
                    'images' => $images->map(fn($image) => [
                        'id' => $image->id,
                        'variant' => $image->variant,
                        'url' => $image->url,
                        'width' => $image->width,
                        'height' => $image->height,
                        'size' => $image->human_readable_size,
                    ]),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Upload completion failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete upload',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get upload status.
     *
     * @param string $uploadId
     * @return \Illuminate\Http\JsonResponse
     */
    public function status(string $uploadId): JsonResponse
    {
        try {
            $status = $this->uploadService->getUploadStatus($uploadId);

            if (!$status['found']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $status,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to get upload status', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get upload status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resume an interrupted upload.
     *
     * @param string $uploadId
     * @return \Illuminate\Http\JsonResponse
     */
    public function resume(string $uploadId): JsonResponse
    {
        try {
            $resumeInfo = $this->uploadService->resumeUpload($uploadId);

            if (!$resumeInfo['can_resume']) {
                return response()->json([
                    'success' => false,
                    'message' => $resumeInfo['message'],
                    'can_resume' => false,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Upload can be resumed',
                'data' => [
                    'can_resume' => true,
                    'uploaded_chunks' => $resumeInfo['uploaded_chunks'],
                    'missing_chunks' => $resumeInfo['missing_chunks'],
                    'progress' => $resumeInfo['progress'],
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to get resume info', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get resume information',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel an upload.
     *
     * @param string $uploadId
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(string $uploadId): JsonResponse
    {
        try {
            $result = $this->uploadService->cancelUpload($uploadId);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload not found or already completed',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Upload cancelled successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to cancel upload', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel upload',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify upload checksum.
     *
     * @param string $uploadId
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyChecksum(string $uploadId): JsonResponse
    {
        try {
            $valid = $this->uploadService->verifyChecksum($uploadId);

            return response()->json([
                'success' => true,
                'data' => [
                    'upload_id' => $uploadId,
                    'checksum_valid' => $valid,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Checksum verification failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify checksum',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get upload history.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 15);
            $status = $request->input('status');

            $query = Upload::query()
                ->with('images')
                ->orderBy('created_at', 'desc');

            if ($status) {
                $query->where('status', $status);
            }

            $uploads = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $uploads,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch upload history', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch upload history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
