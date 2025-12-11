<?php

namespace App\Contracts;

use App\Models\Upload;
use App\DataTransferObjects\UploadChunkDTO;

interface UploadServiceInterface
{
    public function initializeUpload(
        string $originalFilename,
        int $totalChunks,
        int $totalSize,
        string $checksumSha256,
        ?string $mimeType = null
    ): Upload;
    public function processChunk(UploadChunkDTO $chunkDTO): array;
    public function completeUpload(string $uploadId): array;
    public function getUploadStatus(string $uploadId): array;
    public function cancelUpload(string $uploadId): bool;
    public function verifyChecksum(string $uploadId): bool;
    public function resumeUpload(string $uploadId): array;
}
