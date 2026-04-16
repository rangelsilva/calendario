<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Parish Calendar (v2.4.4 Ultra Premium Ultra Premium)
 * High Performance · Glassmorphism · Dynamic Enrollment
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();
ensureInscricoesTable($conn);
ensureUserPhotoColumn($conn);
ensureEventActivitiesStructure($conn);

$userId = (int)($_SESSION['usuario_id'] ?? 0);
$pid = current_paroquia_id();
$canInteractActivities = canInteractWithActivity();
$msg = $_GET['msg'] ?? '';
$autoRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

// 1. Fetch unread notifications
$unreadNotifications = [];
if ($userId > 0) {
    ensureNotificationsTable($conn);

    // Handle Clearing
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_notifications') {
        require_csrf_token();
        $stmtC = $conn->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ?");
        $stmtC->bind_param('i', $userId);
        $stmtC->execute();
        $stmtC->close();
        header("Location: index.php");
        exit();
    }

    $stmtN = $conn->prepare("SELECT * FROM notificacoes WHERE usuario_id = ? AND lida = 0 ORDER BY data_criacao DESC");
    if ($stmtN) {
        $stmtN->bind_param('i', $userId);
        $stmtN->execute();
        $resN = $stmtN->get_result();
        while ($rn = $resN->fetch_assoc()) {
            $unreadNotifications[] = $rn;
        }
        $stmtN->close();
    }
}

// 1. Date Navigation Logic
$month = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('m');
$year  = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');

// Adjust for overflow/underflow
if ($month > 12) { $month = 1; $year++; }
if ($month < 1) { $month = 12; $year--; }

$dt = new DateTime("$year-$month-01");
$monthNames = [
    1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARÇO', 4 => 'ABRIL',
    5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO',
    9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO'
];
$displayMonth = $monthNames[$month];

// 2. Calendar Metadata
$daysInMonth    = (int)$dt->format('t');
$firstDayOfWeek = (int)$dt->format('w'); // 0 (Sun) to 6 (Sat)
$today          = date('Y-m-d');

// 3. Fetch Activities — SPEC-06: query principal limpa sem subqueries correlacionadas.
// Contagens e previews são obtidos em batch separado (veja abaixo).
$startMonth = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$endMonth   = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . $daysInMonth;

$sql = "
    SELECT
        a.id, a.nome, a.descricao, a.data_inicio, a.hora_inicio,
        a.local_id, a.tipo_atividade_id, a.cor, a.is_multi_color,
        a.is_flashing, a.restrito, a.criador_id, a.paroquia_id,
        a.vagas, a.inscricoes_abertas,
        t.cor  AS tipo_cor,
        t.icone,
        (
            SELECT GROUP_CONCAT(ag.grupo_id ORDER BY ag.grupo_id ASC)
            FROM atividade_grupos ag
            WHERE ag.atividade_id = a.id
        ) AS grupo_ids
    FROM atividades a
    LEFT JOIN tipos_atividade t ON a.tipo_atividade_id = t.id
    WHERE a.paroquia_id = ?
      AND a.data_inicio BETWEEN ? AND ?
      AND (a.restrito = 0 OR ? = 1 OR a.criador_id = ?)
      AND (
          NOT EXISTS (SELECT 1 FROM atividade_grupos ag WHERE ag.atividade_id = a.id)
          OR ? = 1
          OR EXISTS (
              SELECT 1 FROM atividade_grupos ag
              INNER JOIN usuario_grupos ug ON ug.grupo_id = ag.grupo_id
              WHERE ag.atividade_id = a.id AND ug.usuario_id = ?
          )
      )
    ORDER BY a.hora_inicio ASC
";

$canVerRestritos = (int)can('ver_restritos');
$isAdmin         = (int)(can('admin_sistema') || ($_SESSION['usuario_nivel'] ?? 99) === 0);
$bypassGroups    = max($canVerRestritos, $isAdmin);

$stmt = $conn->prepare($sql);
$stmt->bind_param('issiiii', $pid, $startMonth, $endMonth, $canVerRestritos, $userId, $bypassGroups, $userId);
$stmt->execute();
$rawActivities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 4. Batch: contagens e previews — SPEC-06.
// Evita N+1: uma query para todos os IDs retornados, ao invés de uma subquery por atividade.
$activityIds     = array_column($rawActivities, 'id');
$countsByAct     = [];   // [atividade_id => total_inscritos]
$previewsByAct   = [];   // [atividade_id => [['nome'=>..,'foto'=>..], ...]]

