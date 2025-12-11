<?php

namespace App\DataTransferObjects;

class ImportResultDTO
{
    public function __construct(
        public readonly int $total,
        public readonly int $imported,
        public readonly int $updated,
        public readonly int $invalid,
        public readonly int $duplicates,
        public readonly array $errors = [],
        public readonly ?string $importLogId = null,
    ) {
    }

    public static function fromImportLog($importLog): self
    {
        return new self(
            total: $importLog->total_rows ?? 0,
            imported: $importLog->imported_rows ?? 0,
            updated: $importLog->updated_rows ?? 0,
            invalid: $importLog->invalid_rows ?? 0,
            duplicates: $importLog->duplicate_rows ?? 0,
            errors: $importLog->error_details ?? [],
            importLogId: $importLog->id,
        );
    }

    public function getProcessedCount(): int
    {
        return $this->imported + $this->updated;
    }

    public function getSuccessRate(): float
    {
        if ($this->total === 0) {
            return 0.0;
        }

        return round(($this->getProcessedCount() / $this->total) * 100, 2);
    }

    public function isFullySuccessful(): bool
    {
        return $this->invalid === 0 && $this->duplicates === 0;
    }

    public function hasFailures(): bool
    {
        return $this->invalid > 0;
    }

    public function hasDuplicates(): bool
    {
        return $this->duplicates > 0;
    }

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'imported' => $this->imported,
            'updated' => $this->updated,
            'invalid' => $this->invalid,
            'duplicates' => $this->duplicates,
            'processed' => $this->getProcessedCount(),
            'success_rate' => $this->getSuccessRate(),
            'errors' => $this->errors,
            'import_log_id' => $this->importLogId,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    public function getSummaryMessage(): string
    {
        $message = "Import completed: {$this->total} total rows. ";
        $message .= "{$this->imported} imported, {$this->updated} updated";

        if ($this->invalid > 0) {
            $message .= ", {$this->invalid} invalid";
        }

        if ($this->duplicates > 0) {
            $message .= ", {$this->duplicates} duplicates";
        }

        $message .= ". Success rate: {$this->getSuccessRate()}%";

        return $message;
    }
}
