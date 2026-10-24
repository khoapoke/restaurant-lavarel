<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\Shared\GuestCommunicationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class GuestCommunicationObserver
{
    public function __construct(private readonly GuestCommunicationService $communications) {}

    private function ready(): bool
    {
        return config('communications.enabled') && Schema::hasTable('customer_notices');
    }

    public function updating(Model $model): void
    {
        if ($this->ready()) {
            $model->communication_revision = (int) $model->getOriginal('communication_revision') + 1;
        }
    }

    public function created(Reservation|Order|Payment $model): void
    {
        if ($this->ready()) {
            $this->communications->changed($model, true);
        }
    }

    public function updated(Reservation|Order|Payment $model): void
    {
        if ($this->ready()) {
            $this->communications->changed($model, false);
        }
    }
}
