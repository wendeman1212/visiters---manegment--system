<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $table = 'feedback';

    protected $fillable = [
        'visit_request_id',
        'rating',
        'comments',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class, 'visit_request_id');
    }
}
