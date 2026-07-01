<?php
use App\Documents\CrmProposalService;
use App\Models\{CrmProposal, CrmProposalCalc, Customer, User};
$svc=app(CrmProposalService::class); $def=$svc->proposalDefaults('bh_fixo');
$calc=new CrmProposalCalc(); $calc->inputs=['horas_consultoria'=>200,'valor_hora_cliente'=>200,'venda_h'=>200];
$p=new CrmProposal(); $p->codigo='X'; $p->versao=1; $p->tipo='bh_fixo'; $p->data_emissao=now();
$p->conteudo=['escopo'=>['tipo_escopo'=>'FECHADO','escopo_funcional'=>str_replace('Diagnóstico de ambiente','Diag AVANCADO',$def['escopo']['escopo_funcional'])]];
$p->setRelation('calc',$calc);
$data=$svc->buildRenderData($p,'datauri');
// extrai só as máscaras/divs do overlay 3 (sem o base64)
$o=$data['overlays'][3];
preg_match_all('/left:(\d+)px;top:(\d+)px;width:(\d+)px;height:(\d+)px;background:(#[0-9a-fA-F]+)/',$o,$m,PREG_SET_ORDER);
foreach($m as $x){ echo "MASK left={$x[1]} top={$x[2]} w={$x[3]} h={$x[4]} bg={$x[5]}\n"; }
echo "has_funcional_text=".(strpos($o,'Diag AVANCADO')!==false?'SIM':'NAO')."\n";