if ($activityIds) {
    $placeholders = implode(',', array_fill(0, count($activityIds), '?'));
    $types        = str_repeat('i', count($activityIds));

    // 4a. Contagem total de inscritos (inscricoes diretas + por evento_item)
    $sqlCounts = "
        SELECT atividade_id, COUNT(*) AS cnt
        FROM inscricoes
        WHERE atividade_id IN ($placeholders)
        GROUP BY atividade_id

        UNION ALL

        SELECT ei.evento_id AS atividade_id, COUNT(*) AS cnt
        FROM atividade_evento_inscricoes aei
        INNER JOIN atividade_evento_itens ei ON ei.id = aei.evento_item_id
        WHERE ei.evento_id IN ($placeholders)
        GROUP BY ei.evento_id
    ";
    $stmtC = $conn->prepare($sqlCounts);
    $allIds = array_merge($activityIds, $activityIds); // duas listas para os dois IN()
    $stmtC->bind_param($types . $types, ...$allIds);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($c = $resC->fetch_assoc()) {
        $countsByAct[(int)$c['atividade_id']] = ($countsByAct[(int)$c['atividade_id']] ?? 0) + (int)$c['cnt'];
    }
    $stmtC->close();

    // 4b. Preview: até 3 inscritos por atividade (inscricoes diretas, deduplicados por nome)
    $sqlPreview = "
        SELECT i.atividade_id, u.nome, COALESCE(u.foto_perfil, '') AS foto
        FROM inscricoes i
        INNER JOIN usuarios u ON u.id = i.usuario_id
        WHERE i.atividade_id IN ($placeholders)
        ORDER BY i.atividade_id, u.nome
    ";
    $stmtP = $conn->prepare($sqlPreview);
    $stmtP->bind_param($types, ...$activityIds);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    while ($p = $resP->fetch_assoc()) {
        $aid = (int)$p['atividade_id'];
        if (!isset($previewsByAct[$aid])) $previewsByAct[$aid] = [];
        if (count($previewsByAct[$aid]) < 3) {
            $foto = $p['foto'];
            if ($foto !== '' && !file_exists(__DIR__ . '/' . $foto)) $foto = '';
            $previewsByAct[$aid][] = ['nome' => $p['nome'], 'foto' => $foto];
        }
    }
    $stmtP->close();
}

// 5. Montar $activitiesByDay com os dados enriquecidos
$activitiesByDay = [];

// Session-based group filter (from meus_grupos.php)
$filtroGrupos = $_SESSION['filtro_grupos'] ?? null; // null = todos ativos

foreach ($rawActivities as $row) {
    // Aplicar filtro de grupo da sessão (admins veem tudo)
    if (!$isAdmin && $filtroGrupos !== null) {
        $grupoIds = $row['grupo_ids'] ?? '';
        $isGeneral = ($grupoIds === '' || $grupoIds === null);
        if (!$isGeneral) {
            $eGrupos  = array_map('intval', explode(',', $grupoIds));
            $hasActive = !empty(array_intersect($eGrupos, $filtroGrupos));
            if (!$hasActive) continue;
        }
    }

    $aid = (int)$row['id'];
    $row['total_inscritos']        = $countsByAct[$aid] ?? 0;
    $row['preview_inscritos_array'] = $previewsByAct[$aid] ?? [];

    $day = (int)date('d', strtotime($row['data_inicio']));
    $activitiesByDay[$day][] = $row;
}

