<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PolicyAssignment extends Model
{
    protected $table = 'policy_assignments';
    protected $fillable = ['company_id', 'user_id', 'module', 'role_id', 'scope_ref'];
    protected $casts = ['scope_ref' => 'array'];
    public function role() { return $this->belongsTo(PolicyRole::class, 'role_id'); }
}
