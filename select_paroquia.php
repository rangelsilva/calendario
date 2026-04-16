<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Parish Context Switcher (v2.0)
 * Context Control · Master Tools · Premium UI
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();

if (!userCanSwitchParish()) {
    header('Location: index.php?error=unauthorized');
    exit();
}

// 1. Handle Selection
if (isset($_GET['id'])) {
    $new_id = (int)$_GET['id'];
    
    // Validate if parish exists
    $v_stmt = $conn->prepare("SELECT id FROM paroquias WHERE id = ?");
    $v_stmt->bind_param('i', $new_id);
    $v_stmt->execute();
    if ($v_stmt->get_result()->num_rows > 0) {
        $_SESSION['paroquia_id'] = $new_id;
        
        // Sync with DB
        $stmt = $conn->prepare('UPDATE usuarios SET paroquia_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $new_id, $_SESSION['usuario_id']);
        $stmt->execute();
        
        logAction($conn, 'MUDAR_PAROQUIA_CONTEXTO', 'paroquias', $new_id, 'Contexto de administração alterado');
        header('Location: index.php?msg=Contexto alterado com sucesso');
        exit();
    }
}

$res = $conn->query('SELECT id, nome FROM paroquias ORDER BY nome');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Selecionar Unidade — PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        body { background: #000; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .selection-panel { width: 100%; max-width: 600px; padding: 4rem; border-radius: 32px; }
        
        .parish-list { display: flex; flex-direction: column; gap: 0.8rem; margin-top: 2.5rem; }
        .parish-item { 
            display: flex; align-items: center; justify-content: space-between; 
            padding: 1.2rem 2rem; border-radius: 20px; text-decoration: none; 
            background: rgba(255,255,255,0.03); border: 1px solid var(--border);
            transition: all 0.3s;
        }
        .parish-item:hover { background: var(--panel-hi); border-color: var(--primary); transform: translateX(10px); }
        .parish-item.active { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.1); }
        
        .parish-name { font-weight: 700; color: var(--text); }
        .parish-arrow { color: var(--text-ghost); transition: transform 0.3s; }
        .parish-item:hover .parish-arrow { transform: translateX(5px); color: var(--primary); }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>

    <main class="glass selection-panel animate-in">
        <header style="text-align: center; margin-bottom: 2rem;">
            <p style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.15em; color: var(--text-ghost); margin-bottom: 0.5rem;">MASTER CONTROL</p>
            <h1 class="gradient-text">Contexto Paroquial</h1>
            <p style="color: var(--text-dim); font-size: 0.95rem;">Selecione a paróquia que deseja administrar agora.</p>
        </header>

        <div class="parish-list">
            <?php while ($p = $res->fetch_assoc()): ?>
                <a href="?id=<?= $p['id'] ?>" class="parish-item <?= $p['id'] == $_SESSION['paroquia_id'] ? 'active' : '' ?>">
                    <span class="parish-name"><?= h($p['nome']) ?></span>
                    <div class="parish-arrow">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </div>
                </a>
            <?php endwhile; ?>
        </div>

        <div style="margin-top: 3rem; text-align: center; border-top: 1px solid var(--border); padding-top: 2rem;">
            <a href="index.php" class="btn btn-ghost" style="font-size: 0.8rem; font-weight: 800;">VOLTAR PARA O PAINEL</a>
        </div>
    </main>
</body>
</html>
