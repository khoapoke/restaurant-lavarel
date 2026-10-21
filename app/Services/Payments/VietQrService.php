<?php

namespace App\Services\Payments;

class VietQrService
{
    /**
     * Build the VietQR payload for a bank transfer.
     *
     * @return array{qrImageUrl: string, transferContent: string, bankBin: string, accountNo: string, accountName: string}
     */
    public function build(float $amount, string $transferContent): array
    {
        $bankBin = (string) config('services.vietqr.bank_bin');
        $accountNo = (string) config('services.vietqr.account_no');
        $accountName = (string) config('services.vietqr.account_name');
        $template = (string) config('services.vietqr.template');

        $query = http_build_query([
            'amount' => (int) round($amount),
            'addInfo' => $transferContent,
            'accountName' => $accountName,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'qrImageUrl' => "https://img.vietqr.io/image/{$bankBin}-{$accountNo}-{$template}.png?{$query}",
            'transferContent' => $transferContent,
            'bankBin' => $bankBin,
            'accountNo' => $accountNo,
            'accountName' => $accountName,
        ];
    }

    public function orderTransferContent(int $orderId): string
    {
        return "DH{$orderId} THANH TOAN";
    }

    public function invoiceTransferContent(int $invoiceId): string
    {
        return "HD{$invoiceId} THANH TOAN";
    }

    public function expiresInMinutes(): int
    {
        return max(1, (int) config('services.vietqr.expires_minutes', 15));
    }
}
