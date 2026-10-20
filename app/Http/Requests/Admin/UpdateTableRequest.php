<?php

namespace App\Http\Requests\Admin;

use App\Enums\TableStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
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
        $tableId = $this->route('tableId');

        return [
            'tableName' => ['sometimes', 'string', 'max:50', Rule::unique('BAN_AN', 'ten_ban')->ignore($tableId, 'ma_ban')],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'location' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(array_column(TableStatus::cases(), 'name'))],
        ];
    }
}
