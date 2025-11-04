# AssetsMe

AssetsMe é um gerenciador de arquivos estáticos construído com Laravel 11, Inertia e React. Ele oferece uma API autenticada via token fixo para upload, listagem e remoção de assets, além de um painel administrativo para operadores autenticados.

> 🚀 **Deploy e Produção:** Veja [README_PRODUCTION.md](README_PRODUCTION.md) para informações sobre build, deploy e configuração em produção.

**Roadmap:** https://assetsme.featurebase.app/en/roadmap


## Requisitos

- PHP 8.2+
- Composer 2+
- Node.js 20+
- NPM 10+
- Extensão PHP `fileinfo`
- SQLite (padrão) ou outro banco compatível configurado no `.env`

## Instalação
Apos clonar o repositório.

1. Instale as dependências PHP e JavaScript:

   ```bash
   composer install
   npm install
   ```

## Configuração inicial

1. Copie o arquivo de ambiente e gere a chave da aplicação:

   ```bash
   cp .env.example .env.develop
   php artisan key:generate
   ```

   **Nota sobre ambientes:** O projeto suporta múltiplos arquivos de ambiente:
   - `.env` - ambiente padrão
   - `.env.develop` - ambiente de desenvolvimento alternativo (opcional)
   - `.env.example` - template com todas as variáveis disponíveis (versionado no git)

2. Ajuste os valores a seguir no `.env` (consulte os comentários em `.env.example` para mais detalhes):

   - `ASSETS_DISK`: disco Laravel utilizado para armazenar os arquivos (padrão `assets`).
   - `ASSETS_BASE_URL`: URL pública base para servir os arquivos em `public/assets`.
   - `ASSETS_MAX_FILE_SIZE`: limite de upload em bytes (padrão 10 MB).
   - `VITE_API_URL`: endereço base para o cliente React alcançar a API (ex.: `http://localhost:8000`).
    - `VITE_ASSETSME_TOKEN`: token utilizado pelo painel para enviar requisições à API (defina com um token gerado no menu **Tokens** do painel).
    - `REGISTRATION_DEV_ALWAYS_OPEN` (opcional): defina como `true` para manter o formulário de cadastro público liberado em ambientes de desenvolvimento.
   - `IMAGE_THUMB_SIZE` (opcional): tamanho em pixels para geração dos thumbs WebP das pastas (padrão `512`).
   - `IMAGE_QUALITY` (opcional): qualidade (0-100) utilizada na compressão WebP das prévias (padrão `80`).

3. Execute as migrações do banco:

   ```bash
   php artisan migrate
   ```

4. Garanta que a pasta pública de assets exista (já criada por padrão) e mantenha o `.htaccess` versionado para cache agressivo e bloqueio de execução PHP:

   ```bash
   mkdir -p public/assets
   ```

## Executando em desenvolvimento

### Usando o ambiente padrão (.env)

1. Inicie o servidor Laravel em um terminal:

   ```bash
   php artisan serve
   ```

2. Em outro terminal, execute o Vite para o front-end React:

   ```bash
   npm run dev
   ```

A aplicação estará disponível em `http://localhost:8000` com assets acessíveis diretamente via `http://localhost:8000/assets/...`.

Se preferir executar tudo em um único terminal, utilize o script `serve`:

```bash
npm run serve
```

### Usando ambiente de desenvolvimento alternativo (.env.develop)

Para rodar a aplicação usando configurações específicas de desenvolvimento, você pode usar o arquivo `.env.develop`:

1. Configure o arquivo `.env.develop` com suas variáveis de ambiente de desenvolvimento:

   ```bash
   cp .env.example .env.develop
   # Edite .env.develop com suas configurações de desenvolvimento
   ```

   **Exemplo de configurações úteis no `.env.develop`:**
   ```env
   APP_ENV=develop
   APP_DEBUG=true
   APP_URL=http://localhost:8000
   
   # Use um banco de dados diferente para desenvolvimento
   DB_CONNECTION=sqlite
   DB_DATABASE=database/database-develop.sqlite
   
   # URLs e tokens específicos de desenvolvimento
   VITE_API_URL=http://localhost:8000
   VITE_ASSETSME_TOKEN=seu-token-de-desenvolvimento
   
   # Manter cadastro sempre aberto em desenvolvimento
   REGISTRATION_DEV_ALWAYS_OPEN=true
   ```

2. Gere a chave da aplicação para o ambiente develop (se necessário):

   ```bash
   php artisan key:generate --env=develop
   ```

3. Execute as migrações para o banco de dados de desenvolvimento:

   ```bash
   php artisan migrate --env=develop
   ```

4. Execute ambos os servidores (Laravel e Vite) usando o ambiente develop:

   ```bash
   npm run serve:develop
   ```

   Ou execute os comandos separadamente:

   **Terminal 1 (Laravel):**
   ```bash
   php artisan serve --env=develop
   ```

   **Terminal 2 (Vite):**
   ```bash
   npm run dev:develop
   ```

O Vite carregará automaticamente as variáveis do arquivo `.env.develop` quando executado com `--mode develop`, enquanto o Laravel usará o `.env.develop` quando a flag `--env=develop` for passada.

## Testes

- Testes de unidade/feature PHP:

  ```bash
  php artisan test
  # ou
  ./vendor/bin/phpunit
  ```

- Verificação do front-end:

  ```bash
  npm run lint
  npm run types
  npm run build
  ```

## Biblioteca de Pastas

- Execute `php artisan migrate` para criar as tabelas `folders` e `folder_tokens` e ajustar a estrutura de `assets`.
- A navegação hierárquica fica disponível em `/library` no painel e exposta via API REST em `/api/v1/folders`.
- Pré-visualizações de pastas utilizam miniaturas WebP (`IMAGE_THUMB_SIZE`/`IMAGE_QUALITY`). Ajuste as variáveis se precisar de resoluções diferentes.

