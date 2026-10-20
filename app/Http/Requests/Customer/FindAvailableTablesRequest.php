<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class FindAvailableTablesRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'guestCount' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Date is required.',
            'date.date_format' => 'Date format must be Y-m-d (e.g. 2026-06-15).',
            'time.required' => 'Time is required.',
            'time.date_format' => 'Time format must be H:i (e.g. 19:00).',
            'guestCount.required' => 'Guest count is required.',
            'guestCount.min' => 'Guest count must be at least 1.',
        ];
    }
}
