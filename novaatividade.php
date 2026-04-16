<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — Activity Creation (v2.0)
 * Modern Forms · Glassmorphism Design · Secure Logic
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requirePerm('criar_eventos');

$pid = current_paroquia_id();
$error = '';
$data_pref = $_GET['data'] ?? date('Y-m-d');
ensureEventActivitiesStructure($conn);
ensureAtividadeGruposTable($conn);
seedDefaultEventActivities($conn, $pid);
$catalogoAtividades = getEventActivityCatalog($conn, $pid);
$selectedActivities = normalizeEventActivityCatalogIds($_POST['atividades_evento'] ?? []);

// 1. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $data = sanitize_post($_POST);
    
    if (empty($data['nome']) || empty($data['data_inicio'])) {
        $error = 'Nome e data de início são obrigatórios.';
    } else {
        $local = !empty($data['local_id']) ? (int)$data['local_id'] : null;
        $tipo = !empty($data['tipo_id']) ? (int)$data['tipo_id'] : null;
        $restrito = isset($data['restrito']) ? 1 : 0;
        $uid = $_SESSION['usuario_id'];
        $cor = trim($data['cor'] ?? '#3b82f6');
        $is_multi = isset($data['is_multi_color']) ? 1 : 0;
        $is_flash = isset($data['is_flashing']) ? 1 : 0;
        $hora_inicio = !empty($data['hora_inicio']) ? $data['hora_inicio'] : null;
        
        $sql = "INSERT INTO atividades (nome, paroquia_id, local_id, tipo_atividade_id, descricao, data_inicio, hora_inicio, criador_id, restrito, cor, is_multi_color, is_flashing) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('siiisssiisii', $data['nome'], $pid, $local, $tipo, $data['descricao'], $data['data_inicio'], $hora_inicio, $uid, $restrito, $cor, $is_multi, $is_flash);
        
        if ($stmt->execute()) {
            $newEventId = (int)$conn->insert_id;
            saveEventActivityItems($conn, $newEventId, $pid, $data['atividades_evento'] ?? []);
            saveActivityGroups($conn, $newEventId, $data['grupos_evento'] ?? []);
            
            // Recurrence Handling
            if (isset($data['se_repete']) && !empty($data['data_fim_recorrencia'])) {
                $iterDate = new DateTime($data['data_inicio']);
                $endDate = new DateTime($data['data_fim_recorrencia']);
                $freq = $data['frequencia'] ?? 'semanal';
                $mensalTipo = $data['mensal_tipo'] ?? 'dia_mes';
                
                $originalDayNum = (int)$iterDate->format('d');
                $dayOfWeek = $iterDate->format('l');
                $weekOfMonth = ceil($originalDayNum / 7);
                $ordinalStrings = ['first', 'second', 'third', 'fourth', 'fifth'];
                $ordinal = $ordinalStrings[min(4, $weekOfMonth - 1)];

                $count = 0;
                while ($count < 100) {
                    if ($freq === 'diario') $iterDate->modify('+1 day');
                    elseif ($freq === 'semanal') $iterDate->modify('+1 week');
                    elseif ($freq === 'quinzenal') $iterDate->modify('+2 weeks');
                    elseif ($freq === 'anual') $iterDate->modify('+1 year');
                    elseif ($freq === 'mensal') {
                        if ($mensalTipo === 'dia_mes') {
                            $iterDate->modify('+1 month');
                        } else {
                            $iterDate->modify("$ordinal $dayOfWeek of next month");
                        }
                    }
                    if ($iterDate > $endDate) break;
                    
                    $newDateStr = $iterDate->format('Y-m-d');
                    $stRepeat = $conn->prepare($sql);
                    $stRepeat->bind_param('siiisssiisii', $data['nome'], $pid, $local, $tipo, $data['descricao'], $newDateStr, $hora_inicio, $uid, $restrito, $cor, $is_multi, $is_flash);
                    if ($stRepeat->execute()) {
                        $repeatId = (int)$conn->insert_id;
                        saveEventActivityItems($conn, $repeatId, $pid, $data['atividades_evento'] ?? []);
                        saveActivityGroups($conn, $repeatId, $data['grupos_evento'] ?? []);
                    }
                    $count++;
                }
            }

            logAction($conn, 'CRIAR_ATIVIDADE', 'atividades', $newEventId, $data['nome']);
            $eventMonth = (int)date('n', strtotime($data['data_inicio']));
            $eventYear = (int)date('Y', strtotime($data['data_inicio']));
            header('Location: index.php?m=' . $eventMonth . '&y=' . $eventYear . '&msg=' . urlencode('Atividades criadas com sucesso!') . '&refresh=1');
            exit();
        } else {
            $error = 'Erro interno: ' . $conn->error;
        }
    }
}

