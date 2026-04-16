-- ═══════════════════════════════════════════════════════
-- PASCOM — Seed Data (Dados Iniciais)
-- ═══════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Inserir Paróquia Inicial (Obrigatório para o domínio)
INSERT INTO `paroquias` (`id`, `nome`, `cidade`, `estado`, `diocese`, `ativo`) 
VALUES (1, 'Paróquia Central Sistema', 'São Paulo', 'SP', 'Arquidiocese de SP', 1);

-- 2. Inserir Perfil Administrador (Regras Basilares)
INSERT INTO `perfis` (`id`, `paroquia_id`, `nome_perfil`, `descricao`, `perm_ver_calendario`, `perm_criar_eventos`, `perm_editar_eventos`, `perm_excluir_eventos`, `perm_ver_restritos`, `perm_admin_usuarios`, `perm_admin_sistema`, `perm_ver_logs`, `perm_cadastrar_usuario`) 
VALUES (1, 1, 'Administrador Master', 'Acesso total ao sistema', 1, 1, 1, 1, 1, 1, 1, 1, 1);

-- 3. Inserir Usuário Administrador de Teste
-- E-mail: admin@sistema.com
-- Senha: Admin123 (Hashed com PASSWORD_DEFAULT)
INSERT INTO `usuarios` (
    `id`, `nome`, `email`, `senha`, `perfil_id`, `paroquia_id`, `ativo`, 
    `nivel_acesso`, `perfil_nome`, `perm_ver_calendario`, `perm_criar_eventos`, 
    `perm_editar_eventos`, `perm_excluir_eventos`, `perm_ver_restritos`, 
    `perm_cadastrar_usuario`, `perm_admin_usuarios`, `perm_admin_sistema`, `perm_ver_logs`
) VALUES (
    1, 'Administrador do Sistema', 'admin@sistema.com', 
    '$2y$10$eFH4CtXjys41UBWNVg5iiecsABVm8apJyHhvE0n5bAlx5GM6ZSzcC', 
    1, 1, 1, 0, 'Administrador Master', 
    1, 1, 1, 1, 1, 1, 1, 1, 1
);

SET FOREIGN_KEY_CHECKS = 1;
