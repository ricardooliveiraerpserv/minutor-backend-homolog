# 🧪 Guia Rápido de Teste - Webhook Movidesk

## 📋 Pré-requisitos

Antes de testar, você precisa:

### 1. Configurar SystemSettings

Execute no terminal:

```bash
docker-compose exec app php artisan tinker
```

Dentro do tinker, execute:

```php
// Definir cliente padrão (substitua 1 pelo ID do seu cliente)
\App\Models\SystemSetting::set('movidesk_default_customer_id', 1, 'integer', 'movidesk', 'Cliente padrão Movidesk');

// Definir projeto padrão (substitua 1 pelo ID do seu projeto)
\App\Models\SystemSetting::set('movidesk_default_project_id', 1, 'integer', 'movidesk', 'Projeto padrão Movidesk');

// Verificar se foram criados
\App\Models\SystemSetting::get('movidesk_default_customer_id');
\App\Models\SystemSetting::get('movidesk_default_project_id');

// Sair do tinker
exit
```

### 2. Verificar Token do Movidesk

Certifique-se que o token está configurado em `.env`:

```env
MOVIDESK_TOKEN=seu_token_aqui
```

## 🚀 Teste 1: Simular Webhook Localmente

### Passo 1: Enviar Requisição de Teste

```bash
curl -X POST http://localhost:8000/api/v1/movidesk/webhook/ticket \
  -H "Content-Type: application/json" \
  -d '{
    "Id": 45127,
    "Subject": "Teste de integração",
    "Status": "Em atendimento",
    "Urgency": "Baixa"
  }'
```

### Passo 2: Verificar Logs

Abra outro terminal e execute:

```bash
docker-compose exec app tail -f storage/logs/laravel.log | grep MOVIDESK
```

Você deverá ver logs como:

```
[MOVIDESK WEBHOOK] ===== REQUISIÇÃO RECEBIDA =====
[MOVIDESK WEBHOOK] Ticket recebido
[MOVIDESK WEBHOOK DEBUG] Ticket completo da API
```

## 🎯 Teste 2: Verificar Processamento Completo

### Cenário: Ticket com Apontamento de Tempo

O webhook automaticamente:
1. Busca os detalhes do ticket na API do Movidesk
2. Extrai dados do `actions[0].timeAppointments[0]`
3. Cria o timesheet automaticamente

**Nota**: Um exemplo completo de JSON retornado pela API do Movidesk está disponível em:
`storage/docs/movidesk/exemplo_full_ticket.json`

### Verificar Timesheet Criado

```bash
docker-compose exec app php artisan tinker
```

```php
// Buscar último timesheet criado
$timesheet = \App\Models\Timesheet::latest()->first();

// Ver detalhes
$timesheet;

// Ver dados relacionados
$timesheet->load('user', 'customer', 'project');
$timesheet;

exit
```

## 🔍 Checklist de Validação

### ✅ Logs Esperados

Ao processar com sucesso, você deve ver:

```
✅ [MOVIDESK WEBHOOK] Usuário encontrado
   user_id: X
   email: usuario@email.com

✅ [MOVIDESK WEBHOOK] Cliente encontrado
   customer_id: X
   customer_name: Nome do Cliente

✅ [MOVIDESK WEBHOOK] Projeto padrão encontrado
   project_id: X
   project_name: Nome do Projeto

✅ [MOVIDESK WEBHOOK] Data extraída
✅ [MOVIDESK WEBHOOK] periodStart extraído
✅ [MOVIDESK WEBHOOK] periodEnd extraído
✅ [MOVIDESK WEBHOOK] effort_hours extraído
✅ [MOVIDESK WEBHOOK] effort_minutes calculado
✅ [MOVIDESK WEBHOOK] Observation construída

✅ [MOVIDESK WEBHOOK] Apontamento criado com sucesso
   timesheet_id: X
   ticket: 45127
```

### ⚠️ Logs de Aviso (Esperados)

Caso não encontre cliente:

```
⚠️ [MOVIDESK WEBHOOK] Cliente não encontrado no sistema
ℹ️ [MOVIDESK WEBHOOK] Usando cliente padrão
```

### 🚨 Erros Possíveis

