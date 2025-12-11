<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class ProductImported
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param \App\Models\Product $product
     * @param string $action 'created' or 'updated'
     * @param array $metadata
     */
    public function __construct(
        public readonly Product $product,
        public readonly string $action,
        public readonly array $metadata = []
    ) {
    }

    /**
     * Get the event name for logging.
     *
     * @return string
     */
    public function getEventName(): string
    {
        return 'product.' . $this->action;
    }

    /**
     * Get event data for logging.
     *
     * @return array
     */
    public function getEventData(): array
    {
        return [
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'name' => $this->product->name,
            'action' => $this->action,
            'metadata' => $this->metadata,
        ];
    }
}
