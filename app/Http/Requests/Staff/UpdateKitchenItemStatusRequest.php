<?php

namespace App\Http\Requests\Staff;

use App\Enums\KitchenItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKitchenItemStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(KitchenItemStatus::class)],
            'reason' => ['nullable', 'string', 'max:300'],
        ];
    }
}
