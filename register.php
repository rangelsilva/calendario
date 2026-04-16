<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Admin Console / User Registration (v2.0)
 * Onboarding · RBAC Setup · Premium UI
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();

requirePerm('admin_usuarios');

$pid = current_paroquia_id();
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$my_level = (int)($_SESSION['usuario_nivel'] ?? 99);
$is_master = has_level(0) || (int)($_SESSION['usuario_id'] ?? 0) === 1;
$max_access_level = 7;
$allowed_access_levels = selectable_access_levels_for_user($my_level, $is_master, $max_access_level);
$my_perfil_id = current_user_perfil_id($conn);
$perfis_options = list_perfis_for_user($conn, $my_perfil_id, $is_master);
$default_perfil_id = pick_default_perfil_id($perfis_options, 9);
$selected_nivel_acesso = isset($_POST['nivel_acesso']) ? (int)$_POST['nivel_acesso'] : $max_access_level;
$selected_perfil_id = isset($_POST['perfil_id']) ? (int)$_POST['perfil_id'] : $default_perfil_id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    ensureUserPhotoColumn($conn);
    ensureUserPermissionsMaterialized($conn);
    ensurePermissionColumns($conn);
    $data = sanitize_post($_POST);
    $senha = (string)($data['senha'] ?? '');
    $confirmarSenha = (string)($data['confirmar_senha'] ?? '');
    $palavraChave = trim((string)($data['palavra_chave'] ?? ''));
    
    if (empty($data['nome']) || empty($data['email']) || $senha === '' || $confirmarSenha === '' || $palavraChave === '') {
        $error = 'Por favor, preencha nome, e-mail, senha, confirmacao e palavra-chave.';
    } elseif (strlen($senha) < 6) {
        $error = 'A senha precisa ter no minimo 6 caracteres.';
    } elseif ($senha !== $confirmarSenha) {
        $error = 'As senhas nao coincidem.';
    } else {
        $hash = password_hash($senha, PASSWORD_DEFAULT);

        $nivel_acesso_raw = trim((string)($data['nivel_acesso'] ?? ''));
        $nivel_acesso = ($nivel_acesso_raw === '') ? $max_access_level : (int)$nivel_acesso_raw;
        if ($nivel_acesso < 0 || $nivel_acesso > $max_access_level) {
            $nivel_acesso = $max_access_level;
        }
        if (!$is_master && $nivel_acesso < $my_level) {
            $error = 'Nivel de acesso invalido para o seu usuario.';
        }

        $allowedPerfilIds = [];
        foreach ($perfis_options as $p) {
            $allowedPerfilIds[(int)$p['id']] = $p;
        }

        $perfil_id_raw = trim((string)($data['perfil_id'] ?? ''));
        if ($perfil_id_raw === '') {
            $perfil_id = pick_default_perfil_id($perfis_options, 9);
        } else {
            $perfil_id = (int)$perfil_id_raw;
            if (!isset($allowedPerfilIds[$perfil_id])) {
                $error = 'Perfil selecionado invalido para o seu nivel.';
            }
        }

        $target_pid = (int)($data['paroquia_id'] ?: $pid);
        $dt_nasc = !empty($data['data_nascimento']) ? $data['data_nascimento'] : null;

        if ($error === '') {
        $perfilNome = '';
        $perfilPerms = [
            'perm_ver_calendario' => 0,
            'perm_criar_eventos' => 0,
            'perm_editar_eventos' => 0,
            'perm_excluir_eventos' => 0,
            'perm_ver_restritos' => 0,
            'perm_cadastrar_usuario' => 0,
            'perm_admin_usuarios' => 0,
            'perm_admin_sistema' => 0,
            'perm_ver_logs' => 0,
        ];
        if (isset($allowedPerfilIds[$perfil_id])) {
            $perfilNome = (string)($allowedPerfilIds[$perfil_id]['nome'] ?? '');
        }

        $stPf = $conn->prepare("SELECT perm_ver_calendario, perm_criar_eventos, perm_editar_eventos, perm_excluir_eventos, perm_ver_restritos, perm_cadastrar_usuario, perm_admin_usuarios, perm_admin_sistema, perm_ver_logs FROM perfis WHERE id = ? LIMIT 1");
        if ($stPf) {
            $stPf->bind_param('i', $perfil_id);
            $stPf->execute();
            $pfRow = $stPf->get_result()->fetch_assoc();
            if ($pfRow) {
                foreach ($perfilPerms as $k => $_) {
                    $perfilPerms[$k] = (int)($pfRow[$k] ?? 0);
                }
            }
        }
        
        $sql = "INSERT INTO usuarios (
                    nome, email, senha, sexo, telefone, data_nascimento, palavra_chave, foto_perfil,
                    paroquia_id, perfil_id, perfil_nome, ativo, nivel_acesso,
                    perm_ver_calendario, perm_criar_eventos, perm_editar_eventos, perm_excluir_eventos,
                    perm_ver_restritos, perm_cadastrar_usuario, perm_admin_usuarios, perm_admin_sistema, perm_ver_logs
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            'sssssssiisiiiiiiiiii',
            $data['nome'], $data['email'], $hash, $data['sexo'], $data['telefone'], $dt_nasc, $palavraChave,
            $target_pid, $perfil_id, $perfilNome,
            $nivel_acesso,
            $perfilPerms['perm_ver_calendario'],
            $perfilPerms['perm_criar_eventos'],
            $perfilPerms['perm_editar_eventos'],
            $perfilPerms['perm_excluir_eventos'],
            $perfilPerms['perm_ver_restritos'],
            $perfilPerms['perm_cadastrar_usuario'],
            $perfilPerms['perm_admin_usuarios'],
            $perfilPerms['perm_admin_sistema'],
            $perfilPerms['perm_ver_logs']
        );
        
        if ($stmt->execute()) {
            $newUserId = (int)$conn->insert_id;
            $savedPhoto = '';

            if (
                isset($_FILES['foto_perfil']) &&
                (int)($_FILES['foto_perfil']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ) {
                $tmpPath = (string)$_FILES['foto_perfil']['tmp_name'];
                if (is_uploaded_file($tmpPath)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo ? (string)finfo_file($finfo, $tmpPath) : '';
                    if ($finfo) {
                        finfo_close($finfo);
                    }
                    if (str_starts_with($mime, 'image/')) {
                        $uploadDir = __DIR__ . '/img/usuarios';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0777, true);
                        }
                        if (is_dir($uploadDir) && is_writable($uploadDir)) {
                            $ext = strtolower(pathinfo((string)($_FILES['foto_perfil']['name'] ?? ''), PATHINFO_EXTENSION));
                            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                                $ext = preg_replace('/[^a-z0-9]+/i', '', substr($mime, 6));
                                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                                    $ext = 'png';
                                }
                            }
                            $fileName = 'user_' . $newUserId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $targetPath = $uploadDir . '/' . $fileName;
                            if (move_uploaded_file($tmpPath, $targetPath)) {
                                $savedPhoto = 'img/usuarios/' . $fileName;
                                $up = $conn->prepare("UPDATE usuarios SET foto_perfil = ? WHERE id = ?");
                                $up->bind_param('si', $savedPhoto, $newUserId);
                                $up->execute();
                            }
                        }
                    }
                }
            }
            
            ensureDefaultVisitorGroup($conn, $target_pid);

            logAction($conn, 'REGISTRAR_USUARIO', 'usuarios', $newUserId, ['novo' => $data, 'foto_perfil' => $savedPhoto]);
            header("Location: register.php?msg=Usuário cadastrado com sucesso!");
            exit();
        } else {
            $error = 'Erro ao cadastrar. Talvez este e-mail já esteja em uso.';
        }
        }
    }
}

