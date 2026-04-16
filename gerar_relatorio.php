<?php
require_once 'functions.php';
requireLogin();

// Permissão para ver o relatório
if (!can('admin_sistema') && !can('ver_logs')) {
    die('Acesso negado.');
}

$tipo = $_GET['tipo'] ?? '';
$formato = 'pdf'; // Force PDF
$pid = current_paroquia_id();
if ($pid <= 0) {
    die('Erro: Nenhuma paróquia selecionada para o contexto do relatório.');
}
$inc_stats = isset($_GET['inc_stats']);
$inc_inscritos = isset($_GET['inc_inscritos']);

$data = [];
$columns = [];
$title = "";
$stats = [];

if ($tipo === 'eventos') {
    $title = "Relatório de Eventos";
    $dtInic = $_GET['data_inicio'] ?? '';
    $dtFim = $_GET['data_fim'] ?? '';
    $tipoId = $_GET['tipo_id'] ?? '';
    
    $where = ["a.paroquia_id = $pid"];
    if (!empty($dtInic)) $where[] = "a.data_inicio >= '" . $conn->real_escape_string($dtInic) . "'";
    if (!empty($dtFim)) $where[] = "a.data_inicio <= '" . $conn->real_escape_string($dtFim) . "'";
    if (!empty($tipoId)) $where[] = "a.tipo_atividade_id = " . (int)$tipoId;
    
    $sql = "
        SELECT a.id, a.nome, a.data_inicio, a.hora_inicio, l.nome_local, t.nome_tipo,
               (SELECT COUNT(*) FROM inscricoes i WHERE i.atividade_id = a.id) as inscritos
        FROM atividades a
        LEFT JOIN locais_paroquia l ON a.local_id = l.id
        LEFT JOIN tipos_atividade t ON a.tipo_atividade_id = t.id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY a.data_inicio ASC
    ";
    $res = $conn->query($sql);
    
    $columns = ['ID', 'Evento', 'Categoria', 'Data', 'Hora', 'Local', 'Inscritos'];
    
    $totalInscritos = 0;
    $countEventos = 0;
    $byCategory = [];

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                $row['id'],
                $row['nome'],
                $row['nome_tipo'] ?: 'N/A',
                formatDate($row['data_inicio']),
                formatTime($row['hora_inicio']),
                $row['nome_local'] ?: 'Não definido',
                $row['inscritos']
            ];
            $totalInscritos += (int)$row['inscritos'];
            $countEventos++;
            $cat = $row['nome_tipo'] ?: 'Indefinida';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + (int)$row['inscritos'];
        }
    }
    
    if ($inc_stats) {
        $stats = [
            'Total de Eventos' => $countEventos,
            'Total de Inscritos' => $totalInscritos,
            'Média por Evento' => $countEventos > 0 ? round($totalInscritos / $countEventos, 1) : 0,
            'Distribuição por Categoria' => $byCategory
        ];
    }

} elseif ($tipo === 'contatos') {
    $title = "Relatório de Contatos";
    $status = $_GET['status'] ?? 'todos';
    
    $where = ["paroquia_id = $pid"];
    if ($status === '1') $where[] = "ativo = 1";
    if ($status === '0') $where[] = "ativo = 0";
    
    $sql = "
        SELECT id, nome, email, telefone, sexo, date_format(data_nascimento, '%d/%m/%Y') as dtnasc, ativo 
        FROM usuarios 
        WHERE " . implode(" AND ", $where) . "
        ORDER BY nome ASC
    ";
    $res = $conn->query($sql);
    
    $reqCols = $_GET['cols'] ?? [];
    $columns = ['ID'];
    if (in_array('Nome', $reqCols) || empty($reqCols)) $columns[] = 'Nome';
    if (in_array('Email', $reqCols) || empty($reqCols)) $columns[] = 'E-mail';
    if (in_array('Telefone', $reqCols) || empty($reqCols)) $columns[] = 'Telefone';
    if (in_array('Sexo', $reqCols) || empty($reqCols)) $columns[] = 'Sexo';
    if (in_array('Nascimento', $reqCols) || empty($reqCols)) $columns[] = 'Nascimento';
    if (in_array('Status', $reqCols) || empty($reqCols)) $columns[] = 'Status';
    
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rowData = [$row['id']];
            if (in_array('Nome', $reqCols) || empty($reqCols)) $rowData[] = $row['nome'];
            if (in_array('Email', $reqCols) || empty($reqCols)) $rowData[] = $row['email'];
            if (in_array('Telefone', $reqCols) || empty($reqCols)) $rowData[] = $row['telefone'];
            if (in_array('Sexo', $reqCols) || empty($reqCols)) $rowData[] = ($row['sexo'] === 'M' ? 'Masculino' : ($row['sexo'] === 'F' ? 'Feminino' : '-'));
            if (in_array('Nascimento', $reqCols) || empty($reqCols)) $rowData[] = $row['dtnasc'] ?: '-';
            if (in_array('Status', $reqCols) || empty($reqCols)) $rowData[] = (int)$row['ativo'] === 1 ? 'Ativo' : 'Inativo';
            
            $data[] = $rowData;
        }
    }
} else {
    die("Tipo de relatório inválido.");
}

