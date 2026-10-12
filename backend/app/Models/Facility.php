<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

class Facility extends Model
{
    protected $fillable = [
        'building',
        'floor',
        'room',
        'company_id',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyScope());
    }
}
