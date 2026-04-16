<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — User Management Console (v2.0)
 * RBAC Control · Staff Listing · Premium UI
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();
ensureUserPhotoColumn($conn);

requirePerm('admin_usuarios');

$pid = current_paroquia_id();
$can_edit = can('admin_usuarios');
$is_master = has_level(0);
$my_user_id = (int)($_SESSION['usuario_id'] ?? 0);
$my_level = (int)($_SESSION['usuario_nivel'] ?? 99);
$can_manage_users_by_level = $is_master || $my_level <= 3;
$my_group_ids = getUserGroups($conn, $my_user_id, $pid);
$todos_group_id = getDefaultTodosGroupId($conn, $pid);
if ($todos_group_id > 0) {
    $my_group_ids = array_values(array_filter(
        $my_group_ids,
        static fn(int $gid): bool => $gid !== $todos_group_id
    ));
}

// 1. Handle Status Toggles (AJAX/Simple POST)
if (isset($_POST['toggle_status']) && $can_edit) {
    require_csrf_token();
    $uid = (int)$_POST['toggle_status'];
    $checkStmt = $conn->prepare("SELECT id, paroquia_id, nivel_acesso, ativo FROM usuarios WHERE id = ? LIMIT 1");
    $checkStmt->bind_param('i', $uid);
    $checkStmt->execute();
    $oldState = $checkStmt->get_result()->fetch_assoc();

    if (!$oldState) {
        header('Location: usuarios.php?error=notfound');
        exit();
    }

    if (
        !$can_manage_users_by_level &&
        !$is_master
    ) {
        header('Location: usuarios.php?error=unauthorized');
        exit();
    }

    if (
        !$is_master &&
        (int)$oldState['id'] !== $my_user_id &&
        (
            (int)$oldState['paroquia_id'] !== $pid ||
            (int)$oldState['nivel_acesso'] <= $my_level
        )
    ) {
        header('Location: usuarios.php?error=unauthorized');
        exit();
    }

    if (
        !$is_master &&
        (int)$oldState['id'] !== $my_user_id
    ) {
        if (empty($my_group_ids)) {
            header('Location: usuarios.php?error=unauthorized');
            exit();
        }

        $targetGroupIds = getUserGroups($conn, (int)$oldState['id'], $pid);
        if ($todos_group_id > 0) {
            $targetGroupIds = array_values(array_filter(
                $targetGroupIds,
                static fn(int $gid): bool => $gid !== $todos_group_id
            ));
        }
        $hasCommonGroup = !empty(array_intersect($my_group_ids, $targetGroupIds));
        if (!$hasCommonGroup) {
            header('Location: usuarios.php?error=unauthorized');
            exit();
        }
    }
    
    db_query($conn, "UPDATE usuarios SET ativo = 1 - ativo WHERE id = ?", [$uid]);
    
    $newResult = db_query($conn, "SELECT * FROM usuarios WHERE id = ?", [$uid]);
    $newState = $newResult->fetch_assoc();
    
    logAction($conn, 'ALTERAR_STATUS_USUARIO', 'usuarios', $uid, ['antigo' => $oldState, 'novo' => $newState]);
    header('Location: usuarios.php?msg=Status alterado com sucesso');
    exit();
}

// 2. Build Query
$where = [];
$params = [];
$types = "";

if (!$is_master) {
    $where[] = "u.paroquia_id = ?";
    $params[] = $pid;
    $types .= "i";
    
    // Admins da paróquia veem todos os usuários da paróquia, exceto o admin master global (id=1)
    $where[] = "(u.id = ? OR (u.id <> 1 AND u.nivel_acesso >= ?))";
    $params[] = $my_user_id;
    $params[] = $my_level;
    $types .= "ii";

    if (!empty($my_group_ids)) {
        $groupPlaceholders = implode(',', array_fill(0, count($my_group_ids), '?'));
        $where[] = "(u.id = ? OR EXISTS (
            SELECT 1
            FROM usuario_grupos ug
            WHERE ug.usuario_id = u.id
              AND ug.paroquia_id = u.paroquia_id
              AND ug.grupo_id IN ($groupPlaceholders)
        ))";
        $params[] = $my_user_id;
        $types .= "i";
        foreach ($my_group_ids as $gid) {
            $params[] = (int)$gid;
            $types .= "i";
        }
    } else {
        $where[] = "u.id = ?";
        $params[] = $my_user_id;
        $types .= "i";
    }
} else {
    // Master global vê apenas os da paróquia selecionada no contexto
    $where[] = "u.paroquia_id = ?";
    $params[] = $pid;
    $types .= "i";
}