**Usuário não encontrado:**
```
⚠️ [MOVIDESK WEBHOOK] Usuário não encontrado ou inativo
   email: usuario@exemplo.com
```
**Solução**: Certifique-se que o usuário com este email existe e está ativo.

**Projeto não configurado:**
```
🚨 [MOVIDESK WEBHOOK] Projeto padrão não configurado em SystemSettings
```
**Solução**: Configure `movidesk_default_project_id` no SystemSettings.

**Cliente não configurado:**
```
🚨 [MOVIDESK WEBHOOK] Cliente padrão não configurado em SystemSettings
```
**Solução**: Configure `movidesk_default_customer_id` no SystemSettings.

## 📊 Teste 3: Verificar Campos do Timesheet

Execute no banco de dados:

```sql
SELECT 
    id,
    user_id,
    customer_id,
    project_id,
    date,
    start_time,
    end_time,
    effort_minutes,
    ticket,
    status,
    LEFT(observation, 100) as observation_preview
FROM timesheets
ORDER BY id DESC
LIMIT 1;
```

**Valores Esperados:**
- `status`: `pending`
- `ticket`: Número do ticket do Movidesk
- `effort_minutes`: Valor em minutos (ex: 120 para 2 horas)
- `observation`: HTML com subject e descrição

## 🎨 Teste 4: Cenários Específicos

### Cenário 1: Múltiplos Clientes (com @erpserv.com.br)

Se o ticket tiver 2 clientes:
- Cliente A: email com `@erpserv.com.br`
- Cliente B: email sem `@erpserv.com.br`

O sistema deve usar o **Cliente B**.

**Verificar no log:**
```
✅ [MOVIDESK WEBHOOK] Cliente encontrado
   organization_name: Nome do Cliente B
```

### Cenário 2: Cliente Não Encontrado

Se a organization do ticket não existir no sistema:

**Log esperado:**
```
⚠️ [MOVIDESK WEBHOOK] Cliente não encontrado no sistema
ℹ️ [MOVIDESK WEBHOOK] Usando cliente padrão
   default_customer_id: 1
```

### Cenário 3: Apontamento Duplicado

Se tentar criar apontamento com mesmo:
- ticket
- user_id
- date
- start_time

**Log esperado:**
```
⚠️ [MOVIDESK WEBHOOK] Apontamento já existe
   timesheet_id: X
   ticket: 45127
```

## 🐛 Debug Avançado

### Ver Todo o Payload do Webhook

```bash
docker-compose exec app tail -f storage/logs/laravel.log | grep "full_ticket_details"
```

### Verificar Configurações do Sistema

```bash
docker-compose exec app php artisan tinker
```

```php
// Ver todas configurações do Movidesk
\App\Models\SystemSetting::where('group', 'movidesk')->get();

// Ver configuração específica
\App\Models\SystemSetting::where('key', 'movidesk_default_customer_id')->first();
\App\Models\SystemSetting::where('key', 'movidesk_default_project_id')->first();
```

### Limpar Cache de Configurações

Se alterou SystemSettings e não está pegando:

```bash
docker-compose exec app php artisan tinker
```

```php
\App\Models\SystemSetting::clearCache();
exit
```

```bash
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
```

## 📝 Checklist Final

Antes de colocar em produção, verifique:

- [ ] SystemSettings configurados
  - [ ] `movidesk_default_customer_id`
  - [ ] `movidesk_default_project_id`
- [ ] Token Movidesk configurado no `.env`
- [ ] Usuários com emails corretos cadastrados
- [ ] Clientes cadastrados com nomes corretos
- [ ] Projeto padrão existe e está ativo
- [ ] Logs funcionando corretamente
- [ ] Teste de webhook local executado com sucesso
- [ ] Verificado timesheet criado no banco

## 🆘 Suporte

Se encontrar problemas:

1. **Verificar logs**: `docker-compose logs -f app`
2. **Verificar banco**: Conectar no MySQL e ver tabela `timesheets`
3. **Verificar configurações**: `SystemSettings` no banco
4. **Reprocessar**: Webhook pode ser reenviado do Movidesk

---

**Data**: Dezembro 2025  
**Última atualização**: Implementação inicial
