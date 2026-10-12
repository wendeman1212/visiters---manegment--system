<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class WorkingHour extends Model
{
    protected $fillable = ['day_of_week','is_working_day','open_time','close_time'];
    protected $casts    = ['is_working_day' => 'boolean'];
}
