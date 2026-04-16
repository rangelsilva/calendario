<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Universal Sidebar (v2.4.3)
 * Glassmorphic UI · Dynamic RBAC · Parish Hub
 * ═══════════════════════════════════════════════════════ */

require_once 'config.php';
requireLogin();
ensureUserPhotoColumn($conn);

$nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$initials = mb_strtoupper(mb_substr($nome, 0, 1));
$userPhoto = trim((string)($_SESSION['usuario_foto'] ?? ''));
if ($userPhoto === '') {
    $sid = (int)($_SESSION['usuario_id'] ?? 0);
    if ($sid > 0) {
        $stPhoto = $conn->prepare("SELECT foto_perfil FROM usuarios WHERE id = ? LIMIT 1");
        if ($stPhoto) {
            $stPhoto->bind_param('i', $sid);
            $stPhoto->execute();
            $rPhoto = $stPhoto->get_result()->fetch_assoc();
            $userPhoto = trim((string)($rPhoto['foto_perfil'] ?? ''));
            $_SESSION['usuario_foto'] = $userPhoto;
        }
    }
}

$parish_name = 'Igreja Católica';
$pid = $_SESSION['paroquia_id'] ?? 0;
if ($pid > 0) {
    $stmt = $conn->prepare("SELECT nome FROM paroquias WHERE id = ?");
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $resParish = $stmt->get_result();
    if ($resParish && $resParish->num_rows > 0) {
        $parish_name = $resParish->fetch_assoc()['nome'];
    }
}

$all_parishes = [];
if (userCanSwitchParish()) {
    $p_res = $conn->query("SELECT id, nome FROM paroquias ORDER BY nome");
    if ($p_res) {
        while ($p_row = $p_res->fetch_assoc()) {
            $all_parishes[] = $p_row;
        }
    }
}

// Navigation logic
$current_page = basename($_SERVER['PHP_SELF']);
function is_active(string $page): string {
    global $current_page;
    return ($current_page === $page) ? 'nav-item active' : 'nav-item';
}
?>

<button class="menu-trigger hide-on-mobile" onclick="toggleSidebar()">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
</button>

