<?php

namespace App\Http\Requests\Dining;

use App\Enums\ServiceRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RaiseServiceRequestRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::enum(ServiceRequestType::class)],
            'note' => ['nullable', 'string', 'max:200', 'required_if:type,OTHER'],
            'preferredMethod' => ['nullable', 'string', Rule::in(['CASH', 'BANK_TRANSFER_QR'])],
        ];
    }
}
