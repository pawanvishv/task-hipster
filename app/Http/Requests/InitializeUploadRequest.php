<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitializeUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'original_filename' => 'required|string|max:255',
            'total_chunks' => [
                'required',
                'integer',
                'min:1',
                'max:10000',
                function ($attribute, $value, $fail) {
                    $totalSize = $this->input('total_size');
                    if ($totalSize && $value > 0) {
                        $minChunkSize = 5 * 1024; // 64KB
                        $maxChunkSize = 100 * 1024 * 1024; // 100MB
                        $calculatedChunkSize = $totalSize / $value;

                        if ($calculatedChunkSize < $minChunkSize) {
                            $fail('Too many chunks for file size. Minimum chunk size is 5KB.');
                        }

                        if ($calculatedChunkSize > $maxChunkSize) {
                            $fail('Too few chunks for file size. Maximum chunk size is 100MB.');
                        }
                    }
                },
            ],
            'total_size' => 'required|integer|min:1|max:5368709120', // 5GB max
            'checksum_sha256' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/',
            'mime_type' => 'nullable|string|max:100',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'original_filename.required' => 'Filename is required',
            'total_chunks.required' => 'Total chunks is required',
            'total_chunks.min' => 'At least 1 chunk is required',
            'total_chunks.max' => 'Maximum 10,000 chunks allowed',
            'total_size.required' => 'File size is required',
            'total_size.max' => 'Maximum file size is 5GB',
            'checksum_sha256.required' => 'File checksum is required',
            'checksum_sha256.size' => 'Checksum must be 64 characters (SHA256)',
            'checksum_sha256.regex' => 'Invalid checksum format',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Upload initialization validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
