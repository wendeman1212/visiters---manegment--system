<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = ['company_name','branch_name','address','phone','email','website','logo_url','timezone','date_format'];
}
