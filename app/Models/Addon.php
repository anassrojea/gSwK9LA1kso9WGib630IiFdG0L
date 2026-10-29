<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Addon extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'price_cents', 'currency', 'active'];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'active' => 'boolean'];
    }
}
