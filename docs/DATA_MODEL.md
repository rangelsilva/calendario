# 🗄️ Modelo de Dados (Schema) - PASCOM V2

O PASCOM System baseia-se em um modelo relacional focado em multi-tenancy a nível de Paróquia (`paroquia_id` como chave organizacional primária) e num Controle de Acesso Baseado em Função (RBAC) extremamente flexível.

## 🏢 1. Estruturas Organizacionais Nucleares

### `paroquias`
Entidade raiz multi-tenant. Cada usuário, evento, grupo e localização pertence estritamente a uma paróquia, garantindo isolamento da base de dados lógica num único banco físico.
- **Relacionamentos:** 1:N com `usuarios`, `atividades`, `locais_paroquia`, `grupos_trabalho`.

### `locais_paroquia` e `tipos_atividade`
Tabelas de metadados dimensionais auxiliares que classificam onde e qual o contexto de uma atividade agendada.

---

## 👥 2. Controle de Acesso (RBAC) e Identidade

### `usuarios`
Centraliza a identidade física de quem acessa o sistema.
- **Segurança:** Senhas em hash forte (Bcrypt), proteções associadas a limite de erros (`auth_throttle`) e armazenamento de tokens de palavras-chave.
- **RBAC Atrelado:** Acesso a granularidade de permissões booleanas exatas (`perm_ver_calendario`, `perm_criar_eventos`, etc) e uma referência ao `nivel_acesso` (0 = Master, 7 = Visitante) para ditar hierarquia impenetrável de modificação.

### `perfis`
Presets (Templates) de permissões. Facilita a inicialização de contas padronizadas para não preencher flags individualmente a cada novo `usuario`.

### `grupos_trabalho` e `usuario_grupos`
Cria silos horizontais de acesso de Pastoral/Departamentos, permitindo visibilidade parcial do panorama do calendário dependendo do grupo envolvido na `atividade`.

---

## 📅 3. Motor Transacional de Eventos

### `atividades`
Coracão do PASCOM. Representa um evento canônico no tempo e no espaço.
- **Principais Campos:** `nome`, `data_inicio`, `hora_inicio`, `is_multi_color`, e a flag de segurança estrita `restrito`.
- **Relacionamentos cruciais:** Pertence a 1 `usuario` (Criador), a 1 `paroquia`, e pode se dividir para múltiplos `grupos_trabalho` através da tabela pivô `atividade_grupos`.

### `atividades_catalogo` & `atividade_evento_itens`
Sub-camada transacional que divide um Evento macro em **Micro-Eventos**.
- *Exemplo*: Evento "Acampamento". Micro-Itens: "Alojamento das 20h", "Vigília das 00h".

### `inscricoes` & `atividade_evento_inscricoes`
Tabelas transacionais de altíssima concorrência. Registram a participação em tempo real que um `usuario` manifesta num Evento (`atividades`) ou em um sub-item específico das atividades. Possuem `UNIQUE KEY` de idempotência.

---

## 🛡️ 4. Segurança, Logs e Resiliência

### `log_alteracoes`
Auditoria contínua (Audit Trail). Rastreia todas as mutações destrutivas e construtivas no banco (ex: Edição de perfis, alterações em eventos críticos).

### `auth_throttle`
Proteção anti-Bruteforce que trava contas ou IPs baseados em número de tentativas contra o Gateway de entrada.
