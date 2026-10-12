<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SystemIntegration extends Model
{
    protected $fillable = ['name','type','status','config','last_checked_at'];
    protected $casts    = ['last_checked_at'=>'datetime'];
}
