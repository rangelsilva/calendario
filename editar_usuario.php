<?php
require_once 'functions.php';
requireLogin();
requirePerm('admin_usuarios');
ensureUserPhotoColumn($conn);
ensurePermissionColumns($conn);
ensureUserPermissionsMaterialized($conn);

$id = (int)($_GET['id'] ?? 0);
$is_master = has_level(0);
$my_user_id = (int)($_SESSION['usuario_id'] ?? 0);
$my_level = (int)($_SESSION['usuario_nivel'] ?? 99);
$can_manage_users_by_level = $is_master || $my_level <= 3;
$my_pid = (int)($_SESSION['paroquia_id'] ?? 0);
$is_self = ($id === $my_user_id);
$my_group_ids = getUserGroups($conn, $my_user_id, $my_pid);
$todos_group_id = getDefaultTodosGroupId($conn, $my_pid);
if ($todos_group_id > 0) {
    $my_group_ids = array_values(array_filter(
        $my_group_ids,
        static fn(int $gid): bool => $gid !== $todos_group_id
    ));
}

$stmt = $conn->prepare('SELECT * FROM usuarios WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    header('Location: usuarios.php?error=notfound');
    exit();
}

if (!$is_master && !$is_self && ((int)$user['nivel_acesso'] === 0 || (int)$user['id'] === 1)) {
    header('Location: usuarios.php?error=unauthorized');
    exit();
}

if (
    !$is_master &&
    (int)$user['id'] !== $my_user_id &&
    (
        (int)$user['paroquia_id'] !== $my_pid ||
        (int)$user['nivel_acesso'] < $my_level
    )
) {
    header('Location: usuarios.php?error=unauthorized');
    exit();
}

if (
    !$is_master &&
    !$is_self
) {
    if (empty($my_group_ids)) {
        header('Location: usuarios.php?error=unauthorized');
        exit();
    }

    $target_group_ids = getUserGroups($conn, (int)$user['id'], $my_pid);
    if ($todos_group_id > 0) {
        $target_group_ids = array_values(array_filter(
            $target_group_ids,
            static fn(int $gid): bool => $gid !== $todos_group_id
        ));
    }
    $has_common_group = !empty(array_intersect($my_group_ids, $target_group_ids));
    if (!$has_common_group) {
        header('Location: usuarios.php?error=unauthorized');
        exit();
    }
}

$can_manage_target = $is_master || $is_self || (
    !$is_master &&
    $can_manage_users_by_level &&
    (int)$user['id'] !== $my_user_id &&
    (int)$user['paroquia_id'] === $my_pid &&
    (int)$user['nivel_acesso'] > $my_level
);
$is_same_level_peer = !$is_master && !$is_self && (int)$user['nivel_acesso'] === $my_level;

$can_edit_photo_for_target = $is_master || $is_self || $can_manage_target;
$can_edit_email_for_target = $is_self || $can_manage_target;
$can_edit_parish_for_target = $is_master;
$can_edit_password_for_target = $is_self; // apenas o próprio usuário pode redefinir sua senha
$can_edit_keyword_for_target = $can_manage_target || $is_self;
$can_delete_target = !$is_self && $can_manage_target;

$visiblePermissions = [
    'perm_ver_calendario' => $is_master || can('ver_calendario'),
    'perm_criar_eventos' => $is_master || can('criar_eventos'),
    'perm_editar_eventos' => $is_master || can('editar_eventos'),
    'perm_excluir_eventos' => $is_master || can('excluir_eventos'),
    'perm_ver_restritos' => $is_master || can('ver_restritos'),
    'perm_cadastrar_usuario' => $is_master || can('cadastrar_usuario'),
    'perm_admin_usuarios' => $is_master || can('admin_usuarios'),
    'perm_admin_sistema' => $is_master || can('admin_sistema'),
    'perm_ver_logs' => $is_master || can('ver_logs'),
    'perm_gerenciar_catalogo' => $is_master || can('gerenciar_catalogo'),
    'perm_gerenciar_grupos' => $is_master || can('gerenciar_grupos'),
];

