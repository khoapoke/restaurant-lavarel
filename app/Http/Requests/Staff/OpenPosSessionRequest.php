<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class OpenPosSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotencyKey' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'tableIds' => ['required', 'array', 'min:1'],
            'tableIds.*' => ['required', 'integer', 'distinct', 'exists:BAN_AN,ma_ban'],
            'guestCount' => ['required', 'integer', 'min:1', 'max:50'],
            'reservationId' => ['nullable', 'integer', 'exists:DAT_BAN,ma_dat_ban'],
            'idempotencyKey' => ['required', 'string', 'min:12', 'max:80'],
            // Nhận khách ngoài cửa sổ giờ (chỉ ADMIN) và nhận ở bàn khác bàn đã đặt.
            'overrideReason' => ['nullable', 'string', 'min:3', 'max:255'],
            'relocationReason' => ['nullable', 'string', 'min:3', 'max:255'],
            // Nhường bàn: kết thúc lượt đã quá hạn giữ chỗ trong cùng giao dịch mở phiên.
            'releaseReservationId' => ['nullable', 'integer', 'exists:DAT_BAN,ma_dat_ban'],
        ];
    }
}