// 2. Fetch Helper Data
$locais = db_query($conn, "SELECT id, nome_local FROM locais_paroquia WHERE paroquia_id = ? ORDER BY nome_local", [$pid]);
$tipos  = db_query($conn, "SELECT id, nome_tipo FROM tipos_atividade WHERE paroquia_id = ? ORDER BY nome_tipo", [$pid]);
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
    <title>Nova Atividade – PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5">
    <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; display: flex; justify-content: center; align-items: flex-start; }
        
        .form-container { width: 100%; max-width: 650px; margin-top: 2rem; }
        .form-header { margin-bottom: 3rem; text-align: center; }
        .form-header h1 { font-size: 2.2rem; font-weight: 900; letter-spacing: -0.02em; }

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

        /* ── Recurrence Fields ─────────────────────────────── */
        .recurrence-box { 
            grid-column: span 2; display: none; background: rgba(59, 130, 246, 0.05); 
            border: 1px solid rgba(59, 130, 246, 0.1); border-radius: 16px; padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .recurrence-box.active { display: block; }
        .recurrence-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; }
        @media (max-width: 600px) { .recurrence-grid { grid-template-columns: 1fr; } }
    </style>
    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <div class="form-container animate-in">
                <header class="form-header">
                    <p style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.2em; color: var(--primary);">CONFIGURAÇÃO</p>
                    <h1 class="gradient-text">Novo Evento</h1>
                </header>

                <?php if ($error): ?>
                    <?= alert('error', h($error)) ?>
                <?php endif; ?>

                <form method="POST" class="glass glass-form">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Identificação do Evento</label>
                            <input type="text" name="nome" placeholder="Nome da atividade ou celebração" required autofocus>
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
                                    <input type="color" name="cor" id="customColor" value="#3b82f6" style="width:32px; height:32px; padding:0; border:none; background:transparent; cursor:pointer;" title="Cor Personalizada">
                                </div>

                                <div class="style-toggles">
                                    <label class="style-toggle">
                                        <input type="checkbox" name="is_multi_color" id="isMulti">
                                        Gradiente Vibrante
                                    </label>
                                    <label class="style-toggle">
                                        <input type="checkbox" name="is_flashing" id="isFlash">
                                        Destaque Pulsante
                                    </label>
                                </div>

                                <div class="preview-box">
                                    <span class="preview-label">Pré-visualização no Calendário</span>
                                    <div id="previewPill" class="act-pill" style="border-left: 4px solid #3b82f6; width: fit-content; pointer-events: none;">
                                        <span style="opacity: 0.6;">19:00</span>
                                        <strong id="previewName" style="font-weight: 800;">Nome do Evento</strong>
                                    </div>
                                </div>
                            </div>


                            <div class="form-group">
                                <label>Data de Início</label>
                                <input type="date" name="data_inicio" value="<?= h($data_pref) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Horário</label>
                                <input type="time" name="hora_inicio">
                            </div>

                            <div class="form-group full-width">
                                <label class="style-toggle" style="color: var(--text); background: rgba(255,255,255,0.03); padding: 1rem; border-radius: 12px; border: 1px solid var(--border); margin-top: 1rem;">
                                    <input type="checkbox" name="se_repete" id="seRepete">
                                    Este evento se repete?
                                </label>
                            </div>

                            <div class="recurrence-box" id="recurrenceBox">
                                <div class="recurrence-grid">
                                    <div class="form-group">
                                        <label>Frequência</label>
                                        <select name="frequencia" id="frequencia">
                                            <option value="diario">Diário</option>
                                            <option value="semanal" selected>Semanal</option>
                                            <option value="quinzenal">Quinzenal</option>
                                            <option value="mensal">Mensal</option>
                                            <option value="anual">Anual</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Data Final (Até quando?)</label>
                                        <input type="date" name="data_fim_recorrencia" id="dataFimRecorrencia">
                                    </div>
                                    <div class="form-group full-width" id="mensalOptions" style="display: none;">
                                        <label>Tipo de Repetição Mensal</label>
                                        <div style="display: flex; gap: 2rem; margin-top: 0.5rem;">
                                            <label class="style-toggle" style="color: var(--text);">
                                                <input type="radio" name="mensal_tipo" value="dia_mes" checked>
                                                Mesmo dia do mês
                                            </label>
                                            <label class="style-toggle" style="color: var(--text);">
                                                <input type="radio" name="mensal_tipo" value="dia_semana">
                                                Mesmo dia da semana
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>



                            <div class="form-group">
                                <label>Localização</label>
                                <select name="local_id">
                                    <option value="">Selecione um local</option>
                                    <?php while ($l = $locais->fetch_assoc()): ?>
                                        <option value="<?= $l['id'] ?>"><?= h($l['nome_local']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Categoria</label>
                                <select name="tipo_id">
                                    <option value="">Selecione o tipo</option>
                                    <?php while ($t = $tipos->fetch_assoc()): ?>
                                        <option value="<?= $t['id'] ?>"><?= h($t['nome_tipo']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>


                        <div class="form-group full-width">
                            <label>Notas Adicionais</label>
                            <textarea name="descricao" rows="4" placeholder="Descreva os detalhes ou objetivos deste evento..."></textarea>
                        </div>

                        <?php if (can('ver_restritos')): ?>
                        <div class="form-group full-width" style="margin-top: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.8rem; background: rgba(239, 68, 68, 0.05); padding: 1rem; border-radius: 12px; border: 1px solid rgba(239, 68, 68, 0.1); width: fit-content;">
                                <input type="checkbox" name="restrito" id="restrito" style="width: 20px; height: 20px; cursor: pointer;">
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
                                            <input type="checkbox" name="grupos_evento[]" value="<?= $grp['id'] ?>" style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer;" <?= $grp['nome'] === 'Todos' ? 'checked' : '' ?>>
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
                                Os administradores podem vincular várias atividades ao mesmo evento. O usuário poderá se inscrever em mais de uma.
                            </div>
                        </div>

                        <div class="form-actions full-width">
                            <button type="submit" class="btn btn-primary shimmer">Criar Evento</button>
                            <a href="index.php" class="btn btn-ghost">Voltar</a>
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
                    <button type="button" class="btn btn-ghost event-activity-remove" title="Remover atividade">&times;</button>
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

            // ── Recurrence Logic ─────────────────────────────
            const seRepete = document.getElementById('seRepete');
            const recurrenceBox = document.getElementById('recurrenceBox');
            const freqSelect = document.getElementById('frequencia');
            const mensalOptions = document.getElementById('mensalOptions');
            const dataFim = document.getElementById('dataFimRecorrencia');
            const dataInicio = document.querySelector('input[name="data_inicio"]');

            if (seRepete) {
                seRepete.addEventListener('change', () => {
                    recurrenceBox.classList.toggle('active', seRepete.checked);
                    if (seRepete.checked && !dataFim.value) {
                        const nextMonth = new Date(dataInicio.value || new Date());
                        nextMonth.setDate(nextMonth.getDate() + 1);
                        dataFim.value = nextMonth.toISOString().split('T')[0];
                    }
                });
            }

            if (freqSelect) {
                freqSelect.addEventListener('change', () => {
                    mensalOptions.style.display = freqSelect.value === 'mensal' ? 'block' : 'none';
                });
            }
        })();
    </script>
</body>
</html>
