# 👥 Feature: Grupos de Consultores

## 📋 Visão Geral

Esta feature permite que usuários com permissões adequadas criem e gerenciem grupos de consultores. Um grupo de consultores é uma forma de organizar usuários que possuem a role "Consultant" em equipes ou times específicos.

## 🎯 Objetivo

Permitir que administradores e usuários autorizados organizem consultores em grupos para facilitar:
- Gestão de equipes
- Atribuição em massa de consultores a projetos
- Organização hierárquica de times
- Relatórios por grupo

## 🔑 Permissões

### Novas Permissões Criadas

```
consultant_groups.view    - Visualizar grupos de consultores
consultant_groups.create  - Criar novos grupos
consultant_groups.update  - Editar grupos existentes
consultant_groups.delete  - Excluir grupos
```

### Acesso Especial

Usuários com a permissão `admin.full_access` têm acesso total a todas as operações de grupos de consultores, independentemente das permissões específicas.

## 📊 Estrutura do Banco de Dados

### Tabela: `consultant_groups`

| Campo       | Tipo        | Descrição                                  |
|-------------|-------------|--------------------------------------------|
| id          | BIGINT      | Identificador único                        |
| name        | VARCHAR     | Nome do grupo (obrigatório, único)         |
| description | TEXT        | Descrição opcional do grupo                |
| active      | BOOLEAN     | Status ativo/inativo (padrão: true)        |
| created_by  | BIGINT      | ID do usuário que criou o grupo            |
| created_at  | TIMESTAMP   | Data de criação                            |
| updated_at  | TIMESTAMP   | Data da última atualização                 |
| deleted_at  | TIMESTAMP   | Data de exclusão (soft delete)             |

**Índices:**
- `name` - Para buscas por nome
- `active` - Para filtros de status

### Tabela Pivot: `consultant_group_user`

| Campo                 | Tipo      | Descrição                              |
|-----------------------|-----------|----------------------------------------|
| id                    | BIGINT    | Identificador único                    |
| consultant_group_id   | BIGINT    | FK para consultant_groups              |
| user_id               | BIGINT    | FK para users                          |
| created_at            | TIMESTAMP | Data de vinculação                     |
| updated_at            | TIMESTAMP | Data de atualização                    |

**Restrições:**
- Unique constraint em `(consultant_group_id, user_id)` - Um usuário não pode estar duplicado no mesmo grupo
- Cascade delete - Ao excluir um grupo, remove os vínculos

## 🔧 Backend (Laravel)

### Model: `ConsultantGroup`

**Localização:** `app/Models/ConsultantGroup.php`

**Relacionamentos:**
- `consultants()` - BelongsToMany com User (através de `consultant_group_user`)
- `creator()` - BelongsTo com User (quem criou o grupo)

**Scopes:**
- `active()` - Filtra apenas grupos ativos

**Métodos Auxiliares:**
- `hasConsultants()` - Verifica se o grupo possui consultores
- `getConsultantsCountAttribute()` - Retorna quantidade de consultores

### Controller: `ConsultantGroupController`

**Localização:** `app/Http/Controllers/ConsultantGroupController.php`

**Endpoints:**

#### GET `/api/v1/consultant-groups`
Lista grupos de consultores com paginação e filtros.

**Query Parameters:**
- `page` - Número da página (padrão: 1)
- `pageSize` - Itens por página (padrão: 20)
- `order` - Ordenação (ex: `name,-created_at`)
- `name` - Filtro por nome (busca parcial)
- `active` - Filtro por status (true/false)

**Resposta:**
```json
{
  "hasNext": true,
  "items": [
    {
      "id": 1,
      "name": "Equipe Alpha",
      "description": "Consultores sêniores",
      "active": true,
      "consultants_count": 5,
      "consultants": [...],
      "creator": {...},
      "created_at": "2025-11-24T19:00:00.000000Z",
      "updated_at": "2025-11-24T19:00:00.000000Z"
    }
  ]
}
```

#### POST `/api/v1/consultant-groups`
Cria um novo grupo de consultores.

