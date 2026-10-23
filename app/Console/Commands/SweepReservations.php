<?php

namespace App\Console\Commands;

use App\Services\Staff\ReservationDeskService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dining:reservations:sweep')]
#[Description('Expire overdue pending reservations, mark unseated confirmed ones as no-show and refresh table holds')]
class SweepReservations extends Command
{
    public function handle(ReservationDeskService $desk): int
    {
        $result = $desk->sweep();
        $this->info("Expired {$result['expired']} pending, marked {$result['noShow']} no-show.");

        return self::SUCCESS;
    }
}
