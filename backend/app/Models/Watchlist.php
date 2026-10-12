<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Watchlist extends Model
{
    protected $table    = 'watchlist';
    protected $fillable = ['name','id_document_no','reason','level','added_by'];
    public function addedBy() { return $this->belongsTo(User::class,'added_by'); }
}