$sql = "
    SELECT u.*, p.nome as paroquia_nome,
    CASE 
        WHEN u.nivel_acesso = 0 THEN 'Master'
        WHEN u.nivel_acesso = 1 THEN 'Administrador'
        WHEN u.nivel_acesso = 2 THEN 'Gerente'
        WHEN u.nivel_acesso = 3 THEN 'Supervisor'
        WHEN u.nivel_acesso = 4 THEN 'Encarregado'
        WHEN u.nivel_acesso = 5 THEN 'Trabalhador'
        WHEN u.nivel_acesso = 6 THEN 'Consultor'
        WHEN u.nivel_acesso = 7 THEN 'Visitante'
        ELSE 'Usuário'
    END as nivel_label
    FROM usuarios u
    LEFT JOIN paroquias p ON u.paroquia_id = p.id
";

// Paginação (SPEC-09)
$itemsPerPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $itemsPerPage;

// COUNT total queries
$sqlCount = "SELECT COUNT(*) as total FROM usuarios u LEFT JOIN paroquias p ON u.paroquia_id = p.id";
if ($where) {
    $sqlCount .= " WHERE " . implode(" AND ", $where);
}
$stmtCount = $conn->prepare($sqlCount);
if ($params) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$totalItems = (int)$stmtCount->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalItems / $itemsPerPage));

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY u.nome ASC LIMIT ? OFFSET ?";
$types .= "ii";
$params[] = $itemsPerPage;
$params[] = $offset;

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Gestão de Usuários — PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5">
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">

    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }
        
        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .header-flex { flex-direction: column; align-items: flex-start; gap: 1.5rem; }
            .user-grid { grid-template-columns: 1fr; }
            .btn-primary { width: 100%; }
        }
        
        .header-flex { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; }
        
        .user-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        
        .user-card { padding: 2rem; display: flex; flex-direction: column; gap: 1.5rem; transition: transform 0.3s var(--anim); }
        .user-card-header { display: flex; align-items: center; gap: 1.2rem; }
        
        .user-avatar-lg { width: 56px; height: 56px; border-radius: 16px; background: var(--panel-hi); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 800; color: var(--primary); overflow: hidden; }
        .user-avatar-lg img { width: 100%; height: 100%; object-fit: cover; }
        
        .user-meta { display: flex; flex-direction: column; }
        .user-name { font-weight: 800; font-size: 1.1rem; color: var(--text); }
        .user-role { font-size: 0.75rem; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.2rem; }
        
        .user-details { border-top: 1px solid var(--border); padding-top: 1.5rem; display: grid; gap: 0.8rem; }
        .detail-item { display: flex; align-items: center; gap: 0.8rem; font-size: 0.85rem; color: var(--text-dim); }
        .detail-item svg { color: var(--text-ghost); }

        .user-actions { margin-top: auto; padding-top: 1.5rem; display: flex; gap: 0.8rem; }
        
        .status-pill { font-size: 0.65rem; font-weight: 900; padding: 0.3rem 0.6rem; border-radius: 6px; text-transform: uppercase; }
        .status-pill.active { background: rgba(34, 197, 94, 0.1); color: #22c55e; }
        .status-pill.inactive { background: rgba(239, 68, 68, 0.1); color: #ef4444; }

        @media (max-width: 768px) {
            .main-content { padding: 1.5rem; }
            .header-flex { flex-direction: column; align-items: flex-start; gap: 1.5rem; }
        }

        /* Paginação */
        .pagination { display: flex; gap: 0.4rem; justify-content: center; margin-top: 3rem; flex-wrap: wrap; }
        .page-item {
            width: 40px; height: 40px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            background: var(--panel); border: 1px solid var(--border);
            font-size: 0.9rem; font-weight: 800; color: var(--text-dim);
            transition: all var(--anim); cursor: pointer; text-decoration: none;
        }
        .page-item.active { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: var(--sh-primary); }
        .page-item:not(.active):hover { background: var(--panel-hi); color: var(--text); border-color: rgba(255,255,255,0.1); }
        .page-item.disabled { opacity: 0.5; pointer-events: none; }


        /* ── View Modes ────────────────────────────────────────── */
        .view-controls { display: flex; gap: 0.5rem; background: var(--panel); padding: 0.4rem; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 1.5rem; width: fit-content; }
        .view-btn { 
            padding: 0.5rem; border-radius: 8px; border: none; background: transparent; color: var(--text-dim); cursor: pointer; display: flex; align-items: center; transition: all var(--anim);
        }
        .view-btn:hover { background: var(--panel-hi); color: var(--text); }
        .view-btn.active { background: var(--primary); color: #fff; box-shadow: var(--sh-primary); }

        /* LIST VIEW */
        .user-grid.view-list { grid-template-columns: 1fr; gap: 0.8rem; }
        .view-list .user-card { 
            flex-direction: row; align-items: center; padding: 1rem 1.5rem; gap: 2rem; 
        }
        .view-list .user-avatar-lg { width: 42px; height: 42px; flex-shrink: 0; }
        .view-list .user-meta { flex-direction: row; align-items: center; gap: 1.5rem; flex: 1; }
        .view-list .user-name { font-size: 1rem; min-width: 200px; }
        .view-list .user-role { width: 120px; margin-bottom: 0; text-align: left; }
        .view-list .user-details { 
            border: none; padding: 0; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); flex: 2; margin: 0;
        }
        .view-list .user-actions { border: none; padding: 0; margin: 0; gap: 0.5rem; margin-top: 0; }
        .view-list .user-actions .btn { padding: 0.5rem 1rem; }

        /* COMPACT VIEW */
        .user-grid.view-compact { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
        .view-compact .user-card { padding: 1.2rem; gap: 1rem; }
        .view-compact .user-avatar-lg { width: 36px; height: 36px; font-size: 1rem; }
        .view-compact .user-details { display: none; }
        .view-compact .user-actions { padding-top: 1rem; border-top: 1px solid var(--border); margin-top: 0.5rem; }

        @media (max-width: 900px) {
            .view-list .user-meta { flex-direction: column; align-items: flex-start; gap: 0.2rem; }
            .view-list .user-card { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .view-list .user-details { grid-template-columns: 1fr; width: 100%; }
        }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>

    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <header class="calendar-header animate-in" style="margin-bottom: 2rem; display: flex; align-items: center; padding: 0 1rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 1rem;">
                    <h1 class="gradient-text" style="font-size: 1.15rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Usuários</h1>
                    <div style="display: flex; gap: 0.5rem; align-items: stretch; flex-shrink: 0;">
                        <a href="index.php" class="hide-on-desktop btn btn-ghost" style="background: #ef4444; color: #fff; border: none; padding: 0 0.8rem; min-height: 44px; border-radius: 10px; display: flex; align-items: center; gap: 0.4rem; font-weight: 800; font-size: 0.75rem; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); justify-content: center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                            SAIR
                        </a>
                        <?php if ($can_edit && (can('cadastrar_usuario') || can('admin_usuarios'))): ?>
                            <a href="register.php" class="hide-on-desktop btn btn-primary" style="min-height: 44px; padding: 0 0.8rem; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 0.3rem; font-size: 0.75rem;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                NOVO
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($can_edit && (can('cadastrar_usuario') || can('admin_usuarios'))): ?>
                <a href="register.php" class="btn btn-primary shimmer hide-on-mobile">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="16" x2="22" y1="11" y2="11"/></svg>
                    Novo Usuário
                </a>
                <?php endif; ?>
            </header>

            <?php if (isset($_GET['msg'])): ?> <?= alert('success', h($_GET['msg'])) ?> <?php endif; ?>

            <div class="view-controls animate-in" style="animation-delay: 0.05s;">
                <button onclick="setView('grid')" id="btn-grid" class="view-btn" title="Grelha">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </button>
                <button onclick="setView('list')" id="btn-list" class="view-btn" title="Lista">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
                <button onclick="setView('compact')" id="btn-compact" class="view-btn" title="Compacto">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                </button>
            </div>

            <div id="usersContainer" class="user-grid animate-in" style="animation-delay: 0.1s;">
                <?php while ($u = $users->fetch_assoc()): ?>
                <div class="glass user-card">
                    <div class="user-card-header">
                        <div class="user-avatar-lg">
                            <?php if (!empty($u['foto_perfil']) && file_exists(__DIR__ . '/' . $u['foto_perfil'])): ?>
                                <img src="<?= h($u['foto_perfil']) ?>?v=<?= time() ?>" alt="Foto">
                            <?php else: ?>
                                <?= mb_substr($u['nome'], 0, 1) ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-meta">
                            <span class="user-role"><?= h(($u['perfil_nome'] ?? '') !== '' ? $u['perfil_nome'] : $u['nivel_label']) ?></span>
                            <span class="user-name"><?= h($u['nome']) ?></span>
                            <div style="margin-top: 0.4rem;">
                                <span class="status-pill <?= $u['ativo'] ? 'active' : 'inactive' ?>">
                                    <?= $u['ativo'] ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="user-details">
                        <div class="detail-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <?= h($u['email']) ?>
                        </div>
                        <div class="detail-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <?= h($u['telefone'] ?: 'N/D') ?>
                        </div>
                        <div class="detail-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            <?= h($u['paroquia_nome'] ?: 'Global / Master') ?>
                        </div>
                    </div>

                    <?php
                        $isSelfCard = (int)$u['id'] === $my_user_id;
                        $canEditThisUser = $isSelfCard || ($can_manage_users_by_level && ((int)$u['nivel_acesso'] > $my_level));
                        $canToggleThisUser = !$isSelfCard && $can_manage_users_by_level && ((int)$u['nivel_acesso'] > $my_level);
                    ?>
                    <?php if ($can_edit && $canEditThisUser): ?>
                    <div class="user-actions">
                        <?php if ($canToggleThisUser): ?>
                        <form method="POST" action="usuarios.php" style="flex: 1; margin: 0; display: flex;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="toggle_status" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn <?= $u['ativo'] ? 'btn-ghost' : 'btn-primary' ?>" style="width: 100%; font-size: 0.75rem; padding: 0.6rem;">
                                <?= $u['ativo'] ? 'Desativar' : 'Ativar' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <a href="editar_usuario.php?id=<?= $u['id'] ?>" class="btn btn-ghost" style="padding: 0.6rem;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>

            <!-- Navegação da Paginação (SPEC-09) -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination animate-in" style="animation-delay: 0.2s;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="page-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></a>
                <?php else: ?>
                    <div class="page-item disabled"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></div>
                <?php endif; ?>

                <?php
                // Range de até 5 páginas ex: [ 1 2 3 ... 10 ]
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1) { echo '<a href="?page=1" class="page-item">1</a>'; if ($startPage > 2) echo '<div class="page-item disabled" style="border:none;background:transparent;">...</div>'; }
                
                for ($p = $startPage; $p <= $endPage; $p++) {
                    $activeClass = ($p === $page) ? 'active' : '';
                    echo "<a href='?page={$p}' class='page-item {$activeClass}'>{$p}</a>";
                }
                
                if ($endPage < $totalPages) { if ($endPage < $totalPages - 1) echo '<div class="page-item disabled" style="border:none;background:transparent;">...</div>'; echo "<a href='?page={$totalPages}' class='page-item'>{$totalPages}</a>"; }
                ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="page-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></a>
                <?php else: ?>
                    <div class="page-item disabled"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <script>
        function setView(mode) {
            const container = document.getElementById('usersContainer');
            const btns = document.querySelectorAll('.view-btn');
            
            // Toggle classes
            container.classList.remove('view-list', 'view-compact');
            if (mode === 'list') container.classList.add('view-list');
            if (mode === 'compact') container.classList.add('view-compact');

            // Active button state
            btns.forEach(b => b.classList.remove('active'));
            document.getElementById('btn-' + mode).classList.add('active');

            localStorage.setItem('user-view-mode', mode);
        }

        // Init view mode
        document.addEventListener('DOMContentLoaded', () => {
            const savedMode = localStorage.getItem('user-view-mode') || 'grid';
            setView(savedMode);
        });
    </script>
</body>
</html>

