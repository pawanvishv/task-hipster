<?php

namespace App\Providers;

use App\Services\UploadService;
use App\Services\ProductImportService;
use App\Repositories\ProductRepository;
use Illuminate\Support\ServiceProvider;
use App\Services\ImageProcessingService;
use App\Contracts\ImportServiceInterface;
use App\Contracts\UploadServiceInterface;
use App\Contracts\ProductRepositoryInterface;
use App\Contracts\ImageProcessingServiceInterface;

class AppServiceProvider extends ServiceProvider
{
    public $bindings = [
        ImportServiceInterface::class => ProductImportService::class,
        UploadServiceInterface::class => UploadService::class,
        ImageProcessingServiceInterface::class => ImageProcessingService::class,
        ProductRepositoryInterface::class => ProductRepository::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
