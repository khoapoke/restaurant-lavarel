<?php

namespace App\Http\Requests\Dining;

use Illuminate\Foundation\Http\FormRequest;

class JoinTableRequest extends FormRequest
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
            'guestName' => ['nullable', 'string', 'max:40', 'regex:/^[^<>{}]+$/u'],
            'joinCode' => ['nullable', 'string', 'max:8'],
        ];
    }
}
