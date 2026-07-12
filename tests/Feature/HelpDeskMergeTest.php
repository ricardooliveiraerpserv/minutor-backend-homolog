<?php

namespace Tests\Feature;

use App\Exceptions\HelpDeskMergeException;
use App\Models\Customer;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTag;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketMerge;
use App\Services\HelpDeskMergeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HelpDeskMergeTest extends TestCase
{
    use DatabaseTransactions;

    private HelpDeskMergeService $svc;
    private int $customerA;
    private int $customerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(HelpDeskMergeService::class);
        $this->mkStatus('novo', ['is_default' => true, 'is_open' => true]);
        $this->mkStatus('em_andamento', ['is_open' => true]);
        $this->mkStatus('resolvido', ['is_resolved' => true]);
        $this->mkStatus('fechado', ['is_resolved' => true, 'is_terminal' => true]);
        $this->customerA = Customer::create(['name' => 'Cliente A'])->id;
        $this->customerB = Customer::create(['name' => 'Cliente B'])->id;
    }

    private function mkStatus(string $key, array $flags = []): HelpDeskStatus
    {
        return HelpDeskStatus::firstOrCreate(['key' => $key], array_merge([
            'label' => ucfirst($key), 'color' => '#888', 'sort_order' => 0, 'active' => true,
            'is_default' => false, 'is_open' => false, 'is_resolved' => false, 'is_terminal' => false, 'sla_paused' => false,
        ], $flags));
    }

    private function ticket(string $statusKey, ?int $customer = null, array $attrs = []): HelpDeskTicket
    {
        static $n = 1000;
        $n++;
        return HelpDeskTicket::create(array_merge([
            'ticket_number' => 'T-' . $n, 'subject' => "Chamado $n", 'priority' => 'normal', 'channel' => 'email',
            'customer_id' => $customer ?? $this->customerA, 'status_id' => HelpDeskStatus::where('key', $statusKey)->value('id'),
        ], $attrs));
    }

    private function comment(HelpDeskTicket $t, string $body): HelpDeskTicketComment
    {
        return $t->comments()->create(['body' => $body, 'visibility' => 'customer', 'channel' => 'email']);
    }

    /** @test */
    public function merge_moves_comments_and_hides_source(): void
    {
        $target = $this->ticket('novo');
        $src = $this->ticket('em_andamento');
        $this->comment($target, 'T1');
        $c = $this->comment($src, 'S1');

        $this->svc->merge($target, [$src], [], null);

        $src->refresh();
        $this->assertSame($target->id, $src->merged_into_id, 'origem marcada como mesclada');
        // Interação da origem foi para o destino, preservando origin_ticket_id = origem.
        $c->refresh();
        $this->assertSame($target->id, $c->ticket_id);
        $this->assertSame($src->id, $c->origin_ticket_id);
        $this->assertSame(2, $target->comments()->count(), 'destino agora tem as 2 interações');
    }

    /** @test */
    public function options_are_applied_independently(): void
    {
        $target = $this->ticket('novo', null, ['cc_emails' => ['t@x.com']]);
        $src = $this->ticket('novo', null, ['cc_emails' => ['s@x.com'], 'requester_email' => 'solic@x.com']);
        $tag = HelpDeskTag::create(['name' => 'urgente', 'color' => '#f00']);
        $src->tags()->attach($tag->id);

        $this->svc->merge($target, [$src], ['keep_cc' => true, 'keep_requesters' => true, 'keep_tags' => true], null);

        $target->refresh();
        $this->assertEqualsCanonicalizing(['t@x.com', 's@x.com', 'solic@x.com'], $target->cc_emails);
        $this->assertTrue($target->tags()->where('helpdesk_tags.id', $tag->id)->exists());

        // Sem opções: nada é copiado.
        $t2 = $this->ticket('novo', null, ['cc_emails' => ['a@x.com']]);
        $s2 = $this->ticket('novo', null, ['cc_emails' => ['b@x.com']]);
        $this->svc->merge($t2, [$s2], [], null);
        $this->assertEqualsCanonicalizing(['a@x.com'], $t2->fresh()->cc_emails);
    }

    /** @test */
    public function unmerge_returns_pre_merge_actions_and_keeps_post_merge(): void
    {
        $target = $this->ticket('novo');
        $src = $this->ticket('em_andamento');
        $pre = $this->comment($src, 'antes da mescla');   // nasceu na origem
        $this->svc->merge($target, [$src], [], null);
        // interação criada DEPOIS, já no contexto do destino:
        $post = $target->comments()->create(['body' => 'depois no destino', 'visibility' => 'internal', 'channel' => 'interno']);

        $restored = $this->svc->unmerge($target->fresh(), $src->fresh(), null);

        $this->assertNull($restored->merged_into_id, 'origem desmesclada');
        $this->assertSame('em_andamento', $restored->status->key, 'volta a status ativo');
        $this->assertSame($src->id, $pre->fresh()->ticket_id, 'interação pré-mescla voltou pra origem');
        $this->assertSame($target->id, $post->fresh()->ticket_id, 'interação criada no destino permanece nele');
    }

    /** @test */
    public function cannot_merge_terminal_ticket(): void
    {
        $target = $this->ticket('novo');
        $closed = $this->ticket('fechado');
        $this->expectException(HelpDeskMergeException::class);
        $this->svc->merge($target, [$closed], [], null);
    }

    /** @test */
    public function cannot_merge_into_or_from_already_merged(): void
    {
        $target = $this->ticket('novo');
        $src = $this->ticket('novo');
        $this->svc->merge($target, [$src], [], null);

        // origem já mesclada não pode ser mesclada de novo (guard de concorrência/reuso)
        $other = $this->ticket('novo');
        $this->expectException(HelpDeskMergeException::class);
        $this->svc->merge($other, [$src->fresh()], [], null);
    }

    /** @test */
    public function respects_max_actions_limit(): void
    {
        config(['helpdesk.merge.max_actions' => 3]);
        $target = $this->ticket('novo');
        $src = $this->ticket('novo');
        foreach (range(1, 2) as $i) $this->comment($target, "t$i");
        foreach (range(1, 2) as $i) $this->comment($src, "s$i"); // 4 > 3
        $this->expectException(HelpDeskMergeException::class);
        $this->svc->merge($target, [$src], [], null);
    }

    /** @test */
    public function cross_customer_merge_is_blocked(): void
    {
        // Só se mescla dentro do MESMO cliente.
        $target = $this->ticket('novo', $this->customerA);
        $src = $this->ticket('novo', $this->customerB);
        $this->expectException(HelpDeskMergeException::class);
        $this->svc->merge($target, [$src], [], null);
    }

    /** @test */
    public function cannot_merge_resolved_ticket(): void
    {
        // Abertos apenas: encerrados, cancelados e resolvidos não entram na mesclagem.
        $target = $this->ticket('novo');
        $resolved = $this->ticket('resolvido');
        $this->expectException(HelpDeskMergeException::class);
        $this->svc->merge($target, [$resolved], [], null);
    }

    /** @test */
    public function merge_and_unmerge_are_audited(): void
    {
        $target = $this->ticket('novo');
        $src = $this->ticket('em_andamento');
        $this->svc->merge($target, [$src], ['keep_cc' => true], null);
        $this->svc->unmerge($target->fresh(), $src->fresh(), null);

        $this->assertSame(1, HelpDeskTicketMerge::where('action', 'merge')->where('source_ticket_id', $src->id)->count());
        $this->assertSame(1, HelpDeskTicketMerge::where('action', 'unmerge')->where('source_ticket_id', $src->id)->count());
        // números preservados no log p/ auditoria mesmo após exclusão.
        $log = HelpDeskTicketMerge::where('action', 'merge')->first();
        $this->assertSame($src->ticket_number, $log->source_number);
        $this->assertSame($target->ticket_number, $log->target_number);
    }

    /** @test */
    public function merge_multiple_sources_into_one_target(): void
    {
        $target = $this->ticket('novo');
        $s1 = $this->ticket('novo');
        $s2 = $this->ticket('em_andamento');
        $this->comment($s1, 'a'); $this->comment($s2, 'b');
        $this->svc->merge($target, [$s1, $s2], [], null);
        $this->assertSame($target->id, $s1->fresh()->merged_into_id);
        $this->assertSame($target->id, $s2->fresh()->merged_into_id);
        $this->assertSame(2, $target->comments()->count());
        $this->assertSame(2, $target->mergedTickets()->count());
    }
}