// 4. Fetch Birthdays
$sqlB = "SELECT nome, data_nascimento, foto_perfil FROM usuarios WHERE paroquia_id = ? AND MONTH(data_nascimento) = ? AND ativo = 1";
$stmtB = $conn->prepare($sqlB);
$stmtB->bind_param('ii', $pid, $month);
$stmtB->execute();
$resB = $stmtB->get_result();
while ($u = $resB->fetch_assoc()) {
    $birthYear = (int)date('Y', strtotime($u['data_nascimento']));
    if ($year < $birthYear) {
        continue; // só mostra a partir do ano de nascimento
    }
    $day = (int)date('d', strtotime($u['data_nascimento']));
    if ($day < 1 || $day > $daysInMonth) {
        continue; // ex: 29/02 em ano não-bissexto
    }
    $nameParts = preg_split('/\s+/', trim((string)$u['nome'])) ?: [];
    $firstName = $nameParts[0] ?? '';
    $lastName = count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : '';
    $displayName = trim($firstName . ' ' . $lastName);
    if ($displayName === '') {
        $displayName = $firstName;
    }
    $shortName = mb_substr($displayName, 0, 12);
    $bdayAct = [
        'is_birthday' => true,
        'nome' => "Aniv. {$shortName}",
        'nome_completo' => (string)$u['nome'],
        'foto_perfil' => (string)($u['foto_perfil'] ?? '')
    ];
    if (!isset($activitiesByDay[$day])) $activitiesByDay[$day] = [];
    array_unshift($activitiesByDay[$day], $bdayAct);
}

// 5. Catholic Holidays
$holidays = [
    '01-01' => 'Santa Maria',
    '10-12' => 'Nossa Sra. Aparecida',
    '11-02' => 'Finados',
    '12-25' => 'Natal do Senhor',
];

if (function_exists('easter_days')) {
    $easterDays = easter_days($year);
    $easterTimestamp = strtotime("$year-03-21 +$easterDays days");
    $holidays[date('m-d', $easterTimestamp)] = 'Páscoa';
    $holidays[date('m-d', strtotime('-2 days', $easterTimestamp))] = 'Sexta-feira Santa';
    $holidays[date('m-d', strtotime('+60 days', $easterTimestamp))] = 'Corpus Christi';
    $holidays[date('m-d', strtotime('-7 days', $easterTimestamp))] = 'Domingo de Ramos';
}

