<?php
$opp = \App\Models\CrmOpportunity::with('customer:id,name')->orderByDesc('id')->first();
$prop = \App\Models\CrmProposal::orderByDesc('id')->first();
echo "OPPS=".\App\Models\CrmOpportunity::count()." PROPS=".\App\Models\CrmProposal::count()."\n";
if($opp) echo "ULTIMA_OPP id=".$opp->id." cliente=".($opp->customer->name??'?')."\n";
if($prop) echo "ULTIMA_PROP id=".$prop->id." codigo=".($prop->codigo??'(sem codigo)')." tipo=".($prop->tipo??'-')." status=".$prop->status."\n";
$u = \App\Models\User::where('type','admin')->first();
echo "ADMIN=".($u->email??'?')."\n";