## Tokens fixos

Os endpoints da API exigem tokens permanentes vinculados a um usuário. Cada token é único, não expira e pode ser revogado a
qualquer momento.

### Criando tokens pelo painel

1. Autentique-se no painel e abra o menu **Tokens**.
2. Clique em **Criar token**, informe um nome opcional e confirme.
3. O token gerado será exibido apenas uma vez em um modal. Copie-o imediatamente e armazene com segurança.
4. Utilize a coluna "Prévia" para identificar tokens existentes e remova-os quando não forem mais necessários.


### Utilizando tokens na API

Os tokens podem ser informados de três maneiras:

- Header `Authorization: Bearer <TOKEN>`.
- Header `X-AssetsMe-Token: <TOKEN>`.
- Query string `?token=<TOKEN>` (fallback útil para integrações simples).

Defina uma variável de ambiente temporária e reutilize nos exemplos abaixo:

```bash
TOKEN="seu-token-copiado"
```

Os arquivos publicados continuam acessíveis diretamente pela URL pública (ex.: `https://seu-dominio.com/assets/banner.jpg`) sem
qualquer verificação de token.

## API HTTP

Todas as rotas ficam sob `/api` e exigem um token válido (veja "Tokens fixos"). Utilize `Authorization: Bearer $TOKEN`,
`X-AssetsMe-Token: $TOKEN` ou o query param `?token=$TOKEN`, exceto no health check.

### Health check

```http
GET /api/health
Response: { "ok": true }
```

### Upload de arquivos

- Variantes de tamanho podem ser geradas automaticamente para imagens enviadas utilizando os parâmetros `small`, `medium` e `large`.

```bash
curl -X POST "http://localhost:8000/api/assets/upload?folder=produtos/2025&small=1&medium=300x400" \
  -H "Authorization: Bearer $TOKEN" \
  -F "files[]=@/caminho/foto1.png" \
  -F "files[]=@/caminho/foto2.jpg"
```

- Parâmetro opcional `folder` sanitizado por regex (`^[a-zA-Z0-9/_-]+$`).
- `files[]` aceita múltiplos arquivos (máximo configurável via `ASSETS_MAX_FILE_SIZE`).
- `small|medium|large` são opcionais. Use `1` para aplicar o tamanho padrão do `config/assetsme.php`, `LARGURAxALTURA` para definir um tamanho customizado (ex.: `800x600`) ou omita/defina como `0` para não gerar a variação.
- Valores que excedem `ASSETS_MAX_WIDTH`/`ASSETS_MAX_HEIGHT` ou não seguem o formato válido retornam HTTP 422.
- Arquivos variantes são salvos com o sufixo `--<tamanho>` e suas URLs aparecem na chave `sizes` da resposta JSON quando geradas.
- A resposta retorna metadados: URL pública, caminho, MIME detectado por `finfo`, tamanho, nome original e checksum SHA-256.

### Listagem de assets

```bash
curl -X GET "http://localhost:8000/api/assets/list?folder=produtos/2025" \
  -H "Authorization: Bearer $TOKEN"
```

- Suporta `page` e `per_page` (máximo 100) para paginação simples.
- Quando `folder` é omitido, retorna arquivos da raiz.

### Remoção de arquivo

```bash
curl -X DELETE "http://localhost:8000/api/assets/file?path=produtos/2025/foto1.png" \
  -H "Authorization: Bearer $TOKEN"
```

- Remove o arquivo físico em `public/assets` e o registro na tabela `assets`.
- Paths inválidos retornam HTTP 400. Arquivos inexistentes retornam HTTP 404.

## Painel administrativo

O painel utiliza autenticação padrão do Laravel Breeze. Após realizar login:

- **Upload** (`/assets/upload`): interface com drag-and-drop, seleção de pasta, barra de progresso e retorno das URLs com botão "Copiar".
- **Listagem** (`/assets/list`): tabela com filtro por pasta, paginação, botões de copiar URL e remover asset.
- **Tokens** (`/tokens`): listagem dos tokens vinculados ao usuário, criação de novos tokens (exibidos uma única vez) e exclusão segura.
- **Usuários** (`/admin/users`): disponível apenas para o usuário master. Permite habilitar/desabilitar o cadastro público, criar usuários manualmente (com geração opcional de senha) e visualizar quem é o master.


## Comandos úteis

### Desenvolvimento

```bash
# Executar servidor Laravel + Vite (ambiente padrão)
npm run serve

# Executar servidor Laravel + Vite (ambiente develop)
npm run serve:develop

# Apenas servidor Laravel (ambiente padrão)
php artisan serve

# Apenas servidor Laravel (ambiente develop)
php artisan serve --env=develop

# Apenas Vite (ambiente padrão)
npm run dev

# Apenas Vite (ambiente develop)
npm run dev:develop
```

### Testes e qualidade de código

```bash
# Executa a suíte de testes PHP
php artisan test

# Checa tipos do front-end
npm run types

# Executa linter no código TypeScript/React
npm run lint

# Formata código
npm run format

# Verifica formatação sem alterar
npm run format:check
```



## Próximos passos

✅ **Ambiente local configurado?** Você está pronto para desenvolver!

🚀 **Pronto para deploy?** Consulte o [README_PRODUCTION.md](README_PRODUCTION.md) para:
- Build de produção
- Deploy manual
- Deploy automatizado via GitHub Actions (FTPS)
- Configuração de servidor web (Apache/Nginx)
- Troubleshooting

📚 **Documentação adicional:**
- [Roadmap do projeto](https://assetsme.featurebase.app/en/roadmap)

---

**Última atualização:** Outubro 2025
