<?php

namespace App\Mail;

use App\Models\CustomerNotice;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Symfony\Component\Mime\Email;

class GuestNoticeMail extends Mailable
{
    /**
     * @param  array{asOf: string, refundedAfterReceipt: float, compensationAfterReceipt: float, orderCancelled: bool}|null  $receiptUpdate
     */
    public function __construct(public readonly CustomerNotice $notice, public readonly ?array $receiptUpdate = null) {}

    /**
     * Các dòng biên nhận theo cùng một công thức cho email và API (modal frontend hiển thị đúng các dòng này):
     * tiền món + phí giao − giảm trừ/bồi hoàn (+ điều chỉnh khác) = tổng phải thu; "Đã thu" tách riêng.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array{label: string, amount: float, kind: string}>
     */
    public static function receiptRows(array $snapshot): array
    {
        $rows = array_map(fn (array $line): array => ['label' => $line['name'].' × '.(int) $line['quantity'], 'amount' => (float) $line['amount'], 'kind' => 'line'], $snapshot['lines'] ?? []);
        if (! isset($snapshot['receiptVersion'])) {
            // Biên nhận tạo trước bản sửa (không có các khoản chi tiết): giữ nguyên như đã phát hành.
            return isset($snapshot['total']) ? [...$rows, ['label' => 'Tổng thanh toán', 'amount' => (float) $snapshot['total'], 'kind' => 'total']] : $rows;
        }
        $rows[] = ['label' => 'Tiền món', 'amount' => (float) $snapshot['itemsSubtotal'], 'kind' => 'subtotal'];
        if ((float) $snapshot['deliveryFee'] > 0) {
            $rows[] = ['label' => 'Phí giao hàng', 'amount' => (float) $snapshot['deliveryFee'], 'kind' => 'subtotal'];
        }
        if ((float) $snapshot['compensation'] > 0) {
            $rows[] = ['label' => 'Giảm trừ / bồi hoàn', 'amount' => -(float) $snapshot['compensation'], 'kind' => 'deduction'];
        }
        if ((float) $snapshot['otherAdjustment'] !== 0.0) {
            $rows[] = ['label' => 'Điều chỉnh khác của đơn', 'amount' => (float) $snapshot['otherAdjustment'], 'kind' => 'deduction'];
        }
        $rows[] = ['label' => 'Tổng phải thu', 'amount' => (float) $snapshot['total'], 'kind' => 'total'];
        $rows[] = ['label' => 'Đã thu', 'amount' => (float) $snapshot['amountPaid'], 'kind' => 'paid'];

        return $rows;
    }

    /**
     * @param  array{asOf: string, refundedAfterReceipt: float, compensationAfterReceipt: float, orderCancelled: bool}|null  $update
     */
    public static function receiptUpdateText(?array $update): ?string
    {
        if (! $update) {
            return null;
        }
        $parts = [];
        if ($update['orderCancelled']) {
            $parts[] = 'Đơn đã được hủy sau thời điểm thu tiền.';
        }
        if ($update['refundedAfterReceipt'] > 0) {
            $parts[] = 'Mộc đã hoàn '.self::money($update['refundedAfterReceipt']).' cho đơn này'
                .($update['compensationAfterReceipt'] > 0 ? ', trong đó '.self::money($update['compensationAfterReceipt']).' là bồi hoàn' : '').'.';
        } elseif ($update['orderCancelled']) {
            $parts[] = 'Khoản hoàn (nếu có) được xử lý và thông báo riêng.';
        }

        return 'Cập nhật tính đến '.Carbon::parse($update['asOf'])->format('H:i d/m/Y').': '.implode(' ', $parts)
            .' Biên nhận phía trên giữ nguyên số liệu tại thời điểm thu tiền, không phản ánh tình trạng hiện tại.';
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 0, ',', '.').' đ';
    }

    public function build(): self
    {
        $notice = $this->notice;
        $url = rtrim(config('communications.frontend_url'), '/').$notice->path;
        $lines = '';
        foreach (self::receiptRows($notice->snapshot ?? []) as $row) {
            $strong = in_array($row['kind'], ['total', 'paid'], true) ? 'font-weight:bold;' : '';
            $lines .= '<tr><td style="padding:8px;'.$strong.'">'.e($row['label']).'</td><td style="padding:8px;text-align:right;'.$strong.'">'.e(self::money($row['amount'])).'</td></tr>';
        }
        $update = self::receiptUpdateText($this->receiptUpdate);
        $updateHtml = $update ? '<p style="margin-top:20px;padding:14px;background:#f8e7e7;color:#5c1424;font-size:15px;line-height:1.7">'.e($update).'</p>' : '';
        $html = '<!doctype html><html lang="vi"><meta charset="utf-8"><body style="margin:0;background:#f5f0e7;color:#211b18;font-family:Arial,sans-serif"><div style="max-width:600px;margin:30px auto;padding:32px;background:#fffdf8;border-top:5px solid #6f1d32"><p style="color:#6f1d32;letter-spacing:3px">MỘC DINING</p><h1 style="font-family:Georgia,serif;font-size:28px">'.e($notice->title).'</h1><p style="font-size:16px;line-height:1.8">'.e($notice->body).'</p><table style="width:100%;font-size:15px">'.$lines.'</table>'.$updateHtml.'<p style="margin:28px 0"><a style="background:#6f1d32;color:#fff8ed;padding:14px 22px;text-decoration:none;display:inline-block" href="'.e($url).'">Xem chi tiết tại Mộc</a></p><p style="font-size:13px;color:#625a55">Thông báo tự động từ Mộc Dining. Cần hỗ trợ? Mở khung trò chuyện trên website. Mộc không yêu cầu mật khẩu hay mã OTP qua email.</p></div></body></html>';
        $text = $notice->title."\n\n".$notice->body."\n\n".implode("\n", array_map(fn (array $row): string => $row['label'].': '.self::money($row['amount']), self::receiptRows($notice->snapshot ?? [])))
            .($update ? "\n\n".$update : '')."\n\n".$url;

        return $this->subject('[Mộc Dining] '.$notice->title.($update ? ' · có cập nhật sau thanh toán' : ''))->html($html)
            ->withSymfonyMessage(fn (Email $email) => $email->text($text));
    }
}
