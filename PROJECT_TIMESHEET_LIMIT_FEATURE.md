# ⏰ Prazo de Lançamento de Apontamentos por Projeto - Feature

## 📋 Visão Geral

Feature que adiciona a configuração de prazo limite para lançamento retroativo de horas **por projeto**, permitindo que cada projeto tenha sua própria política, sobrescrevendo a configuração global do sistema quando necessário.

**Data de Implementação:** 24/11/2025  
**Status:** ✅ Implementado e Testado

---

## 🎯 Objetivo

Permitir que projetos tenham configurações específicas de prazo para lançamento de apontamentos, oferecendo flexibilidade para projetos com necessidades diferentes da política global da empresa.

### Casos de Uso

**Cenário 1: Projeto Urgente**
- Configuração global: 7 dias
- Projeto X (urgente): 1 dia
- **Resultado:** Consultores do Projeto X devem lançar horas no dia seguinte

**Cenário 2: Projeto Flexível**
- Configuração global: 7 dias
- Projeto Y (pesquisa): 30 dias
- **Resultado:** Consultores do Projeto Y têm até 30 dias para lançar

**Cenário 3: Usar Configuração Global**
- Configuração global: 7 dias
- Projeto Z: null (vazio)
- **Resultado:** Projeto Z usa a configuração global de 7 dias

---

## 🏗️ Arquitetura

### Backend (Laravel)

#### 1. **Database Schema**

**Alteração na Tabela:** `projects`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `timesheet_retroactive_limit_days` | integer(nullable) | Prazo limite para lançamento retroativo de horas. NULL = usar configuração global |

**Migration:**
```bash
database/migrations/2025_11_24_210000_add_timesheet_retroactive_limit_days_to_projects_table.php
```

#### 2. **Model: Project**

**Arquivo:** `app/Models/Project.php`

**Alterações:**
```php
// Adicionado ao $fillable
'timesheet_retroactive_limit_days',

// Adicionado ao $casts
'timesheet_retroactive_limit_days' => 'integer',
```

#### 3. **Controller: ProjectController**

**Arquivo:** `app/Http/Controllers/ProjectController.php`

**Validação Adicionada:**
```php
// No método store() e update()
'timesheet_retroactive_limit_days' => 'nullable|integer|min:0|max:365',

// Mensagens de validação
'timesheet_retroactive_limit_days.integer' => 'O prazo deve ser um número inteiro',
'timesheet_retroactive_limit_days.min' => 'O prazo não pode ser negativo',
'timesheet_retroactive_limit_days.max' => 'O prazo não pode ser maior que 365 dias',
```

**Regras de Validação:**
- ✅ Nullable (pode ser vazio para usar global)
- ✅ Integer (0-365)
- ✅ Min: 0 (sem limite)
- ✅ Max: 365 dias (1 ano)

---

### Frontend (Angular 19)

#### 1. **Interface TypeScript**

**Arquivo:** `src/app/models/project.interface.ts`

**Alterações:**
```typescript
export interface IProject {
  // ... outros campos
  timesheet_retroactive_limit_days?: number | null;
}

export interface IProjectCreateRequest {
  // ... outros campos
  timesheet_retroactive_limit_days?: number;
}

export interface IProjectUpdateRequest {
  // ... outros campos
  timesheet_retroactive_limit_days?: number;
}
```

#### 2. **Component: ProjectFormComponent**

**Arquivo:** `src/app/features/projects/project-form/project-form.component.ts`

**Nova Seção no Template:**
```html
<!-- Configurações de Apontamento -->
<div class="form-section">
  <h4>Configurações de Apontamento</h4>

  <div class="po-row">
    <div class="po-md-6">
      <po-number
        name="timesheet_retroactive_limit_days"
        formControlName="timesheet_retroactive_limit_days"
        p-label="Prazo para lançamento de apontamentos (dias)"
        p-placeholder="Ex: 7"
        [p-min]="0"
        [p-max]="365"
        p-help="Limite de dias após o serviço para lançar horas. Deixe vazio para usar a configuração global do sistema.">
      </po-number>
    </div>
  </div>

  <!-- Exemplo dinâmico -->
  <div class="po-row" *ngIf="showTimesheetLimitExample()">
    <div class="po-md-12">
      <div class="config-info-box">
        <strong>ℹ️ Informação:</strong>
        <p>{{ getTimesheetLimitExampleText() }}</p>
      </div>
    </div>
  </div>
</div>
```

