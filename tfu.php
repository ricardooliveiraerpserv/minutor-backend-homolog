<?php
use App\Models\{CrmContactType, CrmOpportunity, CrmTask, User};
$opp=CrmOpportunity::orderByDesc('id')->first();
echo "TIPOS ativos: ".CrmContactType::where('ativo',true)->pluck('slug')->implode(',')."\n";
$slug=CrmContactType::where('ativo',true)->first()->slug;
// follow-up realizado (data passada) + próximo (futuro)
$t1=CrmTask::create(['opportunity_id'=>$opp->id,'tipo'=>$slug,'titulo'=>'BKBKBK texto longo de teste do follow-up','data'=>now()->subDay()]);
$t2=CrmTask::create(['opportunity_id'=>$opp->id,'tipo'=>$slug,'data'=>now()->addDays(3)]);
// recompute manual (igual ao controller)
$prox=CrmTask::where('opportunity_id',$opp->id)->whereNull('concluida_at')->orderBy('data')->value('data');
$opp->update(['proxima_acao_at'=>$prox]);
echo "FOLLOWUP realizado tipo={$t1->tipo} titulo_len=".strlen($t1->titulo)."\n";
echo "PROXIMO agendado tipo={$t2->tipo} data=".$t2->data."\n";
echo "PROXIMA_ACAO_AT opp=".$opp->fresh()->proxima_acao_at."\n";
$t1->forceDelete(); $t2->forceDelete();
echo "LIMPO\n";
