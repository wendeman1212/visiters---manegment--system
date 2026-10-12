<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = [
        'name',
        'holiday_date',
        'company_id',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    /** Dates (Y-m-d) non-working for a company: its own + global holidays. */
    public static function datesForCompany(?int $companyId): array
    {
        return static::query()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->pluck('holiday_date')
            ->map(fn ($date) => $date->format('Y-m-d'))
            ->all();
    }
}
