<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organizational_unit_id' => ['required', 'integer', 'exists:organizational_units,id'],
            'label' => ['nullable', 'string', 'max:100'],
            'log_file' => ['required', 'file', 'mimes:txt,log', 'max:512000'],
        ];
    }

    public function messages(): array
    {
        return [
            'organizational_unit_id.required' => 'Pilih unit tujuan analisis.',
            'organizational_unit_id.exists' => 'Unit yang dipilih tidak tersedia.',
            'log_file.required' => 'Pilih file log yang akan dianalisis.',
            'log_file.mimes' => 'File harus berformat .txt atau .log.',
            'log_file.max' => 'Ukuran file tidak boleh melebihi 500 MB.',
        ];
    }
}