$permissionLabels = [
    'perm_ver_calendario' => 'Ver Calendario',
    'perm_criar_eventos' => 'Criar Eventos',
    'perm_editar_eventos' => 'Editar Eventos',
    'perm_excluir_eventos' => 'Excluir Eventos',
    'perm_ver_restritos' => 'Ver Restritos',
    'perm_cadastrar_usuario' => 'Cadastrar Usuario',
    'perm_admin_usuarios' => 'Gerenciar Usuarios',
    'perm_admin_sistema' => 'Setup de Sistema (Paroquias)',
    'perm_ver_logs' => 'Acesso a Logs',
    'perm_gerenciar_catalogo' => 'Gerenciar Catalogo',
    'perm_gerenciar_grupos' => 'Gerenciar Grupos de Trabalho',
];

$max_access_level = 7;
$allowed_access_levels = selectable_access_levels_for_user($my_level, $is_master, $max_access_level);
$my_perfil_id = current_user_perfil_id($conn);
$perfis_options = list_perfis_for_user($conn, $my_perfil_id, $is_master);
$allowedPerfilMap = [];
foreach ($perfis_options as $p) {
    $allowedPerfilMap[(int)$p['id']] = $p;
}
$selected_nivel_acesso = isset($_POST['nivel_acesso']) ? (int)$_POST['nivel_acesso'] : (int)($user['nivel_acesso'] ?? $max_access_level);
$selected_perfil_id = isset($_POST['perfil_id']) ? (int)$_POST['perfil_id'] : (int)($user['perfil_id'] ?? pick_default_perfil_id($perfis_options, 9));

$msg = $_GET['msg'] ?? '';
$error = '';
$deleteConfirmPending = ((int)($_GET['delete_confirm'] ?? 0) === 1 && (int)($_SESSION['pending_delete_user_id'] ?? 0) === $id);

// --- CONTEXTO DE GRUPOS (Mover para cima para uso no POST) ---
$allGroupsRaw = getWorkingGroups($conn, (int)($user['paroquia_id'] ?? 0), true);
$myGroups = getUserGroups($conn, $id);

$admin_uid_ctx = (int)($_SESSION['usuario_id'] ?? 0);
$is_master_global_ctx = has_level(0) || $admin_uid_ctx === 1;
$adminGroups_ctx = getUserGroups($conn, $admin_uid_ctx);

$allGroups_ctx = [];
$hiddenGroupsCount_ctx = 0;
foreach ($allGroupsRaw as $g) {
    if ($is_master_global_ctx || in_array((int)$g['id'], $adminGroups_ctx, true)) {
        $allGroups_ctx[] = $g;
    } elseif (in_array((int)$g['id'], $myGroups, true)) {
        $hiddenGroupsCount_ctx++;
    }
}
// -----------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/actions/action_editar_usuario.php';
}

// Fetch Parishes for the dropdown
$pid = current_paroquia_id();
if (has_level(0) || ($_SESSION['usuario_id'] ?? 0) === 1) {
    $parishes = db_query($conn, "SELECT id, nome FROM paroquias ORDER BY nome");
} else {
    $parishes = db_query($conn, "SELECT id, nome FROM paroquias WHERE id = ? ORDER BY nome", [$pid]);
}

