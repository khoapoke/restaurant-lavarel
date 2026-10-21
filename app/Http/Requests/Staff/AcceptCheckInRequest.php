<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class AcceptCheckInRequest extends FormRequest
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
            'guestCount' => ['nullable', 'integer', 'min:1', 'max:50'],
            'reservationId' => ['nullable', 'integer', 'exists:DAT_BAN,ma_dat_ban'],
        ];
    }
}
