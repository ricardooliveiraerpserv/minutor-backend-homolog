<?php

namespace Tests\Unit;

use App\Services\OkrProgressService;
use PHPUnit\Framework\TestCase;

/**
 * Cobre as regras de cálculo do PRD (seção 6): fórmulas por direção, aderência,
 * semáforo, distribuição automática e casos extremos.
 */
class OkrProgressServiceTest extends TestCase
{
    private OkrProgressService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new OkrProgressService();
    }

    /** @test */
    public function aumentar_calcula_atingimento_linear(): void
    {
        $this->assertSame(50.0, $this->svc->achievementIncrease(0, 36, 18));
        $this->assertSame(100.0, $this->svc->achievementIncrease(0, 36, 36));
        $this->assertSame(50.0, $this->svc->achievementIncrease(10, 20, 15));
    }

    /** @test */
    public function aumentar_permite_superar_e_trava_regressao_em_zero(): void
    {
        $this->assertSame(111.11, $this->svc->achievementIncrease(0, 36, 40)); // superado
        $this->assertSame(0.0, $this->svc->achievementIncrease(0, 36, -5));    // regressão → 0
    }

    /** @test */
    public function aumentar_meta_degenerada_baseline_igual_target(): void
    {
        $this->assertSame(100.0, $this->svc->achievementIncrease(36, 36, 36));
        $this->assertSame(0.0, $this->svc->achievementIncrease(36, 36, 30));
    }

    /** @test */
    public function reduzir_calcula_atingimento_invertido(): void
    {
        $this->assertSame(50.0, $this->svc->achievementDecrease(15, 5, 10));
        $this->assertSame(100.0, $this->svc->achievementDecrease(15, 5, 5));
        $this->assertSame(120.0, $this->svc->achievementDecrease(15, 5, 3)); // superado
    }

    /** @test */
    public function manter_faixa_usa_conformidade(): void
    {
        $this->assertTrue($this->svc->withinBand(95, 90, 100));
        $this->assertFalse($this->svc->withinBand(85, 90, 100));
        $this->assertSame(100.0, $this->svc->achievement('manter_faixa', null, null, 95, 90, 100));
        $this->assertSame(0.0, $this->svc->achievement('manter_faixa', null, null, 85, 90, 100));
    }

    /** @test */
    public function realizado_nao_informado_retorna_null_nao_zero(): void
    {
        $this->assertNull($this->svc->achievement('aumentar', 0, 36, null));
    }

    /** @test */
    public function aderencia_compara_acumulados(): void
    {
        $this->assertSame(100.0, $this->svc->adherence(18, 18));
        $this->assertSame(50.0, $this->svc->adherence(9, 18));
        $this->assertNull($this->svc->adherence(10, 0));   // sem base
        $this->assertNull($this->svc->adherence(null, 18)); // sem realizado
    }

    /** @test */
    public function semaforo_usa_limiares_configuraveis(): void
    {
        $this->assertSame('verde', $this->svc->status(100));
        $this->assertSame('amarelo', $this->svc->status(90));
        $this->assertSame('vermelho', $this->svc->status(70));
        $this->assertSame('cinza', $this->svc->status(null));
    }

    /** @test */
    public function serie_mensal_acumulativa_calcula_acumulado_aderencia_e_atingimento(): void
    {
        $targets = array_fill(1, 12, 3.0);
        $actuals = array_fill(1, 12, null);
        foreach ([1 => 2, 2 => 4, 3 => 3, 4 => 2, 5 => 4, 6 => 3] as $m => $v) { $actuals[$m] = (float) $v; } // soma 18

        $s = $this->svc->monthlySeries('aumentar', 'acumulativo', 0.0, 36.0, null, null, $targets, $actuals);

        $this->assertSame(6, $s['summary']['reference_month']);
        $this->assertSame(18.0, $s['summary']['accumulated_actual']);
        $this->assertSame(18.0, $s['summary']['accumulated_planned']);
        $this->assertSame(100.0, $s['summary']['adherence']);
        $this->assertSame('verde', $s['summary']['status']);
        $this->assertSame(50.0, $s['summary']['achievement']);
        // Pendente ≠ zero: mês 7 (índice 6) sem realizado mantém o acumulado em 18.
        $this->assertNull($s['months'][6]['actual']);
        $this->assertSame(18.0, $s['months'][6]['accumulated_actual']);
    }

    /** @test */
    public function serie_mensal_identifica_kr_atrasado_por_aderencia(): void
    {
        $targets = array_fill(1, 12, 3.0);
        $actuals = array_fill(1, 12, null);
        foreach ([1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 1, 6 => 1] as $m => $v) { $actuals[$m] = (float) $v; } // soma 10

        $s = $this->svc->monthlySeries('aumentar', 'acumulativo', 0.0, 36.0, null, null, $targets, $actuals);

        $this->assertSame(55.56, $s['summary']['adherence']); // (10/36) / (18/36)
        $this->assertSame('vermelho', $s['summary']['status']);
    }

    /** @test */
    public function distribuicao_automatica_soma_a_meta_anual(): void
    {
        $d = $this->svc->autoDistribute(36);
        $this->assertCount(12, $d);
        $this->assertSame(36.0, round(array_sum($d), 2));
        $this->assertSame(3.0, $d[0]);

        $d2 = $this->svc->autoDistribute(10); // não divisível
        $this->assertSame(10.0, round(array_sum($d2), 2));
    }
}
