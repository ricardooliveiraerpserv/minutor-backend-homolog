<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PolicyOverride extends Model
{
    protected $table = 'policy_overrides';
    protected $fillable = ['company_id', 'user_id', 'module', 'key', 'value', 'reason', 'created_by_id'];
    protected $casts = ['value' => 'array']; // guarda {v: <valor>} p/ preservar tipo (bool/string)
}
