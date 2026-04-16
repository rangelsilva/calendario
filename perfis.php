<?php
require_once 'functions.php';
requireLogin();

// Somente admin do sistema (e master) podem gerenciar perfis.
requirePerm('admin_sistema');

$msg = $_GET['msg'] ?? '';
$error = '';

$pid = current_paroquia_id();
$is_master = has_level(0) || (int)($_SESSION['usuario_id'] ?? 0) === 1;
$my_perfil_id = current_user_perfil_id($conn);

// Colunas de permissao suportadas (renderiza apenas se existirem no banco).
$permCols = [
    'perm_ver_calendario' => 'Ver Calendario',
    'perm_criar_eventos' => 'Criar Eventos',
    'perm_editar_eventos' => 'Editar Eventos',
    'perm_excluir_eventos' => 'Excluir Eventos',
    'perm_ver_restritos' => 'Ver Restritos',
    'perm_cadastrar_usuario' => 'Cadastrar Usuario',
    'perm_admin_usuarios' => 'Gerenciar Usuarios',
    'perm_admin_sistema' => 'Admin Sistema',
    'perm_ver_logs' => 'Ver Logs',
];

$availablePermCols = [];
foreach ($permCols as $col => $label) {
    if (db_has_column($conn, 'perfis', $col)) {
        $availablePermCols[$col] = $label;
    }
}

function fetch_perfil(mysqli $db, int $id, int $paroquiaId): ?array {
    $res = db_query($db, 'SELECT * FROM perfis WHERE id = ? AND paroquia_id = ? LIMIT 1', [$id, $paroquiaId]);
    return $res ? $res->fetch_assoc() : null;
}

function can_manage_perfil(bool $isMaster, int $myPerfilId, int $perfilId): bool {
    if ($isMaster) return true;
    if ($myPerfilId <= 0) return false;
    // Regra: id menor = mais privilegio. Pode gerenciar apenas "igual ou abaixo" do seu.
    return $perfilId >= $myPerfilId;
}

