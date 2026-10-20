<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePosOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:99'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }
}