<aside class="sidebar" id="mainSidebar">
    <div class="sidebar-header">
        <div class="header-top">
            <div class="brand-logo">
                <?php 
                $icon_path = "img/paroquia_{$pid}.png";
                if ($pid > 0 && file_exists(__DIR__ . '/' . $icon_path)): 
                ?>
                    <img src="<?= $icon_path ?>?v=<?= time() ?>" alt="Paróquia" style="width: 100%; height: 100%; border-radius: 10px; object-fit: cover;">
                <?php else: ?>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <?php endif; ?>
            </div>
            <button type="button" class="desktop-toggle hide-on-mobile" onclick="toggleDesktopSidebar()" title="Alternar menu">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="toggle-icon"><polyline points="15 18 9 12 15 6"></polyline></svg>
                <span class="toggle-text">FECHAR</span>
            </button>
        </div>
        <div class="brand-details">
            <span class="brand-text">Calendário Paroquial</span>
            <?php if (count($all_parishes) > 1): ?>
                <select class="brand-sub-select" onchange="window.location.href='select_paroquia.php?id='+this.value">
                    <?php if ($pid == 0): ?><option value="0" selected>Selecionar...</option><?php endif; ?>
                    <?php foreach($all_parishes as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $pid ? 'selected' : '' ?>><?= h($p['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <span class="brand-sub"><?= h($parish_name) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ... rest of sidebar ... -->
    <nav class="sidebar-nav">
        <div class="nav-group">
            <span class="nav-label">Principal</span>
            
            <?php if (can('criar_eventos')): ?>
            <a href="novaatividade.php" class="hide-on-desktop nav-item btn-create-accent">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Novo Evento</span>
            </a>
            <?php endif; ?>

            <a href="index.php" class="<?= is_active('index.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="3" x2="21" y1="10" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01"/></svg>
                <span>Calendário</span>
            </a>
            <a href="meus_grupos.php" class="<?= is_active('meus_grupos.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M4.93 4.93a10 10 0 0 0 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M8.46 8.46a5 5 0 0 0 0 7.07"/></svg>
                <span>Grupos de Trabalho</span>
            </a>
        </div>

        <?php if (can('admin_sistema') || can('admin_usuarios')): ?>
        <div class="nav-group">
            <span class="nav-label">Administração</span>
            <?php if ($_SESSION['usuario_id'] == 1): ?>
            <a href="paroquias.php" class="<?= is_active('paroquias.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/><path d="M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"/></svg>
                <span>Paróquias</span>
            </a>
            <?php endif; ?>
            <?php if (can('admin_sistema')): ?>
            <a href="locais_paroquia.php" class="<?= is_active('locais_paroquia.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <span>Locais</span>
            </a>
            <a href="tipos_atividade.php" class="<?= is_active('tipos_atividade.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.27 5.82 22 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                <span>Categorias</span>
            </a>
            <?php endif; ?>

            <?php if (can('gerenciar_catalogo') || can('admin_sistema')): ?>
            <a href="gerenciar_catalogo.php" class="<?= is_active('gerenciar_catalogo.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect width="6" height="4" x="9" y="3" rx="1"/><path d="M9 14l2 2 4-4"/></svg>
                <span>Catálogo de Atividades</span>
            </a>
            <?php endif; ?>
            
            <?php if (can('admin_usuarios')): ?>
            <a href="usuarios.php" class="<?= is_active('usuarios.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Usuários</span>
            </a>
            <?php endif; ?>

            <?php if (can('admin_sistema')): ?>
            <a href="perfis.php" class="<?= is_active('perfis.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="4"/><path d="M6 21v-2a6 6 0 0 1 12 0v2"/></svg>
                <span>Perfis</span>
            </a>
            <?php endif; ?>

            <?php if (can('gerenciar_grupos')): ?>
            <a href="grupos_trabalho.php" class="<?= is_active('grupos_trabalho.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="12"/><path d="M2 12h20"/></svg>
                <span>Editar Grupos de Trabalho</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (can('ver_logs')): ?>
        <div class="nav-group">
            <span class="nav-label">Relatórios</span>
            <a href="logs.php" class="<?= is_active('logs.php') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Logs do Sistema</span>
            </a>
            <a href="documentos.php" class="<?= is_active('documentos.php') ?>">

                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>
                <span>Relatórios</span>
            </a>
        </div>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <?php if ($userPhoto !== '' && file_exists(__DIR__ . '/' . $userPhoto)): ?>
                    <img src="<?= h($userPhoto) ?>?v=<?= time() ?>" alt="Perfil">
                <?php else: ?>
                    <?= h($initials) ?>
                <?php endif; ?>
            </div>
            <div class="user-details">
                <span class="user-name"><?= h($nome) ?></span>
                <span class="user-status">Online</span>
            </div>
        </div>
        <a href="logout.php" class="logout-btn" title="Sair do Sistema">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
        </a>
    </div>
</aside>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<style>
:root {
    --sidebar-w: 280px;
}

body {
  font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
  background: var(--bg);
  color: var(--text);
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
  min-height: 100vh;
  overflow-x: hidden !important;
  width: 100%;
  touch-action: manipulation;
}
#app-shell { overflow-x: hidden; }

.sidebar {
    position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh;
    background: rgba(13, 14, 26, 0.9); backdrop-filter: blur(25px);
    border-right: 1px solid var(--border); display: flex; flex-direction: column;
    z-index: 2100; transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1);
}

.sidebar-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);
    z-index: 2050; opacity: 0; pointer-events: none; transition: all 0.4s;
}

.sidebar.open + .sidebar-overlay {
    opacity: 1; pointer-events: auto;
}

.menu-trigger {
    position: fixed; top: 1.5rem; left: 1.5rem; z-index: 1000;
    width: 48px; height: 48px; border-radius: 12px;
    background: var(--panel-hi); border: 1px solid var(--border);
    color: var(--text); display: none; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.3s; touch-action: manipulation;
}
.menu-trigger:hover { background: var(--border); transform: scale(1.05); }

.desktop-toggle {
    background: var(--primary); border: none; color: #fff;
    cursor: pointer; display: none; padding: 0.5rem 0.8rem; transition: all 0.3s;
    border-radius: 100px; align-items: center; justify-content: center;
    flex-shrink: 0; gap: 0.5rem; font-weight: 900; font-size: 0.65rem;
    box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.3);
}
.desktop-toggle:hover { background: var(--accent); transform: scale(1.05); }
.toggle-text { letter-spacing: 0.05em; }

