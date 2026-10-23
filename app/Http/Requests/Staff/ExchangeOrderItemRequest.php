<?php

namespace App\Http\Requests\Staff;

use App\Enums\ItemVoidReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExchangeOrderItemRequest extends FormRequest
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
            'dishId' => ['required', 'integer', 'exists:MON_AN,ma_mon_an'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'reason' => ['required', Rule::enum(ItemVoidReason::class)],
            'note' => ['required', 'string', 'min:3', 'max:255'],
            'waive' => ['nullable', 'boolean'],
        ];
    }
}
