<?php

namespace App\Http\Requests\Customer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateReservationRequest extends FormRequest
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
            'tableId' => ['required', 'integer', 'exists:BAN_AN,ma_ban'],
            'reservationTime' => ['required', 'date_format:Y-m-d H:i:s'],
            'guestCount' => ['required', 'integer', 'min:1', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
