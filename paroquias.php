<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Parish Management (v2.0)
 * Context Control · Logo PNG Upload · CRUD Paróquias
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();

if ($_SESSION['usuario_id'] != 1) {
    header('Location: index.php?error=unauthorized');
    exit();
}

$msg = '';
$error = '';


/** Handle Form Submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $action = $_POST['action'] ?? 'save';
    
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $oldResult = db_query($conn, "SELECT * FROM paroquias WHERE id = ?", [$id]);
        if ($oldResult && $oldResult->num_rows > 0) {
            $oldState = $oldResult->fetch_assoc();
            db_execute($conn, "DELETE FROM paroquias WHERE id = ?", [$id]);
            
            $img_path = __DIR__ . '/img/paroquia_' . $id . '.png';
            if (file_exists($img_path)) unlink($img_path);
            
            logAction($conn, 'EXCLUIR_PAROQUIA', 'paroquias', $id, ['antigo' => $oldState]);
            header('Location: paroquias.php?msg=Contexto removido');
            exit();
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
    
    if ($nome) {
        if ($id > 0) {
            // Update
            $oldRes = db_query($conn, "SELECT * FROM paroquias WHERE id = ?", [$id]);
            $oldData = $oldRes ? $oldRes->fetch_assoc() : [];
            
            if (db_execute($conn, 'UPDATE paroquias SET nome = ? WHERE id = ?', [$nome, $id])) {
                logAction($conn, 'EDITAR_PAROQUIA', 'paroquias', $id, ['antigo' => $oldData, 'novo' => ['nome' => $nome]]);
                $msg = 'Contexto salvo.';
            } else {
                $error = 'Erro ao salvar contexto.';
            }
            $target_id = $id;
        } else {
            // Insert
            if (db_execute($conn, 'INSERT INTO paroquias (nome) VALUES (?)', [$nome])) {
                $target_id = $conn->insert_id;
                logAction($conn, 'CRIAR_PAROQUIA', 'paroquias', $target_id, ['novo' => $nome]);
                
                // Set to self context if master uses it
                if (empty($_SESSION['paroquia_id'])) $_SESSION['paroquia_id'] = $target_id;
                
                $msg = 'Contexto registrado.';
            } else {
                $error = 'Erro ao cadastrar.';
            }
        }
        
        // Handle Icon Upload
        if (!empty($target_id) && isset($_FILES['icone']) && $_FILES['icone']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['icone']['tmp_name'];
            $mime = mime_content_type($tmpPath);
            
            if ($mime === 'image/png') {
                $destPath = __DIR__ . "/img/paroquia_{$target_id}.png";
                if (!is_dir(__DIR__ . '/img')) mkdir(__DIR__ . '/img', 0755, true);
                
                move_uploaded_file($tmpPath, $destPath);
                $msg .= ' Logo atualizada.';
            } else {
                $error = 'Formato inválido. Envie apenas arquivos .png.';
            }
        }
        
    } else {
        $error = 'O nome da paróquia/sede é obrigatório.';
    }
    }
}

$res = db_query($conn, 'SELECT p.*, (SELECT COUNT(id) FROM usuarios u WHERE u.paroquia_id = p.id) as totais FROM paroquias p ORDER BY p.nome');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Sedes e Contextos — PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }
        .header-flex { display: flex; justify-content: space-between; align-items: flex-end; gap: 1.5rem; margin-bottom: 3rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }

        .pq-card {
            padding: 1.5rem; border-radius: 24px; position: relative;
            overflow: hidden; display: flex; align-items: center; gap: 1.5rem;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .header-flex { flex-direction: column; align-items: flex-start; }
            .grid { grid-template-columns: 1fr; }
            .pq-card { flex-direction: column; align-items: flex-start; text-align: left; }
            .pq-card > div:last-child { width: 100%; flex-direction: row; justify-content: space-between; }
        }
        /* The previous diff added these media queries, so they should be removed */
        /*
        @media (max-width: 991px) {
            .header-flex { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 576px) {
            .main-content { padding: 1rem; }
            .header-flex { flex-direction: column; align-items: flex-start; }
            .grid { grid-template-columns: 1fr; }
            .pq-card { flex-direction: column; align-items: flex-start; text-align: left; }
            .pq-card > div:last-child { width: 100%; flex-direction: row; justify-content: space-between; }
        }
    </style>
<style>
        /* ── View Modes ────────────────────────────────────────── */
        .view-controls { display: flex; gap: 0.5rem; background: var(--panel); padding: 0.4rem; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 1.5rem; width: fit-content; }
        .view-btn { padding: 0.5rem; border-radius: 8px; border: none; background: transparent; color: var(--text-dim); cursor: pointer; display: flex; align-items: center; transition: all var(--anim); }
        .view-btn:hover { background: var(--panel-hi); color: var(--text); }
        .view-btn.active { background: var(--primary); color: #fff; box-shadow: var(--sh-primary); }

        /* LIST VIEW */
        .grid.view-list { grid-template-columns: 1fr !important; gap: 0.8rem; }
        .view-list .pq-card { flex-direction: row; align-items: center; padding: 1rem 1.5rem; justify-content: space-between; }
        .view-list .pq-card > div { flex-direction: row; align-items: center; gap: 1rem; }
        .view-list .pq-card p { margin: 0; }
        
        /* COMPACT VIEW */
        .grid.view-compact { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)) !important; gap: 1rem; }
        .view-compact .pq-card { padding: 1rem; }
        </style>
