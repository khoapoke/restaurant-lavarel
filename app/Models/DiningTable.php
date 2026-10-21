<?php

namespace App\Models;

use App\Enums\TableStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    /** The QR token is issued and rotated by the system only, never mass assigned. */
    protected $hidden = ['ma_qr'];

    protected $casts = [
        'suc_chua' => 'integer',
        'trang_thai' => TableStatus::class,
        'ma_qr_tao_luc' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (DiningTable $table): void {
            if (empty($table->ma_qr)) {
                $table->ma_qr = self::newQrToken();
                $table->ma_qr_tao_luc = now();
            }
        });
    }

    public static function newQrToken(): string
    {
        return Str::random(40);
    }

    /** Public Live Dining link encoded in the table QR code. */
    public function qrUrl(): ?string
    {
        return $this->ma_qr ? config('dining.frontend_url').'/dine/'.$this->ma_qr : null;
    }

    /** Issue a new public QR token; previously printed codes stop working immediately. */
    public function rotateQrToken(): string
    {
        $this->forceFill(['ma_qr' => self::newQrToken(), 'ma_qr_tao_luc' => now()])->save();

        return $this->ma_qr;
    }

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

    public function visits(): HasMany
    {
        return $this->hasMany(TableVisit::class, 'ma_ban', 'ma_ban');
    }

    public function serviceSessions(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceSession::class,
            'PHIEN_BAN',
            'ma_ban',
            'ma_phien',
            'ma_ban',
            'ma_phien'
        )->withPivot('gan_luc');
    }
}
