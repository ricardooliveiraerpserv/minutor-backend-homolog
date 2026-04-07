# 📋 Plano de Implementação: Usuários de Clientes e Dashboards

## 🎯 Objetivo

Criar um sistema que permita que clientes cadastrados tenham usuários específicos com acesso a dashboards personalizados por tipo de cliente.

---

## 🤔 Decisões de Arquitetura

### 1. Relacionamento Usuário-Cliente

**DECISÃO: Relacionamento no cadastro de USUÁRIO**

**Justificativa:**
- ✅ Mais flexível: um usuário pode ser vinculado a um cliente específico OU ser um usuário interno (sem cliente)
- ✅ Melhor UX: ao criar usuário, já definimos se é "usuário interno" ou "usuário de cliente"
- ✅ Escalável: permite futuramente múltiplos clientes por usuário (se necessário)
- ✅ Consistente: segue o padrão já existente (usuários têm roles, projetos, etc.)

**Implementação:**
- Adicionar campo `customer_id` (nullable) na tabela `users`
- Se `customer_id` for NULL = usuário interno do sistema
- Se `customer_id` for preenchido = usuário específico do cliente

**Alternativa considerada (e descartada):**
- ❌ Criar usuários no cadastro do cliente: menos flexível, mistura responsabilidades

---

### 2. Estrutura de Permissões

**DECISÃO: Hierarquia de Permissões para Dashboards**

**Estrutura proposta:**
```
dashboards.view                    # Permissão geral para ver seção de dashboards
dashboards.{type}.view             # Permissão específica por tipo de dashboard
dashboards.{type}.page1            # Permissão para página específica (se necessário)
dashboards.{type}.page2
dashboards.{type}.page3
```

**Exemplos:**
- `dashboards.view` - Acesso geral à seção
- `dashboards.customer_financial.view` - Dashboard financeiro do cliente
- `dashboards.customer_projects.view` - Dashboard de projetos do cliente
- `dashboards.customer_timesheets.view` - Dashboard de horas do cliente

**Implementação:**
- Criar permissões no `PermissionSeeder`
- Atribuir permissões aos roles apropriados
- Usuários de cliente recebem role específico (ex: "Customer User") com permissões de dashboard

---

### 3. Estrutura de Menu e Navegação

**DECISÃO: Seção "Dashboards" no menu lateral com subitens**

**Estrutura no menu:**
```
📊 Dashboards
  ├── Dashboard Financeiro
  ├── Dashboard de Projetos
  ├── Dashboard de Horas
  └── ... (outros tipos conforme necessário)
```

**Implementação:**
- Adicionar seção "Dashboards" no `main-layout.component.ts`
- Similar à seção "Cadastros" (linhas 429-436)
- Subitens dinâmicos baseados nas permissões do usuário
- Cada subitem leva para o dashboard específico

---

### 4. Organização de Páginas por Dashboard

**DECISÃO: Abas dentro de cada componente de dashboard**

**Justificativa:**
- ✅ Melhor UX: todas as informações relacionadas em um único lugar
- ✅ Navegação mais fluida: sem recarregar página
- ✅ Consistente com padrões modernos de UI
- ✅ Facilita manutenção: um componente por tipo de dashboard

**Estrutura:**
```
/dashboards/financial
  ├── Aba 1: Visão Geral
  ├── Aba 2: Detalhamento
  └── Aba 3: Relatórios

/dashboards/projects
  ├── Aba 1: Status dos Projetos
  ├── Aba 2: Timeline
  └── Aba 3: Métricas
```

**Implementação:**
- Usar `po-tabs` do PO-UI para navegação entre abas
- Cada aba é um componente separado (reutilizável)
- Roteamento interno via query params ou state

---

## 📐 Estrutura de Banco de Dados

### ✅ Migration: Adicionar customer_id em users

**Arquivo:** `2026_01_14_003709_add_customer_id_to_users_table.php`

```php
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('customer_id')
          ->nullable()
          ->after('id')
          ->constrained('customers')
          ->onDelete('set null')
          ->comment('ID do cliente associado ao usuário (null para usuários internos)');
    
    $table->index('customer_id');
});
```

**Regras:**
- ✅ `customer_id` é nullable (usuários internos não têm cliente)
- ✅ Foreign key com `onDelete('set null')` (se cliente for deletado, usuário permanece mas sem cliente)
- ✅ Índice para performance em queries

### ✅ Migration: Tabela pivot user_dashboard_types

**Arquivo:** `2026_01_14_003716_create_user_dashboard_types_table.php`

**Estrutura:**
- `user_id` (foreign key para users)
- `dashboard_type` (string, ex: 'bank_hours_fixed')
- Constraint único: um usuário não pode ter o mesmo tipo de dashboard duplicado
- Índices para performance

**Justificativa:** Permite que cada usuário tenha acesso a tipos específicos de dashboard, não necessariamente todos.

