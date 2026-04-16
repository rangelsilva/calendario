<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Activity Edition (v2.0)
 * Glassmorphism Design · Real-time Feedback · CRUD
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requirePerm('editar_eventos');

$pid = current_paroquia_id();
$id = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';
ensureEventActivitiesStructure($conn);
seedDefaultEventActivities($conn, $pid);

// 1. Fetch Current Data
$stmt = $conn->prepare("SELECT * FROM atividades WHERE id = ? AND paroquia_id = ? LIMIT 1");
$stmt->bind_param('ii', $id, $pid);
$stmt->execute();
$activity = $stmt->get_result()->fetch_assoc();

if (!$activity) {
    header('Location: atividades.php?error=not_found');
    exit();
}

if ($activity['restrito']) {
    $userId = (int)($_SESSION['usuario_id'] ?? 0);
    if (!can('ver_restritos') && $activity['criador_id'] != $userId) {
        header('Location: atividades.php?error=unauthorized_restricted');
        exit();
    }
}

$existingEventActivities = array_map(
    static fn(array $item): int => (int)$item['atividade_catalogo_id'],
    getEventActivityItems($conn, $id, (int)($_SESSION['usuario_id'] ?? 0))
);
$selectedActivities = normalizeEventActivityCatalogIds($_POST['atividades_evento'] ?? $existingEventActivities);

$eventGroups = getActivityGroups($conn, $id);

// 2. Handle Update Submission

// 2. Handle Update Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $data = sanitize_post($_POST);
    
    if (empty($data['nome']) || empty($data['data_inicio'])) {
        $error = 'Nome e data de início são obrigatórios.';
    } else {
        $cor = trim($data['cor'] ?? '#3b82f6');
        $is_multi = isset($data['is_multi_color']) ? 1 : 0;
        $is_flash = isset($data['is_flashing']) ? 1 : 0;
        $restrito = isset($data['restrito']) ? 1 : 0;
        $hora_inicio = !empty($data['hora_inicio']) ? $data['hora_inicio'] : null;

        $sql = "UPDATE atividades SET 
                nome = ?, local_id = ?, tipo_atividade_id = ?, 
                descricao = ?, data_inicio = ?, hora_inicio = ?, 
                restrito = ?, cor = ?, is_multi_color = ?, is_flashing = ?
                WHERE id = ? AND paroquia_id = ?";
        
        $local = !empty($data['local_id']) ? (int)$data['local_id'] : null;
        $tipo = !empty($data['tipo_id']) ? (int)$data['tipo_id'] : null;

        // Detectar se houve mudança crítica para notificar participantes
        $currentH = formatTime($activity['hora_inicio']);
        $newH = formatTime($hora_inicio);
        
        $forceReset = (
            $data['data_inicio'] !== $activity['data_inicio'] ||
            $newH !== $currentH ||
            $local !== (isset($activity['local_id']) ? (int)$activity['local_id'] : null)
        );

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('siisssisiiii', 
            $data['nome'], $local, $tipo, 
            $data['descricao'], $data['data_inicio'], $hora_inicio, 
            $restrito, $cor, $is_multi, $is_flash, $id, $pid
        );
        
        if ($stmt->execute()) {
            saveEventActivityItems($conn, $id, $pid, $data['atividades_evento'] ?? [], $forceReset);
            saveActivityGroups($conn, $id, $data['grupos_evento'] ?? []);
            $eM = (int)date('n', strtotime($data['data_inicio']));
            $eY = (int)date('Y', strtotime($data['data_inicio']));
            header("Location: index.php?m={$eM}&y={$eY}&refresh=1");
            exit();
        } else {
            $error = 'Erro ao atualizar: ' . $conn->error;
        }
    }
}

// 3. Fetch Helper Data
$locais = db_query($conn, "SELECT id, nome_local FROM locais_paroquia WHERE paroquia_id = ? ORDER BY nome_local", [$pid]);
$tipos  = db_query($conn, "SELECT id, nome_tipo FROM tipos_atividade WHERE paroquia_id = ? ORDER BY nome_tipo", [$pid]);
$catalogoAtividades = getEventActivityCatalog($conn, $pid);
$activityOptionMap = [];
foreach ($catalogoAtividades as $catalogoItem) {
    $activityOptionMap[] = [
        'id' => (int)$catalogoItem['id'],
        'nome' => $catalogoItem['nome'],
    ];
}

