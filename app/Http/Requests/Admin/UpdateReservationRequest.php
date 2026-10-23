<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReservationRequest extends FormRequest
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
            'reservationTime' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'tableId' => ['nullable', 'integer', 'exists:BAN_AN,ma_ban'],
            'guestCount' => ['nullable', 'integer', 'min:1', 'max:50'],
            'internalNote' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