$filename = "Relatorio_" . ucfirst($tipo) . "_" . date('Ymd_His');

$html = '<!DOCTYPE html><html lang="pt-BR"><head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'><meta charset="UTF-8">';
$html .= '<title>' . $title . '</title>
<style>
    body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; color: #333; margin: 3rem; background: #fff; line-height: 1.6; }
    .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
    h1 { font-size: 28px; margin: 0; color: #1a1a1a; letter-spacing: -0.02em; }
    .meta { font-size: 13px; color: #666; font-weight: 600; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 13px; }
    th, td { border: 1px solid #e2e8f0; padding: 12px 15px; text-align: left; }
    th { background-color: #f8fafc; font-weight: 800; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em; }
    tr:nth-child(even) { background-color: #fefefe; }
    
    .stats-container { margin-top: 40px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; page-break-inside: avoid; }
    .stat-card { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; text-align: center; }
    .stat-val { font-size: 24px; font-weight: 900; color: #2563eb; display: block; }
    .stat-lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-top: 5px; }
    
    .charts-section { margin-top: 40px; page-break-inside: avoid; }
    .chart-row { display: flex; align-items: center; gap: 15px; margin-bottom: 12px; }
    .chart-label { width: 180px; font-size: 12px; font-weight: 600; color: #475569; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .chart-bar-bg { flex: 1; height: 14px; background: #f1f5f9; border-radius: 100px; overflow: hidden; position: relative; }
    .chart-bar-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #2563eb); border-radius: 100px; }
    .chart-val { width: 40px; font-size: 12px; font-weight: 800; color: #1e293b; text-align: right; }

    @media print { body { margin: 0; padding: 1cm; } .header { border-bottom-color: #000; } }
</style>
</head><body onload="window.print()">';

$html .= "<div class='header'>
            <div><h1>{$title}</h1></div>
            <div class='meta'>Gerado em: " . date('d/m/Y H:i') . "</div>
          </div>";

if ($inc_stats && !empty($stats)) {
    $html .= "<div class='stats-container'>";
    foreach ($stats as $lbl => $val) {
        if (!is_array($val)) {
            $html .= "<div class='stat-card'><span class='stat-val'>{$val}</span><span class='stat-lbl'>{$lbl}</span></div>";
        }
    }
    $html .= "</div>";

    if (!empty($stats['Distribuição por Categoria'])) {
        $html .= "<div class='charts-section'><h3 style='margin-bottom:20px; font-size:16px; border-left:4px solid #2563eb; padding-left:12px;'>Engajamento por Categoria</h3>";
        $max = max($stats['Distribuição por Categoria'] ?: [1]);
        if ($max == 0) $max = 1;
        foreach ($stats['Distribuição por Categoria'] as $cat => $val) {
            $perc = round(($val / $max) * 100);
            $html .= "<div class='chart-row'>
                        <div class='chart-label'>{$cat}</div>
                        <div class='chart-bar-bg'><div class='chart-bar-fill' style='width: {$perc}%'></div></div>
                        <div class='chart-val'>{$val}</div>
                      </div>";
        }
        $html .= "</div>";
    }
}

$html .= "<table><thead><tr>";
foreach ($columns as $col) {
    $html .= "<th>" . htmlspecialchars($col) . "</th>";
}
$html .= "</tr></thead><tbody>";

foreach ($data as $row) {
    $html .= "<tr>";
    foreach ($row as $cell) {
        $html .= "<td>" . htmlspecialchars($cell ?? '') . "</td>";
    }
    $html .= "</tr>";
}
$html .= "</tbody></table></body></html>";

echo $html;
exit();
