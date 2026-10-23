<?php

namespace App\Http\Requests\Staff;

use App\Enums\ItemVoidReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RemakeOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in([ItemVoidReason::RESTAURANT_MISTAKE->value, ItemVoidReason::DAMAGED->value, ItemVoidReason::NOT_SERVED->value, ItemVoidReason::ORDER_ENTRY_ERROR->value])],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
