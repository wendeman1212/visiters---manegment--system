<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class BadgeTemplate extends Model
{
    protected $fillable = ['name','type','color','show_photo','show_qr','is_default'];
    protected $casts    = ['show_photo'=>'boolean','show_qr'=>'boolean','is_default'=>'boolean'];
}
