<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitDocument extends Model
{
    protected $fillable = [
        'visit_request_id', 'doc_type', 'file_path', 'original_name',
    ];

    public function visitRequest()
    {
        return $this->belongsTo(VisitRequest::class);
    }
}
