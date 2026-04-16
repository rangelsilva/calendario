<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Audit Trail & Logs (v2.0)
 * Security Monitoring · Action Timeline · Premium UI
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();
ensureUserPhotoColumn($conn);

requirePerm('ver_logs');

$pid = current_paroquia_id();
$filter_table = $_GET['tabela'] ?? '';
$filter_user = $_GET['usuario'] ?? '';

// 1. Build Query
$where = [];
$params = [];
$types = "";

// Data isolation: Master (0) sees all, others only their Parish
if (!has_level(0)) {
    $where[] = "l.paroquia_id = ?";
    $params[] = $pid;
    $types .= "i";
}

if ($filter_table) {
    $where[] = "l.tabela_afetada LIKE ?";
    $params[] = "%$filter_table%";
    $types .= "s";
}

if ($filter_user) {
    $where[] = "u.nome LIKE ?";
    $params[] = "%$filter_user%";
    $types .= "s";
}

$sql = "
    SELECT l.*, u.nome as usuario_nome, u.foto_perfil
    FROM log_alteracoes l 
    LEFT JOIN usuarios u ON l.usuario_id = u.id
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY l.data_hora DESC LIMIT 100";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$logs = $stmt->get_result();
?>
<?php
// Autocomplete data for filters
$stmtUsers = $conn->prepare("SELECT DISTINCT nome FROM usuarios WHERE paroquia_id = ? ORDER BY nome");
$stmtUsers->bind_param('i', $pid);
$stmtUsers->execute();
$usuarios_list = $stmtUsers->get_result();
$tabelas_list = $conn->query("SELECT DISTINCT tabela_afetada FROM log_alteracoes ORDER BY tabela_afetada");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Auditoria do Sistema – PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }
        
        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .header-flex { flex-direction: column; align-items: center; text-align: center; gap: 1.5rem; }
            .filter-bar { flex-direction: column; align-items: stretch; gap: 1.25rem; }
            .filter-bar .form-group { flex: 1 1 auto; }
            .filter-actions { display: flex; flex-direction: column; gap: 0.8rem; width: 100%; }
            .btn { width: 100%; justify-content: center; }
        }
        
        .header-flex { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 3.5rem; }
        
        .filter-bar { display: flex; gap: 1.5rem; margin-bottom: 2.5rem; flex-wrap: wrap; align-items: flex-end; }
        .filter-bar .form-group { flex: 0 1 250px; }
        .filter-actions { display: flex; gap: 0.5rem; }

        .timeline { display: flex; flex-direction: column; gap: 1rem; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .log-item { display: grid; grid-template-columns: 180px 150px 100px 1fr 200px; align-items: center; gap: 1.5rem; padding: 1.25rem 2rem; transition: background 0.2s; }
        .log-item:hover { background: rgba(255,255,255,0.03); }
        
        .log-date { font-size: 0.75rem; font-weight: 800; color: var(--text-ghost); font-family: monospace; }
        .log-user { display: flex; align-items: center; gap: 0.8rem; font-size: 0.85rem; font-weight: 700; color: var(--text); }
        .log-user .avatar { width: 24px; height: 24px; border-radius: 6px; background: var(--panel-hi); display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: var(--primary); overflow: hidden; }
        .log-user .avatar img { width: 100%; height: 100%; object-fit: cover; }
        
        .log-badge { font-size: 0.65rem; font-weight: 900; padding: 0.3rem 0.6rem; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; background: var(--panel-hi); color: var(--text-dim); border: 1px solid var(--border); }
        .log-badge.success { color: #10b981; border-color: rgba(16, 185, 129, 0.2); }
        .log-badge.warning { color: #f59e0b; border-color: rgba(245, 158, 11, 0.2); }
        .log-badge.danger { color: #ef4444; border-color: rgba(239, 68, 68, 0.2); }

        .log-desc { font-size: 0.85rem; color: var(--text-dim); }
        .log-id { font-size: 0.7rem; color: var(--text-ghost); font-family: monospace; text-align: right; }

        @media (max-width: 1200px) {
            .log-item { grid-template-columns: 1fr 1fr; gap: 1rem; }
            .log-desc { grid-column: span 2; }
            .log-id { display: none; }
        }
    </style>
<style>
        /* ── View Modes ────────────────────────────────────────── */
        .view-controls { display: flex; gap: 0.5rem; background: var(--panel); padding: 0.4rem; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 1.5rem; width: fit-content; }
        .view-btn { padding: 0.5rem; border-radius: 8px; border: none; background: transparent; color: var(--text-dim); cursor: pointer; display: flex; align-items: center; transition: all var(--anim); }
        .view-btn:hover { background: var(--panel-hi); color: var(--text); }
        .view-btn.active { background: var(--primary); color: #fff; box-shadow: var(--sh-primary); }

        /* LIST VIEW */
        .timeline.view-list { grid-template-columns: 1fr !important; gap: 0.8rem; }
        .view-list .log-item { flex-direction: row; align-items: center; padding: 1rem 1.5rem; justify-content: space-between; }
        .view-list .log-item > div { flex-direction: row; align-items: center; gap: 1rem; }
        .view-list .log-item p { margin: 0; }
        
        /* COMPACT VIEW */
        .timeline.view-compact { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)) !important; gap: 1rem; }
        .view-compact .log-item { padding: 1rem; }
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
                        <p class="hide-on-mobile" style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.15em; color: var(--text-ghost); margin:0;">AUDITORIA</p>
                        <h1 class="gradient-text" style="font-size: 1.15rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Logs de Atividades</h1>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: stretch; flex-shrink: 0;">
                        <a href="index.php" class="hide-on-desktop btn btn-ghost" style="background: #ef4444; color: #fff; border: none; padding: 0 0.8rem; min-height: 44px; border-radius: 10px; display: flex; align-items: center; gap: 0.4rem; font-weight: 800; font-size: 0.75rem; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); justify-content: center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                            SAIR
                        </a>
                    </div>
                </div>
            </header>

            <div class="view-controls animate-in" style="animation-delay: 0.05s;">
                <button onclick="setView('grid')" id="btn-grid" class="view-btn active" title="Grelha">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </button>
                <button onclick="setView('list')" id="btn-list" class="view-btn" title="Lista">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </button>
                <button onclick="setView('compact')" id="btn-compact" class="view-btn" title="Compacto">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                </button>
            </div>

            <form method="GET" class="glass filter-bar animate-in" style="padding: 1.5rem; border-radius: 20px; animation-delay: 0.1s;">
                <div class="form-group">
                    <label style="font-size: 0.65rem; margin-bottom: 0.5rem; display: block;">FILTRAR POR TABELA</label>
                    <input type="text" name="tabela" value="<?= h($filter_table) ?>" placeholder="Ex: atividades, locais..." list="tabelas_list" autocomplete="off">
                </div>
                <div class="form-group">
                    <label style="font-size: 0.65rem; margin-bottom: 0.5rem; display: block;">FILTRAR POR USUÁRIO</label>
                    <input type="text" name="usuario" value="<?= h($filter_user) ?>" placeholder="Nome do usuário..." list="usuarios_list" autocomplete="off">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary" style="padding: 0.8rem 1.5rem;">Filtrar</button>
                    <a href="logs.php" class="btn btn-ghost" style="padding: 0.8rem 1.5rem;">Limpar</a>
                </div>

                <datalist id="tabelas_list">
                    <?php if ($tabelas_list) while ($tb = $tabelas_list->fetch_assoc()): ?>
                        <option value="<?= h($tb['tabela_afetada']) ?>">
                    <?php endwhile; ?>
                </datalist>
                <datalist id="usuarios_list">
                    <?php if ($usuarios_list) while ($ul = $usuarios_list->fetch_assoc()): ?>
                        <option value="<?= h($ul['nome']) ?>">
                    <?php endwhile; ?>
                </datalist>
            </form>

            <section class="glass timeline animate-in" id="dataContainer" style="border-radius: 24px; padding: 1rem 0; animation-delay: 0.2s;">
                <?php if ($logs->num_rows > 0): ?>
                    <?php while ($l = $logs->fetch_assoc()): ?>
                    <?php 
                        $badgeClass = '';
                        if (strpos($l['acao'], 'CRIAR') !== false) $badgeClass = 'success';
                        if (strpos($l['acao'], 'EDITAR') !== false) $badgeClass = 'warning';
                        if (strpos($l['acao'], 'EXCLUIR') !== false) $badgeClass = 'danger';
                    ?>
                    <div class="log-item">
                        <div class="log-date"><?= date('d/m/Y H:i:s', strtotime($l['data_hora'])) ?></div>
                        <div class="log-user">
                            <div class="avatar">
                                <?php if (!empty($l['foto_perfil']) && file_exists(__DIR__ . '/' . $l['foto_perfil'])): ?>
                                    <img src="<?= h($l['foto_perfil']) ?>?v=<?= time() ?>" alt="Foto">
                                <?php else: ?>
                                    <?= mb_substr($l['usuario_nome'] ?: '?', 0, 1) ?>
                                <?php endif; ?>
                            </div>
                            <span><?= h($l['usuario_nome'] ?: 'Sistema') ?></span>
                        </div>
                        <div style="display: flex;"><span class="log-badge <?= $badgeClass ?>"><?= h($l['acao']) ?></span></div>
                        <div class="log-desc">
                            Na tabela <b style="color: var(--text);"><?= h($l['tabela_afetada']) ?></b>
                            <?php if ($l['detalhes_alteracao']): ?>
                                — <span style="font-style: italic; opacity: 0.8;"><?= h($l['detalhes_alteracao']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="log-id">REF_ID: <?= $l['registro_id'] ?: 'N/A' ?></div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 5rem; color: var(--text-ghost);">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1.5rem; opacity: 0.3;"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                        <p>Nenhum registro encontrado para os filtros aplicados.</p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

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
