<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AllocateTransactionRequest extends FormRequest
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
            'orderId' => ['required', 'integer', 'exists:DON_HANG,ma_don_hang'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
