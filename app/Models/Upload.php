<?php

namespace App\Models;

use App\Models\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Upload extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    const STATUS_PENDING = 'pending';
    const STATUS_UPLOADING = 'uploading';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UPLOADING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'original_filename',
        'stored_filename',
        'mime_type',
        'total_size',
        'total_chunks',
        'uploaded_chunks',
        'checksum_sha256',
        'status',
        'upload_metadata',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_size' => 'integer',
        'total_chunks' => 'integer',
        'uploaded_chunks' => 'integer',
        'upload_metadata' => 'array',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' &&
               $this->uploaded_chunks === $this->total_chunks;
    }

    public function isUploading(): bool
    {
        return $this->status === 'uploading';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function getProgressPercentage(): float
    {
        if ($this->total_chunks === 0) {
            return 0.0;
        }

        return round(($this->uploaded_chunks / $this->total_chunks) * 100, 2);
    }

    public function markChunkUploaded(int $chunkIndex): void
    {
        $metadata = $this->upload_metadata ?? [];
        $uploadedChunks = $metadata['uploaded_chunks'] ?? [];

        if (!in_array($chunkIndex, $uploadedChunks)) {
            $uploadedChunks[] = $chunkIndex;
            $metadata['uploaded_chunks'] = $uploadedChunks;

            $this->upload_metadata = $metadata;
            $this->uploaded_chunks = count($uploadedChunks);
            $this->status = 'uploading';
        }
    }

    public function isChunkUploaded(int $chunkIndex): bool
    {
        $metadata = $this->upload_metadata ?? [];
        $uploadedChunks = $metadata['uploaded_chunks'] ?? [];

        return in_array($chunkIndex, $uploadedChunks);
    }

    public function markAsCompleted(): void
    {
        $this->status = 'completed';
        $this->completed_at = now();
    }

    public function markAsFailed(?string $reason = null): void
    {
        $this->status = 'failed';

        if ($reason) {
            $metadata = $this->upload_metadata ?? [];
            $metadata['failure_reason'] = $reason;
            $this->upload_metadata = $metadata;
        }
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
