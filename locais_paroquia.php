<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Location Management (v2.0)
 * Modern CRUD · Geographic Setup · Premium UI
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();

$pid = current_paroquia_id();

requirePerm('admin_sistema');

$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// 1. Handle CRUD Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $action = $_POST['action'] ?? '';
    $data = sanitize_post($_POST);
    
    if ($action === 'create' || $action === 'update') {
        if (empty($data['nome_local'])) {
            $error = 'O nome do local é obrigatório.';
        } else {
            if ($action === 'create') {
                $sql = "INSERT INTO locais_paroquia (paroquia_id, nome_local, endereco, telefone, responsavel, capacidade) VALUES (?, ?, ?, ?, ?, ?)";
                $cap = (int)($data['capacidade'] ?? 0);
                if (db_execute($conn, $sql, [$pid, $data['nome_local'], $data['endereco'], $data['telefone'], $data['responsavel'], $cap])) {
                    logAction($conn, 'CRIAR_LOCAL', 'locais_paroquia', $conn->insert_id, ['novo' => $data]);
                    header("Location: locais_paroquia.php?msg=Local criado com sucesso!");
                    exit();
                }
            } else {
                $id = (int)$data['id'];
                
                // Get old state for logging
                $oldResult = db_query($conn, "SELECT * FROM locais_paroquia WHERE id = ? AND paroquia_id = ?", [$id, $pid]);
                $oldState = $oldResult ? $oldResult->fetch_assoc() : [];

                $sql = "UPDATE locais_paroquia SET nome_local = ?, endereco = ?, telefone = ?, responsavel = ?, capacidade = ? WHERE id = ? AND paroquia_id = ?";
                $cap = (int)($data['capacidade'] ?? 0);
                
                if (db_execute($conn, $sql, [$data['nome_local'], $data['endereco'], $data['telefone'], $data['responsavel'], $cap, $id, $pid])) {
                    logAction($conn, 'EDITAR_LOCAL', 'locais_paroquia', $id, ['antigo' => $oldState, 'novo' => $data]);
                    header("Location: locais_paroquia.php?msg=Local atualizado com sucesso!");
                    exit();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        
        // Get old state for logging
        $oldResult = db_query($conn, "SELECT * FROM locais_paroquia WHERE id = ? AND paroquia_id = ?", [$id, $pid]);
        $oldState = $oldResult ? $oldResult->fetch_assoc() : [];

        if (db_execute($conn, "DELETE FROM locais_paroquia WHERE id = ? AND paroquia_id = ?", [$id, $pid])) {
            logAction($conn, 'EXCLUIR_LOCAL', 'locais_paroquia', $id, ['antigo' => $oldState]);
            header("Location: locais_paroquia.php?msg=Local excluído permanentemente.");
            exit();
        }
    }
}

// 2. Fetch Locations
$locais = db_query($conn, "SELECT * FROM locais_paroquia WHERE paroquia_id = ? ORDER BY nome_local", [$pid]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Gerenciar Locais – PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }
        
        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .header-flex { flex-direction: column; align-items: flex-start; gap: 1.5rem; }
            .locations-grid { grid-template-columns: 1fr; }
            .btn-primary { width: 100%; }
        }
        
        .header-flex { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 3rem; }
        
        .locations-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 1.5rem; }
        .location-card { padding: 2rem; display: flex; flex-direction: column; gap: 1.5rem; transition: transform 0.3s var(--ease); }
        .location-card:hover { transform: translateY(-5px); border-color: var(--primary); }

        .loc-header { display: flex; align-items: center; gap: 1rem; }
        .loc-icon { width: 50px; height: 50px; border-radius: 14px; background: var(--panel-hi); display: flex; align-items: center; justify-content: center; color: var(--primary); }
        .loc-title h3 { font-size: 1.2rem; font-weight: 800; margin-bottom: 0.2rem; }
        .loc-title p { font-size: 0.75rem; color: var(--text-ghost); font-weight: 700; }

        .loc-details { flex: 1; display: grid; gap: 0.8rem; }
        .detail-row { display: flex; align-items: center; gap: 0.8rem; font-size: 0.85rem; color: var(--text-dim); font-weight: 500; }
        .detail-row svg { color: var(--text-ghost); flex-shrink: 0; }

        .loc-footer { display: flex; gap: 0.8rem; padding-top: 1.5rem; border-top: 1px solid var(--border); }

        /* Modal Overrides */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center; padding: 2rem; }
        .modal.active { display: flex; }
        .modal-card { width: 100%; max-width: 550px; padding: 3rem; }
    </style>
