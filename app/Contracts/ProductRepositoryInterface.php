<?php

namespace App\Contracts;

use App\Models\Product;
use Illuminate\Support\Collection;

interface ProductRepositoryInterface
{
    public function findBySku(string $sku): ?Product;
    public function create(array $data): Product;
    public function update(Product $product, array $data): Product;
    public function upsertBySku(string $sku, array $data): array;
    public function attachPrimaryImage(Product $product, string $imageId): bool;
    public function bulkUpsert(array $products): array;
    public function findBySkus(array $skus): Collection;
    public function deleteBySku(string $sku): bool;
    public function getAllActive(): Collection;
    public function existsBySku(string $sku): bool;
    public function updateStock(Product $product, int $quantity): Product;
    public function getLowStock(int $threshold = 10): Collection;
}
