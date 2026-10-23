<?php

namespace App\Services\Payments;

/**
 * Định danh một khoản chuyển khoản thật: ngân hàng nhận + số tài khoản nhận + mã giao dịch ngân hàng.
 *
 * Cùng một khoản tiền có thể đến từ nhiều nguồn ghi nhận (webhook ghi `gateway: MBBank`, quản lý nhập
 * tay hoặc POS không có nhãn ngân hàng — lưu "VIETQR"). Quy tắc:
 *  - nguồn nêu rõ ngân hàng (tên/alias/BIN): luôn dùng ngân hàng đó. Số tài khoản đơn độc không bao giờ
 *    được dùng để suy ra ngân hàng — cùng dãy số ở hai ngân hàng là hai tài khoản khác nhau. Thiếu số
 *    tài khoản mà đúng ngân hàng đã cấu hình thì coi là tài khoản nhận đã cấu hình;
 *  - nguồn không nêu ngân hàng (nhập tay, POS, nhãn chung): tài khoản trống hoặc trùng tài khoản đã cấu
 *    hình → tài khoản nhận đã cấu hình; tài khoản khác → ngân hàng UNKNOWN;
 *  - `explicit` cho biết định danh có đủ ngân hàng + tài khoản do nguồn cung cấp (không suy đoán);
 *    `conflict` mô tả thông tin mâu thuẫn cần đối soát (vẫn ghi nhận tiền).
 */
class TransferIdentity
{
    /**
     * @return array{bank: string, account: ?string, key: ?string, explicit: bool, conflict: ?string}
     */
    public static function resolve(?string $provider, ?string $account, ?string $reference): array
    {
        $configuredAccount = self::digits((string) config('services.vietqr.account_no'));
        $configuredBank = self::bankCode((string) config('services.vietqr.bank_bin')) ?? 'CONFIGURED';
        $accountDigits = self::digits((string) $account);
        $providerBank = self::bankCode($provider);
        $conflict = null;

        if ($providerBank !== null) {
            $bank = $providerBank;
            $accountKey = $accountDigits ?? ($providerBank === $configuredBank ? $configuredAccount : null);
            if ($providerBank !== $configuredBank && $accountDigits !== null && $accountDigits === $configuredAccount) {
                $conflict = sprintf(
                    'Số tài khoản nhận trùng tài khoản đã cấu hình (%s) nhưng ngân hàng báo về là %s — đã ghi là khoản riêng, kiểm tra sao kê.',
                    $configuredBank,
                    $providerBank,
                );
            }
        } elseif ($accountDigits === null || $accountDigits === $configuredAccount) {
            [$bank, $accountKey] = [$configuredBank, $configuredAccount];
        } else {
            [$bank, $accountKey] = ['UNKNOWN', $accountDigits];
        }

        $normalizedReference = $reference !== null && trim($reference) !== '' ? mb_strtoupper(trim($reference)) : null;

        return [
            'bank' => $bank,
            'account' => $accountKey,
            'key' => $normalizedReference !== null ? self::key($bank, $accountKey, $normalizedReference) : null,
            'explicit' => $providerBank !== null && $accountDigits !== null,
            'conflict' => $conflict,
        ];
    }

    public static function key(string $bank, ?string $account, string $normalizedReference): string
    {
        return 'BANK:'.$bank.'|'.($account ?? '').'|'.$normalizedReference;
    }

    /** Định danh của một dòng sổ đã lưu (nhãn nguồn + tài khoản nhận) có do nguồn nêu rõ hay không. */
    public static function isExplicit(?string $provider, ?string $account): bool
    {
        return self::bankCode($provider) !== null && self::digits((string) $account) !== null;
    }

    /** Mã ngân hàng chuẩn từ tên/alias/BIN; null với nhãn chung (VietQR, nhập tay, POS) hoặc rỗng. */
    public static function bankCode(?string $label): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $label));
        if ($normalized === '' || in_array($normalized, config('restaurant.payments.generic_providers', []), true)) {
            return null;
        }

        foreach (config('restaurant.payments.bank_aliases', []) as $code => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $code;
            }
        }

        return $normalized;
    }

    private static function digits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }
}
