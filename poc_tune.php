<?php
use App\Models\{User, Customer, CrmOpportunity, CrmPipeline, CrmProposal, CrmProposalCalc, ProjectSequence, DocumentEvent};
use App\Documents\CrmProposalService;
use Illuminate\Support\Facades\{Auth, View};

$admin = User::where('type', 'admin')->first(); Auth::login($admin);
Customer::where('code_prefix', 'ZZV')->withTrashed()->each(function ($c) { ProjectSequence::where('customer_id', $c->id)->delete(); $c->forceDelete(); });
$cust = Customer::create(['name' => 'Indústria Acme do Brasil Ltda', 'company_name' => 'Acme', 'cgc' => '12.345.678/0001-90', 'active' => true, 'code_prefix' => 'ZZV', 'executive_id' => $admin->id]);
$pipe = CrmPipeline::where('tipo', 'comercial')->with('stages')->first();
$opp = CrmOpportunity::create(['customer_id' => $cust->id, 'pipeline_id' => $pipe->id, 'stage_id' => $pipe->stages->sortBy('ordem')->first()->id, 'title' => 'Proj', 'valor' => 0, 'status' => 'aberto', 'responsavel_id' => $admin->id, 'data_abertura' => now()]);
$svc = app(CrmProposalService::class);
$p = $svc->criar(['opportunity_id' => $opp->id, 'tipo' => 'bh_fixo', 'modo_faturamento' => 'por_hora', 'inputs' => ['horas_consultoria' => 100, 'custo_h_consultoria' => 80, 'custo_h_coordenacao' => 72, 'venda_h' => 174, 'valor_hora_cliente' => 170, 'duracao_meses' => 12]], $admin);

$html = View::make('pdf.documents.proposta.render', $svc->buildRenderData($p->fresh('calc')))->render();
file_put_contents('/tmp/visual-extract/tune.html', $html);
echo "codigo={$p->codigo} HTML ok\n";

// cleanup
foreach (CrmProposal::where('codigo', $p->codigo)->get() as $x) { CrmProposalCalc::where('proposal_id', $x->id)->forceDelete(); $x->forceDelete(); }
DocumentEvent::where('codigo', $p->codigo)->delete();
$opp->forceDelete(); ProjectSequence::where('customer_id', $cust->id)->delete(); $cust->forceDelete();
echo "limpeza ok\n";
