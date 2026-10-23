<?php

namespace App\Http\Requests\Customer;

use App\Enums\FulfillmentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutOrderRequest extends FormRequest
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
        return [
            'fulfillmentType' => [
                'required',
                Rule::in([FulfillmentType::DELIVERY->value, FulfillmentType::PICKUP->value]),
            ],
            'recipientName' => ['required', 'string', 'max:100'],
            'recipientPhone' => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9,10}$/'],
            'shippingAddress' => ['required_if:fulfillmentType,DELIVERY', 'nullable', 'string', 'max:255'],
            'scheduledAt' => ['nullable', 'date', 'after:now'],
            'expectedTotal' => ['nullable', 'numeric', 'min:0'],
            'paymentMethod' => ['required', 'string', 'in:CASH,BANK_TRANSFER_QR'],
            'orderNote' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fulfillmentType.required' => 'Fulfillment type is required.',
            'fulfillmentType.in' => 'The selected fulfillment type is invalid. It must be DELIVERY or PICKUP.',
            'recipientName.required' => 'Recipient name is required.',
            'recipientPhone.required' => 'Recipient phone is required.',
            'recipientPhone.regex' => 'The phone number format is invalid.',
            'shippingAddress.required_if' => 'Shipping address is required when fulfillment type is DELIVERY.',
            'scheduledAt.after' => 'Scheduled time must be in the future.',
            'paymentMethod.required' => 'Payment method is required.',
            'paymentMethod.in' => 'The selected payment method is invalid. It must be CASH or BANK_TRANSFER_QR.',
        ];
    }
}
