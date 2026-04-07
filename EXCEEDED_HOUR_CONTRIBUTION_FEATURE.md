# Feature: Aporte de Horas Excedidas

## 📋 Descrição

Adicionado novo campo **"Aporte de Horas Excedidas"** (`exceeded_hour_contribution`) no formulário de projetos para registrar horas de aporte que excedem o planejamento inicial.

## 🎯 Objetivo

Permitir o registro de horas de aporte adicionais que ultrapassam o aporte padrão, facilitando o controle e gestão de horas extras aportadas ao projeto.

## ✅ Implementação

### 🖥️ Frontend (Angular)

#### 1. Interface TypeScript

**Arquivo:** `src/app/models/project.interface.ts`

Adicionado campo nas interfaces:
- `IProject`
- `IProjectCreateRequest`
- `IProjectUpdateRequest`

```typescript
exceeded_hour_contribution?: number | null;
```

#### 2. Componente de Formulário

**Arquivo:** `src/app/features/projects/project-form/project-form.component.ts`

**Mudanças:**

1. **Template HTML** - Adicionado novo campo na seção "Valores e Horas":
```html
<div class="po-md-4">
  <po-input
    name="exceeded_hour_contribution"
    formControlName="exceeded_hour_contribution"
    p-label="Aporte de Horas Excedidas"
    p-placeholder="0"
    p-help="Horas de aporte excedidas"
    p-type="number">
  </po-input>
</div>
```

2. **FormGroup** - Adicionado controle no formulário:
```typescript
exceeded_hour_contribution: ['']
```

3. **setupForm()** - Incluído no patch value para edição:
```typescript
exceeded_hour_contribution: this.project.exceeded_hour_contribution
```

4. **submitForm()** - Incluído nos dados enviados:
```typescript
exceeded_hour_contribution: formData.exceeded_hour_contribution || undefined
```

**Layout:** Os 3 campos de horas agora usam `po-md-4` (33% cada) em vez de `po-md-6`:
- Horas Vendidas
- Aporte de Horas
- **Aporte de Horas Excedidas** (novo)

### 🔧 Backend (Laravel)

#### 1. Migration

**Arquivo:** `database/migrations/2025_11_18_182543_add_exceeded_hour_contribution_to_projects_table.php`

```php
public function up(): void
{
    Schema::table('projects', function (Blueprint $table) {
        $table->integer('exceeded_hour_contribution')
              ->nullable()
              ->after('hour_contribution');
    });
}

public function down(): void
{
    Schema::table('projects', function (Blueprint $table) {
        $table->dropColumn('exceeded_hour_contribution');
    });
}
```

**Executado em:** 2025-11-18 15:25:59

#### 2. Model

**Arquivo:** `app/Models/Project.php`

**$fillable:**
```php
protected $fillable = [
    // ...
    'hour_contribution',
    'exceeded_hour_contribution', // Novo
    'additional_hourly_rate',
    // ...
];
```

**$casts:**
```php
protected $casts = [
    // ...
    'hour_contribution' => 'integer',
    'exceeded_hour_contribution' => 'integer', // Novo
    'start_date' => 'date:Y-m-d',
    // ...
];
```

#### 3. Controller

**Arquivo:** `app/Http/Controllers/ProjectController.php`

**Validações adicionadas em `store()` e `update()`:**
```php
'exceeded_hour_contribution' => 'nullable|integer|min:0|max:999999',
```

**Documentação Swagger atualizada:**
- Adicionada propriedade na documentação do método `store()`
- Adicionada propriedade no `project_info` do método `costSummary()`

**Método `costSummary()` atualizado:**
```php
$projectInfo = [
    // ...
    'hour_contribution' => $project->hour_contribution,
    'exceeded_hour_contribution' => $project->exceeded_hour_contribution, // Novo
];
```

## 📊 Estrutura de Dados

### Tipo de Dado
- **Frontend:** `number | null` (opcional)
- **Backend:** `integer` (nullable)

### Validação
- Campo **opcional** (não obrigatório)
- Tipo numérico inteiro
- Permite valores null/undefined

