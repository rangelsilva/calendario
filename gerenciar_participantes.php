<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Participant Management (v2.1)
 * Assignment UI · Activity Distribution · Premium Admin
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();

if (!can('admin_sistema') && !can('editar_eventos')) {
    header('Location: index.php?error=unauthorized');
    exit();
}

$id = (int)($_GET['id'] ?? 0);
$pid = current_paroquia_id();

$res = db_query($conn, "SELECT * FROM atividades WHERE id = ? AND paroquia_id = ? LIMIT 1", [$id, $pid]);
$activity = $res ? $res->fetch_assoc() : null;

if (!$activity) {
    header('Location: index.php?error=not_found');
    exit();
}

// 2. Handle Assignment Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $targetUserId = (int)($_POST['usuario_id'] ?? 0);
    $selectedItems = $_POST['items'] ?? [];
    $newCatalogId = (int)($_POST['add_catalog_id'] ?? 0);
    
    if ($targetUserId > 0) {
        $conn->begin_transaction();
        try {
            // Delete existing assignments for this event context
            db_execute($conn, "DELETE FROM inscricoes WHERE atividade_id = ? AND usuario_id = ?", [$id, $targetUserId]);
            db_execute($conn, "
                DELETE aei FROM atividade_evento_inscricoes aei
                INNER JOIN atividade_evento_itens ei ON ei.id = aei.evento_item_id
                WHERE ei.evento_id = ? AND aei.usuario_id = ?
            ", [$id, $targetUserId]);
            
            // If we are adding a NEW item from catalog
            if ($newCatalogId > 0) {
                // Check if this catalog item is already in this event
                $stCheck = db_query($conn, "SELECT id FROM atividade_evento_itens WHERE evento_id = ? AND atividade_catalogo_id = ? LIMIT 1", [$id, $newCatalogId]);
                $resCheck = $stCheck ? $stCheck->fetch_assoc() : null;
                
                if ($resCheck) {
                    $itemId = $resCheck['id'];
                } else {
                    db_execute($conn, "INSERT INTO atividade_evento_itens (evento_id, atividade_catalogo_id, ordem) SELECT ?, ?, IFNULL(MAX(ordem)+1, 1) FROM atividade_evento_itens WHERE evento_id = ?", [$id, $newCatalogId, $id]);
                    $itemId = $conn->insert_id;
                }
                
                // Add to selectedItems if not already there
                if (!in_array((string)$itemId, $selectedItems)) {
                    $selectedItems[] = (string)$itemId;
                }
            }

            if (empty($selectedItems)) {
                db_execute($conn, "INSERT INTO inscricoes (atividade_id, usuario_id) VALUES (?, ?)", [$id, $targetUserId]);
            } else {
                foreach ($selectedItems as $itemId) {
                    $itemId = (int)$itemId;
                    if ($itemId > 0) {
                        db_execute($conn, "INSERT INTO atividade_evento_inscricoes (evento_item_id, usuario_id) VALUES (?, ?)", [$itemId, $targetUserId]);
                    }
                }
            }
            
            $conn->commit();
            header("Location: gerenciar_participantes.php?id=$id&msg=" . urlencode("Sincronizado!"));
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Erro: " . $e->getMessage();
        }
    }
}

// 2.2 Catalog for Assignment
$catRes = db_query($conn, "SELECT id, nome FROM atividades_catalogo WHERE paroquia_id = ? ORDER BY nome ASC", [$pid]);
$catalog = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];

// 3. Fetch Participants
$participantsQuery = "
    SELECT u.id, u.nome, u.email, u.foto_perfil,
           (SELECT GROUP_CONCAT(evento_item_id) 
            FROM atividade_evento_inscricoes aei 
            INNER JOIN atividade_evento_itens ei ON ei.id = aei.evento_item_id
            WHERE ei.evento_id = ? AND aei.usuario_id = u.id) as item_ids,
           EXISTS(SELECT 1 FROM inscricoes WHERE atividade_id = ? AND usuario_id = u.id) as is_main
    FROM usuarios u
    WHERE u.id IN (
        SELECT usuario_id FROM inscricoes WHERE atividade_id = ?
        UNION
        SELECT aei.usuario_id FROM atividade_evento_inscricoes aei
        INNER JOIN atividade_evento_itens ei ON ei.id = aei.evento_item_id
        WHERE ei.evento_id = ?
    )
    ORDER BY u.nome ASC
