<?php

namespace App\Http\Requests\Admin;

use App\Enums\DeliveryFailureReason;
use App\Enums\FulfillmentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFulfillmentStatusRequest extends FormRequest
{
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
            'targetStatus' => ['required', Rule::enum(FulfillmentStatus::class)],
            'failureReason' => ['required_if:targetStatus,DELIVERY_FAILED', 'nullable', Rule::enum(DeliveryFailureReason::class)],
            'note' => ['required_if:targetStatus,DELIVERY_FAILED', 'nullable', 'string', 'min:3', 'max:500'],
            'pickupCode' => ['nullable', 'string', 'max:10'],
            'verifiedPhone' => ['nullable', 'string', 'max:20'],
            'collectedBy' => ['nullable', 'string', 'max:100'],
        ];
    }
}
