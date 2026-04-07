# 📊 Guia: DashboardDataSeeder - Dados Fictícios para Dashboards

## 📋 Visão Geral

O `DashboardDataSeeder` cria dados fictícios completos para testar todos os endpoints do `DashboardController`. Ele gera dados realistas distribuídos ao longo dos últimos 12 meses.

## 🎯 O que é criado

### 1. **Clientes** (3 clientes)
- TechCorp Solutions
- InnovaSoft
- Digital Services

### 2. **Tipos de Serviço**
- **Projeto** (code: `projeto`)
- **Sustentação** (code: `sustentacao`)

### 3. **Tipos de Contrato**
- **Fechado** (code: `closed`)
- **Banco de Horas Fixo** (code: `fixed_hours`)
- **Banco de Horas Mensal** (code: `monthly_hours`)
- **On Demand** (code: `on_demand`)

### 4. **Projetos**
Para cada cliente, são criados:
- **2 Projetos Pais** (tipo "Projeto"):
  - 1 projeto com contrato "Banco de Horas Fixo"
  - 1 projeto com contrato "Fechado"
- **2 Projetos Filhos** (tipo "Sustentação"):
  - Vinculados ao primeiro projeto pai
  - Com contratos "Banco de Horas Fixo"

**Total:** 3 clientes × 4 projetos = **12 projetos** (6 pais + 6 filhos)

### 5. **Tickets do Movidesk** (~150-200 tickets)
Distribuídos nos últimos 12 meses com:
- **Solicitantes variados** (5 pessoas diferentes)
- **Categorias:** Desenvolvimento, Bug, Melhoria, Dúvida, Configuração, Treinamento
- **Status:** Em Andamento, Aguardando, Resolvido, Fechado, Cancelado, Pendente
- **Níveis:** N1, N2, N3, N4
- **Serviços/Módulos:** Módulo Financeiro, RH, Vendas, Estoque, Sistema Principal, API
- **Urgências:** Baixa, Média, Alta, Crítica
- **5 tickets especiais** com mais de 8 horas de apontamentos (IDs: 20000-20004)

### 6. **Timesheets** (~500-1000 apontamentos)
- Distribuídos nos últimos 12 meses
- **80% com tickets** vinculados
- **20% sem tickets**
- Status variados: pending, approved, rejected
- Horas variadas: 1-8 horas por apontamento
- Tickets grandes (20000+) têm 8-20 horas totais

### 7. **Histórico de Mudanças** (ProjectChangeLog)
- 2-5 mudanças de `hour_contribution` por projeto pai
- Com razões e usuários que fizeram as mudanças
- Distribuídas nos últimos 6 meses

## 🚀 Como Usar

### Opção 1: Executar apenas o DashboardDataSeeder

```bash
docker-compose exec app php artisan db:seed --class=DashboardDataSeeder
```

### Opção 2: Executar todos os seeders (incluindo DashboardDataSeeder)

```bash
docker-compose exec app php artisan db:seed
```

O `DashboardDataSeeder` já está incluído no `DatabaseSeeder`, então será executado automaticamente.

## ✅ Endpoints que podem ser testados

Com os dados criados, você pode testar todos os endpoints do DashboardController:

### Endpoints Principais
1. ✅ `GET /api/v1/dashboards/bank-hours-fixed` - Dashboard principal
2. ✅ `GET /api/v1/dashboards/bank-hours-fixed/projects` - Lista de projetos
3. ✅ `GET /api/v1/dashboards/bank-hours-fixed/projects/{id}/tickets` - Tickets de um projeto
4. ✅ `GET /api/v1/dashboards/bank-hours-fixed/maintenance/tickets` - Tickets de Sustentação
5. ✅ `GET /api/v1/dashboards/bank-hours-fixed/maintenance/tickets/{ticketId}/timesheets` - Apontamentos de ticket

