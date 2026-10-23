<?php

namespace App\Http\Requests\Staff;

use App\Enums\ItemVoidReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoidOrderItemRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(ItemVoidReason::class)],
            'note' => ['required', 'string', 'min:3', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'waive' => ['nullable', 'boolean'],
        ];
    }
}
