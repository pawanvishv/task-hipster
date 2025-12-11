<?php

namespace App\DataTransferObjects;

class UploadChunkDTO
{
    public function __construct(
        public readonly string $uploadId,
        public readonly int $chunkIndex,
        public readonly int $totalChunks,
        public readonly string $chunkData,
        public readonly string $checksum,
        public readonly string $originalFilename,
        public readonly ?int $chunkSize = null,
        public readonly ?int $totalSize = null,
    ) {
    }

    public static function fromRequest(array $data): self
    {
        return new self(
            uploadId: $data['upload_id'],
            chunkIndex: (int) $data['chunk_index'],
            totalChunks: (int) $data['total_chunks'],
            chunkData: $data['chunk_data'],
            checksum: $data['checksum'],
            originalFilename: $data['original_filename'],
            chunkSize: isset($data['chunk_size']) ? (int) $data['chunk_size'] : null,
            totalSize: isset($data['total_size']) ? (int) $data['total_size'] : null,
        );
    }

    public function isFirstChunk(): bool
    {
        return $this->chunkIndex === 0;
    }

    public function isLastChunk(): bool
    {
        return $this->chunkIndex === ($this->totalChunks - 1);
    }

    public function getChunkDataSize(): int
    {
        return strlen($this->chunkData);
    }

    public function getProgressPercentage(): float
    {
        if ($this->totalChunks === 0) {
            return 0.0;
        }

        return round((($this->chunkIndex + 1) / $this->totalChunks) * 100, 2);
    }

    public function validateChecksum(): bool
    {
        $calculatedChecksum = hash('sha256', $this->chunkData);
        return hash_equals($this->checksum, $calculatedChecksum);
    }

    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'chunk_size' => $this->chunkSize ?? $this->getChunkDataSize(),
            'total_size' => $this->totalSize,
            'checksum' => $this->checksum,
            'original_filename' => $this->originalFilename,
            'is_first_chunk' => $this->isFirstChunk(),
            'is_last_chunk' => $this->isLastChunk(),
            'progress_percentage' => $this->getProgressPercentage(),
        ];
    }
}
