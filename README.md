# ⛪ Sistema Pastoral PASCOM V2

O PASCOM System (Agenda & Pastoral) é uma arquitetura robusta de Gestão Paroquial centralizada num calendário tático de alta performance. Adota os conceitos de _Premium Fluid UI_ (Sensação limpa de Native App) e _Zero-Trust Security_ em seu back-end Vanilla estruturado.

## 🌟 Principais Funcionalidades

- **Multi-tenant Dinâmico:** Multiplas Paróquias operando num mesmo banco de dados sem vazamentos laterais.
- **RBAC Hierárquico Universal:** Mais de 7 instâncias de privilégio, com proteção visual modularizada por perfis, grupos de trabalho restritos, permissões boolianas cruzadas e visão customizada.
- **Agendamento em Alta Escalabilidade:** Ferramenta complexa de Atividades, Micro-catalogações e Sub-Inscrições de pessoas. Perfeito para separar o *Culto* das *Vigílias departamentais*.
- **Arquitetura Anti-Burrice & Anti-Ataques:** Spinners de transação para controle de ansiedade de clique, motor universal reescrito anti-SQL Injection com `mysqli Statements`, e Guardas CSRF para blindagem de ações deletérias via bots ou clicks errôneos de líderes inexperientes.

## ⚙️ Pré-Requisitos Mínimos

A infraestrutura foi modernizada para **DevSecOps**, eliminando dependências sujas na máquina local. Você só precisará de:
1. **Docker Desktop** (ou motor Docker equivalente rodando).
2. **Composer** instalado globalmente.

O projeto é 100% conteinerizado isolando perfeitamente o ambiente.

## 🚀 Como Executar Localmente (Ambiente Dev)

Todo o ecossistema roda através do `composer`, simulando a elegância do ecossistema JS na fundação PHP.

1. **Faça o Clone do Repositório**:
   ```bash
   git clone https://github.com/BrunoRochaBelo/calendario.git
   cd calendario
   ```
2. **Instale as Bibliotecas**:
   O motor resolverá o FPDF e a suíte PHPUnit.
   ```bash
   composer install
   ```
3. **Suba os Motores**:
   ```bash
   composer dev
   ```
   *Mágica acontece aqui: o gancho copia seu `.env` caso não exista, sobe os containers do MariaDB em modo *tmpfs* seguro sob chaves *SHA-256*, instaura o Apache rodando como `www-data` e espeta tudo num túnel na porta `8080`, ligando simultaneamente os Logs ao vivo em Stream!*

4. **Acesse**:
   Navegue para 👉 `http://localhost:8080/`

### Comandos de Utilidade (A qualquer momento)

- Cortar a orquestração: `composer down`
- Acessar o banco MySQL na nuvem do Docker: `composer db`
- Acessar o bash do Apache localmente: `composer shell`
- Rodar Testes de Unidade Seguros: `composer test`

---
_Aprofunde-se tecnicamente nos meandros lendo o arquivo [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) e estude as abstrações no [docs/DATA_MODEL.md](docs/DATA_MODEL.md)._
