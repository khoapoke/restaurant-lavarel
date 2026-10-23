<?php

namespace App\Http\Requests\Staff;

use App\Enums\ItemVoidReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectKitchenItemRequest extends FormRequest
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
            'reason' => ['required', Rule::in([ItemVoidReason::OUT_OF_STOCK->value, ItemVoidReason::RESTAURANT_MISTAKE->value, ItemVoidReason::DAMAGED->value])],
            'note' => ['required', 'string', 'min:3', 'max:255'],
            'markSoldOut' => ['nullable', 'boolean'],
        ];
    }
}