// Filter $allGroups based on what the admin can see/manage
$allGroups = $allGroups_ctx;
$hiddenGroupsCount = $hiddenGroupsCount_ctx;
$is_master_global = $is_master_global_ctx;
$adminGroups = $adminGroups_ctx;
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Editar Usuario - PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5">
    <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        select option, select optgroup { background: #ffffff !important; color: #111827 !important; }
        select option:checked, select option:hover { background: #e5ecff !important; color: #111827 !important; }
        
        /* Modern Tab System */
        .tab-nav { display: flex; gap: 1rem; margin-bottom: 2rem; border-bottom: 1px solid var(--border); overflow-x: auto; scrollbar-width: thin; }
        .tab-btn { padding: 0.8rem 1.2rem; background: transparent; border: none; border-bottom: 3px solid transparent; color: var(--text-dim); font-weight: 800; cursor: pointer; transition: all 0.2s; font-size: 0.8rem; letter-spacing: 0.05em; text-transform: uppercase; white-space: nowrap; }
        .tab-btn:hover { color: var(--text); background: rgba(255,255,255,0.02); border-radius: 8px 8px 0 0; }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-pane { display: none; animation: fadeIn 0.3s ease; }
        .tab-pane.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .perm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: 16px; border: 1px dashed var(--border); }
        .perm-item { display: flex; align-items: center; gap: 0.8rem; font-size: 0.85rem; color: var(--text); font-weight: 600; cursor: pointer; }
        .perm-item input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; }
        
        .field-wrap { position: relative; }
        .toggle-pass { position: absolute; right: 0.8rem; top: 50%; transform: translateY(-50%); background: transparent; border: 0; color: var(--text-dim); font-size: 0.75rem; font-weight: 800; cursor: pointer; padding: 0.25rem 0.5rem; }
        
        .danger-box { background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.18); border-radius: 18px; padding: 1.25rem; margin-bottom: 1.5rem; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>
    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content" style="align-items: center; justify-content: flex-start; overflow-y: auto;">
            <section class="glass animate-in" style="width: 100%; max-width: 850px; padding: 3rem; border-radius: 32px; margin: 2rem auto;">
                <header style="text-align: center; margin-bottom: 2.5rem;">
                    <p style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.15em; color: var(--text-ghost); margin-bottom: 0.5rem;">GESTAO IDENTIDADE</p>
                    <h1 class="gradient-text">Editar Usuario</h1>
                    <p style="color: var(--text-dim); font-size: 0.95rem;">Manutenção de perfil para <b><?= h($user['nome']) ?></b></p>
                </header>

                <?php if ($error): ?> <?= alert('error', h($error)) ?> <?php endif; ?>
                <?php if ($msg): ?> <?= alert('success', h($msg)) ?> <?php endif; ?>

                <?php if ($deleteConfirmPending): ?>
                    <div class="danger-box">
                        <h3 style="margin-bottom: 0.6rem; color: #fca5a5;">Aviso Critico: Exclusao Permanente</h3>
                        <p style="font-size: 0.9rem; color: var(--text-dim); margin-bottom: 1rem;">Digite exatamente <b>EXCLUIR USUARIO</b> para aniquilar este registro do banco de dados.</p>
                        <form method="POST" style="display: grid; gap: 1rem;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <div class="form-group" style="margin-bottom: 0;">
                                <input type="text" name="delete_confirm_text" placeholder="EXCLUIR USUARIO" autocomplete="off" required>
                            </div>
                            <button type="submit" name="final_delete" value="1" class="btn shimmer" style="background: linear-gradient(135deg, #dc2626, #ef4444); border:none; color:#fff;">Confirmar Exclusao Definitiva</button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Tab Navigation (Heuristica 8: Estetica Minimalista) -->
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="switchTab('pane-perfil', this)">👤 Identidade</button>
                    <?php if (!$is_same_level_peer && !$is_self && $can_manage_users_by_level && ($can_manage_target || array_filter($visiblePermissions))): ?>
                        <button class="tab-btn" onclick="switchTab('pane-acessos', this)">🛡️ Acessos e Grupos</button>
                    <?php elseif($is_self): ?>
                         <button class="tab-btn" onclick="switchTab('pane-meusgrupos', this)">👥 Meus Grupos</button>
                    <?php endif; ?>
                    <?php if ($can_edit_password_for_target || $can_edit_keyword_for_target): ?>
                        <button class="tab-btn" onclick="switchTab('pane-seguranca', this)">🔐 Seguranca</button>
                    <?php endif; ?>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <!-- TAB 1: IDENTIDADE -->
                    <div id="pane-perfil" class="tab-pane active">
                        <?php if (!$is_same_level_peer): ?>
                            <div class="grid-2">
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>NOME COMPLETO</label>
                                    <input type="text" name="nome" value="<?= h($user['nome']) ?>" minlength="3" required>
                                </div>
                                <?php if ($can_edit_email_for_target): ?>
                                <div class="form-group">
                                    <label>E-MAIL INSTITUCIONAL</label>
                                    <!-- HTML5 Constraint Validation (Heuristica 5: Prevenção de erro) -->
                                    <input type="email" name="email" value="<?= h($user['email']) ?>" required>
                                </div>
                                <?php endif; ?>
                                <?php if ($can_edit_parish_for_target): ?>
                                <div class="form-group">
                                    <label>PAROQUIA (DOMINIO)</label>
                                    <select name="paroquia_id">
                                        <option value="0">Contexto Global (SYSADMIN)</option>
                                        <?php while ($p = $parishes->fetch_assoc()): ?>
                                            <option value="<?= $p['id'] ?>" <?= $p['id'] == $user['paroquia_id'] ? 'selected' : '' ?>><?= h($p['nome']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label>TELEFONE SECUNDARIO</label>
                                    <input type="tel" name="telefone" inputmode="tel" value="<?= h($user['telefone']) ?>">
                                </div>
                                <div class="form-group">
                                    <label>DATA DE NASCIMENTO</label>
                                    <input type="date" name="data_nascimento" value="<?= h($user['data_nascimento']) ?>">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>GENERO BIOLOGICO</label>
                                    <select name="sexo">
                                        <option value="M" <?= $user['sexo'] == 'M' || $user['sexo'] == 'Masculino' ? 'selected' : '' ?>>Masculino</option>
                                        <option value="F" <?= $user['sexo'] == 'F' || $user['sexo'] == 'Feminino' ? 'selected' : '' ?>>Feminino</option>
                                    </select>
                                </div>

                                <!-- Foto Profile Block -->
                                <?php if ($can_edit_photo_for_target): ?>
                                    <div class="form-group" style="grid-column: span 2; margin-top:1rem; border-top:1px solid var(--border); padding-top:1.5rem;">
                                        <label>FOTO DE PERFIL (OPCIONAL)</label>
                                        <?php if (!empty($user['foto_perfil']) && file_exists(__DIR__ . '/' . $user['foto_perfil'])): ?>
                                        <div style="display:flex; align-items:center; gap:1.5rem; margin-bottom: 1rem;">
                                            <img src="<?= h($user['foto_perfil']) ?>?v=<?= time() ?>" alt="Avatar" style="width:64px; height:64px; border-radius:16px; object-fit:cover; border:2px solid var(--border);">
                                            <label class="perm-item" style="color: #ef4444;">
                                                <input type="checkbox" name="remover_foto" value="1">
                                                Apagar foto atual no salvamento
                                            </label>
                                        </div>
                                        <?php endif; ?>
                                        <input type="file" name="foto_perfil" accept="image/jpeg, image/png, image/webp" title="Arraste ou clique para enviar foto">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="danger-box">Bloqueado Lógico: Visualização de pares de mesmo nível administrativo restrita na camada Visual.</div>
                        <?php endif; ?>
                    </div> <!-- /TAB 1 -->

                    <!-- TAB 2: ACESSOS E GRUPOS -->
                    <?php if (!$is_same_level_peer && !$is_self && $can_manage_users_by_level && ($can_manage_target || array_filter($visiblePermissions))): ?>
                    <div id="pane-acessos" class="tab-pane">
                        <?php if ($can_manage_target): ?>
                        <div class="grid-2" style="margin-bottom: 2rem;">
                            <div class="form-group">
                                <label>CLEARANCE (NIVEL)</label>
                                <select name="nivel_acesso">
                                    <?php foreach ($allowed_access_levels as $lvl): ?>
                                        <option value="<?= (int)$lvl ?>" <?= ((int)$lvl === (int)$selected_nivel_acesso) ? 'selected' : '' ?>><?= h(getAccessLabelV2((int)$lvl)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>MOLDURA DE PERFIL</label>
                                <select name="perfil_id">
                                    <?php foreach ($perfis_options as $pf): ?>
                                        <option value="<?= (int)$pf['id'] ?>" <?= ((int)$pf['id'] === (int)$selected_perfil_id) ? 'selected' : '' ?>><?= h($pf['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (array_filter($visiblePermissions)): ?>
                        <div class="form-group">
                            <label>DIREITOS DE ACESSO INDIVIDUAIS</label>
                            <div class="perm-grid">
                                <?php foreach ($permissionLabels as $field => $label): ?>
                                    <?php if (!empty($visiblePermissions[$field])): ?>
                                        <label class="perm-item">
                                            <input type="checkbox" name="<?= h($field) ?>" <?= !empty($user[$field]) ? 'checked' : '' ?>>
                                            <span><?= h($label) ?></span>
                                        </label>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-group" style="margin-top:2rem;">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <label>ATRIBUICAO A DEPARTAMENTOS</label>
                                <?php if ($hiddenGroupsCount_ctx > 0): ?>
                                    <span style="font-size:0.7rem; background:#f59e0b20; color:#f59e0b; padding:2px 8px; border-radius:12px;">+ <?= $hiddenGroupsCount_ctx ?> ocultos.</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($can_manage_target): ?>
                                <div class="perm-grid">
                                    <?php foreach ($allGroups as $g): ?>
                                        <label class="perm-item" style="border-left:4px solid <?= $g['cor'] ?>; padding-left:1rem; background:rgba(0,0,0,0.1); border-radius:8px;">
                                            <input type="checkbox" name="grupos_trabalho[]" value="<?= $g['id'] ?>" <?= in_array((int)$g['id'], $myGroups, true) ? 'checked' : '' ?>>
                                            <span style="opacity: <?= $g['visivel'] ? '1' : '0.5' ?>;"><?= h($g['nome']) ?> <?= !$g['visivel'] ? '(Oculto)' : '' ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- TAB MEUS GRUPOS (ReadOnly Auto) -->
                    <?php if ($is_self): ?>
                    <div id="pane-meusgrupos" class="tab-pane">
                        <input type="hidden" name="perfil_id" value="<?= (int)$user['perfil_id'] ?>">
                        <div class="form-group">
                            <label>CHANCELA DE GRUPOS ATUAIS</label>
                            <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:1rem;">
                                <?php 
                                $gf = false;
                                foreach ($allGroups as $g): 
                                    if (in_array((int)$g['id'], $myGroups, true)): $gf = true;
                                ?>
                                    <span style="padding:0.6rem 1.2rem; border-radius:99px; background:<?= $g['cor'] ?>15; border:1px solid <?= $g['cor'] ?>44; color:<?= $g['cor'] ?>; font-weight:800; font-size:0.85rem; box-shadow:0 4px 12px <?= $g['cor'] ?>11;"><?= h($g['nome']) ?></span>
                                <?php endif; endforeach; 
                                if (!$gf) echo '<span style="font-size:0.9rem; color:var(--text-ghost)">Sem vínculo operante em comitês.</span>';
                                ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>


                    <!-- TAB 3: SEGURANCA -->
                    <?php if ($can_edit_password_for_target || $can_edit_keyword_for_target): ?>
                    <div id="pane-seguranca" class="tab-pane">
                        <div class="grid-2">
                            <?php if ($can_edit_password_for_target): ?>
                                <div class="form-group">
                                    <label>COAGIR NOVA SENHA</label>
                                    <div class="field-wrap">
                                        <input type="password" name="nova_senha" id="ns" autocomplete="new-password" placeholder="Mínimo 6 chars" minlength="6">
                                        <button type="button" class="toggle-pass" onclick="toggleP('ns', this)">Ver</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>CONFERENCIA COLETIVA</label>
                                    <div class="field-wrap">
                                        <input type="password" name="confirmar_nova_senha" id="cns" autocomplete="new-password" minlength="6">
                                        <button type="button" class="toggle-pass" onclick="toggleP('cns', this)">Ver</button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($can_edit_keyword_for_target): ?>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>CIFRA DE EMERGENCIA (PALAVRA-CHAVE)</label>
                                    <div class="field-wrap">
                                        <input type="password" name="palavra_chave" id="pc" autocomplete="new-password" placeholder="Utilizada na recuperação fria de acessos...">
                                        <button type="button" class="toggle-pass" onclick="toggleP('pc', this)">Ver</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Action Bar Fixo -->
                    <div style="display:flex; justify-content: space-between; align-items:center; margin-top: 3rem; padding-top: 2rem; border-top: 1px solid var(--border);">
                         <a href="usuarios.php" class="btn btn-ghost">← Retornar à Base</a>
                         
                         <div style="display:flex; gap:1rem;">
                            <?php if ($can_delete_target && !$is_same_level_peer): ?>
                                <button type="submit" name="delete_request" value="1" class="btn btn-ghost" style="color:#ef4444; border-color:rgba(239,68,68,0.3);">Desativar Usuário</button>
                            <?php endif; ?>
                            
                            <?php if (!$is_same_level_peer): ?>
                                <!-- Heuristica 1 (Visibilidade com shimmer css nativo) -->
                                <button type="submit" class="btn btn-primary shimmer">Sincronizar Banco</button>
                            <?php endif; ?>
                         </div>
                    </div>

                </form>
            </section>
        </main>
    </div>

    <script>
        function switchTab(targetId, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(targetId).classList.add('active');
        }

        function toggleP(id, btn) {
            const input = document.getElementById(id);
            if(input.type === 'password') { input.type = 'text'; btn.textContent = 'Oculto'; }
            else { input.type = 'password'; btn.textContent = 'Ver'; }
        }
    </script>
</body>
</html>
