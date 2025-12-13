<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use App\Traits\HandlesProductImages;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class HandleProductImage implements ShouldQueue
{
    use Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels,
        HandlesProductImages;

    public function __construct(
        public int $productId,
        public array $row
    ) {}

    public function handle(): void
    {
        $product = Product::findOrFail($this->productId);

        if (!empty($this->row['primary_image'])) {
            $this->attachPrimaryImage($product, $this->row['primary_image']);
        }
    }
}
