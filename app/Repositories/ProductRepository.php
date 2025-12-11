<?php

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Contracts\ProductRepositoryInterface;

class ProductRepository implements ProductRepositoryInterface
{
    public function findBySku(string $sku): ?Product
    {
        return Product::where('sku', $sku)->first();
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        return $product->fresh();
    }

    public function upsertBySku(string $sku, array $data): array
    {
        $product = $this->findBySku($sku);

        if ($product) {
            // Update existing product
            $product->update($data);

            Log::info("Product updated", [
                'sku' => $sku,
                'id' => $product->id,
            ]);

            return [
                'product' => $product->fresh(),
                'action' => 'updated',
            ];
        }

        // Create new product
        $data['sku'] = $sku;
        $product = $this->create($data);

        Log::info("Product created", [
            'sku' => $sku,
            'id' => $product->id,
        ]);

        return [
            'product' => $product,
            'action' => 'created',
        ];
    }

    public function attachPrimaryImage(Product $product, string $imageId): bool
    {
        return $product->attachPrimaryImage($imageId);
    }

    public function bulkUpsert(array $products): array
    {
        $created = 0;
        $updated = 0;
        $resultProducts = collect();

        DB::transaction(function () use ($products, &$created, &$updated, &$resultProducts) {
            foreach ($products as $productData) {
                $result = $this->upsertBySku($productData['sku'], $productData['data']);

                $resultProducts->push($result['product']);

                if ($result['action'] === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'products' => $resultProducts,
        ];
    }

    public function findBySkus(array $skus): Collection
    {
        return Product::whereIn('sku', $skus)->get();
    }

    public function deleteBySku(string $sku): bool
    {
        $product = $this->findBySku($sku);

        if (!$product) {
            return false;
        }

        return (bool) $product->delete();
    }

    public function getAllActive(): Collection
    {
        return Product::active()->get();
    }

    public function existsBySku(string $sku): bool
    {
        return Product::where('sku', $sku)->exists();
    }

    public function updateStock(Product $product, int $quantity): Product
    {
        $product->stock_quantity = $quantity;
        $product->save();

        return $product->fresh();
    }

    public function getLowStock(int $threshold = 10): Collection
    {
        return Product::where('stock_quantity', '<=', $threshold)
            ->where('stock_quantity', '>', 0)
            ->active()
            ->get();
    }
}