</head>
<body>
    <div class="bg-mesh"></div>
    
    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <header class="calendar-header animate-in" style="margin-bottom: 2rem; display: flex; align-items: center; padding: 0 1rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 1rem;">
                    <div>
                        <p class="hide-on-mobile" style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.15em; color: var(--text-ghost); margin:0;">MASTER CONTROL</p>
                        <h1 class="gradient-text" style="font-size: 1.15rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Sedes Paroquiais</h1>
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
                <button onclick="openModal()" class="btn btn-primary shimmer hide-on-mobile">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                    Novo Contexto
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

            <?php if ($msg): ?> <?= alert('success', h($msg)) ?> <?php endif; ?>
            <?php if ($error): ?> <?= alert('danger', h($error)) ?> <?php endif; ?>
            <?php if (isset($_GET['msg'])): ?> <?= alert('success', h($_GET['msg'])) ?> <?php endif; ?>

            <div class="grid animate-in" id="dataContainer" style="animation-delay: 0.1s;">
                <?php while ($row = $res->fetch_assoc()): ?>
                <div class="glass pq-card">
                    <?php 
                    $icon = "img/paroquia_{$row['id']}.png";
                    if (file_exists(__DIR__ . '/' . $icon)): ?>
                        <div style="width: 70px; height: 70px; border-radius: 18px; border: 1px solid var(--border); overflow: hidden; flex-shrink: 0; background: #000;">
                            <img src="<?= $icon ?>?v=<?= time() ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    <?php else: ?>
                        <div style="width: 70px; height: 70px; border-radius: 18px; border: 1px dashed var(--border); display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.02); color: var(--text-ghost); flex-shrink: 0;">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </div>
                    <?php endif; ?>

                    <div style="flex: 1;">
                        <span style="font-size: 0.7rem; font-weight: 800; letter-spacing: 0.1em; color: var(--primary);">ID #<?= $row['id'] ?></span>
                        <h2 style="font-size: 1.3rem; margin: 0.2rem 0;"><?= h($row['nome']) ?></h2>
                        <div style="font-size: 0.8rem; color: var(--text-dim); display: flex; gap: 0.5rem; align-items: center;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <?= $row['totais'] ?> Membros (Admin/Users)
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 0.5rem; flex-shrink: 0;">
                        <button onclick="editPq(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-ghost" style="padding: 0.6rem;">Editar</button>
                        <form method="POST" style="margin:0; width: 100%;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="button" class="btn" style="padding: 0.6rem; color: #ef4444; font-size: 0.75rem; border: 1px solid rgba(239, 68, 68, 0.3); width: 100%; text-align: center;" onclick="return confirmForm(this, 'ATENÇÃO! Excluir este contexto paroquial afeta todos os usuários e lógicas associadas a ele. Deseja prosseguir?')">Excluir</button>
                        </form>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            
        </main>
    </div>

    <!-- Modal Form -->
    <div class="modal" id="formModal">
        <div class="modal-dialog">
            <header class="modal-header">
                <h2>Gestão da Sede/Paróquia</h2>
                <button type="button" class="btn-close" onclick="closeModal()">×</button>
            </header>


            
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="paroquiaId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>NOME OFICIAL DA PARÓQUIA / SEDE</label>
                        <input type="text" name="nome" id="paroquiaNome" required>
                    </div>

                    <div class="form-group" style="background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 16px; border: 1px dashed var(--border);">
                        <label>ÍCONE DO CONTEXTO (.PNG)</label>
                        <input type="file" name="icone" accept=".png" style="width: 100%; border: none; padding: 0.5rem; background: transparent; font-size: 0.8rem; color: var(--text-dim);">
                        <p style="font-size: 0.7rem; color: var(--text-ghost); margin-top: 0.5rem;">Tamanho recomendado: Imagem quadrada (ex: 200x200). Aparecerá no menu lateral substituindo o brasão nativo.</p>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary shimmer">Salvar Configurações</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openModal() {
        document.getElementById('paroquiaId').value = '';
        document.getElementById('paroquiaNome').value = '';
        document.getElementById('formModal').classList.add('active');
    }
    
    function closeModal() {
        document.getElementById('formModal').classList.remove('active');
    }
    
    function editPq(data) {
        document.getElementById('paroquiaId').value = data.id;
        document.getElementById('paroquiaNome').value = data.nome;
        document.getElementById('formModal').classList.add('active');
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
