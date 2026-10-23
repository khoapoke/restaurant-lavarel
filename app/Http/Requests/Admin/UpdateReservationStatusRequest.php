<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReservationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationStatusRequest extends FormRequest
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
            'targetStatus' => ['required', Rule::in(array_column(ReservationStatus::cases(), 'name'))],
            // Lý do hiển thị cho khách (bắt buộc khi từ chối/hủy — kiểm tra trong service).
            'reason' => ['nullable', 'string', 'max:500'],
            // Ghi chú nội bộ: không bao giờ trả cho khách.
            'internalNote' => ['nullable', 'string', 'max:500'],
            // Tương thích ngược: trước đây adminNote vừa là lý do vừa là ghi chú.
            'adminNote' => ['nullable', 'string', 'max:500'],
        ];
    }
}
