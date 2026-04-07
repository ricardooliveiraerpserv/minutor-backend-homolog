# Correção de Busca de Projetos para Administrators

## Problema Identificado

Quando um usuário Administrator selecionava outro usuário (incluindo outro Administrator) em formulários de despesas ou apontamentos de horas, o sistema exibia a mensagem "Você não é consultor em nenhum projeto deste cliente", mesmo que o usuário selecionado fosse Administrator.

## Causa Raiz

O filtro `consultant_only=true` estava sendo aplicado indiscriminadamente a todos os usuários, incluindo Administrators, sem verificar se:
1. O usuário logado era Administrator
2. O usuário selecionado no formulário era Administrator

## Solução Implementada

### Backend (Laravel)

**Arquivo:** `app/Http/Controllers/ProjectController.php`

**Método:** `index()`

**Lógica Implementada:**

```php
if ($consultantOnly === 'true') {
    $currentUser = auth()->user();
    $requestedUserId = $request->get('user_id');
    
    // Determinar qual usuário usar para o filtro
    $targetUserId = $currentUser->id;
    $targetUser = $currentUser;
    
    // Se admin forneceu user_id, usar esse usuário
    if ($requestedUserId && $currentUser->hasRole('Administrator')) {
        $targetUserId = $requestedUserId;
        $targetUser = \App\Models\User::find($targetUserId);
    }
    
    // Apenas aplicar filtro se o usuário alvo NÃO for Administrator
    if ($targetUser && !$targetUser->hasRole('Administrator')) {
        $query->whereHas('consultants', function ($q) use ($targetUserId) {
            $q->where('user_id', $targetUserId);
        });
    }
    // Se o usuário alvo for Administrator, não aplica filtro (vê todos os projetos)
}
```

**Comportamento:**
- Se o usuário logado for Administrator → vê TODOS os projetos
- Se um Administrator selecionar outro Administrator no formulário → o sistema busca projetos usando o user_id fornecido, e como o usuário alvo também é Administrator, não aplica filtro
- Se um Administrator selecionar um usuário comum → filtra apenas projetos onde esse usuário é consultor

### Frontend (Angular)

#### 1. ProjectService

**Arquivo:** `src/app/core/services/project.service.ts`

**Método:** `getConsultantProjectsByCustomer()`

**Mudança:**
```typescript
// Antes
getConsultantProjectsByCustomer(customerId?: number): Observable<IProject[]>

// Depois
getConsultantProjectsByCustomer(customerId?: number, userId?: number): Observable<IProject[]>
```

Agora aceita um parâmetro opcional `userId` que é enviado ao backend como `user_id`.

#### 2. ExpenseFormComponent

**Arquivo:** `src/app/features/expenses/expense-form/expense-form.component.ts`

**Método:** `loadConsultantProjectsByCustomer()`

**Mudança:**
```typescript
private loadConsultantProjectsByCustomer(customerId: number): void {
  // Se é admin e tem usuário selecionado, passar o user_id
  const selectedUserId = this.permissionService.isAdmin() 
    ? this.expenseForm.get('user_id')?.value 
    : undefined;
  
  this.projectService.getConsultantProjectsByCustomer(customerId, selectedUserId)
    .subscribe({
      // ...
    });
}
```

#### 3. TimesheetFormComponent

**Arquivo:** `src/app/features/timesheets/timesheet-form/timesheet-form.component.ts`

**Método:** `loadConsultantProjectsByCustomer()`

**Mudança:** Mesma implementação do ExpenseFormComponent.

## Cenários de Teste

### Cenário 1: Administrator Logado
- **Situação:** Administrator fazendo uma despesa para si mesmo
- **Resultado:** Vê TODOS os projetos de um cliente selecionado

### Cenário 2: Administrator Selecionando Outro Administrator
- **Situação:** Administrator A criando uma despesa para o Administrator B
- **Resultado:** Lista TODOS os projetos do cliente (não aplica filtro de consultor)

### Cenário 3: Administrator Selecionando Usuário Comum
- **Situação:** Administrator criando uma despesa para um Consultor
- **Resultado:** Lista apenas projetos onde o Consultor é membro

### Cenário 4: Usuário Comum
- **Situação:** Consultor criando uma despesa para si mesmo
- **Resultado:** Lista apenas projetos onde ele é consultor

## Impacto

### Formulários Afetados
- ✅ Expense Form (Despesas)
- ✅ Timesheet Form (Apontamento de Horas)

### Endpoints Afetados
- `GET /api/v1/projects?consultant_only=true&customer_id={id}&user_id={id}`

## Arquivos Modificados

### Backend
- `app/Http/Controllers/ProjectController.php`

### Frontend
- `src/app/core/services/project.service.ts`
- `src/app/features/expenses/expense-form/expense-form.component.ts`
- `src/app/features/timesheets/timesheet-form/timesheet-form.component.ts`

## Compatibilidade

Esta alteração é **backward compatible**:
- O parâmetro `user_id` é opcional
- Se não fornecido, usa o comportamento padrão (usuário logado)
- Usuários comuns não podem fornecer `user_id` diferente do seu próprio

## Segurança

✅ **Verificado:** Apenas Administrators podem fornecer `user_id` diferente do próprio:

```php
if ($requestedUserId && $currentUser->hasRole('Administrator')) {
    // Apenas admins podem buscar por outros usuários
}
```

---

**Data:** 2025-01-17  
**Autor:** Leonardo Almeida  
**Versão:** 1.0

