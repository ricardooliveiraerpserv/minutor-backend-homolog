<?php
use App\Documents\CrmProposalService;
use App\Models\{CrmOpportunity, CrmProposal, CrmProposalCalc, User};
use App\Attachments\AttachableEntitiesRegistry as R;
$actor=User::where('type','admin')->first();
$opp=CrmOpportunity::orderByDesc('id')->first();
// descrição obrigatória (regra do controller): simula filled()
$opp->update(['descricao'=>null,'valor'=>999]);
echo "GUARD sem descricao bloqueia? ".(!filled($opp->fresh()->descricao)?'SIM':'nao')."\n";
$opp->update(['descricao'=>'Implantação SmartView']);
// criar proposta → valor da opp = total da proposta
$svc=app(CrmProposalService::class);
$p=$svc->criar(['opportunity_id'=>$opp->id,'tipo'=>'bh_fixo','modo_faturamento'=>'por_hora','inputs'=>['horas_consultoria'=>100,'valor_hora_cliente'=>180,'venda_h'=>180]],$actor);
echo "PROPOSTA total={$p->total} → opp.valor=".$opp->fresh()->valor." (prevalece sobre 999)\n";
// CRM_OPPORTUNITY no registry de anexos
try { R::assertCategory('CRM_OPPORTUNITY','attachment'); echo "ANEXO opp categoria OK\n"; } catch(\Throwable $e){ echo "ANEXO ERRO: ".$e->getMessage()."\n"; }
// limpa
CrmProposalCalc::where('proposal_id',$p->id)->forceDelete(); $p->forceDelete();
$opp->update(['valor'=>0]); $svc->syncOppValor($p);
echo "LIMPO\n";
