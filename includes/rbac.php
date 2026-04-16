<?php
function selectable_access_levels_for_user(int $myLevel, bool $isMaster, int $maxLevel = 7): array {
    if (!$isMaster && $myLevel > $maxLevel) {
        $myLevel = $maxLevel;
    }
    $min = $isMaster ? 0 : max(0, $myLevel);
    $max = max($min, $maxLevel);
    $levels = [];
    for ($lvl = $min; $lvl <= $max; $lvl++) {
        $levels[] = $lvl;
    }
    return $levels;
}

// Infere um "nivel" do perfil usando permissÃµes ja existentes na tabela perfis.
// Isso serve apenas para FILTRAR o dropdown, nao muda a logica de permissao do sistema.
function infer_perfil_nivel(array $perfilRow): int {
    $adminSistema = (int)($perfilRow['perm_admin_sistema'] ?? 0);
    $adminUsuarios = (int)($perfilRow['perm_admin_usuarios'] ?? 0);
    $cadastrarUser = (int)($perfilRow['perm_cadastrar_usuario'] ?? 0);
    $verRestritos = (int)($perfilRow['perm_ver_restritos'] ?? 0);
    $criar = (int)($perfilRow['perm_criar_eventos'] ?? 0);
    $editar = (int)($perfilRow['perm_editar_eventos'] ?? 0);
    $excluir = (int)($perfilRow['perm_excluir_eventos'] ?? 0);

    if ($adminSistema || $adminUsuarios) return 1; // Administrador
    if ($cadastrarUser) return 2; // Gerente
    if ($verRestritos || $criar || $editar || $excluir) return 3; // Supervisor
    return 7; // Visitante
}

function list_perfis_for_user(mysqli $db, int $myPerfilId, bool $isMaster): array {
    // Regra: perfis.id menor = maior privilegio. Mostrar apenas "igual ou abaixo":
    // id >= meu_perfil_id (exceto master que ve todos).
    $paroquiaId = current_paroquia_id();
    $rows       = [];

    $SQL_BASE = "
        SELECT
            p.id,
            COALESCE(
                NULLIF(MAX(NULLIF(TRIM(p.nome_perfil), '')), ''),
                NULLIF(MAX(NULLIF(TRIM(u.perfil_nome), '')), ''),
                CONCAT('Perfil #', p.id)
            ) AS nome
        FROM perfis p
        LEFT JOIN usuarios u ON u.perfil_id = p.id
    ";

    // Closure auxiliar: prepara, vincula parÃ¢metros e executa uma query.
    $execQuery = static function (string $sql, string $types = '', mixed ...$binds) use ($db): ?\mysqli_result {
        $stmt = $db->prepare($sql);
        if (!$stmt) return null;
        if ($types !== '') {
            $stmt->bind_param($types, ...$binds);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result ?: null;
    };

    if ($paroquiaId > 0) {
        $res = $execQuery($SQL_BASE . " WHERE p.paroquia_id = ? GROUP BY p.id ORDER BY p.id ASC", 'i', $paroquiaId);

        // Fallback: se nÃ£o houver perfis na parÃ³quia, exibe todos (contextos globais).
        if ($res !== null && $res->num_rows === 0) {
            $res = $execQuery($SQL_BASE . " GROUP BY p.id ORDER BY p.id ASC");
        }
    } else {
        $res = $execQuery($SQL_BASE . " GROUP BY p.id ORDER BY p.id ASC");
    }

    if (!$res) return [];

    while ($r = $res->fetch_assoc()) {
        $perfilId = (int)($r['id'] ?? 0);
        if ($isMaster || ($myPerfilId > 0 && $perfilId >= $myPerfilId)) {
            $r['nivel_inferido'] = $perfilId;
            $rows[] = $r;
        }
    }

    return $rows;
}

function pick_default_perfil_id(array $perfis, int $fallback = 9): int {
    if (!$perfis) return $fallback;

    // Prioriza VISITANTE se estiver disponivel, senao pega o de maior nivel_inferido.
    foreach ($perfis as $p) {
        $nome = strtoupper(trim((string)($p['nome'] ?? '')));
        if ($nome === 'VISITANTE') {
            return (int)($p['id'] ?? $fallback);
        }
    }

    $bestId = $fallback;
    $bestNivel = -1;
    foreach ($perfis as $p) {
        $nivel = (int)($p['nivel_inferido'] ?? 0);
        if ($nivel > $bestNivel) {
            $bestNivel = $nivel;
            $bestId = (int)($p['id'] ?? $fallback);
        }
    }
    return $bestId;
}
function loadPermissions(mysqli $db, int $userId): array {
    ensureUserPermissionsMaterialized($db);
    ensurePerfisHierarchyRemoved($db);

    $sql = "
        SELECT 
            COALESCE(u.perm_ver_calendario, 0) as ver_calendario,
            COALESCE(u.perm_criar_eventos, 0) as criar_eventos,
            COALESCE(u.perm_editar_eventos, 0) as editar_eventos,
            COALESCE(u.perm_excluir_eventos, 0) as excluir_eventos,
            COALESCE(u.perm_ver_restritos, 0) as ver_restritos,
            COALESCE(u.perm_cadastrar_usuario, 0) as cadastrar_usuario,
            COALESCE(u.perm_admin_usuarios, 0) as admin_usuarios,
            COALESCE(u.perm_admin_sistema, 0) as admin_sistema,
            COALESCE(u.perm_ver_logs, 0) as ver_logs,
            COALESCE(u.perm_gerenciar_catalogo, 0) as gerenciar_catalogo,
            COALESCE(u.perm_gerenciar_grupos, 0) as gerenciar_grupos
        FROM usuarios u
        WHERE u.id = ?
    ";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

function can(string $permission): bool {
    if (has_level(0) || ($_SESSION['usuario_id'] ?? 0) === 1) {
        return true;
    }
    return isset($_SESSION['perms'][$permission]) && (bool)$_SESSION['perms'][$permission] === true;
}

function has_level(int $min_level): bool {
    return isset($_SESSION['usuario_nivel']) && (int)$_SESSION['usuario_nivel'] <= $min_level;
}

function requirePerm(string $permission): void {
    requireLogin();
    if (has_level(0)) {
        return;
    }
    if (!can($permission)) {
        header('Location: index.php?error=unauthorized');
        exit();
    }
}

// 6. Global Utility Helpers