.sidebar-header { padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; border-bottom: 1px solid rgba(255,255,255,0.03); }
.header-top { display: flex; align-items: center; justify-content: space-between; width: 100%; }
.brand-details { display: flex; flex-direction: column; line-height: 1.1; }
.brand-text { font-weight: 900; font-size: 1.05rem; letter-spacing: -0.02em; color: var(--text); }
.brand-sub { 
    font-size: 0.65rem; font-weight: 700; color: var(--text-ghost); 
    text-transform: uppercase; letter-spacing: 0.05em; margin-top: 2px;
    line-height: 1.3; display: block; white-space: normal; overflow-wrap: break-word;
}
.brand-sub-select { background: rgba(var(--primary-rgb), 0.05); border: 1px solid var(--border); font-size: 0.65rem; font-weight: 700; color: var(--primary); cursor: pointer; padding: 0.5rem; outline: none; margin-top: 6px; text-transform: uppercase; width: 100%; border-radius: 8px; }
.brand-sub-select option { background: var(--bg); color: var(--text); }
.brand-logo { 
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.2);
}

.sidebar-nav { flex: 1; overflow-y: auto; padding: 1rem; }
/* ... existing nav styles ... */
.nav-group { margin-bottom: 2rem; }
.nav-label { 
    display: block; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; 
    color: var(--text-ghost); letter-spacing: 0.12em; margin-bottom: 0.8rem; padding-left: 0.8rem;
}
.nav-item {
    display: flex; align-items: center; gap: 1rem; padding: 0.85rem 1rem;
    border-radius: var(--r-md); color: var(--text-dim); text-decoration: none;
    font-size: 0.9rem; font-weight: 600; transition: all var(--anim);
    margin-bottom: 0.25rem;
}
.nav-item:hover { background: var(--panel-hi); color: var(--text); transform: translateX(4px); }
.nav-item.active { 
    background: rgba(var(--primary-rgb), 0.1); color: var(--primary); 
    box-shadow: inset 0 0 0 1px rgba(var(--primary-rgb), 0.2);
}

.btn-create-accent {
    background: linear-gradient(135deg, var(--primary), var(--accent)) !important;
    color: #fff !important;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    margin-bottom: 0.8rem !important;
    border-radius: 12px !important;
}
.btn-create-accent:hover {
    transform: translateY(-2px) scale(1.02) !important;
    box-shadow: 0 10px 20px -5px rgba(var(--primary-rgb), 0.4) !important;
}

@media (max-width: 1024px) {
    .menu-trigger { display: flex; }
}