### Endpoints de Indicadores
6. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/hours-by-requester` - Horas por solicitante
7. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/requester-timesheets?requester=João Silva` - Apontamentos por solicitante
8. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/hours-by-service` - Horas por serviço/módulo
9. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/service-timesheets?service=Módulo Financeiro` - Apontamentos por serviço
10. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/tickets-by-status` - Tickets por status
11. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/status-timesheets?status=Em Andamento` - Apontamentos por status
12. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/tickets-by-level` - Tickets por nível
13. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/level-timesheets?level=N1` - Apontamentos por nível
14. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/tickets-by-category` - Tickets por categoria
15. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/category-timesheets?category=Desenvolvimento` - Apontamentos por categoria
16. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/tickets-above-8-hours` - Tickets com 8h+
17. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/ticket-timesheets?ticket_id=20000` - Apontamentos de um ticket
18. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/monthly-tickets` - Tickets mensais (últimos 12 meses)
19. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/monthly-timesheets?month=dez/2025` - Apontamentos de um mês
20. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/monthly-consumption` - Consumo mensal
21. ✅ `GET /api/v1/dashboards/bank-hours-fixed/indicators/monthly-consumption-timesheets?month=dez/2025` - Apontamentos do consumo mensal

## 📝 Exemplos de Uso

### Testar Dashboard Principal
```bash
curl -X GET "http://localhost:8000/api/v1/dashboards/bank-hours-fixed" \
  -H "Authorization: Bearer {seu_token}" \
  -H "Accept: application/json"
```

### Testar com Filtros
```bash
# Por cliente
curl -X GET "http://localhost:8000/api/v1/dashboards/bank-hours-fixed?customer_id=1" \
  -H "Authorization: Bearer {seu_token}"

# Por projeto
curl -X GET "http://localhost:8000/api/v1/dashboards/bank-hours-fixed?project_id=1" \
  -H "Authorization: Bearer {seu_token}"

# Por mês/ano
curl -X GET "http://localhost:8000/api/v1/dashboards/bank-hours-fixed?month=12&year=2025" \
  -H "Authorization: Bearer {seu_token}"
```

### Testar Indicadores
```bash
# Horas por solicitante
curl -X GET "http://localhost:8000/api/v1/dashboards/bank-hours-fixed/indicators/hours-by-requester" \
  -H "Authorization: Bearer {seu_token}"

# Tickets acima de 8 horas
curl -X GET "http://localhost:8000/api/v1/dashboards/bank-hours-fixed/indicators/tickets-above-8-hours" \
  -H "Authorization: Bearer {seu_token}"

# Consumo mensal
curl -X GET "http://localhost:8000/api/v1/dashboards/bank-hours-fixed/indicators/monthly-consumption" \
  -H "Authorization: Bearer {seu_token}"
```

## 🔍 Dados de Teste Específicos

### Solicitantes Criados
- João Silva
- Maria Santos
- Pedro Oliveira
- Ana Costa
- Carlos Ferreira

### Categorias de Tickets
- Desenvolvimento
- Bug
- Melhoria
- Dúvida
- Configuração
- Treinamento

### Serviços/Módulos
- Módulo Financeiro
- Módulo RH
- Módulo Vendas
- Módulo Estoque
- Sistema Principal
- API

### Tickets com 8h+ (para testar endpoint específico)
- Ticket IDs: 20000, 20001, 20002, 20003, 20004

## ⚠️ Observações

1. **Dependências:** O seeder requer que os seguintes seeders sejam executados primeiro:
   - `PermissionSeeder`
   - `RoleSeeder`
   - `UserSeeder`

2. **Performance:** A criação de dados pode levar alguns minutos devido ao volume de timesheets criados.

3. **Dados Realistas:** Os dados são distribuídos ao longo dos últimos 12 meses para simular um ambiente real de uso.

4. **Projetos Fechados:** Alguns projetos são do tipo "Fechado", que têm lógica especial no cálculo de consumo (usam `sold_hours` ao invés de horas apontadas).

## 🔄 Recriar Dados

Para recriar os dados fictícios:

```bash
# Limpar e recriar tudo
docker-compose exec app php artisan migrate:fresh
docker-compose exec app php artisan db:seed

# Ou apenas recriar os dados do dashboard (se já tiver migrations)
docker-compose exec app php artisan db:seed --class=DashboardDataSeeder
```

## 📊 Estatísticas dos Dados Criados

- **Clientes:** 3
- **Projetos:** ~12 (6 pais + 6 filhos)
- **Tickets Movidesk:** ~150-200
- **Timesheets:** ~500-1000
- **Histórico de Mudanças:** ~18-30 registros
- **Período:** Últimos 12 meses

---

**Criado para facilitar testes completos de todos os endpoints do DashboardController!** 🎉
