<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Activity Detail View (v2.0)
 * Info Layout · Participant Management · Premium UI
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();
ensureInscricoesTable($conn);
ensureUserPhotoColumn($conn);
ensureEventActivitiesStructure($conn);

$pid = current_paroquia_id();
$id = (int)($_GET['id'] ?? 0);
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// 1. Fetch Activity Details
$sql = "
    SELECT a.*, l.nome_local, l.endereco, l.responsavel, t.nome_tipo, t.icone, u.nome as criador_nome
    FROM atividades a
    LEFT JOIN locais_paroquia l ON a.local_id = l.id
    LEFT JOIN tipos_atividade t ON a.tipo_atividade_id = t.id
    LEFT JOIN usuarios u ON a.criador_id = u.id
    WHERE a.id = ? AND a.paroquia_id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $id, $pid);
$stmt->execute();
$activity = $stmt->get_result()->fetch_assoc();

if (!$activity) {
    header('Location: index.php?error=not_found');
    exit();
}

if ($activity['restrito']) {
    $userId = (int)($_SESSION['usuario_id'] ?? 0);
    if (!can('ver_restritos') && $activity['criador_id'] != $userId) {
        header('Location: index.php?error=unauthorized_restricted');
        exit();
    }
}

// 2. Fetch Participants (Inscrições Totais)
$parts_sql = "
    SELECT DISTINCT u.nome, u.email, u.foto_perfil, u.id
    FROM usuarios u 
    WHERE u.id IN (
        SELECT usuario_id FROM inscricoes WHERE atividade_id = ?
        UNION
        SELECT aei.usuario_id 
        FROM atividade_evento_inscricoes aei
        INNER JOIN atividade_evento_itens ei ON ei.id = aei.evento_item_id
        WHERE ei.evento_id = ?
    )
    ORDER BY u.nome ASC
