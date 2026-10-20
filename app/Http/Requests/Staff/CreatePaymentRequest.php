<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotencyKey' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'in:CASH,BANK_TRANSFER_QR'],
            'amount' => ['required', 'numeric', 'min:0'],
            'idempotencyKey' => ['required', 'string', 'min:12', 'max:80'],
        ];
    }
}
