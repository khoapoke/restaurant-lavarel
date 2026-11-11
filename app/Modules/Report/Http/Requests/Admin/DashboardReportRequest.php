<?php

namespace App\Modules\Report\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DashboardReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fromDate' => ['nullable', 'date_format:Y-m-d'],
            'toDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fromDate'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'fromDate.date_format' => 'The from date does not match the format Y-m-d.',
            'toDate.date_format' => 'The to date does not match the format Y-m-d.',
            'toDate.after_or_equal' => 'The to date must be a date after or equal to from date.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => 422,
            'message' => 'Invalid date range',
            'errors' => $validator->errors(),
        ], 422));
    }
}

