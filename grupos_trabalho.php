<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Working Groups Management (v1.0)
 * Modern CRUD · Color Selection · Team Organization
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();

$pid = current_paroquia_id();
requirePerm('gerenciar_grupos');

$my_user_id = (int)($_SESSION['usuario_id'] ?? 0);
$is_master_global = has_level(0) || $my_user_id === 1;
$adminGroups = getUserGroups($conn, $my_user_id);

// Ensure default group exists
ensureDefaultVisitorGroup($conn, $pid);

$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// 1. Handle CRUD Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $action = $_POST['action'] ?? '';
    $data = sanitize_post($_POST);
    
    if ($action === 'create' || $action === 'update') {
        if (empty($data['nome'])) {
            $error = 'O nome do grupo é obrigatório.';
        } else {
            if ($action === 'create') {
                $sql = "INSERT INTO grupos_trabalho (paroquia_id, nome, descricao, cor, visivel, ativo) VALUES (?, ?, ?, ?, ?, ?)";
                $visivel = isset($_POST['visivel']) ? 1 : 0;
                $ativo = isset($_POST['ativo']) ? 1 : 0;
                
                if (db_execute($conn, $sql, [$pid, $data['nome'], $data['descricao'], $data['cor'], $visivel, $ativo])) {
                    $newId = $conn->insert_id;
                    logAction($conn, 'CRIAR_GRUPO', 'grupos_trabalho', $newId, ['novo' => $data]);
                    
                    // AUTO-JOIN: Add creator to the newly created group
                    saveUserGroupsScoped($conn, $my_user_id, [$newId], [$newId]);
                    
                    // AUTO-JOIN: Add master admin (ID 1) to the newly created group
                    if ($my_user_id !== 1) {
                        db_execute($conn, "INSERT IGNORE INTO usuario_grupos (usuario_id, grupo_id, paroquia_id) VALUES (1, ?, ?)", [$newId, $pid]);
                    }
                    
                    header("Location: grupos_trabalho.php?msg=Grupo criado com sucesso e voce foi adicionado como membro!");
                    exit();
                }
            } else {
                $id = (int)$data['id'];
                
                // Get old state for logging
                $oldResult = db_query($conn, "SELECT * FROM grupos_trabalho WHERE id = ? AND paroquia_id = ?", [$id, $pid]);
                $oldState = $oldResult ? $oldResult->fetch_assoc() : [];

                $sql = "UPDATE grupos_trabalho SET nome = ?, descricao = ?, cor = ?, visivel = ?, ativo = ? WHERE id = ? AND paroquia_id = ?";
                $visivel = isset($_POST['visivel']) ? 1 : 0;
                $ativo = isset($_POST['ativo']) ? 1 : 0;
                
                if (db_execute($conn, $sql, [$data['nome'], $data['descricao'], $data['cor'], $visivel, $ativo, $id, $pid])) {
                    logAction($conn, 'EDITAR_GRUPO', 'grupos_trabalho', $id, ['antigo' => $oldState, 'novo' => $data]);
                    header("Location: grupos_trabalho.php?msg=Grupo atualizado com sucesso!");
                    exit();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        // Safety: check if it's the 'Todos' group
        $check = db_query($conn, "SELECT nome FROM grupos_trabalho WHERE id = ?", [$id]);
        $g = $check ? $check->fetch_assoc() : null;
        if ($g && $g['nome'] === 'Todos') {
            $error = "O grupo padrão 'Todos' não pode ser excluído.";
        } else {
            $my_level = (int)($_SESSION['usuario_nivel'] ?? 99);
            $stmtGuard = $conn->prepare("
                SELECT u.id
                FROM usuario_grupos ug
                INNER JOIN usuarios u ON u.id = ug.usuario_id
                WHERE ug.grupo_id = ?
                  AND ug.paroquia_id = ?
                  AND u.id <> ?
                  AND u.nivel_acesso <= ?
                LIMIT 1
            ");

            $blockedByHierarchy = false;
            if ($stmtGuard) {
                $stmtGuard->bind_param('iiii', $id, $pid, $my_user_id, $my_level);
                $stmtGuard->execute();
                $blockedByHierarchy = (bool)$stmtGuard->get_result()->fetch_assoc();
            }

            if ($blockedByHierarchy) {
                $error = 'Nao e possivel excluir: existe usuario do mesmo nivel ou superior neste grupo.';
            } else {
                if (db_execute($conn, "DELETE FROM grupos_trabalho WHERE id = ? AND paroquia_id = ?", [$id, $pid])) {
                    logAction($conn, 'EXCLUIR_GRUPO', 'grupos_trabalho', $id);
                    header("Location: grupos_trabalho.php?msg=Grupo excluído com sucesso.");
                    exit();
                }
            }
        }
    }
}

// 2. Fetch Groups (Filtered by scope)
if ($is_master_global) {
    $sqlGroups = "SELECT g.*, (SELECT COUNT(*) FROM usuario_grupos WHERE grupo_id = g.id) as total_membros FROM grupos_trabalho g WHERE g.paroquia_id = ? ORDER BY g.nome ASC";
    $grupos = db_query($conn, $sqlGroups, [$pid]);
} else {
    // If not master, only see groups I am in
    $sqlGroups = "SELECT g.*, (SELECT COUNT(*) FROM usuario_grupos WHERE grupo_id = g.id) as total_membros 
                  FROM grupos_trabalho g 
                  INNER JOIN usuario_grupos ug ON ug.grupo_id = g.id AND ug.usuario_id = ?
                  WHERE g.paroquia_id = ? AND g.ativo = 1 ORDER BY g.nome ASC";
    $grupos = db_query($conn, $sqlGroups, [$my_user_id, $pid]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Grupos de Trabalho – PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }
        
        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .header-flex { flex-direction: column; align-items: flex-start; gap: 1.5rem; }
            .groups-grid { grid-template-columns: 1fr; }
        }
        
        .header-flex { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 3rem; }
        
        .groups-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        .group-card { padding: 2rem; display: flex; flex-direction: column; gap: 1.2rem; transition: all 0.3s var(--ease); border-top-width: 4px; }
        .group-card:hover { transform: translateY(-5px); box-shadow: var(--sh-lg); }

        .grp-header { display: flex; align-items: center; gap: 1rem; }
        .grp-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 900; }
        .grp-title h3 { font-size: 1.15rem; font-weight: 800; margin-bottom: 0.1rem; }
        .grp-title p { font-size: 0.7rem; color: var(--text-ghost); font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }

        .grp-desc { font-size: 0.85rem; color: var(--text-dim); line-height: 1.6; min-height: 3rem; }
        
        .grp-stats { display: flex; align-items: center; gap: 1rem; padding-top: 1rem; border-top: 1px solid var(--border); }
        .stat-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.75rem; font-weight: 700; color: var(--text-ghost); }
        .stat-icon { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; }
        .stat-icon.inactive { background: #ef4444; }

        .grp-footer { display: flex; gap: 0.75rem; margin-top: auto; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(10px); z-index: 2500; align-items: center; justify-content: center; padding: 2rem; }
        .modal.active { display: flex; }
        .modal-card { width: 100%; max-width: 500px; padding: 2.5rem; }
        
        .color-preview { width: 24px; height: 24px; border-radius: 6px; border: 2px solid var(--border); }
        
        /* ── View Modes ────────────────────────────────────────── */
        .view-controls { display: flex; gap: 0.5rem; background: var(--panel); padding: 0.4rem; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 1.5rem; width: fit-content; }
        .view-btn { 
            padding: 0.5rem; border-radius: 8px; border: none; background: transparent; color: var(--text-dim); cursor: pointer; display: flex; align-items: center; transition: all var(--anim);
        }
        .view-btn:hover { background: var(--panel-hi); color: var(--text); }
        .view-btn.active { background: var(--primary); color: #fff; box-shadow: var(--sh-primary); }

        /* LIST VIEW */
        .groups-grid.view-list { grid-template-columns: 1fr; gap: 0.8rem; }
        .view-list .group-card { 
            flex-direction: row; align-items: center; padding: 1rem 1.5rem; gap: 2rem; 
        }
        .view-list .grp-header { min-width: 250px; flex-shrink: 0; }
        .view-list .grp-desc { flex: 2; min-height: auto; margin: 0; display: flex; align-items: center; }
        .view-list .grp-stats { flex: 1; border: none; padding: 0; margin: 0; justify-content: flex-start; }
        .view-list .grp-footer { flex: 1; margin: 0; align-items: center; }
        
        /* COMPACT VIEW */
        .groups-grid.view-compact { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
        .view-compact .group-card { padding: 1.2rem; gap: 1rem; }
        .view-compact .grp-icon { width: 36px; height: 36px; font-size: 1rem; }
        .view-compact .grp-desc { display: none; }
        .view-compact .grp-footer { padding-top: 1rem; border-top: 1px solid var(--border); margin-top: 0.5rem; }

        @media (max-width: 900px) {
            .view-list .group-card { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .view-list .grp-stats { padding-top: 1rem; border-top: 1px solid var(--border); width: 100%; justify-content: flex-start; }
        }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>

    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <?php if ($msg): ?> <?= alert('success', h($msg)) ?> <?php endif; ?>
            <?php if ($error): ?> <?= alert('error', h($error)) ?> <?php endif; ?>

            <header class="calendar-header animate-in" style="margin-bottom: 2rem; display: flex; align-items: center; padding: 0 1rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 1rem;">
                    <h1 class="gradient-text" style="font-size: 1.15rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Grupos de Trabalho</h1>
                    <div style="display: flex; gap: 0.5rem; align-items: stretch; flex-shrink: 0;">
                        <a href="index.php" class="hide-on-desktop btn btn-ghost" style="background: #ef4444; color: #fff; border: none; padding: 0 0.8rem; min-height: 44px; border-radius: 10px; display: flex; align-items: center; gap: 0.4rem; font-weight: 800; font-size: 0.75rem; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); justify-content: center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                            SAIR
                        </a>
                        <button onclick="openModal()" class="hide-on-desktop btn btn-primary shimmer" style="min-height: 44px; padding: 0 0.8rem; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 0.3rem; font-size: 0.75rem;">
                             <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                             CRIAR
                        </button>
                    </div>
                </div>
                <button onclick="openModal()" class="btn btn-primary shimmer hide-on-mobile">Criar Novo Grupo</button>
            </header>

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

            <div id="groupsContainer" class="groups-grid animate-in" style="animation-delay: 0.1s;">
                <?php while ($g = $grupos->fetch_assoc()): ?>
                <article class="glass group-card" style="border-top-color: <?= $g['cor'] ?>;">
                    <div class="grp-header">
                        <div class="grp-icon" style="background: <?= $g['cor'] ?>;">
                            <?= mb_substr(h($g['nome']), 0, 1) ?>
                        </div>
                        <div class="grp-title">
                            <h3><?= h($g['nome']) ?></h3>
                            <p><?= $g['total_membros'] ?> MEMBROS ATIVOS</p>
                        </div>
                    </div>

                    <div class="grp-desc">
                        <?= h($g['descricao'] ?: 'Sem descrição definida para este grupo.') ?>
                    </div>

                    <div class="grp-stats">
                        <div class="stat-item">
                            <span class="stat-icon <?= $g['ativo'] ? '' : 'inactive' ?>"></span>
                            <?= $g['ativo'] ? 'ATIVO' : 'INATIVO' ?>
                        </div>
                        <?php if (!$g['visivel']): ?>
                        <div class="stat-item" style="color: #f59e0b;">
                             <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                             OCULTO
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="grp-footer">
                        <button onclick='editGroup(<?= json_encode($g) ?>)' class="btn btn-ghost" style="flex: 1;">Configurar</button>
                        <?php if ($g['nome'] !== 'Todos'): ?>
                        <form method="POST" style="flex: 1;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $g['id'] ?>">
                            <button type="button" class="btn btn-ghost" style="width: 100%; color: #ef4444;" onclick="return confirmForm(this, 'Remover este grupo permanentemente?')">Excluir</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endwhile; ?>
            </div>
        </main>
    </div>

    <!-- Modal Form -->
    <div id="groupModal" class="modal">
        <form method="POST" class="glass modal-card">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" id="modalAction" value="create">
            <input type="hidden" name="id" id="groupId">
            
            <h2 id="modalTitle" style="margin-bottom: 2rem; font-weight: 900;">Criar Novo Grupo</h2>
            
            <div style="display: grid; gap: 1.5rem;">
                <div class="form-group">
                    <label>Nome do Grupo</label>
                    <input type="text" name="nome" id="modalNome" placeholder="Ex: Equipe de Apoio" required>
                </div>
                
                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="descricao" id="modalDesc" placeholder="Descreva a finalidade deste grupo..." style="width: 100%; min-height: 80px; padding: 1rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 12px; color: #fff;"></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label>Cor de Identificação</label>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <input type="color" name="cor" id="modalCor" value="#3b82f6" style="width: 44px; height: 44px; padding: 0; border: none; background: transparent; cursor: pointer;">
                            <span style="font-size: 0.8rem; font-family: monospace;" id="colorHex">#3B82F6</span>
                        </div>
                    </div>
                    <div class="form-group" style="display: flex; flex-direction: column; gap: 0.5rem; justify-content: center;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin: 0;">
                            <input type="checkbox" name="ativo" id="modalAtivo" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span>Grupo Ativo</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin: 0;">
                            <input type="checkbox" name="visivel" id="modalVisivel" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span>Visível no Perfil</span>
                        </label>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary shimmer" style="flex: 2;">Gravar Alterações</button>
                    <button type="button" onclick="closeModal()" class="btn btn-ghost" style="flex: 1;">Cancelar</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        const modal = document.getElementById('groupModal');
        const colorInput = document.getElementById('modalCor');
        const colorHex = document.getElementById('colorHex');

        colorInput.addEventListener('input', (e) => {
            colorHex.textContent = e.target.value.toUpperCase();
        });

        function openModal() {
            document.getElementById('modalAction').value = 'create';
            document.getElementById('modalTitle').textContent = 'Criar Novo Grupo';
            document.getElementById('groupId').value = '';
            document.getElementById('modalNome').value = '';
            document.getElementById('modalDesc').value = '';
            document.getElementById('modalCor').value = '#3b82f6';
            document.getElementById('colorHex').textContent = '#3B82F6';
            document.getElementById('modalAtivo').checked = true;
            document.getElementById('modalVisivel').checked = true;
            modal.classList.add('active');
        }

        function editGroup(g) {
            document.getElementById('modalAction').value = 'update';
            document.getElementById('modalTitle').textContent = 'Configurar Grupo';
            document.getElementById('groupId').value = g.id;
            document.getElementById('modalNome').value = g.nome;
            document.getElementById('modalDesc').value = g.descricao || '';
            document.getElementById('modalCor').value = g.cor || '#3b82f6';
            document.getElementById('colorHex').textContent = (g.cor || '#3b82f6').toUpperCase();
            document.getElementById('modalAtivo').checked = !!parseInt(g.ativo);
            document.getElementById('modalVisivel').checked = !!parseInt(g.visivel);
            modal.classList.add('active');
        }

        function closeModal() {
            modal.classList.remove('active');
        }

        function setView(mode) {
            const container = document.getElementById('groupsContainer');
            const btns = document.querySelectorAll('.view-btn');
            
            // Toggle classes
            container.classList.remove('view-list', 'view-compact');
            if (mode === 'list') container.classList.add('view-list');
            if (mode === 'compact') container.classList.add('view-compact');

            // Active button state
            btns.forEach(b => b.classList.remove('active'));
            if(document.getElementById('btn-' + mode)) {
                document.getElementById('btn-' + mode).classList.add('active');
            }

            localStorage.setItem('groups-view-mode', mode);
        }

        // Init view mode
        document.addEventListener('DOMContentLoaded', () => {
            const savedMode = localStorage.getItem('groups-view-mode') || 'grid';
            setView(savedMode);
        });
    </script>
</body>
</html>
