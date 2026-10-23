<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'targetStatus' => ['required', Rule::in(array_column(OrderStatus::cases(), 'name'))],
            // Bắt buộc khi quản lý cho đơn hẹn xuống bếp trước giờ chuẩn bị (kiểm tra trong service).
            'reason' => ['nullable', 'string', 'min:3', 'max:255'],
        ];
    }
}
