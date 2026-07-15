<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HOMOLOG: alinha o menu (nav_modules) ao padrão de PRODUÇÃO, PRESERVANDO os
 * menus/itens exclusivos do homolog (CRM, Help Desk, Cronograma) — "sobe os
 * menus e só preserva os que NÃO existem na base local (prod)".
 *
 * Regra: para cada módulo que existe no PROD E no homolog, items = itens do
 * PROD + itens TOP-LEVEL do homolog totalmente exclusivos (nenhum screen consta
 * no menu do prod). Módulos que só existem no homolog (crm, help_desk, ...)
 * ficam INTACTOS — e por existirem já ganham cor automática (moduleColor).
 *
 * Gated: só roda onde houver os módulos CRM/Help Desk (i.e., homolog).
 * Idempotente. down() é no-op (sem snapshot do estado anterior).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gate: só no homolog (onde existem os módulos exclusivos).
        $isHomolog = DB::table('nav_modules')->whereIn('key', ['crm', 'help_desk'])->exists();
        if (! $isHomolog) {
            return;
        }

        $prod = json_decode(self::PROD_MENU, true);
        if (! is_array($prod)) {
            return;
        }

        // Coleta TODOS os screens (recursivo) de uma lista de itens.
        $collect = function ($items, array &$set) use (&$collect): void {
            foreach ($items as $it) {
                if (isset($it['screen'])) {
                    $set[$it['screen']] = true;
                }
                if (isset($it['children']) && is_array($it['children'])) {
                    $collect($it['children'], $set);
                }
            }
        };

        foreach ($prod as $m) {
            $key = $m['key'] ?? null;
            if (! $key) {
                continue;
            }
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue; // módulo do prod que o homolog não tem → ignora
            }

            $prodItems = $m['items'] ?? [];
            $prodScreens = [];
            $collect($prodItems, $prodScreens);

            $homologItems = json_decode($row->items ?? '[]', true) ?: [];

            // Preserva itens/grupos TOP-LEVEL do homolog TOTALMENTE exclusivos
            // (nenhum screen do item aparece no menu do prod). Evita duplicar
            // cabeçalhos de grupo que o prod já traz.
            $exclusiveTop = [];
            foreach ($homologItems as $it) {
                $screens = [];
                $collect([$it], $screens);
                if (! $screens) {
                    continue;
                }
                $overlaps = false;
                foreach (array_keys($screens) as $s) {
                    if (isset($prodScreens[$s])) {
                        $overlaps = true;
                        break;
                    }
                }
                if (! $overlaps) {
                    $exclusiveTop[] = $it;
                }
            }

            $merged = array_merge($prodItems, $exclusiveTop);
            DB::table('nav_modules')->where('key', $key)->update([
                'items'      => json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sem snapshot do estado anterior — no-op.
    }

    private const PROD_MENU = <<<'PRODNAV'
[{"key":"administrativo","active":true,"profiles":["admin"],"items":[{"id":"n_administrativo_vercomo","screen":"\/ver-como","icon":"Eye"},{"id":"8f903ae9-dafa-49ee-8f9b-223d19fc6e39","screen":"\/inbox","icon":"MessageCircle"},{"id":"g_administrativo_0","label":"Gest\u00e3o Contratual","icon":"FileText","children":[{"id":"n_administrativo_1","screen":"\/gestao-projetos","icon":"LayoutGrid"},{"id":"n_administrativo_2","screen":"\/contratos\/kanban","icon":"SquareKanban"},{"id":"n_administrativo_19","screen":"\/relatorios\/rentabilidade","icon":"ChartPie"},{"id":"n_administrativo_18","screen":"\/relatorios\/rentabilidade\/projeto","icon":"ChartPie"},{"id":"n_administrativo_17","screen":"\/relatorios\/rentabilidade\/consultor","icon":"ChartPie"}]},{"id":"g_administrativo_4","label":"Fechamento","icon":"ChartColumn","children":[{"id":"n_administrativo_5","screen":"\/fechamento","icon":"ChartColumn"},{"id":"n_administrativo_6","screen":"\/fechamento\/cliente","icon":"Building2"},{"id":"n_administrativo_7","screen":"\/fechamento\/parceiro","icon":"Handshake"},{"id":"n_administrativo_8","screen":"\/fechamento\/consultor","icon":"UserCheck"},{"id":"n_administrativo_9","screen":"\/fechamento\/adiantamentos","icon":"Banknote"},{"id":"n_administrativo_10","screen":"\/fechamento\/diretoria","icon":"Briefcase"},{"id":"n_administrativo_11","screen":"\/fechamento\/folha","icon":"FileSpreadsheet"},{"id":"n_administrativo_12","screen":"\/fechamento\/contratos","icon":"FileText"},{"id":"n_administrativo_13","screen":"\/fechamento\/reajustes","icon":"TrendingUp"},{"id":"n_administrativo_excedentes","screen":"\/fechamento\/excedentes","icon":"Clock"},{"id":"n_administrativo_14","screen":"\/pagamento-despesas","icon":"CreditCard"}]},{"id":"g_administrativo_15","label":"Relat\u00f3rios","icon":"ChartPie","children":[{"id":"n_administrativo_16","screen":"\/relatorios\/pagamentos","icon":"Banknote"},{"id":"n_administrativo_20","screen":"\/relatorios\/contratos-sem-vencimento","icon":"CalendarX"}]},{"id":"g_administrativo_21","label":"Cadastros","icon":"Database","children":[{"id":"n_administrativo_22","screen":"\/clientes","icon":"Building2"},{"id":"n_administrativo_31","screen":"\/cadastros?tab=groups","icon":"Users"},{"id":"n_administrativo_32","screen":"\/cadastros?tab=customer_contacts","icon":"Contact"}]},{"id":"g_administrativo_35","label":"Comunica\u00e7\u00e3o","icon":"Megaphone","children":[{"id":"n_administrativo_36","screen":"\/central-comunicacao","icon":"Megaphone"},{"id":"n_administrativo_37","screen":"\/cadastros?tab=email_templates","icon":"Mail"}]},{"id":"g_administrativo_50","label":"Sistema","icon":"Settings","hidden":true,"children":[{"id":"n_administrativo_51","screen":"\/users","icon":"Users","hidden":true},{"id":"n_administrativo_52","screen":"\/settings","icon":"Settings","hidden":true},{"id":"n_administrativo_liberacao_pipeline","screen":"\/liberacao-pipeline","icon":"SlidersHorizontal"},{"id":"n_administrativo_cargos","screen":"\/settings?tab=cargos","icon":"Briefcase"}]}]}, {"key":"administrativo__administrativo","active":true,"profiles":["administrativo"],"items":[{"id":"n_adm_0","screen":"\/inicio","icon":"House"},{"id":"86479e91-c286-4e14-b816-1ba7b43f117b","screen":"\/ver-como","icon":"Eye"},{"id":"n_adm_2","screen":"\/gestao-projetos","icon":"Layers"},{"id":"n_adm_3","screen":"\/contratos\/kanban","icon":"LayoutGrid"},{"id":"n_adm_1","label":"Apontamentos & Despesas","icon":"Clock","children":[{"id":"n_adm_1_0","screen":"\/timesheets","icon":"Clock"},{"id":"n_adm_1_1","screen":"\/expenses","icon":"Receipt"}]},{"id":"n_adm_4","label":"Fechamento","icon":"DollarSign","children":[{"id":"n_adm_4_0","screen":"\/fechamento","icon":"ChartColumn"},{"id":"n_adm_4_1","screen":"\/fechamento\/cliente","icon":"Building2"},{"id":"n_adm_4_2","screen":"\/fechamento\/parceiro","icon":"Handshake"},{"id":"n_adm_4_3","screen":"\/fechamento\/consultor","icon":"Users"},{"id":"n_adm_4_4","screen":"\/fechamento\/excedentes","icon":"Clock"},{"id":"n_adm_4_5","screen":"\/fechamento\/adiantamentos","icon":"Banknote"},{"id":"n_adm_4_6","screen":"\/fechamento\/diretoria","icon":"Briefcase"},{"id":"n_adm_4_7","screen":"\/fechamento\/folha","icon":"FileSpreadsheet"},{"id":"n_adm_4_8","screen":"\/fechamento\/contratos","icon":"FileText"},{"id":"n_adm_4_9","screen":"\/fechamento\/reajustes","icon":"TrendingUp"},{"id":"n_adm_4_10","screen":"\/pagamento-despesas","icon":"DollarSign"}]},{"id":"n_adm_5","label":"Cadastros","icon":"Database","children":[{"id":"n_adm_5_0","screen":"\/clientes","icon":"Building2"},{"id":"e2851124-44c7-41ba-99df-6223bf8b8e26","screen":"\/cadastros?tab=customer_contacts","icon":"Contact"}]},{"id":"n_adm_6","screen":"\/users","icon":"Users"}]}, {"key":"cliente","active":true,"profiles":["cliente"],"items":[{"id":"n_cliente_1","screen":"\/comunicados","icon":"Bell"},{"id":"n_cliente_0","screen":"\/portal-cliente","icon":"Globe"},{"id":"n_cliente_2","screen":"\/contratos\/pipeline","icon":"FolderKanban"},{"id":"g_cliente_4","label":"Contratos","icon":"FileText","children":[{"id":"n_cliente_5","screen":"\/dashboards\/bank-hours-fixed","icon":"Gauge"},{"id":"n_cliente_6","screen":"\/dashboards\/bank-hours-monthly","icon":"Gauge"},{"id":"n_cliente_7","screen":"\/dashboards\/on-demand","icon":"Gauge"},{"id":"n_cliente_8","screen":"\/dashboards\/fechado","icon":"Gauge"}]}]}, {"key":"configurador","active":true,"profiles":["admin"],"items":[{"id":"a2bf3df0-b7d2-4ed4-a4eb-aa4b1e2cc35a","screen":"\/ver-como","icon":"Eye"},{"id":"g_conf_1","label":"Sistema","icon":"Settings","children":[{"id":"n_3_0","screen":"\/configurador","icon":"SlidersHorizontal"},{"id":"n_3_1","screen":"\/settings?tab=perfis","icon":"UserCog"},{"id":"n_administrativo_38","screen":"\/cadastros\/workflows","icon":"Workflow"},{"id":"n_configurador_liberacao_pipeline","screen":"\/liberacao-pipeline","icon":"SlidersHorizontal"},{"id":"n_configurador_cargos","screen":"\/settings?tab=cargos","icon":"Briefcase"}]},{"id":"g_conf_2","label":"Integra\u00e7\u00f5es","icon":"Plug","children":[{"id":"n_administrativo_34","screen":"\/configuracoes\/movidesk","icon":"Plug"},{"id":"n_administrativo_33","screen":"\/cadastros\/saldo-inicial-tickets","icon":"Ticket"}]},{"id":"g_conf_3","label":"Cadastros Gerais","icon":"Database","children":[{"id":"n_administrativo_27","screen":"\/cadastros?tab=contracts","icon":"FileText"},{"id":"n_administrativo_28","screen":"\/cadastros?tab=services","icon":"Wrench"},{"id":"n_administrativo_29","screen":"\/cadastros?tab=expense_types","icon":"Tag"},{"id":"n_administrativo_30","screen":"\/cadastros?tab=expense_categories","icon":"Tags"},{"id":"72b75ce2-486b-4dcc-8573-b7450ad7ef3a","screen":"\/cadastros?tab=payment_methods","icon":"CreditCard"},{"id":"n_administrativo_26","screen":"\/cadastros?tab=holidays","icon":"CalendarDays"},{"id":"9274a522-9528-4f86-a988-2026464f582d","screen":"\/cadastros?tab=groups","icon":"Users"},{"id":"n_administrativo_24","screen":"\/partners","icon":"Handshake"},{"id":"n_administrativo_23","screen":"\/cadastros?tab=executives","icon":"UserCog"}]},{"id":"cb29003e-c583-4c23-8815-c0e4e638bc29","label":"BOT Minutor","icon":"Bot","children":[{"id":"8c5b408c-6e52-4cee-ae4c-4b1191f2253f","screen":"\/configuracoes\/bot-minutor","icon":"Bot"},{"id":"1e6dcb7f-03c3-4809-9d54-bbfc4f75677c","screen":"\/feed-operacional","icon":"Activity"},{"id":"a8956943-17f2-4c21-880e-1421198eaa20","screen":"\/inbox","icon":"MessageCircle"}]},{"id":"13c2a748-93aa-4227-b882-6fb287d9237b","screen":"\/users","icon":"Users"}]}, {"key":"consultor","active":false,"profiles":[],"items":[{"id":"n_consultor_0","screen":"\/inicio","icon":"House"},{"id":"n_consultor_1","screen":"\/meu-painel","icon":"LayoutDashboard"},{"id":"n_consultor_2","screen":"\/timesheets","icon":"Clock"},{"id":"n_consultor_3","screen":"\/approvals","icon":"CircleCheck"},{"id":"n_consultor_4","screen":"\/settings","icon":"Settings"}]}, {"key":"consultor__banco_de_horas","active":true,"profiles":["consultor_banco_de_horas"],"items":[{"id":"n_consultor_banco_de_horas_0","screen":"\/inicio","icon":"House"},{"id":"n_consultor_banco_de_horas_1","screen":"\/meu-painel","icon":"LayoutDashboard"}]}, {"key":"consultor__fixo","active":true,"profiles":["consultor_fixo"],"items":[{"id":"n_consultor_fixo_0","screen":"\/inicio","icon":"House"},{"id":"n_consultor_fixo_1","screen":"\/meu-painel","icon":"LayoutDashboard"}]}, {"key":"consultor__horista","active":true,"profiles":["consultor_horista"],"items":[{"id":"n_consultor_horista_0","screen":"\/inicio","icon":"House"},{"id":"n_consultor_horista_1","screen":"\/meu-painel","icon":"LayoutDashboard"}]}, {"key":"parceiro","active":false,"profiles":[],"items":[{"id":"n_parceiro_0","screen":"\/partner-dashboard","icon":"Gauge"},{"id":"n_parceiro_1","screen":"\/meu-painel","icon":"LayoutDashboard"},{"id":"51a6c2f5-d198-4c6b-bf56-3c37ec282c33","screen":"\/inicio","icon":"House"},{"id":"5c7f67e0-88e9-4c45-b441-1636fce6b39f","screen":"\/inicio","icon":"House"}]}, {"key":"parceiro__gestor","active":true,"profiles":["parceiro_gestor"],"items":[{"id":"d6fa2b01-c73a-43c8-b073-8582183a6f24","screen":"\/inicio","icon":"House"},{"id":"n_parceiro_gestor_0","screen":"\/partner-dashboard","icon":"Gauge"},{"id":"c4d27067-8742-4f4a-ac79-bc2c9ff67d16","screen":"\/contratos\/pipeline","icon":"FolderKanban","hidden":true,"users":[163]}]}, {"key":"parceiro__simples","active":true,"profiles":["parceiro_simples"],"items":[{"id":"1c9ca636-1121-4c67-bac2-2d13b1f263f1","screen":"\/inicio","icon":"House"},{"id":"n_parceiro_simples_0","screen":"\/meu-painel","icon":"LayoutDashboard"}]}, {"key":"servicos","active":true,"profiles":["admin"],"items":[{"id":"g_servicos_0","label":"Projetos","icon":"FolderKanban","children":[{"id":"n_servicos_1","screen":"\/contratos\/pipeline","icon":"FolderKanban"},{"id":"n_servicos_2","screen":"\/investimento-comercial","icon":"Rocket"}]},{"id":"g_servicos_3","label":"Sustenta\u00e7\u00e3o","icon":"Headphones","children":[{"id":"n_servicos_4","screen":"\/sustentacao","icon":"Headphones"},{"id":"n_servicos_10","screen":"\/timesheets\/atrasos","icon":"AlarmClock"}]},{"id":"g_servicos_5","label":"Apontamentos & Despesas","icon":"Clock","children":[{"id":"n_servicos_6","screen":"\/timesheets","icon":"Clock"},{"id":"n_servicos_7","screen":"\/expenses","icon":"Receipt"},{"id":"n_servicos_9","screen":"\/approvals","icon":"CircleCheck"},{"id":"n_servicos_8","screen":"\/relatorios\/apontamentos","icon":"FileClock"},{"id":"n_servicos_11","screen":"\/auditoria\/apontamentos","icon":"ShieldCheck"}]}]}, {"key":"servicos__coordenador_projetos","active":true,"profiles":["coordenador_projetos"],"items":[{"id":"n_cproj_1","screen":"\/inicio","icon":"House"},{"id":"n_cproj_0","screen":"\/meu-painel","icon":"LayoutDashboard"},{"id":"n_cproj_2","screen":"\/contratos\/pipeline","icon":"LayoutGrid"},{"id":"n_cproj_4","screen":"\/investimento-comercial","icon":"TrendingUp"},{"id":"n_cproj_3","label":"Apontamentos & Despesas","icon":"Clock","children":[{"id":"n_cproj_3_0","screen":"\/timesheets","icon":"Clock"},{"id":"n_cproj_3_1","screen":"\/expenses","icon":"Receipt"},{"id":"n_cproj_3_2","screen":"\/approvals","icon":"CircleCheck"},{"id":"n_cproj_3_4","screen":"\/auditoria\/apontamentos","icon":"FileText"}]},{"id":"n_cproj_5","label":"Cadastros","icon":"Database","children":[{"id":"n_cproj_5_0","screen":"\/clientes","icon":"Users"},{"id":"8f845d9f-3d87-4431-81d3-d9e67b0c1d18","screen":"\/cadastros?tab=groups","icon":"Users"},{"id":"4ce461d9-832c-4cc5-83ab-0606f394227b","screen":"\/cadastros?tab=customer_contacts","icon":"Contact"},{"id":"ca7d8e10-54c3-4d57-b33b-cbe66d77a0b2","screen":"\/contratos\/pipeline","icon":"FolderKanban"}]},{"id":"n_cproj_6","screen":"\/users","icon":"Users"}]}, {"key":"servicos__coordenador_sustentacao","active":true,"profiles":["coordenador_sustentacao"],"items":[{"id":"n_csust_1","screen":"\/inicio","icon":"House"},{"id":"n_csust_0","screen":"\/meu-painel","icon":"LayoutDashboard"},{"id":"n_csust_2_0","screen":"\/sustentacao","icon":"Headphones"},{"id":"6194ab64-7fe4-40e8-aa7a-278f10af9776","screen":"\/relatorios\/apontamentos","icon":"FileClock"},{"id":"n_csust_kanban","screen":"\/contratos\/kanban","icon":"LayoutGrid"},{"id":"n_csust_4","screen":"\/investimento-comercial","icon":"TrendingUp"},{"id":"aec9cab8-1e3a-4ffa-9329-33d6bf9a18ae","screen":"\/timesheets\/atrasos","icon":"AlarmClock"},{"id":"n_csust_5","label":"Cadastros","icon":"Database","children":[{"id":"n_csust_5_0","screen":"\/clientes","icon":"Users"},{"id":"16feec01-6742-43ea-9980-41e2015be886","screen":"\/cadastros?tab=groups","icon":"Users"},{"id":"f7537e2e-7af1-46a0-b226-7103df75ceb0","screen":"\/cadastros?tab=customer_contacts","icon":"Contact"}]},{"id":"n_csust_6","screen":"\/users","icon":"Users"}]}]
PRODNAV;
};
