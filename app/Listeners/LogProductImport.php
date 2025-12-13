<?php

namespace App\Listeners;

use App\Events\ProductImported;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class LogProductImport implements ShouldQueue
{
    use InteractsWithQueue;

    public $tries = 3;
    public $backoff = 10;
    public function __construct()
    {
        //
    }

    public function handle(ProductImported $event): void
    {
        Log::info('Product import event processed', [
            'event' => $event->getEventName(),
            'data' => $event->getEventData(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

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
