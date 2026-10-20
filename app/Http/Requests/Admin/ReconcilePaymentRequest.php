<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReconcilePaymentRequest extends FormRequest
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
        return [
            'orderId' => ['nullable', 'integer', 'exists:DON_HANG,ma_don_hang'],
            'paymentId' => ['nullable', 'integer', 'exists:THANH_TOAN,ma_thanh_toan'],
        ];
    }
}
