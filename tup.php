<?php
use App\Attachments\AttachmentService;
use App\Models\{CrmProposal, User, Attachment};
$actor = User::where('type','admin')->first();
$prop = CrmProposal::orderByDesc('id')->first();
$file = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 200, 120); // PNG fake (GD)
try {
  $att = app(AttachmentService::class)->store($actor, ['entity_type'=>'CRM_PROPOSAL','entity_id'=>$prop->id,'category'=>'logo','file'=>$file]);
  echo "UPLOAD OK att_id=".$att->id." mime=".$att->mime_type." path=".$att->storage_path."\n";
  // grava no conteudo + limpa
  $c = (array)($prop->conteudo ?? []); $c['logo_attachment_id']=$att->id; $prop->update(['conteudo'=>$c]);
  echo "CONTEUDO logo_attachment_id=".$prop->fresh()->conteudo['logo_attachment_id']."\n";
  // cleanup attachment + conteudo
  $att->forceDelete(); $c2=(array)$prop->fresh()->conteudo; unset($c2['logo_attachment_id']); $prop->update(['conteudo'=>$c2]);
  echo "LIMPO\n";
} catch(\Throwable $e){ echo "UPLOAD ERRO: ".get_class($e)." ".$e->getMessage()."\n"; }
