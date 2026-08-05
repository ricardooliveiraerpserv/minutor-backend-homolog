<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PolicyRole extends Model
{
    protected $table = 'policy_roles';
    protected $fillable = ['company_id', 'module', 'name', 'is_system', 'archived', 'defaults', 'sort_order'];
    protected $casts = ['is_system' => 'boolean', 'archived' => 'boolean', 'defaults' => 'array', 'sort_order' => 'integer'];
}
