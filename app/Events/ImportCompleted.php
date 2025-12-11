<?php

namespace App\Events;

use App\Models\ImportLog;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class ImportCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param \App\Models\ImportLog $importLog
     * @param array $metadata
     */
    public function __construct(
        public readonly ImportLog $importLog,
        public readonly array $metadata = []
    ) {
    }

    /**
     * Check if import was fully successful.
     *
     * @return bool
     */
    public function isFullySuccessful(): bool
    {
        return $this->importLog->invalid_rows === 0 &&
               $this->importLog->duplicate_rows === 0;
    }

    /**
     * Check if import was partially successful.
     *
     * @return bool
     */
    public function isPartiallySuccessful(): bool
    {
        return $this->importLog->isPartiallyCompleted();
    }

    /**
     * Get success rate.
     *
     * @return float
     */
    public function getSuccessRate(): float
    {
        return $this->importLog->getSuccessRate();
    }

    /**
     * Get event data for logging.
     *
     * @return array
     */
    public function getEventData(): array
    {
        return [
            'import_log_id' => $this->importLog->id,
            'import_type' => $this->importLog->import_type,
            'filename' => $this->importLog->filename,
            'status' => $this->importLog->status,
            'total_rows' => $this->importLog->total_rows,
            'imported_rows' => $this->importLog->imported_rows,
            'updated_rows' => $this->importLog->updated_rows,
            'invalid_rows' => $this->importLog->invalid_rows,
            'duplicate_rows' => $this->importLog->duplicate_rows,
            'success_rate' => $this->getSuccessRate(),
            'processing_time' => $this->importLog->processing_time_seconds,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get summary message.
     *
     * @return string
     */
    public function getSummaryMessage(): string
    {
        $message = "Import completed: {$this->importLog->total_rows} total rows. ";
        $message .= "{$this->importLog->imported_rows} imported, ";
        $message .= "{$this->importLog->updated_rows} updated";

        if ($this->importLog->invalid_rows > 0) {
            $message .= ", {$this->importLog->invalid_rows} invalid";
        }

        if ($this->importLog->duplicate_rows > 0) {
            $message .= ", {$this->importLog->duplicate_rows} duplicates";
        }

        return $message;
    }
}
