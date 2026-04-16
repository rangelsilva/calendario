<?php
function getWorkingGroups(mysqli $db, int $paroquiaId, bool $incluirInativos = false): array {
    $sql = "SELECT * FROM grupos_trabalho WHERE paroquia_id = ? " . ($incluirInativos ? "" : "AND ativo = 1") . " ORDER BY nome ASC";
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $paroquiaId);
    $stmt->execute();
    $res = $stmt->get_result();
    $grupos = [];
    while ($row = $res->fetch_assoc()) {
        $grupos[] = $row;
    }
    return $grupos;
}

/**
 * Retorna os IDs dos grupos aos quais um usuÃ¡rio pertence
 */
function getUserGroups(mysqli $db, int $userId, ?int $paroquiaId = null): array {
    if ($paroquiaId !== null) {
        $sql = "SELECT grupo_id FROM usuario_grupos WHERE usuario_id = ? AND paroquia_id = ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('ii', $userId, $paroquiaId);
    } else {
        $sql = "SELECT grupo_id FROM usuario_grupos WHERE usuario_id = ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $userId);
    }
    
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['grupo_id'];
    }
    return $ids;
}

/**
 * Salva as associaÃ§Ãµes de um usuÃ¡rio com grupos de trabalho
 */
function saveUserGroups(mysqli $db, int $userId, array $groupIds, ?int $paroquiaId = null): bool {
    if ($paroquiaId === null) {
        $paroquiaId = current_paroquia_id();
    }
    
    // Primeiro remove todas as associaÃ§Ãµes atuais NA PAROQUIA
    $stmtDel = $db->prepare("DELETE FROM usuario_grupos WHERE usuario_id = ? AND paroquia_id = ?");
    if ($stmtDel) {
        $stmtDel->bind_param('ii', $userId, $paroquiaId);
        $stmtDel->execute();
    }

    if (empty($groupIds)) return true;

    // Depois insere as novas
    $stmtIns = $db->prepare("INSERT IGNORE INTO usuario_grupos (usuario_id, grupo_id, paroquia_id) VALUES (?, ?, ?)");
    if (!$stmtIns) return false;

    foreach ($groupIds as $gid) {
        $gid = (int)$gid;
        if ($gid > 0) {
            $stmtIns->bind_param('iii', $userId, $gid, $paroquiaId);
            $stmtIns->execute();
        }
    }
    return true;
}

/**
 * Garante que o grupo padrÃ£o 'Todos' exista na parÃ³quia
 * e que todos os usuÃ¡rios da parÃ³quia estejam nele.
 */
function ensureDefaultVisitorGroup(mysqli $db, int $paroquiaId): void {
    if ($paroquiaId <= 0) return;

    // Migrar nome antigo 'Visitante' -> 'Todos' se existir
    $stmtUpd = $db->prepare("UPDATE grupos_trabalho SET nome = 'Todos', descricao = 'Grupo padrÃ£o â€” todos os membros da parÃ³quia', visivel = 1 WHERE paroquia_id = ? AND nome = 'Visitante'");
    if ($stmtUpd) {
        $stmtUpd->bind_param('i', $paroquiaId);
        $stmtUpd->execute();
        $stmtUpd->close();
    }
    
    // Verificar se 'Todos' jÃ¡ existe
    $check = $db->prepare("SELECT id FROM grupos_trabalho WHERE paroquia_id = ? AND nome = 'Todos' LIMIT 1");
    if (!$check) return;
    $check->bind_param('i', $paroquiaId);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $grupoId = (int)$row['id'];
    } else {
        // Criar grupo 'Todos'
        $stmt = $db->prepare("INSERT INTO grupos_trabalho (paroquia_id, nome, descricao, cor, visivel) VALUES (?, 'Todos', 'Grupo padrÃ£o â€” todos os membros da parÃ³quia', '#3b82f6', 1)");
        if (!$stmt) return;
        $stmt->bind_param('i', $paroquiaId);
        $stmt->execute();
        $grupoId = (int)$db->insert_id;
    }

    if ($grupoId <= 0) return;

    // Adicionar todos os usuÃ¡rios da parÃ³quia que ainda nÃ£o estÃ£o no grupo
    $stmtInsUsers = $db->prepare("
        INSERT IGNORE INTO usuario_grupos (usuario_id, grupo_id, paroquia_id)
        SELECT u.id, ?, ?
        FROM usuarios u
        WHERE u.paroquia_id = ?
          AND u.id NOT IN (SELECT usuario_id FROM usuario_grupos WHERE grupo_id = ?)
    ");
    if ($stmtInsUsers) {
        $stmtInsUsers->bind_param('iiii', $grupoId, $paroquiaId, $paroquiaId, $grupoId);
        $stmtInsUsers->execute();
        $stmtInsUsers->close();
    }
}

