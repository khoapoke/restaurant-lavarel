<?php

namespace App\Modules\Reservation\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReservationRequest extends FormRequest
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
            'tableId' => ['sometimes', 'required', 'integer', 'exists:BAN_AN,ma_ban'],
            'reservationTime' => ['sometimes', 'required', 'date', 'after:now'],
            'guestCount' => ['sometimes', 'required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tableId.exists' => 'The selected table does not exist in BAN_AN.',
            'reservationTime.after' => 'Reservation time must be in the future.',
            'guestCount.min' => 'Guest count must be at least 1.',
        ];
    }
}

