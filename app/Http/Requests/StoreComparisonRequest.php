<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_name' => ['required', 'string', 'max:100'],
            'report_file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
        ];
    }
}