.sidebar-footer { 
    padding: 1.5rem; border-top: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.user-info { display: flex; align-items: center; gap: 0.8rem; }
.user-avatar { 
    width: 38px; height: 38px; border-radius: 10px; 
    background: var(--panel-hi); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.9rem; color: var(--primary);
    overflow: hidden;
}
.user-avatar img { width: 100%; height: 100%; object-fit: cover; }
.user-details { display: flex; flex-direction: column; }
.user-name { font-size: 0.85rem; font-weight: 700; color: var(--text); }
.user-status { font-size: 0.7rem; color: #22c55e; font-weight: 700; display: flex; align-items: center; gap: 0.3rem; }
.user-status::before { content: ''; width: 6px; height: 6px; background: #22c55e; border-radius: 50%; }

.logout-btn { 
    color: var(--text-ghost); transition: all 0.2s; 
    padding: 0.5rem; border-radius: 8px;
}
.logout-btn:hover { color: #ef4444; background: rgba(239, 68, 68, 0.1); }

@media (max-width: 1024px) {
    .sidebar { transform: translateX(-100%); width: 260px; }
    .sidebar.open { transform: translateX(0); }
    .menu-trigger, .close-sidebar { display: flex; }
}
/* Modals globais */
.modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(10px); z-index: 9999; align-items: center; justify-content: center; padding: 2rem; }
.modal.active { display: flex; }
.modal-card { width: 100%; max-width: 540px; padding: 3rem; }

@media (min-width: 1025px) {
    .desktop-toggle { display: flex; }
    
    .sidebar-mini .sidebar { width: 80px; padding: 0; overflow: visible; }
    .sidebar-mini .main-content { margin-left: 80px; }
    
    .sidebar-mini .sidebar .nav-item { display: flex; align-items: center; justify-content: center; padding: 0; border-radius: 12px; margin: 0 auto; width: 42px; height: 42px; transform: none !important; }
    .sidebar-mini .sidebar .brand-details,
    .sidebar-mini .sidebar .nav-item span,
    .sidebar-mini .sidebar .user-details,
    .sidebar-mini .sidebar .logout-btn span,
    .sidebar-mini .sidebar .toggle-text,
    .sidebar-mini .sidebar .nav-badge { display: none !important; }
    
    .sidebar-mini .sidebar .nav-item i, .sidebar-mini .sidebar .nav-item svg { margin: 0; font-size: 1.6rem; opacity: 1 !important; color: var(--text-ghost); width: 28px; height: 28px; }
    .sidebar-mini .sidebar .nav-item:hover i, .sidebar-mini .sidebar .nav-item:hover svg { color: var(--primary); }
    .sidebar-mini .sidebar .nav-item.active i, .sidebar-mini .sidebar .nav-item.active svg { color: var(--primary); }
    
    .sidebar-mini .sidebar .sidebar-footer { flex-direction: column; gap: 1rem; padding: 1.5rem 0; align-items: center; justify-content: center; border-top-color: rgba(255,255,255,0.03); }
    .sidebar-mini .sidebar .user-info { justify-content: center; width: 100%; border: 0; padding: 0; }
    .sidebar-mini .sidebar .logout-btn { padding: 0; width: 38px; height: 38px; justify-content: center; border-radius: 10px; margin: 0 auto; display: flex; align-items: center; }
    .sidebar-mini .sidebar .logout-btn svg { width: 20px; height: 20px; }
    
    .sidebar-mini .sidebar .sidebar-header { padding: 2.5rem 0 1.5rem; flex-direction: column; gap: 1rem; align-items: center; justify-content: center; border-bottom: none; }
    .sidebar-mini .sidebar .header-top { flex-direction: column-reverse; gap: 1.5rem; align-items: center; justify-content: center; }
    .sidebar-mini .sidebar .brand { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; }
    .sidebar-mini .sidebar .brand-logo { width: 38px; height: 38px; margin: 0; }
    
    .sidebar-mini .sidebar .desktop-toggle { 
        width: 32px; height: 32px; padding: 0; margin: 0; gap: 0;
        background: var(--primary); color: #fff;
        box-shadow: 0 4px 10px rgba(var(--primary-rgb), 0.3); display: flex;
    }
    .sidebar-mini .sidebar .desktop-toggle .toggle-icon { transform: rotate(180deg); }
    .sidebar-mini .sidebar .nav-group { align-items: center; display: flex; flex-direction: column; gap: 0.5rem; padding: 0; width: 100%; }
    .sidebar-mini .sidebar .nav-label { display: none; }
    .sidebar-mini .sidebar .sidebar-nav { padding: 1rem 0; }
/* ── Premium UI Elements ───────────────────────────── */
.premium-toast {
    position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-20px);
    background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(12px); border: 1px solid var(--border);
    border-radius: 100px; padding: 0.75rem 1.25rem; display: flex; align-items: center; gap: 0.75rem;
    color: #fff; font-size: 0.85rem; font-weight: 700; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    z-index: 10000; opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    pointer-events: none;
}
.premium-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.premium-toast.toast-success { border-bottom: 2px solid #22c55e; }
.premium-toast.toast-error { border-bottom: 2px solid #ef4444; }
.spinner-icon {
    animation: rotate 2s linear infinite; width: 18px; height: 18px; display: inline-block; vertical-align: middle;
}
.spinner-icon .path {
    stroke: currentColor; stroke-linecap: round; animation: dash 1.5s ease-in-out infinite;
}
@keyframes rotate { 100% { transform: rotate(360deg); } }
@keyframes dash { 0% { stroke-dasharray: 1, 150; stroke-dashoffset: 0; } 50% { stroke-dasharray: 90, 150; stroke-dashoffset: -35; } 100% { stroke-dasharray: 90, 150; stroke-dashoffset: -124; } }
}
</style>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    sidebar.classList.toggle('open');
}

function toggleDesktopSidebar() {
    const shell = document.querySelector('.app-shell');
    if (!shell) return;
    shell.classList.toggle('sidebar-mini');
    const isMini = shell.classList.contains('sidebar-mini');
    localStorage.setItem('sidebar-mini', isMini ? 'true' : 'false');
}

// Initial state
(() => {
    const isMini = localStorage.getItem('sidebar-mini') === 'true';
    if (isMini) {
        window.addEventListener('DOMContentLoaded', () => {
            const shell = document.querySelector('.app-shell');
            if (shell) shell.classList.add('sidebar-mini');
        });
    }
})();

let _globalConfirmCallback = null;
let _globalConfirmLink = null;
let _globalConfirmForm = null;

function openConfirmModal(message, callback, btnLabel, btnColor) {
    const modal = document.getElementById('globalConfirmModal');
    if (!modal) {
        if (confirm(message)) callback();
        return;
    }
    document.getElementById('globalConfirmMessage').textContent = message;
    
    // Customize target switch button
    const actionBtn = document.getElementById('globalConfirmActionBtn');
    actionBtn.textContent = btnLabel || 'Continuar';
    actionBtn.style.backgroundColor = btnColor || '#3b82f6';
    actionBtn.style.borderColor = btnColor || '#3b82f6';
    
    _globalConfirmCallback = callback;
    modal.classList.add('active');
}

function closeGlobalConfirm() {
    document.getElementById('globalConfirmModal').classList.remove('active');
    _globalConfirmCallback = null;
    _globalConfirmLink = null;
    _globalConfirmForm = null;
}

function confirmForm(element, message, customCallback, btnLabel = 'Confirmar', btnColor = '#3b82f6') {
    if (event) event.preventDefault();
    _globalConfirmForm = element.closest('form');
    // If element has data-color or data-label
    if (element.getAttribute('data-btn-label')) btnLabel = element.getAttribute('data-btn-label');
    if (element.getAttribute('data-btn-color')) btnColor = element.getAttribute('data-btn-color');
    if (message.toLowerCase().includes('excluir') || message.toLowerCase().includes('remover') || message.toLowerCase().includes('arquivar')) {
        btnLabel = 'Remover'; btnColor = '#ef4444';
    }

    openConfirmModal(message, function() {
        if (typeof customCallback === 'function') {
            customCallback(_globalConfirmForm);
        } else if (_globalConfirmForm) {
            _globalConfirmForm.submit();
        }
    }, btnLabel, btnColor);
    return false;
}

function confirmLink(element, message, btnLabel = 'Continuar', btnColor = '#22c55e') {
    if (event) event.preventDefault();
    _globalConfirmLink = element.getAttribute('href');
    if (element.getAttribute('data-btn-label')) btnLabel = element.getAttribute('data-btn-label');
    if (element.getAttribute('data-btn-color')) btnColor = element.getAttribute('data-btn-color');
    if (message.toLowerCase().includes('excluir') || message.toLowerCase().includes('remover') || message.toLowerCase().includes('arquivar')) {
        btnLabel = 'Excluir'; btnColor = '#ef4444';
    }

    openConfirmModal(message, function() {
        if (_globalConfirmLink) window.location.href = _globalConfirmLink;
    }, btnLabel, btnColor);
// ── Premium UI Skeletons & Toasts ─────────────────────────────
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `premium-toast toast-${type}`;
    // Simple icon match
    let icon = '';
    if(type === 'success') icon = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
    else if(type === 'error') icon = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;

    toast.innerHTML = `<span class="toast-icon">${icon}</span><span class="toast-msg">${message}</span>`;
    document.body.appendChild(toast);
    
    requestAnimationFrame(() => {
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    });
}

// Intercept All Forms for Disable on Submit
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.classList.contains('no-loader')) return;
    
    // Only capture default forms, not specific AJAX ones (though standard fetch prevents default anyway)
    if (!e.defaultPrevented) {
        const submitBtn = form.querySelector('button[type="submit"]:not(.no-spin)');
        if (submitBtn) {
            if (submitBtn.dataset.loading) {
                e.preventDefault();
                return;
            }
            submitBtn.dataset.loading = "true";
            const originalText = submitBtn.innerHTML;
            submitBtn.dataset.ogText = originalText;
            submitBtn.innerHTML = `<svg class="spinner-icon" viewBox="0 0 50 50"><circle class="path" cx="25" cy="25" r="20" fill="none" stroke-width="5"></circle></svg> Processando...`;
            submitBtn.style.opacity = '0.7';
            submitBtn.style.pointerEvents = 'none';
        }
    }
});