";
$allRawRes = db_query($conn, $participantsQuery, [$id, $id, $id, $id]);
$allRaw = $allRawRes ? $allRawRes->fetch_all(MYSQLI_ASSOC) : [];
$allParticipants = array_map(function($p) {
    $p['assigned_ids'] = $p['item_ids'] ? explode(',', $p['item_ids']) : [];
    return $p;
}, $allRaw);

$eventItems = getEventActivityItems($conn, $id);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'>
    <meta charset="UTF-8">
    <title>Gerenciar – <?= htmlspecialchars($activity['nome']) ?></title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }
        .header-actions { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 3rem; flex-wrap: wrap; gap: 1rem; }
        .participation-table { width: 100%; border-collapse: separate; border-spacing: 0 0.75rem; }
        .participation-row td { padding: 1.25rem 1rem; background: rgba(255,255,255,0.02); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .participation-row td:first-child { border-left: 1px solid var(--border); border-top-left-radius: 16px; border-bottom-left-radius: 16px; }
        .participation-row td:last-child { border-right: 1px solid var(--border); border-top-right-radius: 16px; border-bottom-right-radius: 16px; }
        .user-cell { display: flex; align-items: center; gap: 1rem; }
        .user-avatar { width: 40px; height: 40px; border-radius: 12px; background: var(--panel-hi); overflow: hidden; display: flex; align-items: center; justify-content: center; font-weight: 900; }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .assignment-chips { display: flex; flex-wrap: wrap; gap: 0.6rem; }
        .item-chip {
            display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.85rem;
            background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 10px;
            font-size: 0.75rem; font-weight: 700; color: var(--text-dim); cursor: pointer; transition: all 0.2s;
        }
        .item-chip.active { background: rgba(59, 130, 246, 0.1); border-color: var(--primary); color: var(--primary); }
        .item-chip.general { background: rgba(34, 197, 94, 0.05); border-color: rgba(34, 197, 94, 0.2); color: #22c55e; cursor: default; }
        .item-chip input { display: none; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5.5rem; } }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>
    <div class="app-shell">
        <?php include 'sidebar.php'; ?>
        <main class="main-content">
            <?php if (isset($_GET['msg'])): ?><?= alert('success', htmlspecialchars($_GET['msg'])) ?><?php endif; ?>
            <header class="header-actions">
                <div>
                    <h1 class="gradient-text">Gerenciar Inscrições</h1>
                    <p style="color: var(--text-dim); font-weight: 600;"><?= htmlspecialchars($activity['nome'] ?? '') ?></p>
                </div>
                <a href="ver_atividade.php?id=<?= $id ?>" class="btn btn-ghost">Voltar</a>
            </header>

            <section class="glass" style="padding: 2rem;">
                <table class="participation-table">
                    <thead>
                        <tr>
                            <th style="text-align: left; font-size: 0.7rem; color: var(--text-ghost);">PARTICIPANTE</th>
                            <th style="text-align: left; font-size: 0.7rem; color: var(--text-ghost);">ATRIBUIÇÕES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allParticipants as $p): ?>
                        <tr class="participation-row">
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar">
                                        <?php if ($p['foto_perfil']): ?>
                                            <img src="<?= htmlspecialchars($p['foto_perfil']) ?>" alt="">
                                        <?php else: ?>
                                            <?= mb_substr($p['nome'], 0, 1) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 800; color: var(--text);"><?= htmlspecialchars($p['nome'] ?? '') ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-ghost);"><?= htmlspecialchars($p['email'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <form method="POST" id="form_<?= $p['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="usuario_id" value="<?= $p['id'] ?>">
                                    <div class="assignment-chips">
                                        <?php if (empty($p['assigned_ids'])): ?>
                                            <div class="item-chip general">Inscrição Geral</div>
                                        <?php endif; ?>
                                        <?php foreach ($eventItems as $item): ?>
                                            <?php $isAssigned = in_array((string)$item['id'], $p['assigned_ids']); ?>
                                            <label class="item-chip <?= $isAssigned ? 'active' : '' ?>">
                                                <input type="checkbox" name="items[]" value="<?= $item['id'] ?>" <?= $isAssigned ? 'checked' : '' ?> onchange="this.form.submit()">
                                                <?= htmlspecialchars($item['nome'] ?? '') ?>
                                            </label>
                                        <?php endforeach; ?>
                                        <select name="add_catalog_id" class="item-chip" style="display: inline-block; padding: 0.4rem; cursor: pointer; border-style: dashed;" onchange="this.form.submit()">
                                            <option value="">+ Atribuir nova...</option>
                                            <?php foreach($catalog as $cat): ?>
                                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nome']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
