<?php
function ensureInscricoesTable(mysqli $db): bool {
    static $checked = false;

    if ($checked) {
        return true;
    }

    $checked = true;
    $exists = $db->query("SHOW TABLES LIKE 'inscricoes'");
    if ($exists && $exists->num_rows > 0) {
        return true;
    }

    if (defined('DB_SCHEMA_MUTATIONS_ENABLED') && !DB_SCHEMA_MUTATIONS_ENABLED) {
        return false;
    }

    $sql = "
        CREATE TABLE inscricoes (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            atividade_id INT(10) UNSIGNED NOT NULL,
            usuario_id INT(10) UNSIGNED NOT NULL,
            data_inscricao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_inscricao_atividade_usuario (atividade_id, usuario_id),
            KEY fk_inscricao_atividade (atividade_id),
            KEY fk_inscricao_usuario (usuario_id),
            CONSTRAINT fk_inscricao_atividade FOREIGN KEY (atividade_id) REFERENCES atividades (id) ON DELETE CASCADE,
            CONSTRAINT fk_inscricao_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    return (bool)$db->query($sql);
}

function ensureNotificationsTable(mysqli $db): bool {
    static $checked = false;
    if ($checked) return true;
    $checked = true;

    if (defined('DB_SCHEMA_MUTATIONS_ENABLED') && !DB_SCHEMA_MUTATIONS_ENABLED) {
        $exists = $db->query("SHOW TABLES LIKE 'notificacoes'");
        return (bool)($exists && $exists->num_rows > 0);
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS notificacoes (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            usuario_id INT(10) UNSIGNED NOT NULL,
            mensagem TEXT NOT NULL,
            lida TINYINT(1) NOT NULL DEFAULT 0,
            data_criacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY fk_notificacao_usuario (usuario_id),
            CONSTRAINT fk_notificacao_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    return (bool)$db->query($sql);
}

function ensureEventActivitiesStructure(mysqli $db): bool {
    static $checked = false;

    if ($checked) {
        return true;
    }

    $checked = true;

    if (defined('DB_SCHEMA_MUTATIONS_ENABLED') && !DB_SCHEMA_MUTATIONS_ENABLED) {
        $t1 = $db->query("SHOW TABLES LIKE 'atividades_catalogo'");
        $t2 = $db->query("SHOW TABLES LIKE 'atividade_evento_itens'");
        $t3 = $db->query("SHOW TABLES LIKE 'atividade_evento_inscricoes'");
        return (bool)(
            $t1 && $t1->num_rows > 0 &&
            $t2 && $t2->num_rows > 0 &&
            $t3 && $t3->num_rows > 0
        );
    }

    $catalogSql = "
        CREATE TABLE IF NOT EXISTS atividades_catalogo (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            paroquia_id INT(10) UNSIGNED NOT NULL,
            nome VARCHAR(150) NOT NULL,
            descricao TEXT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_atividade_catalogo_nome (paroquia_id, nome),
            KEY fk_atividade_catalogo_paroquia (paroquia_id),
            CONSTRAINT fk_atividade_catalogo_paroquia FOREIGN KEY (paroquia_id) REFERENCES paroquias (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $itemsSql = "
        CREATE TABLE IF NOT EXISTS atividade_evento_itens (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            evento_id INT(10) UNSIGNED NOT NULL,
            atividade_catalogo_id INT(10) UNSIGNED NOT NULL,
            ordem INT(10) UNSIGNED NOT NULL DEFAULT 0,
            data_criacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            ultima_atualizacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_evento_atividade_catalogo (evento_id, atividade_catalogo_id),
            KEY fk_atividade_evento_item_evento (evento_id),
            KEY fk_atividade_evento_item_catalogo (atividade_catalogo_id),
            CONSTRAINT fk_atividade_evento_item_evento FOREIGN KEY (evento_id) REFERENCES atividades (id) ON DELETE CASCADE,
            CONSTRAINT fk_atividade_evento_item_catalogo FOREIGN KEY (atividade_catalogo_id) REFERENCES atividades_catalogo (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $enrollSql = "
        CREATE TABLE IF NOT EXISTS atividade_evento_inscricoes (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            evento_item_id INT(10) UNSIGNED NOT NULL,
            usuario_id INT(10) UNSIGNED NOT NULL,
            data_inscricao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_evento_item_usuario (evento_item_id, usuario_id),
            KEY fk_atividade_evento_inscricao_item (evento_item_id),
            KEY fk_atividade_evento_inscricao_usuario (usuario_id),
            CONSTRAINT fk_atividade_evento_inscricao_item FOREIGN KEY (evento_item_id) REFERENCES atividade_evento_itens (id) ON DELETE CASCADE,
            CONSTRAINT fk_atividade_evento_inscricao_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $ok = (bool)$db->query($catalogSql) && (bool)$db->query($itemsSql) && (bool)$db->query($enrollSql);

    // Remove timestamp columns from atividades_catalogo if they exist (cleanup)
    $colCheck = $db->query("SHOW COLUMNS FROM `atividades_catalogo` LIKE 'data_criacao'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $db->query("ALTER TABLE `atividades_catalogo` DROP COLUMN `data_criacao`");
    }
    $colCheck2 = $db->query("SHOW COLUMNS FROM `atividades_catalogo` LIKE 'ultima_atualizacao'");
    if ($colCheck2 && $colCheck2->num_rows > 0) {
        $db->query("ALTER TABLE `atividades_catalogo` DROP COLUMN `ultima_atualizacao`");
    }

    return $ok;
}

/**
 * Garante que a tabela atividade_grupos exista.
 */
function ensureAtividadeGruposTable(mysqli $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (defined('DB_SCHEMA_MUTATIONS_ENABLED') && !DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    $db->query("
        CREATE TABLE IF NOT EXISTS atividade_grupos (
            atividade_id INT(10) UNSIGNED NOT NULL,
            grupo_id INT(10) UNSIGNED NOT NULL,
            PRIMARY KEY (atividade_id, grupo_id),
            KEY fk_ag_atividade (atividade_id),
            KEY fk_ag_grupo (grupo_id),
            CONSTRAINT fk_ag_atividade FOREIGN KEY (atividade_id) REFERENCES atividades (id) ON DELETE CASCADE,
            CONSTRAINT fk_ag_grupo FOREIGN KEY (grupo_id) REFERENCES grupos_trabalho (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Salva a associaÃ§Ã£o de uma atividade com grupos.
 */
function db_has_column(mysqli $db, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($table === '' || $column === '') {
        return false;
    }

    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return (bool)($res && $res->num_rows > 0);
}

// 3. Global Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");

// 3.1. CSRF Protection
function ensurePermissionColumns(mysqli $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    $targets = [
        ['table' => 'usuarios', 'column' => 'perm_cadastrar_usuario'],
        ['table' => 'perfis', 'column' => 'perm_cadastrar_usuario'],
    ];

    foreach ($targets as $target) {
        $table = $target['table'];
        $column = $target['column'];
        $exists = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($exists && $exists->num_rows > 0) {
            continue;
        }
        $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` TINYINT(1) NULL DEFAULT NULL AFTER `perm_ver_restritos`");
    }
}

function ensureUserPermissionColumns(mysqli $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    $cols = [
        'perm_ver_calendario' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_criar_eventos' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_editar_eventos' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_excluir_eventos' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_ver_restritos' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_cadastrar_usuario' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_admin_usuarios' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_admin_sistema' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_ver_logs' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_gerenciar_catalogo' => "TINYINT(1) NULL DEFAULT NULL",
        'perm_gerenciar_grupos' => "TINYINT(1) NULL DEFAULT NULL",
    ];

    foreach ($cols as $col => $def) {
        $exists = $db->query("SHOW COLUMNS FROM `usuarios` LIKE '{$col}'");
        if ($exists && $exists->num_rows > 0) {
            continue;
        }
        $db->query("ALTER TABLE `usuarios` ADD COLUMN `{$col}` {$def}");
    }
}

function ensureUserProfileNameColumn(mysqli $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    $exists = $db->query("SHOW COLUMNS FROM `usuarios` LIKE 'perfil_nome'");
    if ($exists && $exists->num_rows > 0) {
        return;
    }

    $db->query("ALTER TABLE `usuarios` ADD COLUMN `perfil_nome` VARCHAR(50) NULL DEFAULT NULL");
}

function ensureUserPermissionsMaterialized(mysqli $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensureUserPermissionColumns($db);
    ensureUserProfileNameColumn($db);

    $requiredCols = [
        'perm_ver_calendario',
        'perm_criar_eventos',
        'perm_editar_eventos',
        'perm_excluir_eventos',
        'perm_ver_restritos',
        'perm_cadastrar_usuario',
        'perm_admin_usuarios',
        'perm_admin_sistema',
        'perm_ver_logs',
        'perm_gerenciar_catalogo',
        'perm_gerenciar_grupos',
    ];

    foreach ($requiredCols as $col) {
        if (!db_has_column($db, 'usuarios', $col)) {
            return;
        }
    }

    // Normaliza NULL -> 0 (permissÃµes devem existir na tabela usuarios)
    $db->query("
        UPDATE usuarios
        SET
            perm_ver_calendario = COALESCE(perm_ver_calendario, 0),
            perm_criar_eventos  = COALESCE(perm_criar_eventos, 0),
            perm_editar_eventos = COALESCE(perm_editar_eventos, 0),
            perm_excluir_eventos= COALESCE(perm_excluir_eventos, 0),
            perm_ver_restritos  = COALESCE(perm_ver_restritos, 0),
            perm_cadastrar_usuario = COALESCE(perm_cadastrar_usuario, 0),
            perm_admin_usuarios = COALESCE(perm_admin_usuarios, 0),
            perm_admin_sistema  = COALESCE(perm_admin_sistema, 0),
            perm_ver_logs       = COALESCE(perm_ver_logs, 0),
            perm_gerenciar_catalogo = COALESCE(perm_gerenciar_catalogo, 0),
            perm_gerenciar_grupos = COALESCE(perm_gerenciar_grupos, 0)
    ");
}


function ensureUserPhotoColumn(mysqli $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    $exists = $db->query("SHOW COLUMNS FROM `usuarios` LIKE 'foto_perfil'");
    if ($exists && $exists->num_rows > 0) {
        return;
    }

    $db->query("ALTER TABLE `usuarios` ADD COLUMN `foto_perfil` VARCHAR(255) NULL DEFAULT NULL AFTER `data_nascimento`");
}

function ensureUserLastLoginColumn(mysqli $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    $exists = $db->query("SHOW COLUMNS FROM `usuarios` LIKE 'ultimo_login'");
    if ($exists && $exists->num_rows > 0) {
        return;
    }

    $db->query("ALTER TABLE `usuarios` ADD COLUMN `ultimo_login` TIMESTAMP NULL DEFAULT NULL");
}

function ensurePerfisHierarchyRemoved(mysqli $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    $exists = $db->query("SHOW COLUMNS FROM `perfis` LIKE 'nivel_hierarquia'");
    if (!$exists || $exists->num_rows === 0) {
        return;
    }

    $db->query("ALTER TABLE `perfis` DROP COLUMN `nivel_hierarquia`");
}

function ensureAuthThrottleTable(mysqli $db): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    $db->query("
        CREATE TABLE IF NOT EXISTS auth_throttle (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(50) NOT NULL,
            identifier VARCHAR(191) NOT NULL,
            attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL DEFAULT NULL,
            last_attempt_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_scope_identifier (scope, identifier)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}


function ensureWorkingGroupsTables(mysqli $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (!DB_SCHEMA_MUTATIONS_ENABLED) {
        return;
    }

    // Table for Groups
    $db->query("
        CREATE TABLE IF NOT EXISTS grupos_trabalho (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            paroquia_id INT(10) UNSIGNED NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT NULL,
            cor VARCHAR(7) DEFAULT '#3b82f6',
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            visivel TINYINT(1) NOT NULL DEFAULT 1,
            data_criacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY fk_grupo_paroquia (paroquia_id),
            CONSTRAINT fk_grupo_paroquia FOREIGN KEY (paroquia_id) REFERENCES paroquias (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Table for N:N Relationship
    $db->query("
        CREATE TABLE IF NOT EXISTS usuario_grupos (
            usuario_id INT(10) UNSIGNED NOT NULL,
            grupo_id INT(10) UNSIGNED NOT NULL,
            paroquia_id INT(10) UNSIGNED NULL DEFAULT NULL,
            data_atribuicao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (usuario_id, grupo_id),
            KEY fk_ug_usuario (usuario_id),
            KEY fk_ug_grupo (grupo_id),
            KEY fk_ug_paroquia (paroquia_id),
            CONSTRAINT fk_ug_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
            CONSTRAINT fk_ug_grupo FOREIGN KEY (grupo_id) REFERENCES grupos_trabalho (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
