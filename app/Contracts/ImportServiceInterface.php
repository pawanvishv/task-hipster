<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;
use App\DataTransferObjects\ImportResultDTO;

interface ImportServiceInterface
{
    public function import(UploadedFile $file, array $options = []): ImportResultDTO;
    public function validateFile(UploadedFile $file): array;
    public function getRequiredColumns(): array;
    public function processRow(array $row, int $rowNumber): array;
    public function getImportType(): string;
}