/**
 * Salva as associaÃ§Ãµes de um usuÃ¡rio com grupos de trabalho, 
 * respeitando o escopo do administrador logado (merge inteligente).
 */
function saveUserGroupsScoped(mysqli $db, int $userId, array $postGroupIds, array $manageableGroupIds, ?int $paroquiaId = null): bool {
    if ($paroquiaId === null) {
        $paroquiaId = current_paroquia_id();
    }

    // 1. Converter tudo para int para seguranÃ§a
    $postGroupIds = array_map('intval', $postGroupIds);
    $manageableGroupIds = array_map('intval', $manageableGroupIds);

    // 2. Buscar grupos ATUAIS do usuÃ¡rio no banco (apenas desta parÃ³quia para o escopo local)
    $currentTargetGroups = getUserGroups($db, $userId, $paroquiaId);

    // 3. Identificar grupos que o admin NÃƒO pode gerenciar (devem ser mantidos)
    $groupsToKeep = array_diff($currentTargetGroups, $manageableGroupIds);

    // 4. Identificar grupos que o admin QUER associar (devem estar no escopo dele)
    $groupsToAdd = array_intersect($postGroupIds, $manageableGroupIds);

    // 5. Novo conjunto final = Mantidos + Adicionados
    $finalGroupIds = array_unique(array_merge($groupsToKeep, $groupsToAdd));

    // 6. Aplicar salvamento padrÃ£o
    return saveUserGroups($db, $userId, $finalGroupIds, $paroquiaId);
}

function saveActivityGroups(mysqli $db, int $atividadeId, array $groupIds): bool {
    ensureAtividadeGruposTable($db);
    
    $stmtDel = $db->prepare("DELETE FROM atividade_grupos WHERE atividade_id = ?");
    if ($stmtDel) {
        $stmtDel->bind_param('i', $atividadeId);
        $stmtDel->execute();
    }

    if (empty($groupIds)) return true;

    $stmtIns = $db->prepare("INSERT INTO atividade_grupos (atividade_id, grupo_id) VALUES (?, ?)");
    if (!$stmtIns) return false;

    foreach ($groupIds as $gid) {
        $gid = (int)$gid;
        if ($gid > 0) {
            $stmtIns->bind_param('ii', $atividadeId, $gid);
            $stmtIns->execute();
        }
    }
    return true;
}

/**
 * Retorna os IDs dos grupos associados a uma atividade.
 */
function getActivityGroups(mysqli $db, int $atividadeId): array {
    ensureAtividadeGruposTable($db);
    $sql = "SELECT grupo_id FROM atividade_grupos WHERE atividade_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $atividadeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['grupo_id'];
    }
    return $ids;
}

/**
 * Retorna o ID do grupo padrÃ£o "Todos" da parÃ³quia, quando existir.
 */
function getDefaultTodosGroupId(mysqli $db, int $paroquiaId): int {
    if ($paroquiaId <= 0) return 0;
    $stmt = $db->prepare("SELECT id FROM grupos_trabalho WHERE paroquia_id = ? AND nome = 'Todos' LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $paroquiaId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['id'] ?? 0);
}

// --------------------
// Usuario: niveis/perfis (sem alterar schema)
// --------------------

// Convencao: numero menor = mais privilegio. O usuario logado so pode atribuir
// niveis "iguais ou abaixo" na hierarquia, ou seja: nivel >= meu nivel.
