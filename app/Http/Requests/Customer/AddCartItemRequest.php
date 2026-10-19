<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
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
            'dishId' => ['required', 'integer', 'exists:MON_AN,ma_mon_an'],
            'quantity' => ['required', 'integer', 'min:1'],
            'selectedOptions' => ['nullable', 'string', 'max:255'],
            'itemNote' => ['nullable', 'string', 'max:500'],
        ];
    }
}
