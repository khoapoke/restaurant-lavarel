<?php

namespace App\Modules\Order\Http\Requests\Admin;

use App\Modules\Core\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $allowed = array_merge(
            array_column(OrderStatus::cases(), 'name'),
            array_column(OrderStatus::cases(), 'value')
        );

        return [
            'targetStatus' => ['required', 'string', Rule::in($allowed)],
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
            'targetStatus.required' => 'The targetStatus field is required.',
            'targetStatus.in' => 'The selected targetStatus is invalid.',
        ];
    }
}

