# 🚀 Laravel Docker API Project

Um projeto Laravel configurado exclusivamente como **API REST** com Docker Compose, incluindo MySQL, Redis e Nginx.

## 🔌 Características da API

- ✅ **API-Only** - Sem views/Blade, apenas endpoints JSON
- ✅ **Laravel Sanctum** - Autenticação via tokens
- ✅ **CORS configurado** - Para frontend separado
- ✅ **Rate limiting** - Proteção contra abuso
- ✅ **Respostas JSON padronizadas** - Estrutura consistente
- ✅ **Documentação automática** - Todas as rotas documentadas

## 📋 Pré-requisitos

- Docker
- Docker Compose
- Git

## 🛠️ Configuração Rápida

### Opção 1: Script Automático (Recomendado)

```bash
# Tornar o script executável e rodar
chmod +x setup.sh
./setup.sh
```

### Opção 2: Configuração Manual

1. **Criar projeto Laravel:**
```bash
docker run --rm -v $(pwd):/app composer:latest create-project laravel/laravel . --prefer-dist
```

2. **Configurar variáveis de ambiente:**
```bash
cp .env.docker .env
```

3. **Subir os containers:**
```bash
docker-compose up -d
```

4. **Instalar dependências:**
```bash
docker-compose exec app composer install
```

5. **Gerar chave da aplicação:**
```bash
docker-compose exec app php artisan key:generate
```

6. **Executar migrations:**
```bash
docker-compose exec app php artisan migrate
```

## 🌐 Acesso

- **API Base URL:** http://localhost:8000/api/v1
- **API Documentation:** http://localhost:8000/api/documentation (após configurar)
- **MySQL:** localhost:3306
- **Redis:** localhost:6379

### Endpoints da API:
```
POST   /api/v1/auth/login      # Login e geração de token
POST   /api/v1/auth/register   # Registro de usuário
GET    /api/v1/auth/profile    # Perfil do usuário autenticado
POST   /api/v1/auth/logout     # Logout
```

## 🗄️ Configurações do Banco

- **Host:** db (dentro dos containers) / localhost (fora dos containers)
- **Database:** laravel
- **Username:** laravel_user
- **Password:** laravel_password
- **Root Password:** root_password

## 📁 Estrutura do Projeto

```
├── docker/
│   ├── nginx/
│   │   └── default.conf      # Configuração do Nginx
│   ├── php/
│   │   └── local.ini         # Configurações personalizadas do PHP
│   └── mysql/
│       └── my.cnf            # Configurações do MySQL
├── docker-compose.yml        # Orquestração dos containers
├── Dockerfile               # Container do Laravel/PHP
├── .env.docker             # Configurações de ambiente
└── setup.sh               # Script de configuração automática
```

## 🐳 Comandos Docker Úteis

### Gerenciar Containers
```bash
# Subir containers
docker-compose up -d

# Parar containers
docker-compose down

# Ver logs
docker-compose logs app
docker-compose logs db

# Ver status
docker-compose ps
```

### Laravel/PHP
```bash
# Entrar no container do Laravel
docker-compose exec app bash

# Executar comandos Artisan
docker-compose exec app php artisan migrate
docker-compose exec app php artisan make:model User
docker-compose exec app php artisan cache:clear

# Comandos específicos para API
docker-compose exec app php artisan make:controller Api/UserController --api
docker-compose exec app php artisan make:request CreateUserRequest
docker-compose exec app composer require laravel/sanctum

# Composer
docker-compose exec app composer install
docker-compose exec app composer require package-name
```

### MySQL
```bash
# Conectar ao MySQL
docker-compose exec db mysql -u laravel_user -p laravel

# Backup do banco
docker-compose exec db mysqldump -u root -p laravel > backup.sql

# Restore do banco
docker-compose exec -T db mysql -u root -p laravel < backup.sql
```

## 🔧 Personalizações

### PHP Settings
Edite `docker/php/local.ini` para personalizar configurações do PHP.

### Nginx Configuration
Edite `docker/nginx/default.conf` para personalizar o servidor web.

### MySQL Configuration
Edite `docker/mysql/my.cnf` para personalizar o MySQL.

## 🚨 Solução de Problemas

### Container não sobe
```bash
# Ver logs detalhados
docker-compose logs

# Rebuild containers
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Erro de permissão
```bash
# Ajustar permissões (dentro do container)
docker-compose exec app chown -R www:www /var/www
docker-compose exec app chmod -R 755 /var/www/storage
```

### Cache e configuração
```bash
# Limpar tudo
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan view:clear
docker-compose exec app php artisan route:clear
```

## 🔐 Segurança

O projeto já vem configurado com:
- ✅ **Laravel Sanctum** para autenticação via tokens
- ✅ **Rate limiting** configurado para APIs
- ✅ **CORS** adequadamente configurado
- ✅ **Validação de Content-Type** obrigatória
- ✅ **Middleware de autenticação** para rotas protegidas
- ✅ Variáveis de ambiente seguras
- ✅ Usuário não-root nos containers
- ✅ Configurações MySQL seguras
- ✅ Rede isolada do Docker

## 📦 Stack Incluída

- **PHP 8.3** com FPM
- **Laravel 11** (versão mais recente)
- **MySQL 8.0**
- **Redis Alpine**
- **Nginx Alpine**
- **Composer 2**
- **Node.js & NPM**

## 🎯 Próximos Passos

Após configurar o projeto, você pode:

1. **Configurar autenticação Sanctum:**
   ```bash
   docker-compose exec app composer require laravel/sanctum
   docker-compose exec app php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
   docker-compose exec app php artisan migrate
   ```

2. **Criar controllers para API:**
   ```bash
   docker-compose exec app php artisan make:controller Api/UserController --api
   docker-compose exec app php artisan make:request CreateUserRequest
   ```

3. **Criar models e migrations:**
   ```bash
   docker-compose exec app php artisan make:model Post -m
   ```

4. **Configurar CORS (se necessário):**
   ```bash
   docker-compose exec app php artisan config:publish cors
   ```

5. **Documentar sua API usando ferramentas como:**
   - Swagger/OpenAPI
   - Postman Collections
   - API Blueprint

---

**Projeto configurado com ❤️ para desenvolvimento Laravel com Docker!** 