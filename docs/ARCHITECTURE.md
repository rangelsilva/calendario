# 🏗️ Arquitetura e Engenharia de Software - PASCOM V2

Bem-vindo à engenharia profunda do PASCOM. Nossa prioridade arquitetural foca no **Performance First**, **Zero-Friction UX** e **Secure-by-Design** implementados num ecossistema 100% nativo Vanilla (sem abstrações obesas).

## 🧩 O Paradigma de Arquitetura

Nós utilizamos uma premissa **Procedural Estruturada Híbrida** injetada com Componentes e Padrões da orientação a objeto onde faz sentido (Data Access Objects).

### Camada 1: Database Access Wrapper (SOLID SRE)
### Camada 1: Database Access Wrapper (SOLID SRE)
Centralizada em `includes/db.php`:
O antigo padrão de acoplamento entre Views e queries em texto aberto espalhadas pelos arquivos foi aposentado. Estruturamos um **Wrapper** rigoroso:
- **Prevenção Real:** Toda query repassada é obrigada a passar como Statement Preparado, travando SQL Injection de forma assintomática global.
- **Isolamento de Negócios:** Módulos quebrados como `includes/rbac.php`, `includes/groups.php` retiram toda a lógica engessada do arquivo index principal.

### Camada 2: Proteção Middleware (Anti-CSRF, RCE Mitigation & HSTS)
- Toda comunicação de mutação de estado (POST/DELETE) é blindada via **CSRF Guards** gerados server-side rotacionados por requisição em `includes/auth.php`.
- Trava de Upload rígida nos sistemas de cadastro bloqueiam *Remote Code Execution (RCE)* validando magic bytes através de whitelists com PATHINFO.
- O Config Base injeta `Content-Security-Policy (CSP)` estritas no Client, assegurando confiança "Zero-Trust".
- Containers rodam num modo *Rootless* onde a pasta Web exige UID `www-data` e o MySQL roda preso a memórias voláteis em `/tmp`.

### Camada 3: Motor UI/UX "Premium Glassmorphism" e Observabilidade Nativa
- **Heurística de Prevenção a Erros:** Form Skeletons evitam clicks repetidos de salvamento e disparam *Spinners* visuais.
- **Estética:** Sistema sólido de variáveis CSS3 sem necessidade de *Webpack/Vite*.
- **Cloud-Logging:** Auditoria via banco cruza com Stream de eventos unificados (JSONs de evento `error_log` enviados diretamente ao *Docker Daemon* para serem monitorados do terminal central).

---

## 📂 Árvore de Diretórios e Fluxo

```mermaid
graph TD
    A[Docker Gateway :8080] -->|Proxying| B{Apache Handler}
    B --> C[config.php / vendor autoload]
    C -->|Session & CSP Headers| D[Painéis Principais]
    C -->|DB Manager| H[(MariaDB Container | tmpfs)]
    D --> E[index.php / Calendário Central]
    D --> F[atividades.php / Fluxogramas]
    D --> G[usuarios.php / Gestão RBAC]
    D -->|Sensorial UI| I[sidebar.php / Estilos CSS]
```

## 🔗 Dependências & Docker

A infra-estrutura prega a filosofia da Imutabilidade `as Code`:
- Imagens do Docker travadas por **SHA-256 digests**.
- Orquestração completa provida via Hookings do `composer.json` num comando maestro (`composer dev`).
- Autoloader do Composer ativando pontes externas de relatórios PDF (`Setasign FPDF`) e a suíte de qualificação anti-regressão interna baseada via **PHPUnit**.
