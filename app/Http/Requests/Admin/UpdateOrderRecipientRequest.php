<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipientName' => ['sometimes', 'required', 'string', 'max:100'],
            'recipientPhone' => ['sometimes', 'required', 'string', 'regex:/^(0|\+84)[0-9]{9,10}$/'],
            'shippingAddress' => ['sometimes', 'required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
