<?php

namespace App\Http\Requests\Staff;

use App\Enums\ApprovalType;
use App\Enums\ItemVoidReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateApprovalRequest extends FormRequest
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
            'type' => ['required', Rule::enum(ApprovalType::class)],
            'targetId' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'payload' => ['nullable', 'array'],
            'payload.reason' => ['nullable', Rule::enum(ItemVoidReason::class)],
            'payload.quantity' => ['nullable', 'integer', 'min:1'],
            'payload.waive' => ['nullable', 'boolean'],
            'payload.tableIds' => ['nullable', 'array', 'min:1'],
            'payload.tableIds.*' => ['integer', 'exists:BAN_AN,ma_ban'],
            'payload.guestCount' => ['nullable', 'integer', 'min:1', 'max:50'],
            'payload.relocationReason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
