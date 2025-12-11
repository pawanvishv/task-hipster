<?php

namespace App\Listeners;

use App\Events\ImportCompleted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyImportCompletion implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 10;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param \App\Events\ImportCompleted $event
     * @return void
     */
    public function handle(ImportCompleted $event): void
    {
        $importLog = $event->importLog;

        Log::info('Import completion notification triggered', [
            'import_log_id' => $importLog->id,
            'status' => $importLog->status,
            'success_rate' => $event->getSuccessRate(),
        ]);

        // Send notification based on import status
        if ($event->isFullySuccessful()) {
            $this->notifySuccess($event);
        } elseif ($event->isPartiallySuccessful()) {
            $this->notifyPartialSuccess($event);
        } else {
            $this->notifyFailure($event);
        }
    }

    /**
     * Notify successful import.
     *
     * @param \App\Events\ImportCompleted $event
     * @return void
     */
    private function notifySuccess(ImportCompleted $event): void
    {
        $importLog = $event->importLog;

        Log::info('Import completed successfully', [
            'import_log_id' => $importLog->id,
            'total_rows' => $importLog->total_rows,
            'imported_rows' => $importLog->imported_rows,
            'updated_rows' => $importLog->updated_rows,
            'processing_time' => $importLog->processing_time_seconds . 's',
        ]);
    }

    /**
     * Notify partial import success.
     *
     * @param \App\Events\ImportCompleted $event
     * @return void
     */
    private function notifyPartialSuccess(ImportCompleted $event): void
    {
        $importLog = $event->importLog;

        Log::warning('Import completed with errors', [
            'import_log_id' => $importLog->id,
            'total_rows' => $importLog->total_rows,
            'imported_rows' => $importLog->imported_rows,
            'updated_rows' => $importLog->updated_rows,
            'invalid_rows' => $importLog->invalid_rows,
            'duplicate_rows' => $importLog->duplicate_rows,
            'success_rate' => $event->getSuccessRate() . '%',
        ]);
    }

    /**
     * Notify import failure.
     *
     * @param \App\Events\ImportCompleted $event
     * @return void
     */
    private function notifyFailure(ImportCompleted $event): void
    {
        $importLog = $event->importLog;

        Log::error('Import failed', [
            'import_log_id' => $importLog->id,
            'total_rows' => $importLog->total_rows,
            'invalid_rows' => $importLog->invalid_rows,
            'error_details' => $importLog->error_details,
        ]);
    }

    /**
     * Handle a job failure.
     *
     * @param \App\Events\ImportCompleted $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(ImportCompleted $event, \Throwable $exception): void
    {
        Log::error('Failed to send import completion notification', [
            'import_log_id' => $event->importLog->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
