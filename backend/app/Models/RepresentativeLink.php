<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepresentativeLink extends Model
{
    protected $fillable = [
        'user_id',
        'visit_request_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class);
    }
}
