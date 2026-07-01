<?php
try {
  $u=\App\Models\User::where('type','admin')->first();
  \Illuminate\Support\Facades\Auth::login($u);
  $req=\Illuminate\Http\Request::create('/crm/carteira','GET');
  $resp=app(\App\Http\Controllers\CrmCarteiraController::class)->index($req);
  $d=json_decode($resp->getContent(),true)['data'];
  echo "RES OK pode_ver_todos=".var_export($d['pode_ver_todos'],true)." executivos=".count($d['executivos'])." clientes=".count($d['clientes'])."\n";
} catch(\Throwable $e){ echo "RES ERRO: ".get_class($e).": ".$e->getMessage()." @ ".basename($e->getFile()).":".$e->getLine()."\n"; }
