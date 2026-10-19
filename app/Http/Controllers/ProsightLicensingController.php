<?php

namespace App\Http\Controllers;

use App\Models\EnvEnvironment;
use App\Models\ProsightRpoConfig;
use App\Prosight\LicensingService;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Licenciamento (REST AdvPL) por EMPRESA — o FE consome por companyId (=customer_id); aqui
 * resolvemos o ambiente do cliente que tem endpoint de licença configurado (prioriza PROD) e
 * consultamos via LicensingService. Anti-IDOR: só empresas no escopo do usuário. Enquanto o
 * cliente Cloud não expõe o endpoint, devolve {vazio:true} (honesto, sem dado falso).
 */
class ProsightLicensingController extends Controller
{
    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    /** GET /prosight/companies/{customerId}/licensing?dt_ini=&dt_fim= */
    public function usage(Request $request, int $customerId, LicensingService $service): JsonResponse
    {
        $env = $this->envWithLicensing($request, $customerId);
        if (! $env) {
            return $this->guardOrEmpty($request, $customerId);
        }
        [$ini, $fim] = $this->range($request);
        return response()->json($service->fetchUsage($env, $ini, $fim));
    }

    /** GET /prosight/companies/{customerId}/licensing/customs?dt_ini=&dt_fim= */
    public function customs(Request $request, int $customerId, LicensingService $service): JsonResponse
    {
        $env = $this->envWithLicensing($request, $customerId);
        if (! $env) {
            if (! $this->scope->canAccessCustomerId($request->user(), $customerId)) {
                return response()->json(['message' => 'Empresa fora do seu escopo.'], 403);
            }
            // Customs: o FE espera o objeto (não {vazio}). Sem ambiente/endpoint → lista vazia.
            return response()->json(['total' => 0, 'comUso' => 0, 'semUso' => 0, 'itens' => []]);
        }
        [$ini, $fim] = $this->range($request);
        return response()->json($service->fetchCustoms($env, $ini, $fim));
    }

    /** 403 se a empresa está fora do escopo; senão {vazio:true} (sem ambiente/endpoint ainda). */
    private function guardOrEmpty(Request $request, int $customerId): JsonResponse
    {
        if (! $this->scope->canAccessCustomerId($request->user(), $customerId)) {
            return response()->json(['message' => 'Empresa fora do seu escopo.'], 403);
        }
        return response()->json(['vazio' => true]);
    }

    /** Ambiente do cliente com licensing_api_url configurado (PROD primeiro), dentro do escopo. */
    private function envWithLicensing(Request $request, int $customerId): ?EnvEnvironment
    {
        if (! $this->scope->canAccessCustomerId($request->user(), $customerId)) {
            return null;
        }
        $envIds = ProsightRpoConfig::whereNotNull('licensing_api_url')->where('licensing_api_url', '<>', '')
            ->pluck('environment_id')->all();
        if (! $envIds) {
            return null;
        }
        return EnvEnvironment::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $envIds)
            ->orderByRaw("case type when 'prod' then 0 when 'homolog' then 1 when 'dev' then 2 else 3 end")
            ->first(['id', 'customer_id', 'name', 'type']);
    }

    /** Faixa de datas (YYYY-MM-DD); default = últimos 15 dias. Aceita dd/mm/yyyy e normaliza. */
    private function range(Request $request): array
    {
        $norm = function (?string $s): ?string {
            $s = trim((string) $s);
            if ($s === '') {
                return null;
            }
            if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) {
                return "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            return preg_match('#^\d{4}-\d{2}-\d{2}$#', $s) ? $s : null;
        };
        $fim = $norm($request->query('dt_fim')) ?? now()->format('Y-m-d');
        $ini = $norm($request->query('dt_ini')) ?? now()->subDays(15)->format('Y-m-d');
        return [$ini, $fim];
    }
}
