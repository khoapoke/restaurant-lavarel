<?php

namespace App\Http\Resources\Admin;

use App\Models\Order;
use App\Services\Payments\PaymentLedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Một khoản tiền thực nhận trong sổ giao dịch. */
class MoneyTransactionResource extends JsonResource
{
    private function refundDueAmount(): float
    {
        $ledger = app(PaymentLedgerService::class);
        $due = $this->unallocatedAmount();
        foreach ($ledger->netAllocationsByOrder($this->resource) as $orderId => $net) {
            $order = Order::query()->find($orderId);
            $due += $order ? min($net, $ledger->refundDue($order)) : 0;
        }

        return round($due, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $allocations = $this->whenLoaded('allocations');

        return [
            'transactionId' => (int) $this->ma_giao_dich_tien,
            'source' => $this->nguon->name,
            'provider' => $this->nha_cung_cap,
            'account' => $this->tai_khoan_nhan,
            'reference' => $this->ma_tham_chieu,
            'method' => $this->phuong_thuc->name,
            'amount' => (float) $this->so_tien,
            'allocatedAmount' => (float) $this->so_tien_da_phan_bo,
            'refundedAmount' => (float) $this->so_tien_da_hoan,
            'unallocatedAmount' => $this->unallocatedAmount(),
            'refundableAmount' => $this->refundableAmount(),
            // Phần hoàn được như "nhà hàng đang nợ": chưa phân bổ + phần đã áp vào đơn hủy/đơn trả dư.
            'refundDueAmount' => $this->refundDueAmount(),
            'content' => $this->noi_dung,
            'targetOrderId' => $this->ma_don_hang_muc_tieu ? (int) $this->ma_don_hang_muc_tieu : null,
            'targetInvoiceId' => $this->ma_hoa_don_muc_tieu ? (int) $this->ma_hoa_don_muc_tieu : null,
            'targetInvoiceVersion' => $this->phien_ban_hoa_don,
            'reconciliation' => $this->trang_thai_doi_soat->name,
            'flag' => $this->canh_bao?->name,
            'flagLabel' => $this->canh_bao?->label(),
            'reviewNote' => $this->ghi_chu_doi_soat,
            'reviewedAt' => $this->doi_soat_luc?->toIso8601String(),
            'reviewedBy' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->displayName()),
            'receivedAt' => $this->nhan_luc?->toIso8601String(),
            'recordedAt' => $this->ghi_nhan_luc?->toIso8601String(),
            'recordedBy' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->displayName()),
            'isLegacy' => (bool) $this->la_du_lieu_lich_su,
            'allocations' => $this->whenLoaded('allocations', fn () => $allocations->map(fn ($allocation) => [
                'allocationId' => (int) $allocation->ma_phan_bo,
                'orderId' => (int) $allocation->ma_don_hang,
                'invoiceId' => $allocation->ma_hoa_don ? (int) $allocation->ma_hoa_don : null,
                'invoiceVersion' => $allocation->phien_ban_hoa_don,
                'amount' => (float) $allocation->so_tien,
                'type' => $allocation->loai->name,
                'createdAt' => $allocation->tao_luc?->toIso8601String(),
            ])->values()),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn ($refund) => [
                'refundId' => (int) $refund->ma_hoan_tien,
                'orderId' => $refund->ma_don_hang ? (int) $refund->ma_don_hang : null,
                'amount' => (float) $refund->so_tien,
                'type' => $refund->loai_hoan ?? 'REFUND',
                'fromAllocated' => (float) $refund->so_tien_tu_phan_bo,
                'method' => $refund->phuong_thuc,
                'reference' => $refund->ma_chung_tu,
                'reason' => $refund->ly_do,
                'refundedAt' => $refund->hoan_luc?->toIso8601String(),
                'recordedAt' => $refund->ghi_nhan_luc?->toIso8601String(),
                'refundedBy' => $refund->relationLoaded('refundedBy') ? $refund->refundedBy?->displayName() : null,
                'isLegacy' => (bool) $refund->la_du_lieu_lich_su,
            ])->values()),
        ];
    }
}
