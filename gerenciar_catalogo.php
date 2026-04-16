<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Catálogo de Atividades Manager (v2.0)
 * CRUD Completo · Premium UI · Glassmorphism Design
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();
ensureEventActivitiesStructure($conn);

$pid = current_paroquia_id();

// Restrict access: admin or catalog perm
if (!can('gerenciar_catalogo') && !can('admin_sistema')) {
    header('Location: index.php?error=unauthorized');
    exit();
}

$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// ── Handle POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $action = $_POST['action'] ?? '';
    $data = sanitize_post($_POST);

    if ($action === 'create') {
        $nome = trim($data['nome'] ?? '');
        $descricao = trim($data['descricao'] ?? '');

        if ($nome === '') {
            $error = 'O nome da atividade é obrigatório.';
        } else {
            $check = db_query($conn, "SELECT id FROM atividades_catalogo WHERE paroquia_id = ? AND nome = ?", [$pid, $nome]);
            if ($check && $check->fetch_assoc()) {
                $error = 'Já existe uma atividade com esse nome.';
            } else {
                if (db_execute($conn, "INSERT INTO atividades_catalogo (paroquia_id, nome, descricao, ativo) VALUES (?, ?, ?, 1)", [$pid, $nome, $descricao])) {
                    logAction($conn, 'CRIAR_CATALOGO', 'atividades_catalogo', $conn->insert_id, $nome);
                    header('Location: gerenciar_catalogo.php?msg=' . urlencode('Atividade "' . $nome . '" cadastrada!'));
                    exit();
                } else {
                    $error = 'Erro ao cadastrar: ' . $conn->error;
                }
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($data['id'] ?? 0);
        $nome = trim($data['nome'] ?? '');
        $descricao = trim($data['descricao'] ?? '');

        if ($nome === '' || $id <= 0) {
            $error = 'Dados inválidos.';
        } else {
            $dup = db_query($conn, "SELECT id FROM atividades_catalogo WHERE paroquia_id = ? AND nome = ? AND id != ?", [$pid, $nome, $id]);
            if ($dup && $dup->fetch_assoc()) {
                $error = 'Já existe outra atividade com esse nome.';
            } else {
                if (db_execute($conn, "UPDATE atividades_catalogo SET nome = ?, descricao = ? WHERE id = ? AND paroquia_id = ?", [$nome, $descricao, $id, $pid])) {
                    logAction($conn, 'EDITAR_CATALOGO', 'atividades_catalogo', $id, $nome);
                    header('Location: gerenciar_catalogo.php?msg=' . urlencode('Atividade atualizada!'));
                    exit();
                } else {
                    $error = 'Erro ao atualizar.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            // Check if used in any event
            $usageCheck = db_query($conn, "SELECT COUNT(*) as total FROM atividade_evento_itens WHERE atividade_catalogo_id = ?", [$id]);
            $usage = $usageCheck ? $usageCheck->fetch_assoc() : null;

            if ((int)($usage['total'] ?? 0) > 0) {
                // Soft-delete: deactivate instead
                if (db_execute($conn, "UPDATE atividades_catalogo SET ativo = 0 WHERE id = ? AND paroquia_id = ?", [$id, $pid])) {
                    logAction($conn, 'DESATIVAR_CATALOGO', 'atividades_catalogo', $id);
                    header('Location: gerenciar_catalogo.php?msg=' . urlencode('Atividade desativada (vinculada a eventos existentes).'));
                    exit();
                } else {
                    $error = 'Erro ao desativar: ' . $conn->error;
                }
            } else {
                if (db_execute($conn, "DELETE FROM atividades_catalogo WHERE id = ? AND paroquia_id = ?", [$id, $pid])) {
                    logAction($conn, 'EXCLUIR_CATALOGO', 'atividades_catalogo', $id);
                    header('Location: gerenciar_catalogo.php?msg=' . urlencode('Atividade removida!'));
                    exit();
                } else {
                    $error = 'Erro ao remover: ' . $conn->error;
                }
            }
        }
    } elseif ($action === 'reactivate') {
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            db_execute($conn, "UPDATE atividades_catalogo SET ativo = 1 WHERE id = ? AND paroquia_id = ?", [$id, $pid]);
            logAction($conn, 'REATIVAR_CATALOGO', 'atividades_catalogo', $id);
            header('Location: gerenciar_catalogo.php?msg=' . urlencode('Atividade reativada!'));
            exit();
        }
    } elseif ($action === 'deactivate') {
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            db_execute($conn, "UPDATE atividades_catalogo SET ativo = 0 WHERE id = ? AND paroquia_id = ?", [$id, $pid]);
            logAction($conn, 'DESATIVAR_CATALOGO', 'atividades_catalogo', $id);
            header('Location: gerenciar_catalogo.php?msg=' . urlencode('Atividade desativada!'));
            exit();
        }
    }
}

// ── Fetch Data ──
$items = db_query($conn, "SELECT * FROM atividades_catalogo WHERE paroquia_id = ? ORDER BY ativo DESC, nome ASC", [$pid]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Catálogo de Atividades – PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }

        .header-stack { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 3rem; }
        .header-stack h1 { font-size: 1.8rem; font-weight: 900; }

        .catalog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }

        .catalog-card {
            padding: 1.8rem; display: flex; flex-direction: column; gap: 1rem;
            transition: transform 0.3s var(--anim); position: relative;
        }
        .catalog-card:hover { transform: translateY(-5px); border-color: var(--primary); }
        .catalog-card.inactive { opacity: 0.5; border-style: dashed; }

        .card-name { font-size: 1.15rem; font-weight: 800; color: var(--text); }
        .card-desc { font-size: 0.85rem; color: var(--text-dim); line-height: 1.5; }
        .card-status {
            position: absolute; top: 1rem; right: 1rem;
            font-size: 0.6rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;
            padding: 0.3rem 0.6rem; border-radius: 100px;
        }
        .card-status.active { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .card-status.inactive { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

        .card-actions {
            display: flex; flex-wrap: wrap; gap: 0.5rem; border-top: 1px solid var(--border);
            padding-top: 1.2rem; margin-top: auto;
        }
        .card-actions .btn { flex: 1 1 auto; min-width: 80px; text-align: center; }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(10px); z-index: 2600; align-items: center; justify-content: center; padding: 2rem; }
        .modal.active { display: flex; }
        .modal-card { width: 100%; max-width: 540px; padding: 3rem; }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .header-stack { flex-direction: column; align-items: flex-start; gap: 1.5rem; }
            .catalog-grid { grid-template-columns: 1fr; }
            .btn-primary { width: 100%; }
        }
    
        .catalog-actions {
            display: flex; flex-wrap: wrap; gap: 0.5rem; padding-top: 1rem;
            border-top: 1px solid var(--border); margin-top: auto;
        }
        .catalog-actions .btn { flex: 1 1 auto; min-width: 80px; text-align: center; font-size: 0.75rem; padding: 0.6rem 0.8rem; white-space: nowrap; }
        @media (max-width: 480px) {
            .catalog-actions { flex-direction: column; }
            .catalog-actions .btn { width: 100%; }
        }
    
    </style>
