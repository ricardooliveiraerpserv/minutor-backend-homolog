<?php

namespace App\Services;

use App\Models\HelpDeskDepartment;
use App\Models\MovideskOrganization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cruza os DEPARTAMENTOS de um cliente entre o Movidesk (organização » departamento) e o Minutor
 * (helpdesk_departments + users.helpdesk_department_id). Só LÊ o Movidesk.
 *  - cria no Minutor os departamentos que existem só no Movidesk;
 *  - preenche o departamento de quem está SEM departamento no Minutor;
 *  - DIVERGÊNCIA (Minutor ≠ Movidesk): o MOVIDESK PREVALECE — o Minutor é corrigido (e relatado).
 * Também resolve o id do departamento no Movidesk a partir do departamento do Minutor (cópia Promax).
 */
class MovideskDepartmentSync
{
    public function __construct(private MovideskService $movidesk) {}

    private static function norm(?string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
        return strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    }

    private function org(int $customerId): ?MovideskOrganization
    {
        return MovideskOrganization::where('hd_customer_id', $customerId)
            ->where('company_id', MovideskPushPolicy::primaryCompanyId())->first();
    }

    /** @return array<string,mixed> estatísticas + listas p/ relatório */
    public function sync(int $customerId, bool $apply = true): array
    {
        $org = $this->org($customerId);
        if (!$org) return ['erro' => 'cliente sem organização vinculada no Movidesk'];
        $prev = $this->movidesk->currentCompany();
        $this->movidesk->forCompany(null);
        try { $md = $this->movidesk->orgDepartmentsAndPeople((string) $org->movidesk_id); }
        finally { $this->movidesk->forCompany($prev); }

        $deps = HelpDeskDepartment::where('customer_id', $customerId)->get()->keyBy(fn ($d) => self::norm($d->name));
        $st = ['deps_criados' => [], 'usuarios_preenchidos' => 0, 'ok' => 0, 'divergentes' => [], 'sem_usuario_minutor' => 0, 'raiz' => 0];

        foreach (array_unique(array_filter(array_column($md['people'], 'dep'))) as $name) {
            if ($deps->has(self::norm($name))) continue;
            if ($apply) {
                $d = HelpDeskDepartment::withTrashed()->where('customer_id', $customerId)->where('name', $name)->first();
                if ($d) { $d->restore(); $d->update(['active' => true]); }
                else $d = HelpDeskDepartment::create(['customer_id' => $customerId, 'name' => $name, 'active' => true, 'company_id' => MovideskPushPolicy::primaryCompanyId()]);
                $deps->put(self::norm($name), $d);
            }
            $st['deps_criados'][] = $name;
        }

        $byEmail = collect($md['people'])->filter(fn ($p) => $p['email'] !== '')->keyBy('email');
        $users = User::where('customer_id', $customerId)->whereNotNull('email')->get(['id', 'email', 'helpdesk_department_id']);
        $depNameById = $deps->mapWithKeys(fn ($d) => [$d->id => $d->name]);
        foreach ($users as $u) {
            $p = $byEmail[mb_strtolower($u->email)] ?? null;
            if (!$p) { $st['sem_usuario_minutor']++; continue; }
            if (!$p['dep']) { $st['raiz']++; continue; }
            $target = $deps[self::norm($p['dep'])] ?? null;
            if (!$u->helpdesk_department_id) {
                if ($target && $apply) User::whereKey($u->id)->update(['helpdesk_department_id' => $target->id]);
                $st['usuarios_preenchidos']++;
            } elseif ($target && (int) $u->helpdesk_department_id === (int) $target->id) {
                $st['ok']++;
            } elseif ($target) {
                // MOVIDESK PREVALECE: alinha o departamento do Minutor ao do Movidesk.
                if ($apply) User::whereKey($u->id)->update(['helpdesk_department_id' => $target->id]);
                $st['divergentes'][] = ['email' => $u->email, 'minutor' => $depNameById[$u->helpdesk_department_id] ?? '?', 'movidesk' => $p['dep']];
            }
        }
        $st['sem_usuario_minutor'] = $byEmail->keys()->diff($users->map(fn ($u) => mb_strtolower($u->email)))->count();
        Cache::put("md_deps_{$customerId}", $md['deps'], now()->addHours(12));
        if ($st['deps_criados'] || $st['usuarios_preenchidos'] || $st['divergentes']) {
            Log::info('🏢 [MOVIDESK DEPTOS] cruzamento', ['customer_id' => $customerId, 'criados' => $st['deps_criados'], 'preenchidos' => $st['usuarios_preenchidos'], 'divergentes' => count($st['divergentes'])]);
        }
        return $st;
    }

    /** Id do departamento no Movidesk equivalente ao departamento (Minutor) do solicitante. */
    public function movideskDepartmentFor(int $customerId, ?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') return null;
        $depId = User::where('customer_id', $customerId)->whereRaw('lower(email) = ?', [$email])->value('helpdesk_department_id');
        $name = $depId ? HelpDeskDepartment::whereKey($depId)->value('name') : null;
        if (!$name) return null;
        $deps = Cache::get("md_deps_{$customerId}");
        if ($deps === null) {
            $org = $this->org($customerId);
            if (!$org) return null;
            try { $deps = $this->movidesk->orgDepartmentsAndPeople((string) $org->movidesk_id, false)['deps']; } catch (\Throwable) { return null; }
            Cache::put("md_deps_{$customerId}", $deps, now()->addHours(12));
        }
        foreach ($deps as $id => $n) if (self::norm($n) === self::norm($name)) return (string) $id;
        return null;
    }
}
