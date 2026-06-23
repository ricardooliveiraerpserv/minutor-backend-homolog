<?php
use App\Attachments\AttachableEntitiesRegistry as R;
try { R::assertCategory('CRM_PROPOSAL','logo'); echo "CATEGORY logo OK\n"; } catch(\Throwable $e){ echo "CAT ERRO: ".$e->getMessage()."\n"; }
$prop = \App\Models\CrmProposal::orderByDesc('id')->first();
try { $e = R::resolve('CRM_PROPOSAL', $prop->id); echo "RESOLVE OK id=".$e->id."\n"; } catch(\Throwable $e){ echo "RESOLVE ERRO: ".$e->getMessage()."\n"; }
echo "max_mb=".R::maxSizeMb('CRM_PROPOSAL')."\n";
