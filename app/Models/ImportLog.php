<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ImportLog extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARTIALLY_COMPLETED = 'partially_completed';

    public const TYPE_PRODUCTS = 'products';
    public const TYPE_USERS = 'users';

    protected $fillable = [
        'import_type',
        'type',
        'filename',
        'file_hash',
        'status',
        'total_rows',
        'processed_rows',
        'imported_rows',
        'updated_rows',
        'invalid_rows',
        'duplicate_rows',
        'error_details',
        'configuration',
        'started_at',
        'completed_at',
        'processing_time_seconds',
        'user_id',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'imported_rows' => 'integer',
        'updated_rows' => 'integer',
        'invalid_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'error_details' => 'array',
        'configuration' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'processing_time_seconds' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPartiallyCompleted(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_COMPLETED;
    }

    public function markAsStarted(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->started_at = now();
        $this->save();
    }

    public function markAsCompleted(): void
    {
        $this->status = $this->invalid_rows > 0
            ? self::STATUS_PARTIALLY_COMPLETED
            : self::STATUS_COMPLETED;

        $this->completed_at = now();
        $this->calculateProcessingTime();
        $this->save();
    }

    public function markAsFailed($error = null): void
    {
        $this->status = self::STATUS_FAILED;
        $this->completed_at = now();
        $this->calculateProcessingTime();

        if ($error) {
            $errorDetails = $this->error_details ?? [];
            $errorDetails['failure_reason'] = is_array($error) ? $error : ['message' => $error];
            $this->error_details = $errorDetails;
        }

        $this->save();
    }

    public function addErrorDetail(int $row, array $errors): void
    {
        $errorDetails = $this->error_details ?? [];
        $errorDetails['rows'][$row] = $errors;
        $this->error_details = $errorDetails;
    }

    public function incrementProcessed(int $count = 1): void
    {
        $this->increment('processed_rows', $count);
    }

    public function incrementImported(int $count = 1): void
    {
        $this->increment('imported_rows', $count);
    }

    public function incrementUpdated(int $count = 1): void
    {
        $this->increment('updated_rows', $count);
    }

    public function incrementInvalid(int $count = 1): void
    {
        $this->increment('invalid_rows', $count);
    }

    public function incrementDuplicate(int $count = 1): void
    {
        $this->increment('duplicate_rows', $count);
    }

    protected function calculateProcessingTime(): void
    {
        if ($this->started_at && $this->completed_at) {
            $seconds = $this->completed_at->diffInSeconds($this->started_at);
            $this->processing_time_seconds = max(0, $seconds);
        } elseif ($this->created_at && $this->completed_at) {
            // Fallback if started_at wasn't set
            $seconds = $this->completed_at->diffInSeconds($this->created_at);
            $this->processing_time_seconds = max(0, $seconds);
        } else {
            $this->processing_time_seconds = 0;
        }
    }

    public function getSuccessRate(): float
    {
        if ($this->total_rows === 0) {
            return 0.0;
        }

        $successfulRows = $this->imported_rows + $this->updated_rows;
        return round(($successfulRows / $this->total_rows) * 100, 2);
    }

    public function getSummary(): array
    {
        return [
            'total' => $this->total_rows,
            'processed' => $this->processed_rows,
            'imported' => $this->imported_rows,
            'updated' => $this->updated_rows,
            'invalid' => $this->invalid_rows,
            'duplicates' => $this->duplicate_rows,
            'success_rate' => $this->getSuccessRate(),
            'processing_time' => $this->processing_time_seconds,
            'status' => $this->status,
        ];
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('import_type', $type);
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_PARTIALLY_COMPLETED]);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public static function getAvailableTypes(): array
    {
        return [
            self::TYPE_PRODUCTS,
            self::TYPE_USERS,
        ];
    }
}
