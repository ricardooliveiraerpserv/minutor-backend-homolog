<?php
use App\Documents\CrmProposalService;
use App\Models\{CrmOpportunity, CrmProposal, CrmProposalCalc, User};
$svc=app(CrmProposalService::class); $actor=User::where('type','admin')->first();
$opp=CrmOpportunity::orderByDesc('id')->first();
// 1) cria rascunho mínimo (como o botão + Nova proposta)
$p=$svc->criar(['opportunity_id'=>$opp->id,'tipo'=>'bh_fixo','modo_faturamento'=>'por_hora','inputs'=>['duracao_meses'=>12]],$actor);
echo "CRIADO id={$p->id} codigo={$p->codigo} tipo={$p->tipo} status={$p->status} valor={$p->valor}\n";
// 2) editar trocando tipo p/ projeto_fechado + valor
$p2=$svc->editar($p,['tipo'=>'projeto_fechado','inputs'=>['valor_projeto'=>30000,'faturamento_fixo'=>30000,'parcelas'=>2],'conteudo'=>['escopo'=>['tipo_escopo'=>'FECHADO']]],$actor);
echo "EDITADO tipo={$p2->tipo} modo=".($p2->calc?->modo_faturamento)." valor={$p2->valor}\n";
// cleanup
CrmProposalCalc::where('proposal_id',$p->id)->forceDelete(); $p->forceDelete();
echo "LIMPO\n";
