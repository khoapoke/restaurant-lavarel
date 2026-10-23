<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RefundPaymentRequest extends FormRequest
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
            'refundReason' => ['required', 'string', 'min:5', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'in:BANK_TRANSFER,CASH,OTHER'],
            'referenceCode' => ['required', 'string', 'min:3', 'max:100'],
            'refundedAt' => ['nullable', 'date', 'before_or_equal:now'],
            // REFUND: hoàn phần nhà hàng đang nợ. COMPENSATION: bồi hoàn, giảm khoản phải thu của đơn.
            'refundType' => ['nullable', 'string', 'in:REFUND,COMPENSATION'],
        ];
    }
}
