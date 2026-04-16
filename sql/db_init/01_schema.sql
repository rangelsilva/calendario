SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE `atividade_evento_inscricoes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `evento_item_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `data_inscricao` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `evento_item_id` (`evento_item_id`,`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `atividade_evento_itens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `evento_id` int(10) unsigned NOT NULL,
  `atividade_catalogo_id` int(10) unsigned NOT NULL,
  `ordem` int(10) unsigned NOT NULL DEFAULT 0,
  `data_criacao` timestamp NULL DEFAULT current_timestamp(),
  `ultima_atualizacao` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `evento_id` (`evento_id`,`atividade_catalogo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `atividade_grupos` (
  `atividade_id` int(10) unsigned NOT NULL,
  `grupo_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`atividade_id`,`grupo_id`),
  KEY `fk_ag_atividade` (`atividade_id`),
  KEY `fk_ag_grupo` (`grupo_id`),
  CONSTRAINT `fk_ag_atividade` FOREIGN KEY (`atividade_id`) REFERENCES `atividades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ag_grupo` FOREIGN KEY (`grupo_id`) REFERENCES `grupos_trabalho` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `atividades` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `cor` varchar(7) DEFAULT '#3b82f6',
  `data_inicio` date NOT NULL,
  `hora_inicio` time DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `hora_fim` time DEFAULT NULL,
  `local_id` int(10) unsigned DEFAULT NULL,
  `tipo_atividade_id` int(10) unsigned DEFAULT NULL,
  `categoria_id` int(10) unsigned DEFAULT NULL,
  `criador_id` int(10) unsigned DEFAULT NULL,
  `paroquia_id` int(10) unsigned DEFAULT NULL,
  `status` varchar(50) DEFAULT 'ativo',
  `restrito` tinyint(1) DEFAULT 0,
  `vagas` int(10) unsigned DEFAULT 0,
  `inscricoes_abertas` tinyint(1) DEFAULT 1,
  `serie_key` varchar(100) DEFAULT NULL,
  `serie_frequencia` varchar(50) DEFAULT NULL,
  `serie_dias_semana` varchar(100) DEFAULT NULL,
  `serie_data_fim` date DEFAULT NULL,
  `data_criacao` timestamp NULL DEFAULT current_timestamp(),
  `ultima_atualizacao` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_multi_color` tinyint(1) DEFAULT 0,
  `is_flashing` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_atividades_paroquia` (`paroquia_id`),
  KEY `fk_atividades_local` (`local_id`),
  KEY `fk_atividades_tipo` (`tipo_atividade_id`),
  CONSTRAINT `fk_atividades_local` FOREIGN KEY (`local_id`) REFERENCES `locais_paroquia` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_atividades_paroquia` FOREIGN KEY (`paroquia_id`) REFERENCES `paroquias` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_atividades_tipo` FOREIGN KEY (`tipo_atividade_id`) REFERENCES `tipos_atividade` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `atividades_catalogo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paroquia_id` int(10) unsigned NOT NULL,
  `nome` varchar(150) NOT NULL,
  `descricao` text DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paroquia_id` (`paroquia_id`,`nome`),
  CONSTRAINT `fk_catalogo_paroquia` FOREIGN KEY (`paroquia_id`) REFERENCES `paroquias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `auth_throttle` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(50) NOT NULL,
  `identifier` varchar(191) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `scope` (`scope`,`identifier`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `grupos_trabalho` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paroquia_id` int(10) unsigned NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `cor` varchar(7) DEFAULT '#3b82f6',
  `visivel` tinyint(1) DEFAULT 1,
  `ativo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `inscricoes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `atividade_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned NOT NULL,
  `data_inscricao` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `atividade_id` (`atividade_id`,`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `locais_paroquia` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paroquia_id` int(10) unsigned NOT NULL,
  `nome_local` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `endereco` varchar(255) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `responsavel` varchar(100) DEFAULT NULL,
  `capacidade` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `log_alteracoes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `acao` varchar(255) NOT NULL,
  `tabela_afetada` varchar(100) DEFAULT NULL,
  `registro_id` int(10) unsigned DEFAULT NULL,
  `detalhes_alteracao` text DEFAULT NULL,
  `paroquia_id` int(10) unsigned DEFAULT NULL,
  `ip_origem` varchar(45) DEFAULT NULL,
  `data_hora` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notificacoes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned NOT NULL,
  `mensagem` text NOT NULL,
  `lida` tinyint(1) NOT NULL DEFAULT 0,
  `data_criacao` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_notificacao_usuario` (`usuario_id`),
  CONSTRAINT `fk_notificacao_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `paroquias` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `estado` char(2) DEFAULT NULL,
  `diocese` varchar(255) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `perfis` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paroquia_id` int(10) unsigned NOT NULL DEFAULT 2,
  `nome_perfil` varchar(80) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `perm_ver_calendario` tinyint(1) DEFAULT 1,
  `perm_criar_eventos` tinyint(1) DEFAULT 0,
  `perm_editar_eventos` tinyint(1) DEFAULT 0,
  `perm_excluir_eventos` tinyint(1) DEFAULT 0,
  `perm_ver_restritos` tinyint(1) DEFAULT 0,
  `perm_admin_usuarios` tinyint(1) DEFAULT 0,
  `perm_admin_sistema` tinyint(1) DEFAULT 0,
  `perm_ver_logs` tinyint(1) DEFAULT 0,
  `perm_cadastrar_usuario` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tabelaperfil` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paroquia_id` int(10) unsigned NOT NULL DEFAULT 2,
  `nome_perfil` varchar(80) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `perm_ver_calendario` tinyint(1) DEFAULT 1,
  `perm_criar_eventos` tinyint(1) DEFAULT 0,
  `perm_editar_eventos` tinyint(1) DEFAULT 0,
  `perm_excluir_eventos` tinyint(1) DEFAULT 0,
  `perm_ver_restritos` tinyint(1) DEFAULT 0,
  `perm_admin_usuarios` tinyint(1) DEFAULT 0,
  `perm_admin_sistema` tinyint(1) DEFAULT 0,
  `perm_ver_logs` tinyint(1) DEFAULT 0,
  `perm_cadastrar_usuario` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tipos_atividade` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paroquia_id` int(10) unsigned DEFAULT NULL,
  `nome_tipo` varchar(100) NOT NULL,
  `cor` varchar(7) DEFAULT NULL,
  `icone` varchar(50) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `usuario_grupos` (
  `usuario_id` int(10) unsigned NOT NULL,
  `grupo_id` int(10) unsigned NOT NULL,
  `paroquia_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`usuario_id`,`grupo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `usuarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `perfil_id` int(10) unsigned DEFAULT NULL,
  `paroquia_id` int(10) unsigned DEFAULT NULL,
  `sexo` varchar(20) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `palavra_chave` varchar(255) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `perm_criar_eventos` tinyint(1) DEFAULT 0,
  `perm_editar_eventos` tinyint(1) DEFAULT 0,
  `perm_excluir_eventos` tinyint(1) DEFAULT 0,
  `perm_ver_restritos` tinyint(1) DEFAULT 0,
  `perm_cadastrar_usuario` tinyint(1) DEFAULT 0,
  `perm_admin_usuarios` tinyint(1) DEFAULT 0,
  `perm_ver_logs` tinyint(1) DEFAULT 0,
  `data_criacao` timestamp NULL DEFAULT current_timestamp(),
  `ultimo_login` timestamp NULL DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `nivel_acesso` int(10) DEFAULT 0,
  `perfil_nome` varchar(50) DEFAULT NULL,
  `perm_ver_calendario` tinyint(1) DEFAULT 1,
  `perm_admin_sistema` tinyint(1) DEFAULT 0,
  `perm_gerenciar_catalogo` tinyint(1) DEFAULT 0,
  `perm_gerenciar_grupos` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_usuario_perfil` (`perfil_id`),
  KEY `fk_usuario_paroquia` (`paroquia_id`),
  CONSTRAINT `fk_usuario_paroquia` FOREIGN KEY (`paroquia_id`) REFERENCES `paroquias` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usuario_perfil` FOREIGN KEY (`perfil_id`) REFERENCES `perfis` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
