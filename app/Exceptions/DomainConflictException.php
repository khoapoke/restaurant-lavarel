<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lỗi nghiệp vụ kèm dữ liệu để giao diện xử lý (ví dụ PRICE_CHANGED kèm tổng mới).
 * Trả về trong trường `errors` của phong bì API.
 */
class DomainConflictException extends HttpException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, public readonly array $details = [], int $statusCode = 409)
    {
        parent::__construct($statusCode, $message);
    }
}