**Body:**
```json
{
  "name": "Equipe Alpha",
  "description": "Consultores sêniores especializados",
  "active": true,
  "consultant_ids": [1, 2, 3]
}
```

**Validações:**
- `name` é obrigatório, único e máximo 255 caracteres
- `description` é opcional, máximo 1000 caracteres
- `consultant_ids` é obrigatório e deve conter pelo menos 1 consultor
- Todos os IDs devem ser de usuários com role "Consultant"

#### GET `/api/v1/consultant-groups/{id}`
Busca detalhes de um grupo específico.

**Resposta:**
```json
{
  "id": 1,
  "name": "Equipe Alpha",
  "description": "Consultores sêniores",
  "active": true,
  "consultants_count": 5,
  "consultants": [
    {
      "id": 1,
      "name": "João Silva",
      "email": "joao@example.com"
    }
  ],
  "creator": {
    "id": 10,
    "name": "Admin User"
  },
  "created_at": "2025-11-24T19:00:00.000000Z",
  "updated_at": "2025-11-24T19:00:00.000000Z"
}
```

#### PUT `/api/v1/consultant-groups/{id}`
Atualiza um grupo existente.

**Body (todos os campos são opcionais):**
```json
{
  "name": "Equipe Alpha Atualizada",
  "description": "Nova descrição",
  "active": false,
  "consultant_ids": [1, 2, 4, 5]
}
```

#### DELETE `/api/v1/consultant-groups/{id}`
Exclui um grupo (soft delete).

**Resposta:** 204 No Content

#### GET `/api/v1/consultant-groups/available-consultants`
Lista todos os usuários com role "Consultant" disponíveis.

**Resposta:**
```json
[
  {
    "id": 1,
    "name": "João Silva",
    "email": "joao@example.com"
  }
]
```

### Request Classes

**StoreConsultantGroupRequest** - Validação para criação
**UpdateConsultantGroupRequest** - Validação para atualização

Ambas incluem:
- Validação de permissões no método `authorize()`
- Filtro automático para garantir que apenas usuários com role "Consultant" sejam aceitos

### Policy: `ConsultantGroupPolicy`

**Localização:** `app/Policies/ConsultantGroupPolicy.php`

Gerencia autorização para:
- `viewAny()` - Ver lista de grupos
- `view()` - Ver detalhes de um grupo
- `create()` - Criar grupo
- `update()` - Atualizar grupo
- `delete()` - Excluir grupo
- `restore()` - Restaurar grupo excluído
- `forceDelete()` - Exclusão permanente (apenas admin)

## 🎨 Frontend (Angular 19)

### Interface: `IConsultantGroup`

**Localização:** `src/app/models/consultant-group.interface.ts`

```typescript
export interface IConsultantGroup {
  id: number;
  name: string;
  description?: string;
  active: boolean;
  created_by?: number;
  consultants?: IUser[];
  consultants_count?: number;
  creator?: IUser;
  created_at?: string;
  updated_at?: string;
  deleted_at?: string;
}
```

### Service: `ConsultantGroupService`

**Localização:** `src/app/core/services/consultant-group.service.ts`

**Métodos:**
- `getAll(params?)` - Lista grupos com filtros
- `getById(id)` - Busca grupo por ID
- `create(data)` - Cria novo grupo
- `update(id, data)` - Atualiza grupo
- `delete(id)` - Exclui grupo
- `getAvailableConsultants()` - Lista consultores disponíveis
- `toggleActive(id, active)` - Ativa/desativa grupo

### Componentes

#### `ConsultantGroupListComponent`

**Localização:** `src/app/features/consultant-groups/consultant-group-list.component.ts`

**Funcionalidades:**
- Listagem paginada de grupos
- Filtros por nome e status
- Tabela com ações (Visualizar, Editar, Excluir)
- Modal de formulário integrado (criação e edição)
- Modal de detalhes com lista de consultores
- Modal de confirmação de exclusão

**Rota:** `/consultant-groups`

#### `ConsultantGroupFormComponent`

**Localização:** `src/app/features/consultant-groups/consultant-group-form.component.ts`

