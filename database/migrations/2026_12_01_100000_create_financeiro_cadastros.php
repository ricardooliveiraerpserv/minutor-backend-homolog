<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro F1 — cadastros fiscais e financeiros (substituição do Keruak).
 *
 *  - customers: dados fiscais do tomador da NFS-e (endereço, IBGE, IM/IE, regime, e-mail de cobrança).
 *  - fin_company_settings: 1 linha por empresa emissora (ERPSERV, BIZIFY…): IM, regime, alíquotas
 *    padrão, série/numeração da DPS, ambiente da NFS-e e o certificado A1 CIFRADO (cast encrypted).
 *  - fin_bank_accounts: contas bancárias por empresa + gateway de cobrança (none|fake|inter|cnab_caixa)
 *    e credenciais do gateway CIFRADAS.
 *  - fin_chart_accounts: plano de contas por empresa, com o grupo da DRE.
 *  - fin_payees: favorecidos a pagar (consultor, cooperativa, parceiro, diretoria, fornecedor).
 *  - fin_audit_logs: trilha de quem mexeu em certificado/credenciais (nunca guarda o segredo).
 *
 * Aditiva e idempotente (hasTable/hasColumn). Não altera nenhuma tabela existente além de
 * ACRESCENTAR colunas anuláveis em customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $cols = [
                'cep' => fn () => $t->string('cep', 9)->nullable(),
                'logradouro' => fn () => $t->string('logradouro')->nullable(),
                'numero' => fn () => $t->string('numero', 20)->nullable(),
                'complemento' => fn () => $t->string('complemento')->nullable(),
                'bairro' => fn () => $t->string('bairro')->nullable(),
                'cidade' => fn () => $t->string('cidade')->nullable(),
                'uf' => fn () => $t->string('uf', 2)->nullable(),
                'codigo_ibge' => fn () => $t->string('codigo_ibge', 7)->nullable(),
                'inscricao_municipal' => fn () => $t->string('inscricao_municipal', 30)->nullable(),
                'inscricao_estadual' => fn () => $t->string('inscricao_estadual', 30)->nullable(),
                'regime_tributario' => fn () => $t->string('regime_tributario', 30)->nullable(),
                'email_cobranca' => fn () => $t->text('email_cobranca')->nullable(),
            ];
            foreach ($cols as $name => $add) {
                if (! Schema::hasColumn('customers', $name)) {
                    $add();
                }
            }
        });

        if (! Schema::hasTable('fin_company_settings')) {
            Schema::create('fin_company_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
                $t->string('razao_social')->nullable();
                $t->string('inscricao_municipal', 30)->nullable();
                $t->string('regime_tributario', 30)->nullable(); // simples|lucro_presumido|lucro_real
                $t->string('codigo_ibge', 7)->nullable();         // município do prestador
                $t->string('cep', 9)->nullable();
                $t->string('logradouro')->nullable();
                $t->string('numero', 20)->nullable();
                $t->string('bairro')->nullable();
                $t->string('cidade')->nullable();
                $t->string('uf', 2)->nullable();
                // Alíquotas padrão (%) — o contrato pode sobrescrever.
                $t->decimal('aliquota_iss', 6, 4)->nullable();
                $t->decimal('aliquota_irrf', 6, 4)->nullable();
                $t->decimal('aliquota_csll', 6, 4)->nullable();
                $t->decimal('aliquota_pis', 6, 4)->nullable();
                $t->decimal('aliquota_cofins', 6, 4)->nullable();
                $t->decimal('aliquota_cbs', 6, 4)->nullable();
                $t->decimal('aliquota_ibs', 6, 4)->nullable();
                $t->string('codigo_tributacao_nacional', 20)->nullable();
                // DPS / NFS-e nacional
                $t->string('dps_serie', 5)->nullable();
                $t->unsignedBigInteger('dps_proximo_numero')->default(1);
                $t->string('nfse_ambiente', 20)->default('homologacao'); // homologacao|producao
                // Certificado A1 — SEMPRE cifrado (cast encrypted), nunca serializado.
                $t->longText('certificado_pfx')->nullable();
                $t->text('certificado_senha')->nullable();
                $t->string('certificado_titular')->nullable();
                $t->string('certificado_cnpj', 14)->nullable();
                $t->timestamp('certificado_valido_ate')->nullable();
                $t->timestamp('certificado_enviado_em')->nullable();
                $t->foreignId('certificado_enviado_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('fin_bank_accounts')) {
            Schema::create('fin_bank_accounts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('nome');                      // "Itaú ERPSERV", "Caixa ERPSERV – boletos"
                $t->string('banco_codigo', 5)->nullable(); // 077 Inter, 341 Itaú, 104 Caixa
                $t->string('agencia', 10)->nullable();
                $t->string('conta', 20)->nullable();
                $t->string('tipo', 20)->default('corrente'); // corrente|cobranca|aplicacao|caixa
                $t->string('gateway', 20)->default('none');  // none|fake|inter|cnab_caixa
                $t->text('gateway_credenciais')->nullable(); // JSON cifrado (cast encrypted:array)
                $t->decimal('saldo_inicial', 15, 2)->default(0);
                $t->date('saldo_inicial_data')->nullable();
                $t->boolean('ativo')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'ativo']);
            });
        }

        if (! Schema::hasTable('fin_chart_accounts')) {
            Schema::create('fin_chart_accounts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->foreignId('parent_id')->nullable()->constrained('fin_chart_accounts')->nullOnDelete();
                $t->string('codigo', 30);
                $t->string('nome');
                $t->string('natureza', 10);              // receita|despesa
                $t->string('grupo_dre', 40)->nullable(); // receita_bruta|deducoes|custo_servicos|despesas_operacionais|resultado_financeiro|impostos_lucro
                $t->boolean('aceita_lancamento')->default(true);
                $t->boolean('ativo')->default(true);
                $t->timestamps();
                $t->unique(['company_id', 'codigo']);
            });
        }

        if (! Schema::hasTable('fin_payees')) {
            Schema::create('fin_payees', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('tipo', 20);                  // consultor|cooperativa|parceiro|diretoria|fornecedor
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
                $t->string('nome');
                $t->string('documento', 14)->nullable(); // CPF/CNPJ só dígitos
                $t->string('email')->nullable();
                $t->string('pix_tipo', 20)->nullable();  // cpf|cnpj|email|telefone|aleatoria
                $t->string('pix_chave')->nullable();
                $t->string('banco_codigo', 5)->nullable();
                $t->string('agencia', 10)->nullable();
                $t->string('conta', 20)->nullable();
                $t->foreignId('chart_account_id')->nullable()->constrained('fin_chart_accounts')->nullOnDelete();
                $t->boolean('ativo')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'tipo']);
                $t->index(['company_id', 'documento']);
            });
        }

        if (! Schema::hasTable('fin_audit_logs')) {
            Schema::create('fin_audit_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('acao', 60);      // certificado.enviado, certificado.removido, gateway.credenciais…
                $t->string('alvo_tipo', 60)->nullable();
                $t->unsignedBigInteger('alvo_id')->nullable();
                $t->json('detalhes')->nullable(); // NUNCA segredo
                $t->string('ip', 45)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['company_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_audit_logs');
        Schema::dropIfExists('fin_payees');
        Schema::dropIfExists('fin_chart_accounts');
        Schema::dropIfExists('fin_bank_accounts');
        Schema::dropIfExists('fin_company_settings');
        Schema::table('customers', function (Blueprint $t) {
            foreach (['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'codigo_ibge',
                'inscricao_municipal', 'inscricao_estadual', 'regime_tributario', 'email_cobranca'] as $c) {
                if (Schema::hasColumn('customers', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
