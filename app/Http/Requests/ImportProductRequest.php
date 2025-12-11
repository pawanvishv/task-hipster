<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:csv,txt|max:102400', // 100MB
            'validate_only' => 'sometimes|boolean',
            'skip_invalid' => 'sometimes|boolean',
            'update_existing' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a CSV file to import',
            'file.mimes' => 'File must be a CSV file',
            'file.max' => 'File size must not exceed 100MB',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
