<?php

namespace App\Listeners;

use App\Events\ProductImported;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class LogProductImport implements ShouldQueue
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
     * @param \App\Events\ProductImported $event
     * @return void
     */
    public function handle(ProductImported $event): void
    {
        Log::info('Product import event processed', [
            'event' => $event->getEventName(),
            'data' => $event->getEventData(),
            'timestamp' => now()->toIso8601String(),
        ]);

        // Additional logging or processing can be added here
        // For example: send to external logging service, analytics, etc.
    }

    /**
     * Handle a job failure.
     *
     * @param \App\Events\ProductImported $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(ProductImported $event, \Throwable $exception): void
    {
        Log::error('Failed to log product import event', [
            'event' => $event->getEventName(),
            'product_id' => $event->product->id,
            'sku' => $event->product->sku,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
