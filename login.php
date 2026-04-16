<?php
require_once 'functions.php';

if (is_authenticated()) {
    header('Location: index.php');
    exit();
}

$msg = $_GET['msg'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $error = 'Sessão inválida ou expirada. Por favor, recarregue a página.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if ($email && $senha) {
        $throttleKey = authThrottleKey('login', $email);
        $throttle = authThrottleState($conn, 'login', $throttleKey, 3);

        if (!$throttle['allowed']) {
            $mins = max(1, (int)ceil(((int)($throttle['seconds_left'] ?? 0)) / 60));
            $error = "Muitas tentativas. Tente novamente em {$mins} minuto(s).";
        } else {
            $stmt = $conn->prepare('SELECT id, nome, senha, paroquia_id, nivel_acesso, perfil_id, perfil_nome, ativo, foto_perfil FROM usuarios WHERE email = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
            } else {
                $u = null;
            }

            if ($u && password_verify($senha, $u['senha'])) {
                if ((int)$u['ativo'] !== 1) {
                    $error = 'Sua conta esta temporariamente inativa.';
                    authThrottleRegisterFailure($conn, 'login', $throttleKey, 3, 5);
                } else {
                    authThrottleReset($conn, 'login', $throttleKey);
                    session_regenerate_id(true);

                    $_SESSION['usuario_id'] = (int)$u['id'];
                    $_SESSION['usuario_nome'] = $u['nome'];
                    $_SESSION['paroquia_id'] = (int)$u['paroquia_id'];
                    $_SESSION['usuario_nivel'] = (int)$u['nivel_acesso'];
                    $_SESSION['usuario_perfil_id'] = (int)($u['perfil_id'] ?? 0);
                    $_SESSION['usuario_perfil_nome'] = (string)($u['perfil_nome'] ?? '');
                    $_SESSION['usuario_foto'] = $u['foto_perfil'] ?? '';
                    $_SESSION['perms'] = loadPermissions($conn, (int)$u['id']);

                    $stmtLogin = $conn->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?');
                    if ($stmtLogin) {
                        $uid = (int)$u['id'];
                        $stmtLogin->bind_param('i', $uid);
                        $stmtLogin->execute();
                    }

                    logAction($conn, 'LOGIN', 'usuarios', (int)$u['id'], 'Autenticacao bem-sucedida');
                    header('Location: index.php');
                    exit();
                }
            } else {
                $throttle = authThrottleRegisterFailure($conn, 'login', $throttleKey, 3, 5);
                if (!$throttle['allowed']) {
                    $error = 'Muitas tentativas. Tente novamente em 5 minutos.';
                } else {
                    $remaining = (int)($throttle['remaining'] ?? 0);
                    $error = 'Credenciais invalidas. Verifique seus dados.' . ($remaining > 0 ? " Restam {$remaining} tentativa(s)." : '');
                }
            }
        }
        } else {
            $error = 'Por favor, preencha todos os campos obrigatorios.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Acessar Portal — PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5">
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">

    <style>
        body { background: #000; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .login-gate { width: 100%; max-width: 1000px; display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: center; }
        .hero-side { padding: 2rem; position: relative; }
        .hero-side h2 { font-size: 3.5rem; font-weight: 900; line-height: 1; letter-spacing: -0.04em; margin-bottom: 2rem; }
        .hero-side p { font-size: 1.1rem; color: var(--text-dim); line-height: 1.6; max-width: 400px; }
        .auth-card { padding: 4rem; border-radius: 32px; position: relative; overflow: hidden; }
        .auth-card::before { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.05), transparent); pointer-events: none; }
        .brand-icon { width: 64px; height: 64px; border-radius: 20px; background: linear-gradient(135deg, var(--primary), var(--accent)); display: flex; align-items: center; justify-content: center; margin-bottom: 3rem; box-shadow: 0 20px 40px rgba(var(--primary-rgb), 0.3); }
        .field label { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.1em; color: var(--text-ghost); margin-bottom: 0.8rem; display: block; }
        .field input { padding: 1.2rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 16px; font-size: 1rem; color: #fff; transition: all 0.3s; width: 100%; }
        .field input:focus { background: rgba(255,255,255,0.06); border-color: var(--primary); box-shadow: 0 0 20px rgba(var(--primary-rgb), 0.2); }
        .field-wrap { position: relative; }
        .toggle-pass {
            position: absolute; right: 0.8rem; top: 50%; transform: translateY(-50%);
            background: transparent; border: 0; color: var(--text-dim); font-size: 0.75rem; font-weight: 800;
            cursor: pointer; padding: 0.25rem 0.5rem;
        }
        .note-limit { margin-top: 0.6rem; color: var(--text-ghost); font-size: 0.78rem; line-height: 1.4; }
        .login-subtitle { color: var(--text-ghost); font-size: 0.95rem; }
        .login-subtitle .mobile-break { display: inline; }
        @media (max-width: 900px) {
            .login-gate { grid-template-columns: 1fr; gap: 2rem; }
            .hero-side { display: none; }
            .auth-card { padding: 3rem 2rem; }
            .login-subtitle .mobile-break { display: block; }
        }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>
    <div class="login-gate">
        <section class="hero-side animate-in">
            <div class="brand-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </div>
            <h2 class="gradient-text">Conectando<br>Sua Comunidade.</h2>
            <p>O ecossistema definitivo para a gestao e articulacao paroquial. Simples, potente e focado na missao.</p>
        </section>

        <main class="auth-card glass animate-in" style="animation-delay: 0.1s;">
            <div style="margin-bottom: 3rem;">
                <h1 style="font-size: 2rem; font-weight: 900; margin-bottom: 0.5rem; letter-spacing: -0.02em;">Acessar Portal</h1>
                <p class="login-subtitle">Bem-vindo de volta! <span class="mobile-break">Identifique-se.</span></p>
            </div>

            <?php if ($error): ?>
                <div style="padding: 1rem 1.5rem; background: rgba(239, 68, 68, 0.1); border-radius: 12px; border-left: 4px solid #ef4444; color: #ef4444; font-size: 0.85rem; font-weight: 700; margin-bottom: 2rem;">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" style="display: grid; gap: 1.8rem;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="field">
                    <label>E-MAIL INSTITUCIONAL</label>
                    <input type="email" name="email" placeholder="nome@exemplo.com" required autofocus autocomplete="username">
                </div>

                <div class="field">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem;">
                        <label style="margin: 0;">SENHA DE ACESSO</label>
                        <a href="recuperar_senha.php" style="font-size: 0.7rem; font-weight: 800; color: var(--primary); text-decoration: none;">ESQUECI MEU ACESSO</a>
                    </div>
                    <div class="field-wrap">
                        <input type="password" name="senha" id="loginSenha" placeholder="••••••••" required autocomplete="current-password">
                        <button type="button" class="toggle-pass" data-target="loginSenha">Mostrar</button>
                    </div>
                    <div class="note-limit">Bloqueio automatico apos 3 tentativas incorretas por 5 minutos.</div>
                </div>

                <button type="submit" class="btn btn-primary shimmer" style="width: 100%; padding: 1.2rem; font-size: 1rem; border-radius: 18px;">
                    Entrar no Sistema
                </button>
            </form>

            <div style="margin-top: 3rem; text-align: center; border-top: 1px solid var(--border); padding-top: 2rem;">
                <a href="index.php" style="color: var(--text-ghost); text-decoration: none; font-size: 0.8rem; font-weight: 800; letter-spacing: 0.05em;">← VOLTAR PARA O SITE</a>
            </div>
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
