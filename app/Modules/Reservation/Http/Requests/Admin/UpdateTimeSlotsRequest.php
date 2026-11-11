<?php

namespace App\Modules\Reservation\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'openingTime' => ['nullable', 'string', 'date_format:H:i'],
            'closingTime' => ['nullable', 'string', 'date_format:H:i'],
            'slotDurationMinutes' => ['nullable', 'integer', 'min:15', 'max:360'],
            'advanceNoticeMinutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'specialRequestNoticeMinutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'timeSlots' => ['nullable', 'array'],
            'timeSlots.*' => ['string', 'date_format:H:i'],
        ];
    }
}

