<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $table = 'VAI_TRO';
    protected $primaryKey = 'ma_vai_tro';
    public $timestamps = false;

    protected $fillable = [
        'ten_vai_tro',
    ];

    // Accessors / Mutators
    protected function roleId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_vai_tro);
    }

    protected function roleName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ten_vai_tro,
            set: fn (string $value) => ['ten_vai_tro' => $value]
        );
    }

    // Relationships
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'ma_vai_tro', 'ma_vai_tro');
    }
}

