<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Semeia o modelo padrão de e-mail da rotina "Horas Excedentes" (categoria
 * "excedente"), caso ainda não exista nenhum cadastrado. Corpo/assunto usam
 * as variáveis do modelo ({competencia}, {horas_*}, {valor_*}, {nome}).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('fechamento_email_templates')->where('categoria', 'excedente')->exists();
        if ($exists) {
            return;
        }

        $body = "Prezados,\n\n"
            . "Segue em anexo o relatório de apuração das horas excedentes referente à competência {competencia}.\n\n"
            . "Resumo da apuração:\n\n"
            . "• Horas contratadas (acumuladas): {horas_contratadas}\n"
            . "• Horas consumidas: {horas_consumidas}\n"
            . "• Horas excedentes: {horas_excedentes}\n"
            . "• Valor da hora excedente: {valor_hora}\n"
            . "• Valor total a faturar: {valor_total}\n\n"
            . "Essas horas correspondem ao consumo realizado acima da quantidade de horas contratadas e serão faturadas conforme previsto em contrato.\n\n"
            . "Em caso de dúvidas ou divergências, nossa equipe permanece à disposição.\n\n"
            . "Atenciosamente,";

        DB::table('fechamento_email_templates')->insert([
            'categoria'     => 'excedente',
            'contract_type' => null,
            'empresa'       => 'erpserv',
            'nome'          => 'Horas Excedentes — padrão',
            'subject'       => 'Horas Excedentes {competencia} | {nome}',
            'body'          => $body,
            'pay_day'       => null,
            'active'        => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('fechamento_email_templates')
            ->where('categoria', 'excedente')
            ->where('nome', 'Horas Excedentes — padrão')
            ->delete();
    }
};