";
$stmt_p = $conn->prepare($parts_sql);
$stmt_p->bind_param('ii', $id, $id);
$stmt_p->execute();
$participants = $stmt_p->get_result();
$eventItems = getEventActivityItems($conn, $id, (int)($_SESSION['usuario_id'] ?? 0));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= h($activity['nome']) ?> – Detalhes</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; overflow-y: auto; max-height: 100vh; }
        
        .detail-header { margin-bottom: 3.5rem; display: flex; justify-content: space-between; align-items: flex-start; }
        
        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .detail-header { flex-direction: column; gap: 1.5rem; }
            .detail-header h1 { font-size: 2rem; }
            .btn { width: 100%; }
            .info-grid { grid-template-columns: 1fr; }
        }
        .detail-header h1 { font-size: 2.8rem; font-weight: 900; margin-top: 0.5rem; line-height: 1.1; }
        
        .info-grid { display: grid; grid-template-columns: 1.8fr 1fr; gap: 2rem; margin-bottom: 3rem; }
        .info-card { padding: 2.5rem; height: 100%; }
        .info-card h3 { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: var(--text-ghost); letter-spacing: 0.1em; margin-bottom: 2rem; }

        .meta-list { display: grid; gap: 1.5rem; }
        .meta-box { display: flex; align-items: flex-start; gap: 1rem; }
        .meta-icon { width: 40px; height: 40px; border-radius: 12px; background: var(--panel-hi); display: flex; align-items: center; justify-content: center; color: var(--primary); }
        .meta-content div { font-size: 0.75rem; font-weight: 700; color: var(--text-dim); margin-bottom: 0.2rem; }
        .meta-content span { font-size: 1rem; font-weight: 700; color: var(--text); }

        .participants-list { display: grid; gap: 1rem; }
        .participant-item { display: flex; align-items: center; gap: 1rem; padding: 1rem; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid var(--border); }
        .p-avatar { width: 32px; height: 32px; border-radius: 8px; background: var(--panel-hi); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem; color: var(--accent); overflow: hidden; }
        .p-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .event-items-board { margin-bottom: 2rem; padding: 2rem; }
        .event-items-grid {
            display: grid; gap: 1rem;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 0.5rem;
            /* Firefox */
            scrollbar-width: thin;
            scrollbar-color: var(--primary) rgba(255,255,255,0.05);
        }
        .event-items-grid::-webkit-scrollbar { width: 8px; }
        .event-items-grid::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        .event-items-grid::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 10px; }
        .event-items-grid::-webkit-scrollbar-thumb:hover { background: var(--accent); }
        .event-item-card { padding: 1.1rem; border-radius: 14px; border: 1px solid var(--border); background: rgba(255,255,255,0.03); }
        .event-item-header { display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; }
        .event-item-participants { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.9rem; }
        .event-item-chip { padding: 0.4rem 0.6rem; border-radius: 10px; background: var(--panel-hi); border: 1px solid var(--border); font-size: 0.8rem; }

        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: var(--accent); }
        .custom-scrollbar { scrollbar-width: thin; scrollbar-color: var(--primary) transparent; }

        @media (max-width: 1100px) {
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>

    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <?php if ($msg): ?><?= alert('success', h($msg)) ?><?php endif; ?>
            <?php if ($error): ?><?= alert('error', h($error)) ?><?php endif; ?>
            
            <header class="detail-header animate-in">
                <div>
                    <span class="type-badge" style="background:var(--panel-hi);"><?= h($activity['nome_tipo'] ?: 'Evento') ?></span>
                    <h1 class="gradient-text"><?= h($activity['nome']) ?></h1>
                    <?php if ($activity['restrito']): ?>
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; color: #ef4444; font-size: 0.8rem; font-weight: 800; text-transform: uppercase;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Evento Restrito (Privado)
                        </div>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <?php if (can('editar_eventos')): ?>
                    <a href="editar_atividade.php?id=<?= $id ?>" class="btn btn-ghost">Editar Registro</a>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-primary shimmer">Voltar ao Calendário</a>
                </div>
            </header>

            <div class="info-grid animate-in" style="animation-delay: 0.1s;">
                <section class="glass info-card">
                    <h3>Visão Geral do Evento</h3>
                    <div class="meta-list">
                        <div class="meta-box">
                            <div class="meta-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                            </div>
                            <div class="meta-content">
                                <div>CRONOGRAMA</div>
                                <span><?= formatDate($activity['data_inicio']) ?> às <?= formatTime($activity['hora_inicio']) ?></span>
                            </div>
                        </div>

                        <div class="meta-box">
                            <div class="meta-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            </div>
                            <div class="meta-content">
                                <div>LOCALIZAÇÃO</div>
                                <span><?= h($activity['nome_local'] ?: 'Não definido') ?></span>
                                <p style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.3rem;"><?= h($activity['endereco'] ?: 'Endereço não disponível') ?></p>
                            </div>
                        </div>

                        <div class="meta-box">
                            <div class="meta-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <div class="meta-content">
                                <div>CRIADO POR</div>
                                <span><?= h($activity['criador_nome'] ?: 'Gerenciador do Sistema') ?></span>
                            </div>
                        </div>

                        <?php if ($activity['descricao']): ?>
                        <div class="meta-box" style="margin-top: 1rem; padding: 1.5rem; background: var(--panel-hi); border-radius: 16px;">
                            <div class="meta-content custom-scrollbar" style="width: 100%; max-height: 250px; overflow-y: auto; padding-right: 0.5rem;">
                                <div style="margin-bottom: 0.8rem; position: sticky; top: 0; background: var(--panel-hi); padding-bottom: 0.5rem; z-index: 1;">DETALHES DA ATIVIDADE</div>
                                <p style="color: var(--text); line-height: 1.6; font-size: 0.95rem; margin: 0;"><?= nl2br(h($activity['descricao'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="glass info-card">
                    <h3>Participantes Confirmados</h3>
                    <div class="participants-list">
                        <?php if ($participants->num_rows > 0): ?>
                            <?php while ($p = $participants->fetch_assoc()): ?>
                            <div class="participant-item">
                                <div class="p-avatar">
                                    <?php if (!empty($p['foto_perfil']) && file_exists(__DIR__ . '/' . $p['foto_perfil'])): ?>
                                        <img src="<?= h($p['foto_perfil']) ?>?v=<?= time() ?>" alt="Foto">
                                    <?php else: ?>
                                        <?= mb_substr($p['nome'], 0, 1) ?>
                                    <?php endif; ?>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-size: 0.85rem; font-weight: 700;"><?= h($p['nome']) ?></div>
                                    <div style="font-size: 0.7rem; color: var(--text-ghost);"><?= h($p['email']) ?></div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 3rem; color: var(--text-ghost);">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.4;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <p>Nenhuma inscrição realizada.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (can('admin_sistema') || can('editar_eventos')): ?>
                    <a href="gerenciar_participantes.php?id=<?= $id ?>" class="btn btn-ghost" style="width:100%; margin-top:1.5rem; font-size:0.75rem; text-decoration: none; display: inline-flex; justify-content: center; align-items: center;">Gerenciar Inscrições</a>
                    <?php endif; ?>
                </section>
            </div>

            <?php if (empty($eventItems) && canInteractWithActivity()): ?>
                <?php 
                $userId = (int)($_SESSION['usuario_id'] ?? 0);
                $isEnrolledMain = (bool)db_fetch_one($conn, "SELECT id FROM inscricoes WHERE atividade_id = ? AND usuario_id = ? LIMIT 1", [$id, $userId]);
                ?>
                <section class="glass event-items-board animate-in" style="animation-delay: 0.15s; text-align: center; margin-top: -1.5rem;">
                    <h3 style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: var(--text-ghost); letter-spacing: 0.1em; margin-bottom: 1.5rem;">Inscrição neste Evento</h3>
                    <div style="padding: 1rem; background: var(--panel-hi); border-radius: 16px; border: 1px solid var(--border);">
                        <?php if (!$isEnrolledMain): ?>
                            <form method="POST" action="inscrever.php" style="margin: 0;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="join">
                                <button type="submit" class="btn btn-primary shimmer" style="padding: 1rem 3rem;">Inscrever-me no Evento</button>
                            </form>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; align-items: center; gap: 1rem;">
                                <div style="color: var(--primary); font-weight: 800;">✓ Você já está inscrito neste evento!</div>
                                <?php if (activityStartTimestamp($activity) - 86400 >= time() || canBypassEnrollmentDeadline()): ?>
                                    <form method="POST" action="inscrever.php" style="margin: 0;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="leave">
                                        <button type="submit" class="btn btn-ghost">Cancelar minha Inscrição</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($eventItems)): ?>
            <section class="glass event-items-board animate-in" style="animation-delay: 0.15s;">
                <h3 style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: var(--text-ghost); letter-spacing: 0.1em; margin-bottom: 1.5rem;">Atividades do Evento</h3>
                <div class="event-items-grid">
                    <?php foreach ($eventItems as $item): ?>
                    <div class="event-item-card">
                        <div class="event-item-header">
                            <div>
                                <div style="font-size: 1rem; font-weight: 800;"><?= h($item['nome']) ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-dim);"><?= (int)$item['total_inscritos'] ?> inscrito(s)</div>
                            </div>
                            <?php if (canInteractWithActivity()): ?>
                                <?php if (!$item['usuario_inscrito']): ?>
                                    <form method="POST" action="inscrever.php" style="margin: 0;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="action" value="join">
                                        <button type="submit" class="btn btn-primary shimmer">Inscrever-me</button>
                                    </form>
                                <?php elseif (activityStartTimestamp($activity) - 86400 >= time() || canBypassEnrollmentDeadline()): ?>
                                    <form method="POST" action="inscrever.php" style="margin: 0;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="action" value="leave">
                                        <button type="submit" class="btn btn-ghost">Desistir</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="event-item-participants">
                            <?php if (!empty($item['participants'])): ?>
                                <?php foreach ($item['participants'] as $participant): ?>
                                    <div class="event-item-chip" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                        <div class="p-avatar" style="width: 20px; height: 20px; font-size: 0.5rem; border-radius: 50%;">
                                            <?php if (!empty($participant['foto_perfil']) && file_exists(__DIR__ . '/' . $participant['foto_perfil'])): ?>
                                                <img src="<?= h($participant['foto_perfil']) ?>?v=<?= time() ?>" alt="">
                                            <?php else: ?>
                                                <?= mb_substr($participant['nome'], 0, 1) ?>
                                            <?php endif; ?>
                                        </div>
                                        <span style="font-weight: 600;"><?= h($participant['nome']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="event-item-chip">Nenhum inscrito nesta atividade.</div>
                            <?php endif; ?>
                        </div>
                        <?php if ($item['usuario_inscrito'] && !(activityStartTimestamp($activity) - 86400 >= time() || canBypassEnrollmentDeadline())): ?>
                            <div style="margin-top: 0.9rem; font-size: 0.8rem; color: #fbbf24;">Somente usuários de nível 3 ou superior podem desistir com menos de 24 horas de antecedência.</div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
