<?php

namespace App\Modules\Reservation\Http\Requests\Admin;

use App\Enums\TableStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tableId = $this->route('tableId');
        $allowedStatuses = array_merge(
            array_column(TableStatus::cases(), 'name'),
            array_column(TableStatus::cases(), 'value')
        );

        return [
            'tableName' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('BAN_AN', 'ten_ban')->ignore($tableId, 'ma_ban')],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
            'location' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in($allowedStatuses)],
        ];
    }
}

