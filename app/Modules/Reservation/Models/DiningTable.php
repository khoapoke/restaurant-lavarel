<?php

namespace App\Modules\Reservation\Models;

use App\Modules\Core\Enums\TableStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiningTable extends Model
{
    protected $table = 'BAN_AN';
    protected $primaryKey = 'ma_ban';
    public $timestamps = false;

    protected $fillable = [
        'ten_ban',
        'suc_chua',
        'trang_thai',
        'vi_tri',
    ];

    protected $casts = [
        'suc_chua' => 'integer',
        'trang_thai' => TableStatus::class,
    ];

    // Accessors / Mutators
    protected function tableId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_ban);
    }

    protected function tableName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ten_ban,
            set: fn (string $value) => ['ten_ban' => $value]
        );
    }

    protected function capacity(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) $this->suc_chua,
            set: fn (int $value) => ['suc_chua' => $value]
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->trang_thai,
            set: fn (TableStatus|string $value) => ['trang_thai' => $value]
        );
    }

    protected function location(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->vi_tri,
            set: fn (?string $value) => ['vi_tri' => $value]
        );
    }

    // Relationships
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'ma_ban', 'ma_ban');
    }
}

