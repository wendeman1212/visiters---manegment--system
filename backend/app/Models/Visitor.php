<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use App\Models\Traits\CompanyScoped;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Visitor extends Authenticatable
{
    use Notifiable, CompanyScoped;

    protected $fillable = [
        'name', 'first_name', 'last_name', 'username', 'email', 'phone', 'id_document_no', 'photo_url',
        'nationality', 'age', 'sex',
        'company_name', 'company_position', 'id_document_url', 'profile_complete', 'password',
        'company_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'profile_complete' => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new CompanyScope());
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function visitRequests()
    {
        return $this->hasMany(VisitRequest::class, 'visitor_id');
    }
}