// Auto-parse ?msg= or Error from URL to Native Toast
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.has('msg')) {
        showToast(urlParams.get('msg'), 'success');
        // Clean URL softly
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    if(urlParams.has('error')) {
        showToast(urlParams.get('error'), 'error');
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});
</script>

<div id="globalConfirmModal" class="modal" style="z-index: 9999;">
    <div class="glass modal-card" style="max-width: 400px; text-align: center; padding: 2.5rem 2rem;">
        <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(59, 130, 246, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto;" id="globalConfirmIconDiv">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h3 style="font-size: 1.25rem; font-weight: 900; margin-bottom: 0.5rem; color: var(--text);">Confirmação</h3>
        <p id="globalConfirmMessage" style="color: var(--text-dim); margin-bottom: 2rem; font-size: 0.9rem;">Tem certeza que deseja realizar esta ação?</p>
        <div style="display: flex; gap: 1rem;">
            <button type="button" class="btn btn-primary" id="globalConfirmActionBtn" style="flex: 1;" onclick="if(typeof _globalConfirmCallback === 'function') { _globalConfirmCallback(); } closeGlobalConfirm();">Continuar</button>
            <button type="button" onclick="closeGlobalConfirm()" class="btn btn-ghost" style="flex: 1;">Cancelar</button>
        </div>
    </div>
</div>
