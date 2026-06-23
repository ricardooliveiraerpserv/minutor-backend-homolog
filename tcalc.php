<?php
use App\Documents\{CrmProposalCalcService, CrmProposalService};
use App\Models\{CrmOpportunity, CrmProposal, CrmProposalCalc, User};
$actor=User::where('type','admin')->first();
$opp=CrmOpportunity::orderByDesc('id')->first();
$calcSvc=app(CrmProposalCalcService::class);
// simula calcSave: compute + upsert + opp.valor
$inputs=['horas_consultoria'=>150,'valor_hora_cliente'=>190,'venda_h'=>190,'duracao_meses'=>12,'custo_h_consultoria'=>80,'custo_h_coordenacao'=>72];
$out=$calcSvc->compute($inputs,'por_hora');
CrmProposalCalc::create(['opportunity_id'=>$opp->id,'proposal_id'=>null,'versao'=>1,'modo_faturamento'=>'por_hora','inputs'=>$inputs,'outputs'=>$out,'custo_total'=>$out['custo_total'],'faturamento'=>$out['faturamento'],'premios_total'=>$out['premios_total'],'desconto_valor'=>$out['desconto_valor'],'margem_pct'=>$out['margem_pct'],'lucro_liquido'=>$out['lucro_liquido']]);
$opp->update(['valor'=>$out['faturamento']]);
echo "OPP_CALC faturamento={$out['faturamento']} margem={$out['margem_pct']} opp_valor=".$opp->fresh()->valor."\n";
// criar proposta SEM inputs → herda calc da opp
$svc=app(CrmProposalService::class);
$oc=CrmProposalCalc::where('opportunity_id',$opp->id)->whereNull('proposal_id')->latest('id')->first();
$p=$svc->criar(['opportunity_id'=>$opp->id,'tipo'=>'bh_fixo','modo_faturamento'=>$oc->modo_faturamento,'inputs'=>(array)$oc->inputs],$actor);
echo "PROP herdou horas=".($p->calc->inputs['horas_consultoria']??'?')." valor_hora=".($p->calc->inputs['valor_hora_cliente']??'?')." codigo={$p->codigo}\n";
// cleanup
CrmProposalCalc::where('proposal_id',$p->id)->forceDelete(); $p->forceDelete();
CrmProposalCalc::where('opportunity_id',$opp->id)->whereNull('proposal_id')->forceDelete();
echo "LIMPO\n";
