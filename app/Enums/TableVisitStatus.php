<?php

namespace App\Enums;

/**
 * Lifecycle of a guest device at a table (Live Dining).
 *
 * PENDING: check-in requested, waiting for staff. ACTIVE: bound to a service session.
 */
enum TableVisitStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
}
