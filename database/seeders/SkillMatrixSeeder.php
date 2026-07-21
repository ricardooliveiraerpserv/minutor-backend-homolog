<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Models\SkillMatrixVersion;
use App\Models\SkillMatrixVersionItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semeia a MATRIZ ÚNICA de competências da ERPSERV (97 competências) a partir
 * do formulário oficial (ERPSERV - MATRIZ DE CONHECIMENTO). Idempotente:
 * firstOrCreate por (name, category). Publica a versão v1 (snapshot congelado).
 *
 * Gerado de xl header em ERPSERV - MATRIZ DE CONHECIMENTO.xlsx.
 */
class SkillMatrixSeeder extends Seeder
{
    /** Ordem das categorias no wizard. */
    public const CATEGORY_ORDER = ['Protheus', 'App TOTVS', 'Linguagens', 'Backend', 'Frontend', 'Banco de Dados', 'Infraestrutura', 'Ferramentas'];

    public function run(): void
    {
        $matrix = [
            ['category' => 'Protheus', 'name' => 'SIGAATF - Ativo Fixo', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGACOM - Compras', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAEST - Estoque e Custos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAFAT - Faturamento', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAFIN - Financeiro', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAGPE - Gestão de Pessoal', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAFIS - Livros Fiscais', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAPCP - Planejamento e Controle da Produção', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAVEI - Veículos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGALOJA - Controle de Lojas', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGATMK - Call Center', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAOFI - Oficina', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAPON - Ponto Eletrônico', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAEIC - Easy Import Control', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGATCF - Terminal de Consulta do Funcionário', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAMNT - Manutenção de Ativos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGARSP - Recrutamento e Seleção de Pessoal', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAQIE - Inspeção de Entradas', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAQMT - Metrologia', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAFRT - Front Loja', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAQDO - Controle de Documentos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAQIP - Inspeção de Processos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGATRM - Treinamento', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAEEC - Easy Export Control', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAEFF - Easy Financing', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAECO - Easy Accounting', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAPLS - Plano de Saúde', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGACTB - Contabilidade Gerencial', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAMDT - Medicina e Segurança do Trabalho', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAQNC - Controle de Não-Conformidades', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAQAD - Controle de Auditoria', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAOMS - OMS -Gestão da Distribuição', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGATMS - TMS -Gestão de Transporte', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGACSA - Cargos e Salários', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAPEC - Auto Peças', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAWMS - WMS - Gestão de Armazenagem', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAPMS - Gestão de Projetos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGACDA - Controle de Direitos Autorais', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAPPAP - PPAP', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAEDC - Easy Drawback Control', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAHSP - Gestão Hospitalar', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAAPD - Avaliação e Pesquisa de Desempenho', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGACRD - Sistema de Fidelizacao e Analise de Creditos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGASGA - Gestão Ambiental', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAPCO - Planejamento e Controle Orcamento', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAGPR - Gerenciamento de Pesquisa e Resultado', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAAPT - Processos Trabalhistas', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAICE - Gestão de riscos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAAGR - Agro Indústria', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAGCT - Gestão de Contratos', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAORG - Arquitetura Organizacional', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGACRM - CRM', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAJURI - Gestão Jurídica', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAPFS - Pré faturamento de Serviço', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAGFE - Gestão de Frete Embarcador', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGASFC - Chão de Fábrica', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAACV - Acessibilidade Visual', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGALOG - Monitoramento e Desempenho Logístico', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGATAF - TOTVS Automação Fiscal', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAESS - Easy Siscoserv', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGAGCV - Gestão de Comércio do Varejo', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'APSDU', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'SIGACFG - Configurador', 'type' => 'module'],
            ['category' => 'Protheus', 'name' => 'Arquitetura e Instalação do Protheus', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Meu CRM', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Meus Contratos', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Meus Ativos Fixos', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Minha Prestação de Contas', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Meu Protheus', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Meu Coletor de Dados', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Meu Posto de Trabalho', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Minha Gestão de Postos', 'type' => 'module'],
            ['category' => 'App TOTVS', 'name' => 'Smart View', 'type' => 'module'],
            ['category' => 'Linguagens', 'name' => 'TL++', 'type' => 'technology'],
            ['category' => 'Linguagens', 'name' => 'ADVPL', 'type' => 'technology'],
            ['category' => 'Linguagens', 'name' => '.Net', 'type' => 'technology'],
            ['category' => 'Linguagens', 'name' => 'Java', 'type' => 'technology'],
            ['category' => 'Linguagens', 'name' => 'PHP', 'type' => 'technology'],
            ['category' => 'Linguagens', 'name' => 'JavaScript', 'type' => 'technology'],
            ['category' => 'Linguagens', 'name' => 'Python', 'type' => 'technology'],
            ['category' => 'Backend', 'name' => 'BackEnd: Nodejs', 'type' => 'technology'],
            ['category' => 'Backend', 'name' => 'BackEnd: Express', 'type' => 'technology'],
            ['category' => 'Backend', 'name' => 'BackEnd: Parse Server', 'type' => 'technology'],
            ['category' => 'Frontend', 'name' => 'FrontEnd: Angular', 'type' => 'technology'],
            ['category' => 'Frontend', 'name' => 'FrontEnd: Flutter', 'type' => 'technology'],
            ['category' => 'Frontend', 'name' => 'FrontEnd: PoUi', 'type' => 'technology'],
            ['category' => 'Banco de Dados', 'name' => 'Banco de Dados Microsot SQL', 'type' => 'technology'],
            ['category' => 'Banco de Dados', 'name' => 'Banco de Dados Postgre', 'type' => 'technology'],
            ['category' => 'Banco de Dados', 'name' => 'Banco de Dados MySQL', 'type' => 'technology'],
            ['category' => 'Banco de Dados', 'name' => 'Banco de Dados: Mongodb', 'type' => 'technology'],
            ['category' => 'Infraestrutura', 'name' => 'Redes (LAN / WLAN - WAN)', 'type' => 'technology'],
            ['category' => 'Infraestrutura', 'name' => 'Windows Server', 'type' => 'technology'],
            ['category' => 'Infraestrutura', 'name' => 'Sistemas Linux em Shell (Linha de Comando)', 'type' => 'technology'],
            ['category' => 'Infraestrutura', 'name' => 'Sistemas Linux em X (Interface Gráfica)', 'type' => 'technology'],
            ['category' => 'Ferramentas', 'name' => 'Good Data', 'type' => 'technology'],
            ['category' => 'Ferramentas', 'name' => 'Microsoft PowerBI', 'type' => 'technology'],
            ['category' => 'Ferramentas', 'name' => 'GitHub', 'type' => 'technology'],
        ];

        DB::transaction(function () use ($matrix) {
            $order = array_flip(self::CATEGORY_ORDER);
            foreach ($matrix as $i => $row) {
                Skill::firstOrCreate(
                    ['name' => $row['name'], 'category' => $row['category']],
                    ['type' => $row['type']]
                );
            }

            // Publica v1 se ainda não houver versão ativa.
            if (SkillMatrixVersion::where('status', 'active')->exists()) {
                return;
            }
            $skills = Skill::orderBy('category')->orderBy('name')->get();
            $version = SkillMatrixVersion::create([
                'number' => (int) (SkillMatrixVersion::max('number') ?? 0) + 1,
                'label' => 'Matriz ' . date('Y'),
                'status' => 'active',
                'skills_count' => $skills->count(),
                'published_at' => now(),
            ]);
            $sort = 0;
            foreach ($skills->sortBy(fn ($s) => [$order[$s->category] ?? 99, $s->name])->values() as $s) {
                SkillMatrixVersionItem::create([
                    'matrix_version_id' => $version->id,
                    'skill_id' => $s->id,
                    'category' => $s->category,
                    'name' => $s->name,
                    'skill_type' => $s->type,
                    'sort_order' => $sort++,
                ]);
            }
        });
    }
}