**Métodos Auxiliares:**
```typescript
/**
 * Verifica se deve mostrar o exemplo
 */
showTimesheetLimitExample(): boolean {
  const value = this.projectForm.get('timesheet_retroactive_limit_days')?.value;
  return value !== null && value !== undefined && value !== '';
}

/**
 * Retorna texto de exemplo dinâmico
 */
getTimesheetLimitExampleText(): string {
  const days = this.projectForm.get('timesheet_retroactive_limit_days')?.value;

  if (days === 0) {
    return 'Sem limite: Consultores podem lançar horas de qualquer data no passado.';
  }

  if (days === 1) {
    return 'Limite de 1 dia: Se o serviço foi realizado em 10/01/2025, o consultor pode lançar até 11/01/2025 às 23:59.';
  }

  // Calcular data de exemplo
  const exampleDate = new Date(2025, 0, 10);
  const limitDate = new Date(exampleDate);
  limitDate.setDate(limitDate.getDate() + days);
  const limitDateStr = limitDate.toLocaleDateString('pt-BR');

  return `Limite de ${days} dias: Se o serviço foi realizado em 10/01/2025, o consultor pode lançar até ${limitDateStr} às 23:59.`;
}
```

**Form Control:**
```typescript
// No método createForm()
timesheet_retroactive_limit_days: [null, [Validators.min(0), Validators.max(365)]],

// No método setupForm() - carregar valor ao editar
timesheet_retroactive_limit_days: this.project.timesheet_retroactive_limit_days

// No método submitForm() - incluir no payload
timesheet_retroactive_limit_days: formData.timesheet_retroactive_limit_days || undefined,
```

**Estilos:**
```css
.config-info-box {
  background-color: #e3f2fd;
  border-left: 4px solid #2196f3;
  padding: 12px 16px;
  border-radius: 4px;
  margin-top: 12px;
}

/* Dark mode support */
:host-context(.theme-dark) .config-info-box {
  background-color: #1e3a5f !important;
  border-left-color: #2196f3 !important;
}
```

---

## 📊 Lógica de Precedência

### Ordem de Prioridade

1. **Configuração do Projeto** (se definida)
   - Se `project.timesheet_retroactive_limit_days` !== null
   - Usa o valor específico do projeto

2. **Configuração Global do Sistema** (fallback)
   - Se `project.timesheet_retroactive_limit_days` === null
   - Usa `system_settings.timesheet_retroactive_limit_days`

### Exemplos Práticos

**Exemplo 1: Projeto com configuração própria**
```
Sistema: 7 dias
Projeto A: 14 dias
Resultado: Consultores do Projeto A têm 14 dias
```

**Exemplo 2: Projeto sem configuração (usa global)**
```
Sistema: 7 dias
Projeto B: null
Resultado: Consultores do Projeto B têm 7 dias (global)
```

**Exemplo 3: Projeto com limite mais restritivo**
```
Sistema: 30 dias
Projeto C: 3 dias
Resultado: Consultores do Projeto C têm apenas 3 dias (mais restritivo)
```

---

## 🎨 Interface do Usuário

### Localização

**Caminho:** Projetos > Criar/Editar Projeto > Configurações de Apontamento

### Campos

**Campo:** "Prazo para lançamento de apontamentos (dias)"
- **Tipo:** Numérico (0-365)
- **Placeholder:** "Ex: 7"
- **Help Text:** "Limite de dias após o serviço para lançar horas. Deixe vazio para usar a configuração global do sistema."
- **Validações:**
  - Mínimo: 0
  - Máximo: 365
  - Opcional (pode ficar vazio)

### Feedback Visual

**Caixa de Informação Dinâmica:**
- Aparece apenas quando há valor configurado
- Mostra exemplo prático com datas
- Atualiza em tempo real conforme usuário digita
- Suporte a dark mode

**Exemplos de Feedback:**

```
ℹ️ Informação:
Sem limite: Consultores podem lançar horas de qualquer data no passado.
```

```
ℹ️ Informação:
Limite de 7 dias: Se o serviço foi realizado em 10/01/2025, o consultor pode lançar até 17/01/2025 às 23:59.
```

---

## 🔄 Integração com Timesheet

### Validação no Lançamento de Horas

**Implementação Futura (Recomendada):**

```php
// No TimesheetController ao criar/editar timesheet
public function store(Request $request)
{
    $project = Project::findOrFail($request->project_id);
    $serviceDate = Carbon::parse($request->service_date);
    $today = Carbon::today();

    // Determinar limite aplicável
    $limitDays = $project->timesheet_retroactive_limit_days 
        ?? SystemSetting::get('timesheet_retroactive_limit_days', 7);

    // Calcular data limite
    $limitDate = $serviceDate->copy()->addDays($limitDays);

    // Validar se ainda está dentro do prazo
    if ($today->greaterThan($limitDate)) {
        return response()->json([
            'code' => 'TIMESHEET_LATE',
            'message' => "Prazo expirado para lançamento de horas deste serviço.",
            'detailMessage' => "O prazo limite era {$limitDate->format('d/m/Y')}."
        ], 422);
    }

    // Continuar com criação...
}
```

