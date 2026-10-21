<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
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
            'guestName' => ['required', 'string', 'min:1', 'max:40', 'regex:/^[^<>{}]+$/u'],
            'guestCount' => ['required', 'integer', 'min:1', 'max:30'],
        ];
    }
}
