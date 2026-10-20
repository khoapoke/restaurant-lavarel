<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class SendKitchenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'itemIds' => ['required', 'array', 'min:1'],
            'itemIds.*' => ['required', 'integer', 'distinct', 'exists:CHI_TIET_DON_HANG,ma_chi_tiet'],
        ];
    }
}
