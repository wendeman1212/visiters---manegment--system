<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class VisitorCategory extends Model
{
    protected $fillable = ['name','color','requires_approval','auto_approve','description'];
    protected $casts    = ['requires_approval'=>'boolean','auto_approve'=>'boolean'];

    public function visitRequests()
    {
        return $this->hasMany(VisitRequest::class, 'visitor_category_id');
    }
}
