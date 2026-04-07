# 🔧 Correção de Charset UTF-8 no MySQL

## 📋 Problema Identificado

Os caracteres especiais (acentos, cedilha, etc.) estavam sendo salvos incorretamente no banco de dados MySQL. Por exemplo:
- `Dúvida` estava sendo salvo como `D?vida`

## 🔍 Causas do Problema

1. **Configuração incorreta do banco de dados no `.env`**:
   - O projeto estava configurado para usar SQLite
   - As migrations foram executadas no SQLite, mas a aplicação em produção usava MySQL

2. **Falta de configuração UTF-8 no MySQL**:
   - O arquivo `docker/mysql/my.cnf` não tinha configuração de charset
   - Não estava forçando UTF-8 nas conexões

3. **Falta de configuração UTF-8 na conexão PDO do Laravel**:
   - O `config/database.php` não estava forçando o charset na inicialização da conexão

## ✅ Correções Implementadas

### 1. Atualização do `.env`
Configurado para usar MySQL corretamente:
```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel_user
DB_PASSWORD=laravel_password
```

### 2. Configuração do MySQL (`docker/mysql/my.cnf`)
Adicionada configuração UTF-8:
```ini
[mysqld]
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
init-connect='SET NAMES utf8mb4'

[client]
default-character-set=utf8mb4

[mysql]
default-character-set=utf8mb4
```

### 3. Configuração do Laravel (`config/database.php`)
Adicionada opção PDO para forçar UTF-8 na conexão:
```php
'options' => extension_loaded('pdo_mysql') ? array_filter([
    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
]) : [],
```

### 4. Comando Artisan para Correção
Criado comando `php artisan db:fix-charset` que:
- Verifica o charset atual do banco
- Converte o banco para utf8mb4
- Converte a tabela `movidesk_tickets` para utf8mb4
- Converte todas as colunas string para utf8mb4
- Permite truncar a tabela para reinserção de dados

## 🚀 Como Aplicar as Correções

### Passo 1: Reiniciar o Container MySQL
```bash
docker compose restart db
```

### Passo 2: Limpar Cache do Laravel
```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
```

### Passo 3: Verificar Charset (Opcional)
```bash
docker compose exec db mysql -u laravel_user -plaravel_password laravel -e "SHOW CREATE TABLE movidesk_tickets\G"
```

### Passo 4: Reinserir Dados
Opção 1: Aguardar novos webhooks do Movidesk

Opção 2: Reprocessar dados antigos (se aplicável)

## ✨ Resultado

Após as correções, os caracteres especiais são salvos corretamente:
- ✅ `Dúvida` → `Dúvida`
- ✅ `Média` → `Média`
- ✅ `Configuração` → `Configuração`
- ✅ `Ação` → `Ação`

## 🧪 Teste Realizado

Teste manual executado com sucesso:
```sql
INSERT INTO movidesk_tickets (ticket_id, categoria, urgencia, titulo) 
VALUES ('99999', 'Dúvida', 'Média', 'Teste de acentuação');

SELECT * FROM movidesk_tickets WHERE ticket_id = '99999';
-- Resultado: Caracteres especiais salvos corretamente! ✅
```

## 📝 Observações Importantes

1. **Dados antigos**: Os dados inseridos ANTES da correção ainda estão corrompidos
   - Use o comando `php artisan db:fix-charset --truncate` para limpar se necessário

2. **Novos dados**: Todos os dados inseridos APÓS a correção terão caracteres especiais salvos corretamente

3. **Banco em produção**: Se estiver usando um banco MySQL em produção:
   - Aplique as mesmas configurações do `my.cnf`
   - Execute o comando `db:fix-charset` no ambiente de produção
   - Considere reprocessar os dados corrompidos

## 🔧 Manutenção

Para garantir que o problema não ocorra novamente:

1. **SEMPRE** use MySQL como banco de dados principal (não SQLite)
2. **SEMPRE** configure o charset nas 3 camadas:
   - Servidor MySQL (`my.cnf`)
   - Conexão PDO (Laravel config)
   - Tabelas e colunas (migrations)
3. **SEMPRE** teste com dados contendo acentos antes de deploy

## 📚 Referências

- [Laravel Database Configuration](https://laravel.com/docs/database#configuration)
- [MySQL Character Set Configuration](https://dev.mysql.com/doc/refman/8.0/en/charset-configuration.html)
- [PDO MySQL Driver Options](https://www.php.net/manual/en/ref.pdo-mysql.php)

---

**Data da correção**: 2025-12-10
**Autor**: Leonardo Almeida
**Status**: ✅ Resolvido
