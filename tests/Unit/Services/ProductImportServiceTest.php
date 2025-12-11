<?php

namespace Tests\Unit\Services;

use Mockery;
use Tests\TestCase;
use App\Models\Product;
use App\Models\ImportLog;
use Illuminate\Http\UploadedFile;
use App\Services\ProductImportService;
use Illuminate\Support\Facades\Storage;
use App\Contracts\ProductRepositoryInterface;
use App\Contracts\ImageProcessingServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductImportService $service;
    private ProductRepositoryInterface $productRepository;
    private ImageProcessingServiceInterface $imageProcessingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->imageProcessingService = Mockery::mock(ImageProcessingServiceInterface::class);

        // Create service instance
        $this->service = new ProductImportService(
            $this->productRepository,
            $this->imageProcessingService
        );

        // Fake storage
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_get_required_columns()
    {
        $columns = $this->service->getRequiredColumns();

        $this->assertIsArray($columns);
        $this->assertContains('sku', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('price', $columns);
        $this->assertContains('stock_quantity', $columns);
    }

    /** @test */
    public function it_can_get_import_type()
    {
        $type = $this->service->getImportType();

        $this->assertEquals(ImportLog::TYPE_PRODUCTS, $type);
    }

    /** @test */
    public function it_validates_csv_file_with_missing_columns()
    {
        $csvContent = "name,price\nProduct 1,10.00\n";
        $file = $this->createCsvFile($csvContent);

        $validation = $this->service->validateFile($file);

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
        $this->assertStringContainsString('sku', implode(' ', $validation['errors']));
    }

    /** @test */
    public function it_validates_csv_file_with_all_required_columns()
    {
        $csvContent = "sku,name,price,stock_quantity\nSKU001,Product 1,10.00,100\n";
        $file = $this->createCsvFile($csvContent);

        $validation = $this->service->validateFile($file);

        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);
    }

    /** @test */
    public function it_processes_valid_row_and_creates_product()
    {
        $row = [
            'sku' => 'SKU001',
            'name' => 'Test Product',
            'price' => '19.99',
            'stock_quantity' => '50',
            'description' => 'Test description',
            'status' => 'active',
        ];

        $product = new Product([
            'id' => 1,
            'sku' => 'SKU001',
            'name' => 'Test Product',
            'price' => 19.99,
            'stock_quantity' => 50,
        ]);

        $this->productRepository
            ->shouldReceive('upsertBySku')
            ->once()
            ->with('SKU001', Mockery::type('array'))
            ->andReturn([
                'product' => $product,
                'action' => 'created',
            ]);

        $result = $this->service->processRow($row, 2);

        $this->assertTrue($result['success']);
        $this->assertEquals('created', $result['action']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function it_processes_valid_row_and_updates_product()
    {
        $row = [
            'sku' => 'SKU001',
            'name' => 'Updated Product',
            'price' => '29.99',
            'stock_quantity' => '75',
        ];

        $product = new Product([
            'id' => 1,
            'sku' => 'SKU001',
            'name' => 'Updated Product',
            'price' => 29.99,
            'stock_quantity' => 75,
        ]);

        $this->productRepository
            ->shouldReceive('upsertBySku')
            ->once()
            ->with('SKU001', Mockery::type('array'))
            ->andReturn([
                'product' => $product,
                'action' => 'updated',
            ]);

        $result = $this->service->processRow($row, 2);

        $this->assertTrue($result['success']);
        $this->assertEquals('updated', $result['action']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function it_rejects_row_with_invalid_data()
    {
        $row = [
            'sku' => 'SKU001',
            'name' => 'Test Product',
            'price' => 'invalid_price', // Invalid price
            'stock_quantity' => '50',
        ];

        $result = $this->service->processRow($row, 2);

        $this->assertFalse($result['success']);
        $this->assertEquals('invalid', $result['action']);
        $this->assertNotEmpty($result['errors']);
    }

    /** @test */
    public function it_rejects_row_with_missing_required_fields()
    {
        $row = [
            'sku' => 'SKU001',
            'name' => 'Test Product',
            // Missing price and stock_quantity
        ];

        $result = $this->service->processRow($row, 2);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    /** @test */
    public function it_imports_csv_file_successfully()
    {
        $csvContent = <<<CSV
sku,name,price,stock_quantity,description,status
SKU001,Product 1,10.00,100,Description 1,active
SKU002,Product 2,20.00,200,Description 2,active
CSV;

        $file = $this->createCsvFile($csvContent);

        $product1 = new Product(['id' => 1, 'sku' => 'SKU001', 'name' => 'Product 1']);
        $product2 = new Product(['id' => 2, 'sku' => 'SKU002', 'name' => 'Product 2']);

        $this->productRepository
            ->shouldReceive('upsertBySku')
            ->twice()
            ->andReturn(
                ['product' => $product1, 'action' => 'created'],
                ['product' => $product2, 'action' => 'created']
            );

        $result = $this->service->import($file);

        $this->assertEquals(2, $result->total);
        $this->assertEquals(2, $result->imported);
        $this->assertEquals(0, $result->updated);
        $this->assertEquals(0, $result->invalid);
        $this->assertEquals(0, $result->duplicates);
    }

    /** @test */
    public function it_handles_duplicate_skus_in_csv()
    {
        $csvContent = <<<CSV
sku,name,price,stock_quantity
SKU001,Product 1,10.00,100
SKU001,Product 1 Duplicate,15.00,150
CSV;

        $file = $this->createCsvFile($csvContent);

        $product1 = new Product(['id' => 1, 'sku' => 'SKU001', 'name' => 'Product 1']);

        $this->productRepository
            ->shouldReceive('upsertBySku')
            ->once()
            ->andReturn(['product' => $product1, 'action' => 'created']);

        $result = $this->service->import($file);

        $this->assertEquals(2, $result->total);
        $this->assertEquals(1, $result->imported);
        $this->assertEquals(1, $result->duplicates);
    }

    /** @test */
    public function it_handles_invalid_rows_without_stopping_import()
    {
        $csvContent = <<<CSV
sku,name,price,stock_quantity
SKU001,Product 1,10.00,100
SKU002,Product 2,invalid,200
SKU003,Product 3,30.00,300
CSV;

        $file = $this->createCsvFile($csvContent);

        $product1 = new Product(['id' => 1, 'sku' => 'SKU001', 'name' => 'Product 1']);
        $product3 = new Product(['id' => 3, 'sku' => 'SKU003', 'name' => 'Product 3']);

        $this->productRepository
            ->shouldReceive('upsertBySku')
            ->twice()
            ->andReturn(
                ['product' => $product1, 'action' => 'created'],
                ['product' => $product3, 'action' => 'created']
            );

        $result = $this->service->import($file);

        $this->assertEquals(3, $result->total);
        $this->assertEquals(2, $result->imported);
        $this->assertEquals(1, $result->invalid);
    }

    /** @test */
    public function it_creates_import_log()
    {
        $csvContent = "sku,name,price,stock_quantity\nSKU001,Product 1,10.00,100\n";
        $file = $this->createCsvFile($csvContent);

        $product = new Product(['id' => 1, 'sku' => 'SKU001', 'name' => 'Product 1']);

        $this->productRepository
            ->shouldReceive('upsertBySku')
            ->once()
            ->andReturn(['product' => $product, 'action' => 'created']);

        $result = $this->service->import($file);

        $this->assertNotNull($result->importLogId);
        $this->assertDatabaseHas('import_logs', [
            'id' => $result->importLogId,
            'import_type' => ImportLog::TYPE_PRODUCTS,
            'status' => ImportLog::STATUS_COMPLETED,
        ]);
    }

    /**
     * Helper method to create a CSV file.
     *
     * @param string $content
     * @return \Illuminate\Http\UploadedFile
     */
    private function createCsvFile(string $content): UploadedFile
    {
        $filename = 'test_import_' . time() . '.csv';
        Storage::disk('local')->put($filename, $content);

        return new UploadedFile(
            Storage::disk('local')->path($filename),
            $filename,
            'text/csv',
            null,
            true
        );
    }
}
