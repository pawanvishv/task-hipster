<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadChunkRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'upload_id' => 'required|uuid|exists:uploads,id',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
            'chunk_data' => 'required|string', // Base64 encoded
            'checksum' => 'required|string|size:64', // SHA256 hash
            'original_filename' => 'required|string|max:255',
            'chunk_size' => 'nullable|integer|min:1',
            'total_size' => 'nullable|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'upload_id.required' => 'Upload ID is required.',
            'upload_id.uuid' => 'Upload ID must be a valid UUID.',
            'upload_id.exists' => 'Upload not found.',
            'chunk_index.required' => 'Chunk index is required.',
            'chunk_index.integer' => 'Chunk index must be an integer.',
            'chunk_index.min' => 'Chunk index must be 0 or greater.',
            'total_chunks.required' => 'Total chunks count is required.',
            'total_chunks.integer' => 'Total chunks must be an integer.',
            'total_chunks.min' => 'Total chunks must be at least 1.',
            'chunk_data.required' => 'Chunk data is required.',
            'chunk_data.string' => 'Chunk data must be a string.',
            'checksum.required' => 'Checksum is required for verification.',
            'checksum.string' => 'Checksum must be a string.',
            'checksum.size' => 'Checksum must be a valid SHA256 hash (64 characters).',
            'original_filename.required' => 'Original filename is required.',
            'original_filename.max' => 'Filename must not exceed 255 characters.',
        ];
    }

    // method
    public function attributes(): array
    {
        return [
            'upload_id' => 'upload identifier',
            'chunk_index' => 'chunk index',
            'total_chunks' => 'total chunks',
            'chunk_data' => 'chunk data',
            'checksum' => 'checksum',
            'original_filename' => 'filename',
            'chunk_size' => 'chunk size',
            'total_size' => 'total file size',
        ];
    }

    // method
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validate chunk_index is less than total_chunks
            if ($this->has('chunk_index') && $this->has('total_chunks')) {
                if ($this->chunk_index >= $this->total_chunks) {
                    $validator->errors()->add(
                        'chunk_index',
                        'Chunk index must be less than total chunks.'
                    );
                }
            }

            // Validate checksum format (SHA256 hex)
            if ($this->has('checksum')) {
                if (!preg_match('/^[a-f0-9]{64}$/i', $this->checksum)) {
                    $validator->errors()->add(
                        'checksum',
                        'Checksum must be a valid SHA256 hash (64 hexadecimal characters).'
                    );
                }
            }

            // Validate chunk_data is valid base64
            if ($this->has('chunk_data')) {
                $decoded = base64_decode($this->chunk_data, true);
                if ($decoded === false) {
                    $validator->errors()->add(
                        'chunk_data',
                        'Chunk data must be valid base64 encoded.'
                    );
                }
            }
        });
    }

    // method
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        if ($this->expectsJson()) {
            throw new \Illuminate\Validation\ValidationException(
                $validator,
                response()->json([
                    'success' => false,
                    'message' => 'Chunk validation failed',
                    'errors' => $validator->errors(),
                ], 422)
            );
        }

        parent::failedValidation($validator);
    }
}
