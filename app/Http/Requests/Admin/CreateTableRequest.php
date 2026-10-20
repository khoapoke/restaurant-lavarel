<?php

namespace App\Http\Requests\Admin;

use App\Enums\TableStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTableRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tableName' => ['required', 'string', 'max:50', 'unique:BAN_AN,ten_ban'],
            'capacity' => ['required', 'integer', 'min:1', 'max:30'],
            'location' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(TableStatus::cases(), 'name'))],
        ];
    }
}