---

## 🔐 Permissões Criadas

### Permissões Gerais
- ✅ `dashboards.view` - Acesso geral à seção de dashboards (mostrar seção no menu e validar endpoints)

### Permissões por Tipo de Dashboard
- ✅ `dashboards.bank_hours_fixed.view` - Permissão específica para dashboard de banco de horas fixo (mostrar subitem no menu e passar pelo guard de roteamento)

**Nota:** Mais tipos de dashboard podem ser adicionados conforme necessário seguindo o padrão `dashboards.{tipo}.view`.

---

## 🎨 Estrutura de Arquivos (Frontend)

```
src/app/features/
  └── dashboards/
      ├── dashboards.routes.ts
      ├── dashboards.module.ts (se necessário)
      ├── financial-dashboard/
      │   ├── financial-dashboard.component.ts
      │   ├── financial-dashboard.component.html
      │   ├── financial-dashboard.component.scss
      │   ├── overview-tab/
      │   ├── details-tab/
      │   └── reports-tab/
      ├── projects-dashboard/
      │   └── ...
      └── timesheets-dashboard/
          └── ...
```

---

## 🚀 Plano de Implementação (Fases)

### **FASE 1: Backend - Estrutura Base**
1. ✅ Criar migration para adicionar `customer_id` em `users`
2. ✅ Criar migration para tabela pivot `user_dashboard_types`
3. ✅ Atualizar model `User` com relacionamento `customer()` e métodos de dashboard
4. ✅ Atualizar model `Customer` com relacionamento `users()`
5. ✅ Criar permissões no `PermissionSeeder`
6. ✅ Atualizar `UserController` para aceitar `customer_id` e `dashboard_types` no create/update
7. ✅ Atualizar métodos `index` e `show` para incluir `customer` e `dashboard_types` nas respostas
8. ⏳ Criar role "Customer User" no seeder (próximo passo)
9. ⏳ Atualizar validações e policies (se necessário)

### **FASE 2: Backend - API de Dashboards**
1. ✅ Criar `DashboardController` com endpoints base
2. ✅ Implementar filtros por `customer_id` (usuário só vê dados do seu cliente)
3. ✅ Criar endpoints específicos por tipo de dashboard
4. ✅ Implementar segurança (usuário só acessa dados do seu cliente)

### **FASE 3: Frontend - Estrutura Base**
1. ✅ Atualizar interface `IUser` com `customer_id`
2. ✅ Atualizar `user-form.component.ts` para incluir campo de cliente
3. ✅ Criar módulo de dashboards
4. ✅ Adicionar seção "Dashboards" no menu lateral
5. ✅ Criar rotas para dashboards

### **FASE 4: Frontend - Componentes de Dashboard**
1. ✅ Criar componente base de dashboard
2. ✅ Implementar `financial-dashboard` com abas
3. ✅ Implementar `projects-dashboard` com abas
4. ✅ Implementar `timesheets-dashboard` com abas
5. ✅ Implementar guards de permissão para rotas

### **FASE 5: Testes e Refinamentos**
1. ✅ Testes de integração backend
2. ✅ Testes de permissões
3. ✅ Testes de segurança (usuário não acessa dados de outro cliente)
4. ✅ Ajustes de UX/UI
5. ✅ Documentação

---

## 🔒 Regras de Segurança

### Backend
1. **Validação de acesso:**
   - Usuário com `customer_id` só pode ver dados do seu cliente
   - Usuários internos (sem `customer_id`) podem ver todos os dados (se tiverem permissão)

2. **Middleware de autorização:**
   - Criar middleware `EnsureCustomerAccess` para validar acesso
   - Aplicar em todas as rotas de dashboard

3. **Policies:**
   - Criar `DashboardPolicy` para centralizar regras de acesso

### Frontend
1. **Guards:**
   - `PermissionGuard` já existe, usar para proteger rotas
   - Validar `dashboards.view` e permissão específica do tipo

2. **Filtros automáticos:**
   - Service de dashboard filtra automaticamente por `customer_id` do usuário logado

---

## 📝 Próximos Passos

1. **Revisar este plano** e validar decisões
2. **Definir tipos de dashboards** específicos que serão implementados
3. **Definir estrutura de dados** de cada dashboard (quais informações mostrar)
4. **Iniciar FASE 1** (Backend - Estrutura Base)

---

## ❓ Questões para Decidir

1. **Tipos de Dashboard:** Quais tipos específicos de dashboard serão criados inicialmente?
2. **Dados por Dashboard:** Quais informações/metricas cada dashboard deve mostrar?
3. **Role Padrão:** Qual role será atribuído automaticamente aos usuários de cliente?
4. **Múltiplos Clientes:** Um usuário pode ter acesso a múltiplos clientes? (futuro)

---

**Status:** 📋 Plano criado - Aguardando validação e início da implementação