foreach ($holidays as $mmdd => $hName) {
    list($hM, $hD) = explode('-', $mmdd);
    if ((int)$hM === $month) {
        $day = (int)$hD;
        $hAct = [
            'is_holiday' => true,
            'nome' => $hName
        ];
        if (!isset($activitiesByDay[$day])) $activitiesByDay[$day] = [];
        array_unshift($activitiesByDay[$day], $hAct);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Calendário Paroquial – PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.5.1">
    <link rel="stylesheet" href="css/responsive.css?v=2.5.1">
    <link rel="stylesheet" href="css/calendar.css?v=2.5.2">

</head>
<body>
    <div class="bg-mesh"></div>

    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <?php if (!empty($unreadNotifications)): ?>
                <div class="notifications-overlay animate-in" id="notifOverlay" style="position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 2rem;">
                    <div class="glass" style="width: min(500px, 100%); padding: 2.5rem; border-radius: 24px; border: 1px solid var(--border); text-align: center;">
                        <div style="width: 60px; height: 60px; background: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; color: #fff;">
                            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        </div>
                        <h2 class="gradient-text" style="font-size: 1.8rem; font-weight: 900; margin-bottom: 1rem;">Notificações</h2>
                        <div style="max-height: 300px; overflow-y: auto; margin-bottom: 2rem; display: grid; gap: 1rem; padding-right: 0.5rem; text-align: left;">
                            <?php foreach ($unreadNotifications as $n): ?>
                                <div style="background: rgba(255,255,255,0.03); padding: 1.2rem; border-radius: 16px; border: 1px solid var(--border); font-size: 0.9rem; line-height: 1.5;">
                                    <?= h($n['mensagem']) ?>
                                    <div style="font-size: 0.7rem; color: var(--text-ghost); margin-top: 0.5rem;"><?= date('d/m/Y H:i', strtotime($n['data_criacao'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="clearNotifForm">
                            <input type="hidden" name="action" value="clear_notifications">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="btn btn-primary shimmer" style="width: 100%;">Entendido, fechar</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($msg && strpos(strtolower($msg), 'contexto') === false): ?>
                <div class="animate-in status-alert" style="margin-bottom: 1rem;"><?= alert('success', h($msg)) ?></div>
            <?php endif; ?>
                        <header class="calendar-header animate-in">
                <button class="menu-trigger inline hide-on-desktop" onclick="toggleSidebar()"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg></button>

                <div class="month-display">
                    <h1 class="gradient-text" style="background: linear-gradient(to bottom, var(--primary), var(--accent)); -webkit-background-clip: text;"><?= $displayMonth ?></h1>
                    <?php if ($msg && strpos(strtolower($msg), 'contexto') !== false): ?>
                        <span id="ctxMsg" class="context-msg animate-in"><?= h($msg) ?></span>
                    <?php endif; ?>
                    <span class="year-label"><?= $year ?></span>
                    
                </div>

                <div class="nav-controls">
                    <a href="?m=<?= $month-1 ?>&y=<?= $year ?>" class="btn btn-ghost" style="padding: 0.70rem;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="15 18 9 12 15 6"/></svg></a>
                    <a href="?m=<?= date('n') ?>&y=<?= date('Y') ?>" class="btn btn-ghost" style="padding: 0.70rem 1.2rem; font-weight: 800; font-size: 0.75rem; letter-spacing: 0.05em;">HOJE</a>
                    <a href="?m=<?= $month+1 ?>&y=<?= $year ?>" class="btn btn-ghost" style="padding: 0.70rem;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="9 18 15 12 9 6"/></svg></a>
                </div>
            </header>

            <div class="calendar-container animate-in" style="animation-delay: 0.1s;">
                <div class="cal-grid">
                    <?php 
                    $weekdays = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
                    foreach ($weekdays as $i => $day): ?>
                        <div class="cal-weekday <?= $i === 0 ? 'sunday' : '' ?>"><?= $day ?></div>
                    <?php endforeach; ?>

                    <?php 
                    // Empty cells before start
                    for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                        <div class="cal-day empty"></div>
                    <?php endfor;

                    // Days of the month
                    for ($dayIdx = 1; $dayIdx <= $daysInMonth; $dayIdx++): 
                        $currentDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($dayIdx, 2, '0', STR_PAD_LEFT);
                        $isSunday = (date('w', strtotime($currentDate)) == 0);
                        $isToday = ($currentDate === $today);
                        ?>
                        <div class="cal-day <?= $isSunday ? 'sunday' : '' ?> <?= $isToday ? 'today' : '' ?>">
                            <div class="day-number">
                                <?= $dayIdx ?>
                                <span class="mobile-day-initial"><?= ['D','S','T','Q','Q','S','S'][(int)date('w', strtotime($currentDate))] ?></span>
                            </div>
                            
                            <div class="activities-list">
                                <?php if (isset($activitiesByDay[$dayIdx])): ?>
                                    <?php foreach ($activitiesByDay[$dayIdx] as $act): ?>
                                        <?php if (!empty($act['is_birthday'])): ?>
                                                <div class="act-pill act-birthday bday-trigger" 
                                                     data-full-name="<?= h($act['nome_completo']) ?>"
                                                     title="<?= h($act['nome_completo']) ?>">
                                                    <?php if (!empty($act['foto_perfil']) && file_exists(__DIR__ . '/' . $act['foto_perfil'])): ?>
                                                        <img class="bday-photo" src="<?= h($act['foto_perfil']) ?>?v=<?= time() ?>" alt="Foto">
                                                    <?php else: ?>
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v2"/><path d="M12 8v2"/><path d="M17 8v2"/><path d="M7 4h.01"/><path d="M12 4h.01"/><path d="M17 4h.01"/></svg>
                                                    <?php endif; ?>
                                                    <strong style="font-weight: 700; font-size: 0.55rem;"><?= h($act['nome']) ?></strong>
                                                </div>
                                        <?php elseif (!empty($act['is_holiday'])): ?>
                                            <div class="act-pill act-holiday" style="pointer-events: none;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/></svg>
                                                <strong style="font-weight: 800;"><?= h($act['nome']) ?></strong>
                                            </div>
                                        <?php else: ?>
                                            <?php if ($canInteractActivities): ?>
                                            <?php
                                                $pillClasses = "act-pill button-reset event-trigger";
                                                if (!empty($act['is_multi_color'])) $pillClasses .= " is-multi";
                                                if (!empty($act['is_flashing'])) $pillClasses .= " is-flashing";
                                                
                                                $pillStyle = "border-left: 3px solid " . ($act['cor'] ?: 'var(--primary)') . ";";
                                                if (!empty($act['is_multi_color'])) {
                                                    $pillStyle .= " background: linear-gradient(90deg, " . ($act['cor'] ?: '#8b5cf6') . "22, rgba(255,255,255,0.02)) !important;";
                                                }
                                            ?>
                                            <button
                                                    type="button"
                                                    class="<?= $pillClasses ?>"
                                                    data-activity-id="<?= (int)$act['id'] ?>"
                                                    style="<?= $pillStyle ?>"
                                                >
                                                    <span style="opacity: 0.6;"><?= substr($act['hora_inicio'] ?? '', 0, 5) ?></span>
                                                    <strong class="act-name"><?= h($act['nome']) ?></strong>
                                                    <span class="act-count"><?= (int)($act['total_inscritos'] ?? 0) ?></span>
                                                </button>
                                                <?php
                                                    $previewArr = $act['preview_inscritos_array'] ?? [];
                                                    $enTotal = (int)($act['total_inscritos'] ?? 0);
                                                    $hasInscritos = (count($previewArr) > 0);
                                                    $firstNameDisp = '?';
                                                    if ($hasInscritos) {
                                                        $enParts = preg_split('/\s+/', trim($previewArr[0]['nome'])) ?: [];
                                                        $enFirst = $enParts[0] ?? $previewArr[0]['nome'];
                                                        $enLast = count($enParts) > 1 ? $enParts[count($enParts) - 1] : '';
                                                        $firstNameDisp = mb_substr(trim($enFirst . ' ' . $enLast), 0, 12) ?: '?';
                                                    }
                                                    $enMore = max(0, $enTotal - count($previewArr));
                                                ?>
                                                <div class="enroll-preview" <?= ($enTotal > 0 && $hasInscritos) ? '' : 'hidden' ?>>
                                                    <div style="display: flex; align-items: center;">
                                                        <?php foreach ($previewArr as $i => $u): ?>
                                                            <?php if ($u['foto'] !== '' && file_exists(__DIR__ . '/' . $u['foto'])): ?>
                                                                <img class="enroll-avatar-img" src="<?= h($u['foto']) ?>?v=<?= time() ?>" alt="Foto" title="<?= h($u['nome']) ?>">
                                                            <?php else: ?>
                                                                <div class="enroll-avatar" title="<?= h($u['nome']) ?>"><?= mb_strtoupper(mb_substr($u['nome'] ?: '?', 0, 1)) ?></div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <span class="enroll-name" style="margin-left: 0.3rem;"><?= h($firstNameDisp) ?></span>
                                                    <?php if ($enMore > 0): ?><span class="enroll-more">+<?= $enMore ?></span><?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <?php
                                                    $pillClasses = "act-pill";
                                                    if (!empty($act['is_multi_color'])) $pillClasses .= " is-multi";
                                                    if (!empty($act['is_flashing'])) $pillClasses .= " is-flashing";
                                                    
                                                    $pillStyle = "border-left: 3px solid " . ($act['cor'] ?: 'var(--primary)') . ";";
                                                    if (!empty($act['is_multi_color'])) {
                                                        $pillStyle .= " background: linear-gradient(90deg, " . ($act['cor'] ?: '#8b5cf6') . "22, rgba(255,255,255,0.02)) !important;";
                                                    }
                                                ?>
                                                <a href="ver_atividade.php?id=<?= $act['id'] ?>" class="<?= $pillClasses ?>" style="<?= $pillStyle ?>">
                                                    <span style="opacity: 0.6;"><?= substr($act['hora_inicio'] ?? '', 0, 5) ?></span>
                                                    <strong class="act-name"><?= h($act['nome']) ?></strong>
                                                    <span class="act-count"><?= (int)($act['total_inscritos'] ?? 0) ?></span>
                                                </a>
                                                <?php
                                                    $previewArr = $act['preview_inscritos_array'] ?? [];
                                                    $enTotal = (int)($act['total_inscritos'] ?? 0);
                                                    $hasInscritos = (count($previewArr) > 0);
                                                    $firstNameDisp = '?';
                                                    if ($hasInscritos) {
                                                        $enParts = preg_split('/\s+/', trim($previewArr[0]['nome'])) ?: [];
                                                        $enFirst = $enParts[0] ?? $previewArr[0]['nome'];
                                                        $enLast = count($enParts) > 1 ? $enParts[count($enParts) - 1] : '';
                                                        $firstNameDisp = mb_substr(trim($enFirst . ' ' . $enLast), 0, 12) ?: '?';
                                                    }
                                                    $enMore = max(0, $enTotal - count($previewArr));
                                                ?>
                                                <div class="enroll-preview" <?= ($enTotal > 0 && $hasInscritos) ? '' : 'hidden' ?>>
                                                    <div style="display: flex; align-items: center;">
                                                        <?php foreach ($previewArr as $i => $u): ?>
                                                            <?php if ($u['foto'] !== '' && file_exists(__DIR__ . '/' . $u['foto'])): ?>
                                                                <img class="enroll-avatar-img" src="<?= h($u['foto']) ?>?v=<?= time() ?>" alt="Foto" title="<?= h($u['nome']) ?>">
                                                            <?php else: ?>
                                                                <div class="enroll-avatar" title="<?= h($u['nome']) ?>"><?= mb_strtoupper(mb_substr($u['nome'] ?: '?', 0, 1)) ?></div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <span class="enroll-name" style="margin-left: 0.3rem;"><?= h($firstNameDisp) ?></span>
                                                    <?php if ($enMore > 0): ?><span class="enroll-more">+<?= $enMore ?></span><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Move FAB inside a check that is less restrictive for Master users -->
    <?php if (can('criar_eventos') || $_SESSION['usuario_id'] == 1): ?>
    <a href="novaatividade.php" class="fab shimmer" title="Adicionar Atividade">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
    </a>
    <?php endif; ?>

    <div class="event-modal-backdrop" id="eventModalBackdrop">
        <div class="event-modal" role="dialog" aria-modal="true" aria-labelledby="eventModalTitle">
            <div class="event-modal-header">
                <div>
                    <div id="eventModalType" class="type-badge" style="background: var(--panel-hi);">Evento</div>
                    <h2 id="eventModalTitle" class="event-modal-title">Carregando...</h2>
                    <div class="event-meta">
                        <span id="eventModalDate"></span>
                        <span id="eventModalLocation"></span>
                    </div>
                </div>
                <button type="button" class="btn btn-ghost" id="closeEventModal">Fechar</button>
            </div>

            <div class="event-modal-content-scroll">
                <p id="eventModalDescription" style="color: var(--text); line-height: 1.6; margin: 0;"></p>

                <div id="eventModalItemsWrap" class="event-items-wrap">
                    <strong style="font-size: 0.85rem;">Atividades do evento</strong>
                    <div id="eventModalItems" class="event-items-list"></div>
                </div>

                <div id="eventModalParticipantsWrap" style="margin-top: 1rem;">
                    <strong style="font-size: 0.85rem;">Participantes</strong>
                    <div id="eventModalParticipants" class="participant-chips"></div>
                </div>

                <div id="eventModalNote" class="modal-note"></div>
                <div id="eventModalFeedback" class="modal-feedback"></div>
            </div>

            <div class="event-modal-actions">
                <button type="button" class="btn btn-primary shimmer" id="eventJoinButton" style="display:none;">Inscrever-me</button>
                <button type="button" class="btn btn-ghost" id="eventLeaveButton" style="display:none;">Desistir</button>
                <a href="#" class="btn btn-ghost" id="eventViewButton">Ver detalhes</a>
            </div>
        </div>
    </div>

    <!-- Modal de Aniversário -->
    <div class="bday-modal-backdrop" id="bdayModalBackdrop">
        <div class="bday-modal">
            <div class="bday-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v2"/><path d="M12 8v2"/><path d="M17 8v2"/><path d="M7 4h.01"/><path d="M12 4h.01"/><path d="M17 4h.01"/></svg>
            </div>
            <div class="bday-info">
                <h2>Aniversariante do Dia</h2>
                <p id="bdayModalName">Nome do Usuário</p>
            </div>
            <div class="bday-actions">
                <button type="button" class="btn btn-primary shimmer" style="width: 100%;" id="closeBdayModal">SAIR</button>
            </div>
        </div>
    </div>

    <!-- Global Data for JS -->
    <script>
        window.CSRF_TOKEN = '<?= csrf_token() ?>';
        window.AUTO_REFRESH = <?= $autoRefresh ? 'true' : 'false' ?>;
        window.MSG_SUCCESS_ALERT = <?= json_encode(alert('success', '__MSG__')) ?>;
        window.MSG_ERROR_ALERT   = <?= json_encode(alert('error', '__MSG__')) ?>;
        window.MSG_INFO_ALERT    = <?= json_encode(alert('info', '__MSG__')) ?>;
    </script>
    <script src="js/calendar.js?v=2.5.2"></script>

</body>
</html>
