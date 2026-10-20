<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class AddPosOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dishId' => ['required', 'integer', 'exists:MON_AN,ma_mon_an'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'note' => ['nullable', 'string', 'max:300'],
            'clientRequestId' => ['required', 'string', 'min:12', 'max:80'],
        ];
    }
}