$gruposTrabalhoRaw = getWorkingGroups($conn, $pid);
$userGroups = getUserGroups($conn, (int)($_SESSION['usuario_id'] ?? 0));
$isAdmin = can('admin_sistema') || ((int)($_SESSION['usuario_nivel'] ?? 99) === 0);

$gruposTrabalho = [];
foreach ($gruposTrabalhoRaw as $g) {
    if ($isAdmin || in_array((int)$g['id'], $userGroups, true)) {
        $gruposTrabalho[] = $g;
    }
}

if (!$selectedActivities) {
    $selectedActivities = [0];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Editar Atividade – PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; display: flex; justify-content: center; align-items: flex-start; }
        
        .form-container { width: 100%; max-width: 650px; margin-top: 2rem; }
        .form-header { margin-bottom: 3rem; text-align: center; }
        .form-header h1 { font-size: 2.2rem; font-weight: 900; }

        .glass-form { padding: 3.5rem; border-radius: var(--r-lg); }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.8rem; }
        .form-grid > .form-group { grid-column: span 1; }
        .form-grid > .full-width { grid-column: span 2; }
        
        .form-group { display: flex; flex-direction: column; gap: 0.7rem; }
        .form-group label { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: var(--text-ghost); letter-spacing: 0.1em; }
        
        .event-activities-box { display: grid; gap: 0.9rem; }
        .event-activity-row { display: flex; gap: 0.75rem; align-items: center; }
        select {
            background: rgba(255, 255, 255, 0.03) !important;
            backdrop-filter: blur(10px);
            border: 1px solid var(--border) !important;
            color: #fff !important;
            padding: 1.1rem !important;
            border-radius: 16px !important;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 1.2rem center !important;
        }
        select option {
            background: #05060f !important;
            color: #fff !important;
        }

        /* ── Event Style Enhancements ───────────────────────── */
        .color-palette { display: flex; flex-wrap: wrap; gap: 0.8rem; margin-top: 0.5rem; }
        .color-opt { 
            width: 32px; height: 32px; border-radius: 50%; cursor: pointer; 
            border: 2px solid transparent; transition: all 0.2s;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .color-opt:hover { transform: scale(1.15); }
        .color-opt.active { border-color: #fff; transform: scale(1.2); box-shadow: 0 0 15px rgba(255,255,255,0.4); }

        .style-toggles { display: flex; gap: 1.5rem; margin-top: 1rem; }
        .style-toggle { display: flex; align-items: center; gap: 0.6rem; cursor: pointer; font-size: 0.8rem; font-weight: 700; color: var(--text-dim); }
        .style-toggle input { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary); }
        
        .preview-box { 
            margin-top: 2rem; padding: 1.5rem; border-radius: 16px; 
            background: rgba(255,255,255,0.03); border: 1px dashed var(--border); 
            display: flex; flex-direction: column; gap: 0.8rem;
        }
        .preview-label { font-size: 0.65rem; font-weight: 800; color: var(--text-ghost); text-transform: uppercase; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 1.5rem; }
            .glass-form { padding: 2rem; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid > .full-width { grid-column: span 1; }
        }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>

    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <div class="form-container animate-in">
                <header class="form-header">
                    <p style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.2em; color: var(--primary);">MODIFICAÇÃO</p>
                    <h1 class="gradient-text">Editar Atividade</h1>
                </header>

                <?php if ($error): ?>
                    <?= alert('error', h($error)) ?>
                <?php endif; ?>

                <form method="POST" class="glass glass-form">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Identificação do Evento</label>
                            <input type="text" name="nome" value="<?= h($activity['nome']) ?>" required autofocus>
                        </div>

                        <div class="form-group full-width">
                            <label>Estilo Visual do Evento</label>
                            <div class="color-palette" id="colorPalette">
                                <?php 
                                    $presets = ['#f8fafc', '#dc2626', '#16a34a', '#7e22ce', '#f472b6', '#0f172a', '#2563eb', '#f59e0b', '#0ea5e9', '#78350f', '#ea580c', '#64748b'];
                                    foreach($presets as $p): 
                                ?>
                                    <div class="color-opt" style="background: <?= $p ?>" data-color="<?= $p ?>"></div>
                                <?php endforeach; ?>
                                <input type="color" name="cor" id="customColor" value="<?= h($activity['cor'] ?? '#3b82f6') ?>" style="width:32px; height:32px; padding:0; border:none; background:transparent; cursor:pointer;" title="Cor Personalizada">
                            </div>

                            <div class="style-toggles">
                                <label class="style-toggle">
                                    <input type="checkbox" name="is_multi_color" id="isMulti" <?= $activity['is_multi_color'] ? 'checked' : '' ?>>
                                    Gradiente Vibrante
                                </label>
                                <label class="style-toggle">
                                    <input type="checkbox" name="is_flashing" id="isFlash" <?= $activity['is_flashing'] ? 'checked' : '' ?>>
                                    Destaque Pulsante
                                </label>
                            </div>

                            <div class="preview-box">
                                <span class="preview-label">Pré-visualização no Calendário</span>
                                <div id="previewPill" class="act-pill" style="border-left: 4px solid #3b82f6; width: fit-content; pointer-events: none;">
                                    <span style="opacity: 0.6;"><?= substr($activity['hora_inicio'], 0, 5) ?></span>
                                    <strong id="previewName" style="font-weight: 800;"><?= h($activity['nome']) ?></strong>
                                </div>
                            </div>
                        </div>


                            <div class="form-group">
                                <label>Data de Início</label>
                                <input type="date" name="data_inicio" value="<?= h($activity['data_inicio']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Horário</label>
                                <input type="time" name="hora_inicio" value="<?= formatTime($activity['hora_inicio'] ?? '') ?>">
                            </div>



                            <div class="form-group">
                                <label>Localização</label>
                                <select name="local_id">
                                    <option value="">Selecione um local</option>
                                    <?php while ($l = $locais->fetch_assoc()): ?>
                                        <option value="<?= $l['id'] ?>" <?= $l['id'] == $activity['local_id'] ? 'selected' : '' ?>>
                                            <?= h($l['nome_local']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Categoria</label>
                                <select name="tipo_id">
                                    <option value="">Selecione o tipo</option>
                                    <?php while ($t = $tipos->fetch_assoc()): ?>
                                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $activity['tipo_atividade_id'] ? 'selected' : '' ?>>
                                            <?= h($t['nome_tipo']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>


                        <div class="form-group full-width">
                            <label>Notas Adicionais</label>
                            <textarea name="descricao" rows="4"><?= h($activity['descricao']) ?></textarea>
                        </div>

                        <?php if (can('ver_restritos')): ?>
                        <div class="form-group full-width" style="margin-top: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.8rem; background: rgba(239, 68, 68, 0.05); padding: 1rem; border-radius: 12px; border: 1px solid rgba(239, 68, 68, 0.1); width: fit-content;">
                                <input type="checkbox" name="restrito" id="restrito" style="width: 20px; height: 20px; cursor: pointer;" <?= $activity['restrito'] ? 'checked' : '' ?>>
                                <label for="restrito" style="margin: 0; cursor: pointer; color: #ef4444;">Evento Restrito</label>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-group full-width">
                            <label>Participação por Grupos de Trabalho</label>
                            <div class="groups-checkbox-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.8rem; background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border);">
                                <?php if (empty($gruposTrabalho)): ?>
                                    <p style="color: var(--text-dim); font-size: 0.8rem; margin: 0;">Você não possui grupos disponíveis para seleção.</p>
                                <?php else: ?>
                                    <?php foreach ($gruposTrabalho as $grp): ?>
                                        <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer; font-size: 0.85rem; color: var(--text); padding: 0.4rem; border-radius: 8px; transition: background 0.2s;">
                                            <input type="checkbox" name="grupos_evento[]" value="<?= $grp['id'] ?>" style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer;" <?= in_array((int)$grp['id'], $eventGroups, true) ? 'checked' : '' ?>>
                                            <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:<?= $grp['cor'] ?? '#fff' ?>;"></span>
                                            <?= h($grp['nome']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <small style="color: var(--text-dim); font-size: 0.75rem; margin-top: 0.5rem; display: block;">Se nenhum grupo for selecionado, o evento ficará visível para **todos** na paróquia.</small>
                        </div>

                        <div class="form-group full-width">
                            <label>Atividades do Evento</label>
                            <div id="eventActivitiesBox" class="event-activities-box"></div>
                            <button type="button" id="addEventActivity" class="btn btn-ghost" style="width: fit-content;">+ Adicionar atividade</button>
                            <div class="event-activities-help">
                                Clique em “+” para adicionar outra atividade ao mesmo evento. Na edição você também pode trocar ou remover as já vinculadas.
                            </div>
                        </div>

                        <div class="form-actions full-width">
                            <button type="submit" class="btn btn-primary shimmer">Salvar Alterações</button>
                            <a href="index.php" class="btn btn-ghost">Cancelar</a>
                            <form method="POST" action="excluir_atividade.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="btn btn-ghost" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.2);"
                                    onclick="return confirmForm(this, 'Tem certeza que deseja excluir permanentemente este evento?')"
                                    data-btn-label="Excluir" data-btn-color="#ef4444">
                                    Excluir
                                </button>
                            </form>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
    <script>
        (() => {
            // ── Event Style Preview ──────────────────────────
            const palette = document.getElementById('colorPalette');
            const customColor = document.getElementById('customColor');
            const isMulti = document.getElementById('isMulti');
            const isFlash = document.getElementById('isFlash');
            const previewPill = document.getElementById('previewPill');
            const previewName = document.getElementById('previewName');
            const nameInput = document.querySelector('input[name="nome"]');

            function updatePreview() {
                const color = customColor.value;
                previewPill.style.borderLeftColor = color;
                previewName.textContent = nameInput.value || 'Nome do Evento';
                
                previewPill.classList.toggle('is-multi', isMulti.checked);
                previewPill.classList.toggle('is-flashing', isFlash.checked);
                
                if (isMulti.checked) {
                    previewPill.style.background = `linear-gradient(90deg, ${color}22, rgba(255,255,255,0.02))`;
                } else {
                    previewPill.style.background = '';
                }
            }

            palette.querySelectorAll('.color-opt').forEach(opt => {
                opt.addEventListener('click', () => {
                    palette.querySelectorAll('.color-opt').forEach(o => o.classList.remove('active'));
                    opt.classList.add('active');
                    customColor.value = opt.dataset.color;
                    updatePreview();
                });
            });

            [customColor, isMulti, isFlash, nameInput].forEach(el => {
                el.addEventListener('input', updatePreview);
            });

            // Set initial active
            const initial = palette.querySelector(`[data-color="${customColor.value}"]`);
            if (initial) initial.classList.add('active');
            updatePreview();

            // ── Activity Rows ────────────────────────────────
            const box = document.getElementById('eventActivitiesBox');
            const addButton = document.getElementById('addEventActivity');
            const options = <?= json_encode($activityOptionMap, JSON_UNESCAPED_UNICODE) ?>;
            const initialValues = <?= json_encode($selectedActivities) ?>;

            function buildOptions(selectedValue) {
                const base = ['<option value="">Selecione uma atividade</option>'];
                options.forEach((item) => {
                    const selected = Number(selectedValue) === Number(item.id) ? ' selected' : '';
                    base.push(`<option value="${item.id}"${selected}>${item.nome}</option>`);
                });
                return base.join('');
            }

            function addRow(selectedValue = '') {
                const row = document.createElement('div');
                row.className = 'event-activity-row';
                row.innerHTML = `
                    <select name="atividades_evento[]">${buildOptions(selectedValue)}</select>
                    <button type="button" class="btn btn-ghost event-activity-remove" title="Remover atividade">×</button>
                `;
                row.querySelector('.event-activity-remove').addEventListener('click', () => {
                    row.remove();
                    if (!box.children.length) {
                        addRow('');
                    }
                });
                box.appendChild(row);
            }

            addButton.addEventListener('click', () => addRow(''));

            if (initialValues.length) {
                initialValues.forEach((value) => addRow(value));
            } else {
                addRow('');
            }
        })();
    </script>
</body>
</html>