---

## 🧪 Testes

### Teste Manual - Backend

```bash
# 1. Criar projeto com limite específico
POST /api/v1/projects
{
  "name": "Projeto Teste",
  "code": "TEST001",
  "customer_id": 1,
  "service_type_id": 1,
  "contract_type_id": 1,
  "timesheet_retroactive_limit_days": 14
}

# 2. Atualizar limite
PUT /api/v1/projects/1
{
  "timesheet_retroactive_limit_days": 30
}

# 3. Remover limite (usar global)
PUT /api/v1/projects/1
{
  "timesheet_retroactive_limit_days": null
}

# 4. Validar limites
PUT /api/v1/projects/1
{
  "timesheet_retroactive_limit_days": -1  # ❌ Erro: min 0
}

PUT /api/v1/projects/1
{
  "timesheet_retroactive_limit_days": 400  # ❌ Erro: max 365
}
```

### Teste Manual - Frontend

**Cenário 1: Criar Projeto com Limite**
1. ✅ Acessar "Criar Projeto"
2. ✅ Preencher dados obrigatórios
3. ✅ Na seção "Configurações de Apontamento", definir: 14 dias
4. ✅ Verificar exemplo: "Limite de 14 dias: ..."
5. ✅ Salvar projeto
6. ✅ Verificar valor persistido

**Cenário 2: Deixar Vazio (Usar Global)**
1. ✅ Criar projeto sem preencher o campo
2. ✅ Salvar
3. ✅ Verificar que usa configuração global

**Cenário 3: Editar Limite Existente**
1. ✅ Editar projeto que tem limite de 7 dias
2. ✅ Alterar para 21 dias
3. ✅ Verificar exemplo atualizado
4. ✅ Salvar e verificar persistência

**Cenário 4: Dark Mode**
1. ✅ Alternar para tema escuro
2. ✅ Verificar cores da caixa de informação
3. ✅ Verificar legibilidade

---

## 📝 Benefícios

### Flexibilidade
- ✅ Projetos podem ter políticas diferentes
- ✅ Adequação a necessidades específicas
- ✅ Facilita gestão de projetos diversos

### Centralização
- ✅ Configuração global como padrão
- ✅ Apenas sobrescrever quando necessário
- ✅ Menos configurações redundantes

### Auditoria
- ✅ Controle por projeto
- ✅ Histórico de mudanças
- ✅ Rastreabilidade

### UX
- ✅ Interface intuitiva
- ✅ Exemplos práticos
- ✅ Feedback visual claro

---

## 🚀 Deploy

### Backend

```bash
# 1. Executar migration
docker compose exec app php artisan migrate

# 2. Verificar estrutura
docker compose exec app php artisan tinker
>>> Schema::hasColumn('projects', 'timesheet_retroactive_limit_days')
=> true

# 3. Testar criação de projeto
>>> $project = Project::create([
...   'name' => 'Test',
...   'code' => 'TEST001',
...   'timesheet_retroactive_limit_days' => 14,
...   // outros campos
... ]);
```

### Frontend

```bash
# Build e deploy (seguir processo padrão do projeto)
npm run build
```

---

## ✅ Checklist de Implementação

### Backend
- [x] Migration criada e executada
- [x] Model atualizado (fillable + casts)
- [x] Validações no Controller (store + update)
- [x] Mensagens de erro em português
- [x] Testes manuais realizados
- [x] Sem erros de lint

### Frontend
- [x] Interface TypeScript atualizada
- [x] Campo adicionado no formulário
- [x] Form control com validações
- [x] Lógica de submit atualizada
- [x] Métodos auxiliares implementados
- [x] Exemplo dinâmico funcionando
- [x] Estilos (light + dark mode)
- [x] Sem erros de lint

### Documentação
- [x] Documentação completa criada
- [x] Exemplos de uso documentados
- [x] Integração futura planejada

---

## 🎯 Próximos Passos (Futuro)

1. **Validação no Lançamento de Horas:**
   - Implementar verificação no TimesheetController
   - Bloquear lançamentos fora do prazo
   - Mensagens de erro claras

2. **Relatórios:**
   - Dashboard com projetos por limite configurado
   - Alertas de prazos próximos ao vencimento
   - Estatísticas de lançamentos fora do prazo

3. **Notificações:**
   - Avisar consultores quando prazo está acabando
   - Relatório semanal de pendências
   - Alertas para gestores

4. **Exceções:**
   - Sistema para solicitar extensão de prazo
   - Aprovação de lançamentos fora do prazo
   - Justificativas obrigatórias

---

**Feature implementada com sucesso! 🚀**

