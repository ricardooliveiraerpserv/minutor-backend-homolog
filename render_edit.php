<?php
use App\Documents\CrmProposalService;
use App\Models\{CrmProposal, CrmProposalCalc, Customer, User};
$svc=app(CrmProposalService::class);
$calc=new CrmProposalCalc(); $calc->inputs=['horas_consultoria'=>200,'valor_hora_cliente'=>200,'venda_h'=>200,'duracao_meses'=>12];
$p=new CrmProposal(); $p->codigo='MLP001-26'; $p->versao=1; $p->tipo='bh_fixo'; $p->data_emissao=now();
$p->conteudo=[]; // SEM overrides → artwork puro + campos sempre-dinâmicos
$p->setRelation('calc',$calc); $cust=new Customer(); $cust->name='METAL LIMPO'; $cust->cgc='04386988000120'; $vend=new User(); $vend->name='Ricardo Badawi';
$p->setRelation('customer',$cust); $p->setRelation('vendedor',$vend);
file_put_contents('/tmp/clean.html', view('pdf.documents.proposta.render',$svc->buildRenderData($p,'datauri'))->render());
echo "HTMLOK\n";
