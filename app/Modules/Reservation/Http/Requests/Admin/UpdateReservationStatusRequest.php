<?php

namespace App\Modules\Reservation\Http\Requests\Admin;

use App\Modules\Core\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedStatuses = array_merge(
            array_column(ReservationStatus::cases(), 'name'),
            array_column(ReservationStatus::cases(), 'value')
        );

        return [
            'targetStatus' => ['required', 'string', Rule::in($allowedStatuses)],
            'adminNote' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'targetStatus.required' => 'The targetStatus field is required.',
            'targetStatus.in' => 'The selected targetStatus is invalid.',
        ];
    }
}