$edit_id = (int)($_GET['id'] ?? 0);
$editing = null;
if ($edit_id > 0) {
    $editing = fetch_perfil($conn, $edit_id, $pid);
    if (!$editing) {
        $editing = null;
        $error = 'Perfil nao encontrado.';
    } elseif (!can_manage_perfil($is_master, $my_perfil_id, (int)$editing['id'])) {
        $editing = null;
        $error = 'Voce nao tem permissao para editar este perfil.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $data = sanitize_post($_POST);
    $action = (string)($data['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($data['id'] ?? 0);
        $nome_perfil = trim((string)($data['nome_perfil'] ?? ''));
        $descricao = trim((string)($data['descricao'] ?? ''));

        if ($nome_perfil === '') {
            $error = 'Nome do perfil obrigatorio.';
        } elseif ($id > 0 && !can_manage_perfil($is_master, $my_perfil_id, $id)) {
            $error = 'Voce nao tem permissao para editar este perfil.';
        } else {
            $permValues = [];
            foreach ($availablePermCols as $col => $_label) {
                $permValues[$col] = isset($_POST[$col]) ? 1 : 0;
            }

            if ($id > 0) {
                $perfilAtual = fetch_perfil($conn, $id, $pid);
                if (!$perfilAtual) {
                    $error = 'Perfil nao encontrado.';
                } else {
                    $set = "nome_perfil = ?, descricao = ?";
                    $types = "ss";
                    $params = [$nome_perfil, ($descricao !== '' ? $descricao : null)];

                    foreach ($availablePermCols as $col => $_label) {
                        $set .= ", {$col} = ?";
                        $types .= "i";
                        $params[] = (int)$permValues[$col];
                    }

                    $types .= "ii";
                    $params[] = $id;
                    $params[] = $pid;

                    $sql = "UPDATE perfis SET {$set} WHERE id = ? AND paroquia_id = ?";
                    if (db_execute($conn, $sql, $params)) {
                        // Mantem o rotulo de perfil sincronizado nos usuarios da mesma paroquia.
                        db_execute($conn, 'UPDATE usuarios SET perfil_nome = ? WHERE perfil_id = ? AND paroquia_id = ?', [$nome_perfil, $id, $pid]);
                        logAction($conn, 'EDITAR_PERFIL', 'perfis', $id, ['antigo' => $perfilAtual, 'novo' => array_merge($perfilAtual, ['nome_perfil' => $nome_perfil, 'descricao' => ($descricao !== '' ? $descricao : null)], $permValues)]);
                        header('Location: perfis.php?msg=Perfil atualizado com sucesso!');
                        exit();
                    } else {
                        $error = 'Erro ao atualizar perfil.';
                    }
                }
            } else {
                // Criar novo perfil (fica no fim pela auto_increment, coerente com "menor privilegio")
                $cols = ['paroquia_id', 'nome_perfil', 'descricao'];
                $place = ['?', '?', '?'];
                $types = "iss";
                $params = [$pid, $nome_perfil, ($descricao !== '' ? $descricao : null)];

                foreach ($availablePermCols as $col => $_label) {
                    $cols[] = $col;
                    $place[] = '?';
                    $types .= 'i';
                    $params[] = (int)$permValues[$col];
                }

                $sql = "INSERT INTO perfis (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $place) . ")";
                if (db_execute($conn, $sql, $params)) {
                    $newId = (int)$conn->insert_id;
                    logAction($conn, 'CRIAR_PERFIL', 'perfis', $newId, ['novo' => ['paroquia_id' => $pid, 'nome_perfil' => $nome_perfil, 'descricao' => ($descricao !== '' ? $descricao : null)] + $permValues]);
                    header('Location: perfis.php?msg=Perfil criado com sucesso!');
                    exit();
                } else {
                    $error = 'Erro ao criar perfil.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $error = 'Perfil invalido.';
        } elseif (!$is_master) {
            // Evita excluir por engano (e evita quebrar FK). Se precisar, voce pode habilitar.
            $error = 'Exclusao de perfil desativada por seguranca.';
        } else {
            $perfilAtual = fetch_perfil($conn, $id, $pid);
            if (!$perfilAtual) {
                $error = 'Perfil nao encontrado.';
            } else {
                $check = db_query($conn, 'SELECT COUNT(*) as c FROM usuarios WHERE perfil_id = ? AND paroquia_id = ?', [$id, $pid]);
                $c = $check ? (int)($check->fetch_assoc()['c'] ?? 0) : 0;
                if ($c > 0) {
                    $error = 'Nao e possivel excluir: existem usuarios com este perfil.';
                } else {
                    if (db_execute($conn, 'DELETE FROM perfis WHERE id = ? AND paroquia_id = ?', [$id, $pid])) {
                        logAction($conn, 'EXCLUIR_PERFIL', 'perfis', $id, ['antigo' => $perfilAtual]);
                        header('Location: perfis.php?msg=Perfil excluido!');
                        exit();
                    }
                    $error = 'Erro ao excluir perfil.';
                }
            }
        }
    } elseif ($action === 'move') {
        $id = (int)($data['id'] ?? 0);
        $dir = (string)($data['dir'] ?? '');

        if ($id <= 0 || ($dir !== 'up' && $dir !== 'down')) {
            $error = 'Operacao invalida.';
        } elseif (!can_manage_perfil($is_master, $my_perfil_id, $id)) {
            $error = 'Voce nao tem permissao para mover este perfil.';
        } else {
            // Vizinho por id (a "posicao" e definida pelo ID).
            if ($dir === 'up') {
                $stmtN = db_query($conn, 'SELECT * FROM perfis WHERE paroquia_id = ? AND id < ? ORDER BY id DESC LIMIT 1', [$pid, $id]);
            } else {
                $stmtN = db_query($conn, 'SELECT * FROM perfis WHERE paroquia_id = ? AND id > ? ORDER BY id ASC LIMIT 1', [$pid, $id]);
            }

            if (!$stmtN) {
                $error = 'Falha ao preparar movimentacao.';
            } else {
                $neighbor = $stmtN->fetch_assoc();
                $current = fetch_perfil($conn, $id, $pid);

                if (!$current || !$neighbor) {
                    $error = 'Nao ha para onde mover.';
                } elseif (!can_manage_perfil($is_master, $my_perfil_id, (int)$neighbor['id'])) {
                    $error = 'Voce nao pode mover acima do seu escopo.';
                } else {
                    $aId = (int)$current['id'];
                    $bId = (int)$neighbor['id'];

                    $conn->begin_transaction();
                    try {
                        // Swap de conteudo (mantem IDs). Isso altera a "posicao" na hierarquia.
                        $permColsOnly = array_keys($availablePermCols);

                        // Prepara SQL base (nome_perfil + descricao + perms)
                        $setTail = "nome_perfil = ?, descricao = ?";
                        $typesTail = "ss";
                        $valsA = [
                            (string)($current['nome_perfil'] ?? ''),
                            (string)($current['descricao'] ?? ''),
                        ];
                        $valsB = [
                            (string)($neighbor['nome_perfil'] ?? ''),
                            (string)($neighbor['descricao'] ?? ''),
                        ];
                        foreach ($permColsOnly as $col) {
                            $setTail .= ", {$col} = ?";
                            $typesTail .= "i";
                            $valsA[] = (int)($current[$col] ?? 0);
                            $valsB[] = (int)($neighbor[$col] ?? 0);
                        }

                        // 1) Atualiza B com conteudo de A
                        $sqlB = "UPDATE perfis SET {$setTail} WHERE id = ? AND paroquia_id = ?";
                        if (!db_execute($conn, $sqlB, array_merge($valsA, [$bId, $pid]))) throw new Exception('Falha ao atualizar B.');

                        // 2) Atualiza A com conteudo de B
                        $sqlA = "UPDATE perfis SET {$setTail} WHERE id = ? AND paroquia_id = ?";
                        if (!db_execute($conn, $sqlA, array_merge($valsB, [$aId, $pid]))) throw new Exception('Falha ao atualizar A.');

                        // Mantem usuarios.perfil_nome alinhado com o perfil atualizado.
                        if (db_has_column($conn, 'usuarios', 'perfil_nome')) {
                            $newNameA = (string)($neighbor['nome_perfil'] ?? '');
                            $newNameB = (string)($current['nome_perfil'] ?? '');
                            db_execute($conn, 'UPDATE usuarios SET perfil_nome = ? WHERE perfil_id = ? AND paroquia_id = ?', [$newNameA, $aId, $pid]);
                            db_execute($conn, 'UPDATE usuarios SET perfil_nome = ? WHERE perfil_id = ? AND paroquia_id = ?', [$newNameB, $bId, $pid]);
                        }

                        logAction($conn, 'MOVER_PERFIL', 'perfis', $aId, ['dir' => $dir, 'a' => $aId, 'b' => $bId]);
                        $conn->commit();
                        header('Location: perfis.php?msg=Perfil movido com sucesso!');
                        exit();
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = 'Erro ao mover perfil.';
                    }
                }
            }
        }
    }
}

// Lista de perfis (visibilidade tambem segue a regra do ID)
$perfis = [];
$res = db_query($conn, "
    SELECT p.*,
           COALESCE(
             NULLIF(MAX(NULLIF(TRIM(p.nome_perfil), '')), ''),
             NULLIF(MAX(NULLIF(TRIM(u.perfil_nome), '')), ''),
             CONCAT('Perfil #', p.id)
           ) AS label_nome
    FROM perfis p
    LEFT JOIN usuarios u ON u.perfil_id = p.id AND u.paroquia_id = p.paroquia_id
    WHERE p.paroquia_id = ?
    GROUP BY p.id
    ORDER BY p.id ASC
", [$pid]);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if ($is_master || can_manage_perfil($is_master, $my_perfil_id, (int)$r['id'])) {
            $perfis[] = $r;
        }
    }
}

$form = $editing ?: [
    'id' => 0,
    'nome_perfil' => '',
    'descricao' => '',
];
foreach ($availablePermCols as $col => $_label) {
    if (!array_key_exists($col, $form)) {
        $form[$col] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Perfis — PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; width: 100%; overflow-x: hidden; }
        .main-content { flex: 1; min-width: 0; width: 100%; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; } }

        .layout { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 1.5rem; }
        @media (max-width: 1100px) { .layout { grid-template-columns: 1fr; } }

        .card { padding: 1.6rem; border-radius: 22px; }
        .header { display:flex; align-items:flex-end; justify-content:space-between; gap: 1rem; margin-bottom: 1.5rem; }
        .header h1 { margin: 0; font-size: 1.15rem; }
        .small { color: var(--text-dim); font-size: 0.9rem; }

        .perfil-row { display:flex; align-items:center; justify-content:space-between; gap: 1rem; padding: 1rem; border-radius: 16px; border: 1px solid var(--border); background: rgba(255,255,255,0.02); }
        .perfil-row + .perfil-row { margin-top: 0.8rem; }
        .perfil-meta { display:flex; flex-direction:column; gap: 0.15rem; min-width: 0; }
        .perfil-name { font-weight: 900; letter-spacing: -0.01em; white-space: nowrap; overflow:hidden; text-overflow: ellipsis; }
        .perfil-sub { font-size: 0.8rem; color: var(--text-ghost); }

        .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .full { grid-column: span 2; }
        @media (max-width: 520px) { .form-grid { grid-template-columns: 1fr; } .full { grid-column: span 1; } }

        .perm-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        @media (max-width: 520px) { .perm-grid { grid-template-columns: 1fr; } }
        .perm-item { display:flex; align-items:center; gap: 0.6rem; padding: 0.75rem; border-radius: 14px; border: 1px solid var(--border); background: rgba(255,255,255,0.02); }
        .actions { display:flex; gap: 0.75rem; }
        .actions .btn { flex: 1; }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>
    <div class="app-shell">
        <?php include 'sidebar.php'; ?>
        <main class="main-content">
            <?php if ($msg): ?> <?= alert('success', h($msg)) ?> <?php endif; ?>
            <?php if ($error): ?> <?= alert('error', h($error)) ?> <?php endif; ?>

            <div class="header animate-in">
                <div>
                    <h1 class="gradient-text">Perfis</h1>
                    <div class="small">Ordem por ID (1 maior, 11 menor). Voce so ve/edita perfis iguais ou abaixo do seu.</div>
                </div>
                <a href="perfis.php" class="btn btn-ghost" style="height:44px;">Novo</a>
            </div>

            <div class="layout">
                <section class="glass card animate-in" style="animation-delay:0.05s;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom: 1rem;">
                        <div style="font-size:0.75rem; font-weight:900; letter-spacing:0.12em; color:var(--text-ghost);">LISTA</div>
                        <div class="small"><?= count($perfis) ?> perfil(is)</div>
                    </div>

                    <?php if (!$perfis): ?>
                        <div class="small">Nenhum perfil disponivel no seu escopo.</div>
                    <?php else: ?>
                        <?php foreach ($perfis as $idx => $p): ?>
                            <div class="perfil-row">
                                <div class="perfil-meta">
                                    <div class="perfil-name">#<?= (int)$p['id'] ?> · <?= h($p['label_nome'] ?? ('Perfil #' . (int)$p['id'])) ?></div>
                                    <div class="perfil-sub"><?= h((string)($p['descricao'] ?? '')) ?></div>
                                </div>
                                <div style="display:flex; gap:0.5rem; align-items:center; flex-shrink:0;">
                                    <?php if ($idx > 0): ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="dir" value="up">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <button type="submit" class="btn btn-ghost" style="height:40px; padding:0 0.7rem;" title="Subir">UP</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($idx < count($perfis) - 1): ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="dir" value="down">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <button type="submit" class="btn btn-ghost" style="height:40px; padding:0 0.7rem;" title="Descer">DN</button>
                                        </form>
                                    <?php endif; ?>

                                    <a class="btn btn-ghost" style="height:40px; padding:0 0.9rem;" href="perfis.php?id=<?= (int)$p['id'] ?>">Editar</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section class="glass card animate-in" style="animation-delay:0.1s;">
                    <div style="font-size:0.75rem; font-weight:900; letter-spacing:0.12em; color:var(--text-ghost); margin-bottom: 1rem;">
                        <?= $editing ? 'EDITAR' : 'CRIAR' ?>
                    </div>

                    <form method="POST" class="form-grid" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
                        <div class="form-group full">
                            <label>NOME DO PERFIL</label>
                            <input type="text" name="nome_perfil" value="<?= h((string)($form['nome_perfil'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group full">
                            <label>DESCRICAO (OPCIONAL)</label>
                            <textarea name="descricao" rows="3" style="width:100%; padding: 1rem; border-radius: 16px; background: rgba(255,255,255,0.03); border:1px solid var(--border); color:#fff;"><?= h((string)($form['descricao'] ?? '')) ?></textarea>
                        </div>

                        <div class="form-group full" style="margin-top:0.5rem;">
                            <label>PERMISSOES</label>
                            <?php if (!$availablePermCols): ?>
                                <div class="small">Nenhuma coluna de permissao encontrada na tabela `perfis`.</div>
                            <?php else: ?>
                                <div class="perm-grid">
                                    <?php foreach ($availablePermCols as $col => $label): ?>
                                        <label class="perm-item">
                                            <input type="checkbox" name="<?= h($col) ?>" value="1" <?= ((int)($form[$col] ?? 0) === 1) ? 'checked' : '' ?>>
                                            <span style="font-weight:800;"><?= h($label) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="full actions" style="margin-top: 1rem;">
                            <button type="submit" class="btn btn-primary shimmer" style="height:44px;">Salvar</button>
                            <a href="perfis.php" class="btn btn-ghost" style="height:44px;">Cancelar</a>
                        </div>

                        <?php if ($editing && $is_master): ?>
                            <div class="full" style="margin-top: 0.5rem;">
                                <button type="submit" name="action" value="delete" class="btn btn-ghost" style="width:100%; height:44px; border-color: rgba(239,68,68,0.5); color:#fca5a5;"
                                    onclick="return confirm('Excluir este perfil? Isso so e permitido se nenhum usuario estiver usando.');">Excluir (Master)</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>

