# 📋 Requisitos e Regras de Negócio - PASCOM V2

Abaixo consolidamos todas as regras de negócio intrínsecas ao Sistema PASCOM (Agenda Multi-Paroquial e Gestão RBAC), devidamente extraídas da engenharia de código atual. Serve como o Documento Fundamental de Requisitos da plataforma.

---

## 🎯 1. Requisitos Funcionais (Core)

1. **Autenticação e Multi-Tenancy:**
   - O sistema deve bloquear qualquer acesso não autenticado (exceto rotas públicas de login/recuperação).
   - O sistema deve isolar fisicamente dados entre diferentes Paróquias (Tenants), mas permitir que Administradores Master globais transitem entre elas.
2. **Gestão de Perfil de Usuários:**
   - O sistema deve permitir a atribuição granular de permissões por usuário (ver calendário, criar/editar/excluir eventos, gerenciar usuários, etc.).
   - O sistema deve suportar Perfis Prontos (Presets de grupo) que autopreencham os cheques de permissões no cadastro.
3. **Agenda Institucional Escalonada:**
   - O sistema deve criar Eventos Canônicos restritos a uma Paróquia, podendo abranger um Local geográfico e um Tipo específico.
   - O evento deve possibilitar a quebra em "Sub-Atividades" ou Trilhas baseadas no Catálogo (Ex: Evento Central = "Acampamento". Trilhas: "Equipe de Cozinha", "Equipe do Estacionamento").
4. **Sistema de Inscrição / Presença:**
   - Qualquer membro autenticado da paróquia (desde que possua visibilidade do evento) tem o direito de realizar sua Inscrição/Check-in digital.
   - A inscrição pode ocorrer no Evento Geral ou nas Sub-atividades específicas.

---

## 💼 2. Regras de Negócio (Business Logic)

### 2.1 Multi-Tenancy e Visibilidade Horizontal
- **RN01 - Isolamento de Dados:** Um usuário nivel Paróquia nunca visualiza eventos, locais, sub-atividades ou usuários originários de outra Paróquia (`WHERE paroquia_id = $my_pid`).
- **RN02 - Permissão Divina (Master):** Apenas contas de `nivel_acesso = 0` (Master) ou IDs Fundadores (ex: `id = 1`) são isentas do RN01 e podem visualizar a malha geral.

### 2.2 Hierarquia Vertical e Segurança Funcional (RBAC Limítrofe)
- **RN03 - Teto Administrativo:** Usuários não podem editar, reverter ou deletar a conta de membros que possuam um Nível de Acesso **maior ou igual** ao deles (Exemplo numérico: um Nível 3 nunca pode excluir a conta de outro Nível 3 ou de um Nível 1 Admin). Eles atuam somente na pirâmide para baixo.
- **RN04 - Auto-Preservação:** Os usuários têm direito a redefinir sua própria senha, avatar e nome. Porém não possuem direito de escalar seus próprios privilégios (Level/Perfis) sem intermédio de um superior.

### 2.3 Visibilidade do Calendário (Regras de Evento e Grupos)
- **RN05 - Publico vs Restrito:** Se ao criar um evento, nenhum *Grupo de Trabalho* for assinalado, o evento torna-se público à vista de TODOS os membros da Paróquia.
- **RN06 - Isolamento por Grupos:** Ao designar um evento para 1 ou mais *Grupos de Trabalho*, membros que não pertencem a estes grupos não verão o card transitar no Calendário Geral (Exceto Master Admins e utilizadores com flag de by-pass `perm_ver_restritos`).
- **RN07 - Sigilo Total (Eventos Restritos):** A checkbox `Evento Restrito` sobrepõe qualquer pertinência. Se o autor marcar um evento como restrito, apenas o Próprio Autor e perfis com a permissão administrativa expressa de Ver Restritos e Chegar a Visualizar poderão abrir seus anexos/inscrições.

### 2.4 Prazos Críticos e Logística Operacional (Inscrições)
- **RN08 - Trava de Inscrição Única:** O backend barra rigidamente uma conta de efetuar duplo-inscrição em uma mesma atividade matriz via Constraint DB.
- **RN09 - Lock de Fugas (Deadline de Desistência):** Nenhum integrante pode clicar no botão "Desistir/Cancelar Inscrição" se faltarem menos de **24 Horas** (T-86400s) para o horário de início do evento. Exceção à regra: Administradores/Organizadores (Nível 3 para cima) que conservam o poder de trancar/destrancar manualmente as lógicas da pauta.

### 2.5 Resiliência e Auditoria Completa
- **RN10 - Registro Imutável:** É terminantemente proibido que CRUDS primários (Edição de Usuários, Suspensão de Contas, Criação de Eventos Críticos) existam sem gerar um espelho no `log_alteracoes` especificando Metadados Antigos Vs Metadados Novos Atuais e quem operou o botão final.
- **RN11 - Anti-Bruteforce Login:** Proteção restrita implementada em `auth_throttle`. Erros contínuos disparam gatilhos transacionais de negação de serviço ao respectivo Endpoint.
