<?php

namespace App\Http\Requests\Customer;

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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipientPhone.regex' => 'The phone number format is invalid.',
        ];
    }
}