**Funcionalidades:**
- Componente de formulário puro (usado dentro do modal)
- Formulário reativo com validação
- Modo criação e edição
- Multiselect de consultores com busca
- Validações em tempo real
- Switch para ativar/desativar grupo
- Emissão de eventos de validação

**Nota:** Este componente é usado dentro do modal no `ConsultantGroupListComponent`, seguindo o padrão de outras features da aplicação (Users, Roles, etc).

### Rotas

```typescript
{
  path: 'consultant-groups',
  loadChildren: () => import('./features/consultant-groups/consultant-groups.routes')
    .then(m => m.consultantGroupsRoutes)
}

// consultant-groups.routes.ts
export const consultantGroupsRoutes: Routes = [
  {
    path: '',
    loadComponent: () => import('./consultant-group-list.component')
      .then(m => m.ConsultantGroupListComponent),
    canActivate: [PermissionGuard],
    data: { permissions: ['consultant_groups.view', 'admin.full_access'] }
  }
];
```

## 🚀 Como Usar

### 1. Atribuir Permissões

Primeiro, certifique-se de que o usuário tem as permissões necessárias:

```php
// Via Tinker ou Seeder
$user = User::find(1);
$user->givePermissionTo('consultant_groups.view');
$user->givePermissionTo('consultant_groups.create');
$user->givePermissionTo('consultant_groups.update');
$user->givePermissionTo('consultant_groups.delete');

// Ou atribuir role que já contém essas permissões
$role = Role::findByName('Administrator');
$role->givePermissionTo([
    'consultant_groups.view',
    'consultant_groups.create',
    'consultant_groups.update',
    'consultant_groups.delete'
]);
```

### 2. Criar um Grupo

**Via API:**
```bash
curl -X POST http://localhost:8000/api/v1/consultant-groups \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Equipe Frontend",
    "description": "Consultores especializados em frontend",
    "active": true,
    "consultant_ids": [1, 2, 3]
  }'
```

**Via Interface:**
1. Acesse `/consultant-groups`
2. Clique em "Novo Grupo"
3. Preencha o formulário
4. Selecione os consultores
5. Clique em "Salvar"

### 3. Listar Grupos

**Via API:**
```bash
curl -X GET "http://localhost:8000/api/v1/consultant-groups?page=1&pageSize=20&active=true" \
  -H "Authorization: Bearer {token}"
```

**Via Interface:**
1. Acesse `/consultant-groups`
2. Use os filtros disponíveis
3. Visualize a lista com detalhes

### 4. Editar um Grupo

**Via API:**
```bash
curl -X PUT http://localhost:8000/api/v1/consultant-groups/1 \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Equipe Frontend Atualizada",
    "consultant_ids": [1, 2, 3, 4]
  }'
```

**Via Interface:**
1. Na lista, clique em "Editar" no grupo desejado (abre modal)
2. No modal, modifique os campos
3. Clique em "Salvar"

### 5. Excluir um Grupo

**Via API:**
```bash
curl -X DELETE http://localhost:8000/api/v1/consultant-groups/1 \
  -H "Authorization: Bearer {token}"
```

**Via Interface:**
1. Na lista, clique em "Excluir" no grupo desejado
2. Confirme a exclusão no modal

## 🔒 Segurança

### Validações Implementadas

1. **Autorização:**
   - Todas as rotas verificam permissões antes de executar
   - Admin tem acesso total via `admin.full_access`

2. **Validação de Dados:**
   - Nome é obrigatório e único
   - Consultores devem ter role "Consultant"
   - Grupo deve ter pelo menos 1 consultor

3. **Sanitização:**
   - Request classes filtram automaticamente usuários inválidos
   - Query Builder previne SQL Injection

4. **Soft Delete:**
   - Grupos excluídos podem ser recuperados
   - Exclusão permanente só para admin

## 🔗 Integração com Projetos

### Adicionando Grupos a Projetos

A feature está integrada com o formulário de projetos, permitindo adicionar grupos completos de consultores de uma só vez.

**Como usar:**

