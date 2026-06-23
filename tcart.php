<?php
$u=\App\Models\User::where('type','admin')->first();
$req=\Illuminate\Http\Request::create('/crm/carteira','GET');
$req->setUserResolver(fn()=>$u);
$resp=app(\App\Http\Controllers\CrmCarteiraController::class)->index($req);
$d=json_decode($resp->getContent(),true)['data'];
echo "pode_ver_todos=".var_export($d['pode_ver_todos'],true)."\n";
echo "executivos=".count($d['executivos'])."\n";
echo "clientes=".count($d['clientes'])." resumo.clientes=".$d['resumo']['clientes']."\n";