<style>
        /* ── View Modes ────────────────────────────────────────── */
        .view-controls { display: flex; gap: 0.5rem; background: var(--panel); padding: 0.4rem; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 1.5rem; width: fit-content; }
        .view-btn { padding: 0.5rem; border-radius: 8px; border: none; background: transparent; color: var(--text-dim); cursor: pointer; display: flex; align-items: center; transition: all var(--anim); }
        .view-btn:hover { background: var(--panel-hi); color: var(--text); }
        .view-btn.active { background: var(--primary); color: #fff; box-shadow: var(--sh-primary); }

        /* LIST VIEW */
        .catalog-grid.view-list { grid-template-columns: 1fr !important; gap: 0.8rem; }
        .view-list .catalog-card { flex-direction: row; align-items: center; padding: 1rem 1.5rem; justify-content: space-between; }
        .view-list .catalog-card > div { flex-direction: row; align-items: center; gap: 1rem; }
        .view-list .catalog-card p { margin: 0; }
        
        /* COMPACT VIEW */
        .catalog-grid.view-compact { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)) !important; gap: 1rem; }
        .view-compact .catalog-card { padding: 1rem; }
        
        .catalog-actions {
            display: flex; flex-wrap: wrap; gap: 0.5rem; padding-top: 1rem;
            border-top: 1px solid var(--border); margin-top: auto;
        }
        .catalog-actions .btn { flex: 1 1 auto; min-width: 80px; text-align: center; font-size: 0.75rem; padding: 0.6rem 0.8rem; white-space: nowrap; }
        @media (max-width: 480px) {
            .catalog-actions { flex-direction: column; }
            .catalog-actions .btn { width: 100%; }
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

            <header class="header-stack animate-in">
                <div>
                    <p style="font-size: 0.65rem; font-weight: 800; opacity: 0.6; letter-spacing: 0.15em; color: var(--text-ghost);">ADMINISTRAÇÃO</p>
                    <h1 class="gradient-text">Catálogo de Atividades</h1>
                    <p style="font-size: 0.85rem; color: var(--text-dim); margin-top: 0.5rem;">
                        Gerencie as atividades disponíveis para vincular aos eventos da paróquia.
                    </p>
                </div>
                <button onclick="openModal()" class="btn btn-primary shimmer">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Nova Atividade
                </button>
            </header>

            <div class="view-controls animate-in" style="animation-delay: 0.05s;">
                <button onclick="setView('grid')" id="btn-grid" class="view-btn active" title="Grande">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </button>
                <button onclick="setView('compact')" id="btn-compact" class="view-btn" title="Média">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                </button>
                <button onclick="setView('list')" id="btn-list" class="view-btn" title="Lista">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
            </div>

            <div class="catalog-grid animate-in" id="dataContainer" style="animation-delay: 0.1s;">
                <?php if ($items && $items->num_rows > 0): ?>
                    <?php while ($item = $items->fetch_assoc()): ?>
                    <article class="glass-card catalog-card <?= $item['ativo'] ? '' : 'inactive' ?>">
                        <span class="card-status <?= $item['ativo'] ? 'active' : 'inactive' ?>">
                            <?= $item['ativo'] ? 'Ativa' : 'Inativa' ?>
                        </span>
                        <div>
                            <div class="card-name"><?= h($item['nome']) ?></div>
                            <?php if (!empty($item['descricao'])): ?>
                                <div class="card-desc"><?= h($item['descricao']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="card-actions">
                            <button 
                                type="button"
                                class="btn btn-ghost btn-edit" 
                                data-id="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>"
                                data-nome="<?= htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                data-descricao="<?= htmlspecialchars($item['descricao'], ENT_QUOTES, 'UTF-8') ?>"
                                style="flex: 1; font-size: 0.75rem; border-color: rgba(var(--primary-rgb), 0.3); color: var(--primary); cursor: pointer;"
                                onclick="editByData(this)"
                            >
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right: -4px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Editar
                            </button>
                            <?php if ($item['ativo']): ?>
                            <form method="POST" style="flex: 1; margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button type="button" class="btn btn-ghost" style="width: 100%; color: #eab308; font-size: 0.75rem; border-color: rgba(234, 179, 8, 0.2); cursor: pointer;" onclick="return confirmForm(this, 'Desativar temporariamente esta atividade?')">
                                    Desativar
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="flex: 1; margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="reactivate">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button type="button" class="btn btn-ghost" style="width: 100%; color: #22c55e; font-size: 0.75rem; border-color: rgba(34, 197, 94, 0.2); cursor: pointer;" onclick="return confirmForm(this, 'Reativar esta atividade?')">
                                    Ativar
                                </button>
                            </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-ghost" style="flex: 1; margin: 0; color: #ef4444; font-size: 0.75rem; border-color: rgba(239, 68, 68, 0.2); cursor: pointer;" onclick="confirmDelete(<?= $item['id'] ?>)">
                                Remover
                            </button>
                        </div>
                    </article>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 5rem; background: var(--panel-hi); border-radius: 20px; border: 1px dashed var(--border);">
                        <p style="color: var(--text-dim); font-weight: 600;">Nenhuma atividade cadastrada no catálogo desta paróquia.</p>
                        <button onclick="openModal()" class="btn btn-primary" style="margin-top: 1.5rem;">Cadastrar Primeira</button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <div id="catalogModal" class="modal" onclick="if(event.target.id==='catalogModal'){closeModal();}">
        <form method="POST" action="gerenciar_catalogo.php" class="glass modal-card">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" id="modalAction" value="create">
            <input type="hidden" name="id" id="itemId">

            <h2 id="modalTitle" style="margin-bottom: 2rem; font-weight: 900;">Nova Atividade do Catálogo</h2>

            <div style="display: grid; gap: 1.5rem;">
                <div class="form-group">
                    <label>Nome da Atividade</label>
                    <input type="text" name="nome" id="modalNome" placeholder="Ex: Transmissão Instagram, Canto, Leitura..." required>
                </div>
                <div class="form-group">
                    <label>Descrição (Opcional)</label>
                    <textarea name="descricao" id="modalDescricao" rows="3" placeholder="Breve descrição sobre esta atividade..."></textarea>
                </div>
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary shimmer" style="flex: 2;">Confirmar</button>
                    <button type="button" onclick="closeModal()" class="btn btn-ghost" style="flex: 1;">Cancelar</button>
                </div>
            </div>
        </form>
    </div>

    <div id="deleteModal" class="modal" onclick="if(event.target.id==='deleteModal'){closeDeleteModal();}">
        <form method="POST" action="gerenciar_catalogo.php" class="glass modal-card">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteItemId">

            <div style="text-align: center; margin-bottom: 2rem;">
                <div style="background: rgba(239, 68, 68, 0.15); width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; color: #ef4444;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                </div>
                <h2 style="font-weight: 900; color: var(--text);">Confirmar Exclusão</h2>
                <p style="color: var(--text-dim); margin-top: 0.5rem;">
                    Tem certeza que deseja remover esta atividade do catálogo?<br>Esta ação não pode ser desfeita.
                </p>
            </div>

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn" style="flex: 1; background: #ef4444; color: white;">Sim, Remover</button>
                <button type="button" onclick="closeDeleteModal()" class="btn btn-ghost" style="flex: 1;">Cancelar</button>
            </div>
        </form>
    </div>

    <script>
        function openModal() {
            document.getElementById('modalAction').value = 'create';
            document.getElementById('modalTitle').textContent = 'Nova Atividade do Catálogo';
            document.getElementById('itemId').value = '';
            document.getElementById('modalNome').value = '';
            document.getElementById('modalDescricao').value = '';
            document.getElementById('catalogModal').classList.add('active');
            document.getElementById('modalNome').focus();
        }

        function editByData(btn) {
            try {
                document.getElementById('modalAction').value = 'update';
                document.getElementById('modalTitle').textContent = 'Editar Atividade';
                document.getElementById('itemId').value = btn.getAttribute('data-id');
                document.getElementById('modalNome').value = btn.getAttribute('data-nome');
                document.getElementById('modalDescricao').value = btn.getAttribute('data-descricao') || '';
                document.getElementById('catalogModal').classList.add('active');
                document.getElementById('modalNome').focus();
            } catch(e) {
                console.error('Erro ao editar item:', e);
            }
        }

        function closeModal() {
            document.getElementById('catalogModal').classList.remove('active');
        }

        function confirmDelete(id) {
            document.getElementById('deleteItemId').value = id;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }
    </script>

    <script>
        function setView(mode) {
            const container = document.getElementById('dataContainer');
            if(!container) return;
            const btns = document.querySelectorAll('.view-btn');
            container.classList.remove('view-list', 'view-compact');
            if (mode === 'list') container.classList.add('view-list');
            if (mode === 'compact') container.classList.add('view-compact');
            btns.forEach(b => b.classList.remove('active'));
            const btn = document.getElementById('btn-' + mode);
            if(btn) btn.classList.add('active');
            localStorage.setItem('layout-mode', mode);
        }
        document.addEventListener('DOMContentLoaded', () => {
            const savedMode = localStorage.getItem('layout-mode') || 'grid';
            setView(savedMode);
        });
    </script>
</body>
</html>