1. No formulário de projeto, na seção "Equipe do Projeto"
2. Clique no botão "Adicionar Grupo" (ao lado de "Adicionar Consultor")
3. Modal abre com lista de grupos ativos
4. Use a busca para filtrar grupos por nome
5. Clique em "Adicionar Grupo" na linha do grupo desejado
6. Todos os consultores do grupo são adicionados automaticamente ao projeto

**Funcionalidades:**

- ✅ **Lista apenas grupos ativos** - Grupos inativos não aparecem
- ✅ **Busca por nome** - Filtro em tempo real
- ✅ **Informações do grupo** - Mostra nome, quantidade de consultores e status
- ✅ **Adição inteligente** - Não duplica consultores já adicionados
- ✅ **Feedback claro** - Notificações informam quantos consultores foram adicionados
- ✅ **Validação** - Alerta se o grupo não tiver consultores

**Notificações:**

```
✅ "3 consultores do grupo 'Equipe Frontend' foram adicionados ao projeto."
⚠️ "Todos os consultores do grupo 'Equipe Alpha' já estão no projeto."
⚠️ "O grupo 'Equipe Beta' não possui consultores."
```

### Implementação Técnica

**Frontend:**
- `ConsultantGroupService` integrado ao `ProjectFormComponent`
- Modal de busca com tabela de grupos
- Método `addGroupToProject()` que busca detalhes completos do grupo
- Verificação de duplicatas antes de adicionar consultores

**Backend:**
- Endpoint `/api/v1/consultant-groups?active=true` retorna apenas grupos ativos
- Endpoint `/api/v1/consultant-groups/{id}` retorna grupo com lista completa de consultores
- Relacionamento `consultants()` no model carrega dados dos usuários

## 📈 Possíveis Extensões Futuras

1. ~~**Atribuição em Projetos:**~~ ✅ **IMPLEMENTADO**
   - ~~Permitir atribuir um grupo inteiro a um projeto~~
   - ~~Sincronizar consultores do grupo automaticamente~~

2. **Relatórios por Grupo:**
   - Horas trabalhadas por grupo
   - Performance de grupos
   - Custos por grupo

3. **Hierarquia de Grupos:**
   - Grupos pais e filhos
   - Subgrupos

4. **Notificações:**
   - Notificar consultores quando adicionados/removidos
   - Alertas de mudanças no grupo

5. **Histórico:**
   - Auditoria de mudanças
   - Log de consultores adicionados/removidos

6. **Sincronização Automática:**
   - Quando um consultor é adicionado ao grupo, adicioná-lo automaticamente aos projetos que usam o grupo
   - Quando removido, opcionalmente remover dos projetos

## 🐛 Troubleshooting

### Erro: "Um ou mais usuários não possuem a permissão Consultant"

**Causa:** Os IDs fornecidos não são de usuários com role "Consultant".

**Solução:**
```php
// Verificar role do usuário
$user = User::find(1);
$user->assignRole('Consultant');

// Ou listar consultores disponíveis
GET /api/v1/consultant-groups/available-consultants
```

### Erro: "Já existe um grupo com este nome"

**Causa:** Nome duplicado.

**Solução:** Use um nome único para o grupo.

### Erro: "Permissão negada"

**Causa:** Usuário não tem permissão necessária.

**Solução:**
```php
$user->givePermissionTo('consultant_groups.view');
// ou
$user->assignRole('Administrator');
```

## ✅ Checklist de Implementação

- [x] Migrations criadas
- [x] Models configurados com relacionamentos
- [x] Controller com CRUD completo
- [x] Request classes com validação
- [x] Policy de autorização
- [x] Permissões adicionadas no seeder
- [x] Rotas da API configuradas
- [x] Interface TypeScript criada
- [x] Service Angular implementado
- [x] Componente de listagem
- [x] Componente de formulário
- [x] Rotas frontend configuradas
- [x] Guards de permissão aplicados
- [x] Documentação completa

---

**Data de Implementação:** 24 de Novembro de 2025  
**Versão:** 1.0.0  
**Status:** ✅ Completo

