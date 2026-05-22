# CodeSync Manager for GitHub

[English](#english) | [Português](#português)

---

## English

**CodeSync Manager for GitHub** is an open-source WordPress plugin developed to simplify the distribution, installation, and update of other plugins hosted in public or private GitHub repositories, using repository **Releases** or **Branches** as the single source of truth (*Source of Truth*).

The main use case is the quick distribution of tailor-made or active-development plugins: when you publish a new release (or push to a branch) on GitHub, WordPress automatically detects and lists the update on the native Plugins screen, identical to official WordPress.org plugins.

---

### 🚀 Key Features

- **Verification Modal with Advanced Options**: Clicking "Install Plugin" opens a modern, centered popup modal with a background blur (`backdrop-filter`) effect. It runs a verification scan and lets you configure the source and base directory before installing.
- **Manual Branch and Subfolder Selection**: If your plugin is located in a nested subdirectory (e.g. `includes/` or `my-plugin/`) or if you need to install from a specific git branch, you can select it manually. The system isolates the subfolder contents, discards the rest of the zip, and renames the folder to the canonical slug.
- **Native WordPress Updates**: Updates are integrated into the core WordPress updater. Clicking "Update Now" handles downloading and unzipping natively.
- **Branch Fallback Support**: If a repository does not have any releases, the plugin lists and installs directly from branch references (e.g., `main` or `master`).
- **Automatic Repository Filtering**: The add-plugin directory automatically hides non-PHP or non-WordPress repositories to keep the interface clutter-free.
- **Secure Credentials**: GitHub PATs are authenticated securely using **AES-256-GCM authenticated encryption**.
- **Backup & Automatic Rollback**: Before updating, a temporary backup of the plugin directory is generated. If the update fails, the previous version is instantly restored.
- **Intelligent Caching**: GitHub API requests are cached in WordPress transients for **1 hour** to respect API rate limits.
- **Database Action Logs**: Keeps a running history of the last 100 plugin updates, installations, and connection checks (`autoload = no`).

---

### 🛠️ Requirements & Security

1. **AES-256-GCM Encryption**: Credentials are encrypted before saving. The encryption key is derived using the `AUTH_KEY` and `SECURE_AUTH_KEY` constants from your `wp-config.php`.
2. **Zero Plaintext Leak**: Tokens are masked (e.g., `••••••••••••••••ABCD`) and never displayed in HTML, form inputs, or error logs.
3. **Compatibility**: Requires PHP 7.2+ (fully compatible with PHP 8+) and WordPress 5.8+.

---

### 📂 Directory Structure

```text
├── codesync-manager-for-github/          # Core plugin directory
│   ├── assets/
│   │   ├── css/admin.css             # CSS styles and modal styles
│   │   └── js/admin.js               # Tab logic and AJAX handler
│   ├── includes/
│   │   ├── class-admin.php           # Admin views, AJAX actions, and modal HTML
│   │   ├── class-encryption.php      # AES-256-GCM credentials encryption
│   │   ├── class-github-api.php      # REST API GitHub Client (with Trees API support)
│   │   ├── class-manager.php         # DB, directory check, and logger
│   │   └── class-updater.php         # Native WordPress update hooks integration
│   ├── languages/                    # Translation binary MO catalogs (PT, EN, ES)
│   ├── codesync-manager-for-github.php   # Main entrypoint file
│   └── readme.txt                    # WordPress.org plugin directory readme documentation
│
└── releases/                         # Packaged zip release archives
    └── codesync-manager-for-github-v1.0.6.zip
```

---

### 💾 Installation & Setup

1. Navigate to the `releases/` directory and download the latest version ZIP (`v1.0.6`).
2. Inside your WordPress admin panel, go to **Plugins > Add New > Upload Plugin** and select the ZIP file.
3. Activate the plugin.
4. Go to the new **GitHub Sync** menu item in your WordPress sidebar.
5. Paste your GitHub Personal Access Token (PAT) with `repo` scope to log in.

---

## Português

O **CodeSync Manager for GitHub** é um plugin WordPress open-source desenvolvido para simplificar a distribuição, instalação e atualização de outros plugins hospedados em repositórios do GitHub (públicos ou privados), utilizando as **Releases** ou **Branches** do repositório como fonte de verdade (*Source of Truth*).

O caso de uso central é a distribuição ágil de plugins sob medida ou em desenvolvimento ativo: o desenvolvedor publica uma nova release (ou faz commit em um ramo) no GitHub, e o WordPress automaticamente identifica e exibe a atualização disponível na tela padrão de Plugins, exatamente como ocorre com plugins hospedados no diretório oficial do WordPress.org.

---

### 🚀 Principais Recursos

- **Modal de Instalação com Verificação e Opções Avançadas**: Ao clicar em "Instalar", um pop-up centralizado e moderno com efeito de desfoque de fundo (`backdrop-filter`) realiza a verificação de integridade do repositório e permite configurar de forma avançada a origem e a pasta base antes da instalação.
- **Seleção Manual de Ramo e Subpasta**: Se o seu plugin não estiver localizado na raiz do repositório (ex: subpasta `includes/` ou `my-plugin/`) ou se você deseja instalar de uma branch específica, o plugin permite fazer a seleção manual no modal. O sistema isola apenas os arquivos da subpasta e descarta o restante do ZIP, renomeando o diretório final para o slug canônico.
- **Integração Nativa de Atualizações**: A atualização é integrada ao ecossistema nativo do WordPress. O botão nativo "Atualizar agora" na tela principal de Plugins executa o download e a descompactação automaticamente.
- **Fallback por Ramo (Branches)**: Se o repositório não possuir nenhuma release oficial gerada, o plugin lista e instala o conteúdo diretamente a partir das branches (ex: `main` ou `master`).
- **Filtragem Automática**: A lista de repositórios filtra automaticamente repositórios que não tenham estrutura PHP ou WordPress, evitando poluir o painel de plugins com outros sites ou ferramentas.
- **Conectividade Segura**: Autenticação simplificada por meio de Personal Access Tokens (PAT) do GitHub, suportando **Classic PATs** e **Fine-Grained PATs**.
- **Cópia de Segurança & Recuperação Automática**: Antes de substituir os arquivos de um plugin durante uma atualização, o sistema cria um backup da pasta existente. Se a atualização falhar, a versão anterior é restaurada imediatamente.
- **Cache Inteligente**: Consultas a APIs do GitHub são salvas em transients do WordPress por **1 hora** para evitar limites de requisições (Rate Limits).
- **Aba de Logs Completa**: Histórico detalhado no banco de dados das últimas 100 ações executadas (instalações, atualizações, conexões e falhas de rede) com escrita otimizada (`autoload = no`).

---

### 🛠️ Requisitos e Segurança do Token

1. **Criptografia AES-256-GCM**: O token do GitHub é criptografado antes de ser salvo no banco de dados de forma segura. A chave criptográfica é derivada com base nas constantes `AUTH_KEY` e `SECURE_AUTH_KEY` configuradas no seu `wp-config.php`.
2. **Exposição Zero**: O token nunca é exibido em texto limpo no HTML, campos de formulário ou logs de erros.
3. **Versões Suportadas**: Requer PHP 7.2+ (totalmente compatível com PHP 8+) e WordPress 5.8+.

---

### 📂 Estrutura de Pastas do Repositório

```text
├── codesync-manager-for-github/          # Código principal do plugin
│   ├── assets/
│   │   ├── css/admin.css             # Estilos customizados e modal premium
│   │   └── js/admin.js               # Lógica de abas, busca e chamadas AJAX do modal
│   ├── includes/
│   │   ├── class-admin.php           # Telas administrativas, AJAX e render do modal
│   │   ├── class-encryption.php      # Criptografia AES-256-GCM das credenciais
│   │   ├── class-github-api.php      # Cliente de chamadas à API do GitHub (com Trees API)
│   │   ├── class-manager.php         # Controle de banco de dados, diretórios e logs
│   │   └── class-updater.php         # Acoplamento de atualizações nativas do WordPress
│   ├── languages/                    # Arquivos binários (.mo) de traduções (PT, EN, ES)
│   ├── codesync-manager-for-github.php   # Arquivo principal do plugin
│   └── readme.txt                    # Documentação para o repositório WordPress.org
│
└── releases/                         # Pasta de distribuição contendo os pacotes ZIP
    └── codesync-manager-for-github-v1.0.6.zip
```

---

### 💾 Instalação e Configuração

1. Acesse a pasta `releases/` deste repositório e baixe o arquivo `.zip` da versão mais recente (`v1.0.6`).
2. No painel do seu WordPress, vá em **Plugins > Adicionar Novo > Enviar Plugin** e envie o arquivo ZIP.
3. Ative o plugin.
4. Acesse o menu **GitHub Sync** no painel lateral do WordPress.
5. Insira o seu Personal Access Token (PAT) do GitHub para autenticar.

---

## 🔒 License

This project is open-source software licensed under the **GNU General Public License v2 (or later)**. Feel free to use, modify, and contribute!
