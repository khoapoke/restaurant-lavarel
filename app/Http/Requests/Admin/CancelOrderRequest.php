<?php

namespace App\Http\Requests\Admin;

use App\Enums\CancellationMoneyDecision;
use App\Services\Admin\OrderService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelOrderRequest extends FormRequest
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
            'cancelReason' => ['required', 'string', 'min:5', 'max:500'],
            'reasonType' => ['nullable', Rule::in(OrderService::CANCEL_REASON_TYPES)],
            'moneyDecision' => ['nullable', Rule::enum(CancellationMoneyDecision::class)],
        ];
    }
}
