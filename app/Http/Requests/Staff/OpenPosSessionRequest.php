<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class OpenPosSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotencyKey' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'tableIds' => ['required', 'array', 'min:1'],
            'tableIds.*' => ['required', 'integer', 'distinct', 'exists:BAN_AN,ma_ban'],
            'guestCount' => ['required', 'integer', 'min:1', 'max:50'],
            'reservationId' => ['nullable', 'integer', 'exists:DAT_BAN,ma_dat_ban'],
            'idempotencyKey' => ['required', 'string', 'min:12', 'max:80'],
        ];
    }
}