<style>
        /* ── View Modes ────────────────────────────────────────── */
        .view-controls { display: flex; gap: 0.5rem; background: var(--panel); padding: 0.4rem; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 1.5rem; width: fit-content; }
        .view-btn { padding: 0.5rem; border-radius: 8px; border: none; background: transparent; color: var(--text-dim); cursor: pointer; display: flex; align-items: center; transition: all var(--anim); }
        .view-btn:hover { background: var(--panel-hi); color: var(--text); }
        .view-btn.active { background: var(--primary); color: #fff; box-shadow: var(--sh-primary); }

        /* LIST VIEW */
        .locais-grid.view-list { grid-template-columns: 1fr !important; gap: 0.8rem; }
        .view-list .local-card { flex-direction: row; align-items: center; padding: 1rem 1.5rem; justify-content: space-between; }
        .view-list .local-card > div { flex-direction: row; align-items: center; gap: 1rem; }
        .view-list .local-card p { margin: 0; }
        
        /* COMPACT VIEW */
        .locais-grid.view-compact { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)) !important; gap: 1rem; }
        .view-compact .local-card { padding: 1rem; }
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
                    <div>
                        <p class="hide-on-mobile" style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.15em; color: var(--text-ghost); margin:0;">ADMINISTRAÇÃO</p>
                        <h1 class="gradient-text" style="font-size: 1.15rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Locais da Paróquia</h1>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: stretch; flex-shrink: 0;">
                        <a href="index.php" class="hide-on-desktop btn btn-ghost" style="background: #ef4444; color: #fff; border: none; padding: 0 0.8rem; min-height: 44px; border-radius: 10px; display: flex; align-items: center; gap: 0.4rem; font-weight: 800; font-size:0.75rem; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); justify-content: center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                            SAIR
                        </a>
                        <button onclick="openModal()" class="hide-on-desktop btn btn-primary shimmer" style="min-height: 44px; padding: 0 0.8rem; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 0.3rem; font-size: 0.75rem;">
                             <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                             NOVO
                        </button>
                    </div>
                </div>
                <button onclick="openModal()" class="btn btn-primary shimmer hide-on-mobile">Adicionar Local</button>
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

            <div class="locations-grid animate-in" id="dataContainer" style="animation-delay: 0.1s;">
                <?php while ($l = $locais->fetch_assoc()): ?>
                <article class="glass location-card">
                    <div class="loc-header">
                        <div class="loc-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </div>
                        <div class="loc-title">
                            <h3><?= h($l['nome_local']) ?></h3>
                            <p>CAPACIDADE: <?= $l['capacidade'] ?: 'N/A' ?> PESSOAS</p>
                        </div>
                    </div>

                    <div class="loc-details">
                        <div class="detail-row">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span><?= h($l['responsavel'] ?: 'Sem responsável') ?></span>
                        </div>
                        <div class="detail-row" style="align-items: flex-start;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-top: 2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span><?= h($l['endereco'] ?: 'Endereço não informado') ?></span>
                        </div>
                        <?php if ($l['telefone']): ?>
                        <div class="detail-row">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <span><?= h($l['telefone']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="loc-footer">
                        <button onclick='editLocal(<?= json_encode($l) ?>)' class="btn btn-ghost" style="flex: 1;">Editar</button>
                        <form method="POST" style="flex: 1;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <button type="button" class="btn btn-ghost" style="width: 100%; color: #ef4444;" onclick="return confirmForm(this, 'Excluir este local permanentemente?')">Excluir</button>
                        </form>
                    </div>
                </article>
                <?php endwhile; ?>
            </div>
        </main>
    </div>

    <!-- Modal Form -->
    <div id="locModal" class="modal">
        <form method="POST" class="glass modal-card">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" id="modalAction" value="create">
            <input type="hidden" name="id" id="locId">
            
            <h2 id="modalTitle" style="margin-bottom: 2rem; font-weight: 900;">Adicionar Local</h2>
            
            <div style="display: grid; gap: 1.5rem;">
                <div class="form-group">
                    <label>Nome do Local</label>
                    <input type="text" name="nome_local" id="modalNome" placeholder="Ex: Salão Paroquial" required>
                </div>
                
                <div class="form-group">
                    <label>Endereço</label>
                    <input type="text" name="endereco" id="modalEnd" placeholder="Rua, número, bairro">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label>Telefone</label>
                        <input type="text" name="telefone" id="modalTel" placeholder="(00) 0000-0000">
                    </div>
                    <div class="form-group">
                        <label>Capacidade (Pessoas)</label>
                        <input type="number" name="capacidade" id="modalCap" placeholder="100">
                    </div>
                </div>

                <div class="form-group">
                    <label>Responsável</label>
                    <input type="text" name="responsavel" id="modalResp" placeholder="Nome do encarregado">
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 2;">Confirmar</button>
                    <button type="button" onclick="closeModal()" class="btn btn-ghost" style="flex: 1;">Cancelar</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function openModal() {
            document.getElementById('modalAction').value = 'create';
            document.getElementById('modalTitle').textContent = 'Adicionar Local';
            document.getElementById('locId').value = '';
            document.getElementById('modalNome').value = '';
            document.getElementById('modalEnd').value = '';
            document.getElementById('modalTel').value = '';
            document.getElementById('modalCap').value = '';
            document.getElementById('modalResp').value = '';
            document.getElementById('locModal').classList.add('active');
        }

        function editLocal(l) {
            document.getElementById('modalAction').value = 'update';
            document.getElementById('modalTitle').textContent = 'Editar Local';
            document.getElementById('locId').value = l.id;
            document.getElementById('modalNome').value = l.nome_local;
            document.getElementById('modalEnd').value = l.endereco || '';
            document.getElementById('modalTel').value = l.telefone || '';
            document.getElementById('modalCap').value = l.capacidade || '';
            document.getElementById('modalResp').value = l.responsavel || '';
            document.getElementById('locModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('locModal').classList.remove('active');
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