// Fetch Parishes for the dropdown
if (has_level(0) || ($_SESSION['usuario_id'] ?? 0) === 1) {
    $parishes = db_query($conn, "SELECT id, nome FROM paroquias ORDER BY nome");
} else {
    $parishes = db_query($conn, "SELECT id, nome FROM paroquias WHERE id = ? ORDER BY nome", [$pid]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Cadastrar Usuário — PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; width: 100%; overflow-x: hidden; }
        .main-content { flex: 1; min-width: 0; width: 100%; margin-left: var(--sidebar-w); padding: 3rem; display: flex; flex-direction: column; align-items: center; justify-content: center; transition: margin 0.3s; }
        
        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .form-grid { grid-template-columns: 1fr; }
            .full-row { grid-column: span 1; }
            .form-container { padding: 2.5rem; width: 100%; max-width: 100%; }
        }
        
        .form-container { width: 100%; max-width: 700px; padding: 4rem; border-radius: 32px; }
        .form-header { margin-bottom: 3rem; text-align: center; }
        .form-header h1 { font-size: 2.2rem; font-weight: 900; margin-bottom: 0.5rem; letter-spacing: -0.03em; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .full-row { grid-column: span 2; }
        .field-wrap { position: relative; }
        .toggle-pass {
            position: absolute;
            right: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: 0;
            color: var(--text-dim);
            font-size: 0.75rem;
            font-weight: 800;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .full-row { grid-column: span 1; }
            .main-content { padding: 1.5rem; }
            .form-container { padding: 2rem; }
        }
        .form-actions { display: flex; gap: 1rem; margin-top: 1rem; }
        @media (max-width: 768px) {
            .form-actions { flex-direction: column; }
            .form-actions .btn,
            .form-actions a { width: 100%; }
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

            <section class="glass form-container animate-in">
                <header class="form-header">
                    <p style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.15em; color: var(--text-ghost); margin-bottom: 0.5rem;">CONTROLE DE ACESSO</p>
                    <h1 class="gradient-text">Novo Membro da Equipe</h1>
                    <p style="color: var(--text-dim); font-size: 0.95rem;">Configure as credenciais e o nível de acesso para o novo usuário.</p>
                </header>

                <form method="POST" enctype="multipart/form-data" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="form-group full-row">
                        <label>NOME COMPLETO</label>
                        <input type="text" name="nome" placeholder="Ex: João da Silva" required>
                    </div>

                    <div class="form-group">
                        <label>E-MAIL DE ACESSO</label>
                        <input type="email" name="email" placeholder="nome@paroquia.com" required>
                    </div>

                    <div class="form-group">
                        <label>TELEFONE / WHATSAPP</label>
                        <input type="text" name="telefone" placeholder="(00) 00000-0000">
                    </div>

                    <div class="form-group">
                        <label>DATA DE ANIVERSÁRIO</label>
                        <input type="date" name="data_nascimento">
                    </div>

                    <div class="form-group">
                        <label>GÊNERO</label>
                        <select name="sexo">
                            <option value="M">Masculino</option>
                            <option value="F">Feminino</option>
                        </select>
                    </div>

                    <div class="form-group full-row">
                        <label>FOTO DE PERFIL (OPCIONAL)</label>
                        <input type="file" name="foto_perfil" accept="image/*">
                    </div>

                    <div class="form-group">
                        <label>NIVEL DE ACESSO</label>
                        <select name="nivel_acesso">
                            <?php foreach ($allowed_access_levels as $lvl): ?>
                                <option value="<?= (int)$lvl ?>" <?= ((int)$lvl === (int)$selected_nivel_acesso) ? 'selected' : '' ?>>
                                    <?= h(getAccessLabelV2((int)$lvl)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>PERFIL</label>
                        <select name="perfil_id">
                            <?php foreach ($perfis_options as $pf): ?>
                                <option value="<?= (int)$pf['id'] ?>" <?= ((int)$pf['id'] === (int)$selected_perfil_id) ? 'selected' : '' ?>>
                                    <?= h($pf['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-row">
                        <label>PARÓQUIA DESIGNADA</label>
                        <select name="paroquia_id">
                            <?php while($p = $parishes->fetch_assoc()): ?>
                                <option value="<?= $p['id'] ?>" <?= $p['id'] == $pid ? 'selected' : '' ?>><?= h($p['nome']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group full-row" style="margin-bottom: 1rem;">
                        <label>SENHA TEMPORARIA</label>
                        <div class="field-wrap">
                            <input type="password" name="senha" id="regSenha" placeholder="••••••••" required autocomplete="new-password">
                            <button type="button" class="toggle-pass" data-target="regSenha">Mostrar</button>
                        </div>
                    </div>

                    <div class="form-group full-row">
                        <label>CONFIRMAR SENHA TEMPORARIA</label>
                        <div class="field-wrap">
                            <input type="password" name="confirmar_senha" id="regConfirmarSenha" placeholder="Repita a senha" required autocomplete="new-password">
                            <button type="button" class="toggle-pass" data-target="regConfirmarSenha">Mostrar</button>
                        </div>
                    </div>

                    <div class="form-group full-row">
                        <label>PALAVRA-CHAVE (RECUPERACAO)</label>
                        <div class="field-wrap">
                            <input type="password" name="palavra_chave" id="regPalavraChave" placeholder="Defina uma palavra-chave de recuperacao" required autocomplete="new-password">
                            <button type="button" class="toggle-pass" data-target="regPalavraChave">Mostrar</button>
                        </div>
                    </div>

                    <div class="full-row form-actions">
                        <button type="submit" class="btn btn-primary shimmer" style="flex: 2;">Finalizar Cadastro</button>
                        <a href="index.php" class="btn btn-ghost" style="flex: 1;">Cancelar</a>
                    </div>
                </form>
            </section>
        </main>
    </div>
    <script>
    document.querySelectorAll('.toggle-pass').forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.target);
            if (!target) return;
            const hidden = target.type === 'password';
            target.type = hidden ? 'text' : 'password';
            btn.textContent = hidden ? 'Ocultar' : 'Mostrar';
        });
    });
    </script>
</body>
</html>
