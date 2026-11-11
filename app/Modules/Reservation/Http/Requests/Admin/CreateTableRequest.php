<?php

namespace App\Modules\Reservation\Http\Requests\Admin;

use App\Enums\TableStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedStatuses = array_merge(
            array_column(TableStatus::cases(), 'name'),
            array_column(TableStatus::cases(), 'value')
        );

        return [
            'tableName' => ['required', 'string', 'max:50', 'unique:BAN_AN,ten_ban'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'location' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in($allowedStatuses)],
        ];
    }

    public function messages(): array
    {
        return [
            'tableName.required' => 'The tableName field is required.',
            'tableName.unique' => 'The table name has already been taken.',
            'capacity.required' => 'The capacity field is required.',
            'capacity.min' => 'Capacity must be at least 1.',
        ];
    }
}