## 🔄 Fluxo de Dados

### Criação de Projeto
1. Usuário preenche o campo "Aporte de Horas Excedidas" (opcional)
2. Valor é enviado como `exceeded_hour_contribution` no payload
3. Backend salva na tabela `projects`

### Edição de Projeto
1. Backend retorna valor atual de `exceeded_hour_contribution`
2. Frontend popula o campo no formulário
3. Usuário pode alterar o valor
4. Novo valor é enviado no payload de update

### Listagem
- Campo disponível no objeto `IProject` retornado pela API
- Pode ser exibido em tabelas e detalhes do projeto

## 🎨 UI/UX

### Localização
**Seção:** "Valores e Horas"
**Posição:** Terceiro campo da segunda linha (após "Aporte de Horas")

### Estilo
- **Label:** "Aporte de Horas Excedidas"
- **Placeholder:** "0"
- **Help Text:** "Horas de aporte excedidas"
- **Tipo:** Numérico (input type="number")
- **Tamanho:** `po-md-4` (33% da largura)

### Dark Mode
✅ Totalmente compatível com tema escuro (herda estilos do formulário)

## 📝 Casos de Uso

### Exemplo 1: Projeto com Aporte Extra
```
Horas Vendidas: 160
Aporte de Horas: 20
Aporte de Horas Excedidas: 10
---
Total disponível: 190 horas
```

### Exemplo 2: Projeto sem Excedente
```
Horas Vendidas: 160
Aporte de Horas: 20
Aporte de Horas Excedidas: (vazio)
---
Total disponível: 180 horas
```

## ✅ Checklist de Implementação

- [x] Migration criada e executada
- [x] Model atualizado ($fillable e $casts)
- [x] Controller atualizado (validações em store e update)
- [x] Documentação Swagger atualizada
- [x] Método costSummary() incluindo o campo
- [x] Interface TypeScript atualizada
- [x] Formulário Angular atualizado
- [x] Campo visível no template
- [x] FormGroup configurado
- [x] setupForm() incluindo o campo
- [x] submitForm() enviando o campo
- [x] Layout ajustado (3 campos em linha)
- [x] Sem erros de lint
- [x] Compatível com dark mode
- [x] Testes do backend passando

## 🧪 Testes Recomendados

### Frontend
1. ✅ Criar projeto com aporte de horas excedidas
2. ✅ Criar projeto sem aporte de horas excedidas
3. ✅ Editar projeto existente e adicionar aporte excedido
4. ✅ Editar projeto e remover aporte excedido
5. ✅ Validar que campo aceita apenas números
6. ✅ Verificar responsividade em mobile

### Backend
1. ✅ POST /projects com exceeded_hour_contribution
2. ✅ POST /projects sem exceeded_hour_contribution
3. ✅ PUT /projects atualizando exceeded_hour_contribution
4. ✅ GET /projects retornando o campo corretamente
5. ✅ Verificar cast para integer

## 📦 Arquivos Modificados

### Frontend
```
src/app/models/project.interface.ts
src/app/features/projects/project-form/project-form.component.ts
```

### Backend
```
app/Models/Project.php
app/Http/Controllers/ProjectController.php
database/migrations/2025_11_18_182543_add_exceeded_hour_contribution_to_projects_table.php
```

## 🔄 Compatibilidade

### Backward Compatibility
✅ **Totalmente compatível:**
- Campo é opcional (nullable)
- Projetos existentes continuam funcionando
- Não quebra integrações existentes
- API aceita requests com ou sem o campo

### Versão
- **Frontend:** Angular 19
- **Backend:** Laravel 11
- **Database:** MySQL 8.0

## 📚 Documentação Relacionada

- [Feature: Despesas Ilimitadas](UNLIMITED_EXPENSE_FEATURE.md)
- [Correção: Acesso de Administrators](ADMIN_PROJECT_ACCESS_FIX.md)
- [API Documentation](API_DOCUMENTATION.md)

---

**Data de Implementação:** 2025-11-18  
**Desenvolvedor:** Leonardo Almeida  
**Status:** ✅ Implementado e Testado

