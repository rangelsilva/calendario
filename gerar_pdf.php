<?php
/**
 * ═══════════════════════════════════════════════════════
 * PASCOM — PDF Report Engine (v2.0)
 * High-Quality Export · FPDF Integration · Data Viz
 * ═══════════════════════════════════════════════════════ */

require_once 'functions.php';
requireLogin();

// Restricted to Managers and Master
if (!has_level(1)) {
    die('Acesso negado.');
}

// 1. Check FPDF Library via Composer Autoloader
if (!class_exists('FPDF')) {
    // Tenta carregar o autoloader caso nao tenha sido carregado
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    } else {
        die("<b>Erro Crítico:</b> O autoloader do Composer não foi encontrado. Rode 'composer install'.");
    }
}

// 2. Report Parameters
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$pid = current_paroquia_id();

// Month Name (Standard PHP way without deprecated strftime)
$date_obj = DateTime::createFromFormat('!m', $month);
$month_name = strtr($date_obj->format('F'), [
    'January' => 'Janeiro', 'February' => 'Fevereiro', 'March' => 'Março',
    'April' => 'Abril', 'May' => 'Maio', 'June' => 'Junho',
    'July' => 'Julho', 'August' => 'Agosto', 'September' => 'Setembro',
    'October' => 'Outubro', 'November' => 'Novembro', 'December' => 'Dezembro'
]);

$start_date = "$year-$month-01";
$end_date = date('Y-m-t', strtotime($start_date));

// 3. Fetch Data
$sql = "
    SELECT a.data_inicio, a.hora_inicio, a.nome, l.nome_local,
    (SELECT COUNT(*) FROM inscricoes i WHERE i.atividade_id = a.id) as total_inscritos
    FROM atividades a
    LEFT JOIN locais_paroquia l ON a.local_id = l.id
    WHERE a.paroquia_id = ? AND a.data_inicio BETWEEN ? AND ?
    ORDER BY a.data_inicio ASC, a.hora_inicio ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iss', $pid, $start_date, $end_date);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Parish Name
$p_stmt = $conn->prepare("SELECT nome FROM paroquias WHERE id = ?");
$p_stmt->bind_param('i', $pid);
$p_stmt->execute();
$parish_name = $p_stmt->get_result()->fetch_assoc()['nome'] ?? 'Minha Paróquia';

// 4. PDF Generation Class
class PascomPDF extends FPDF {
    public $month_year;
    public $p_name;

    function Header() {
        // Aesthetic Header Strip
        $this->SetFillColor(13, 110, 253); // Primary Blue
        $this->Rect(0, 0, 210, 35, 'F');
        
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 18);
        $this->Cell(0, 15, utf8_decode('PASCOM — RELATÓRIO DE ATIVIDADES'), 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode("REFERÊNCIA: " . strtoupper($this->month_year)), 0, 1, 'L');
        $this->Cell(0, 5, utf8_decode("UNIDADE: " . strtoupper($this->p_name)), 0, 1, 'L');
        
        $this->Ln(15);
    }

    function Footer() {
        $this->SetY(-20);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, utf8_decode('Documento gerado automaticamente pelo Sistema PasCom — Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function ActivityTable($data) {
        // Table Header
        $this->SetFillColor(245, 247, 251);
        $this->SetTextColor(50, 50, 50);
        $this->SetFont('Arial', 'B', 9);
        
        $this->Cell(25, 10, 'DATA', 1, 0, 'C', true);
        $this->Cell(20, 10, 'HORA', 1, 0, 'C', true);
        $this->Cell(80, 10, 'ATIVIDADE', 1, 0, 'L', true);
        $this->Cell(45, 10, 'LOCAL', 1, 0, 'L', true);
        $this->Cell(20, 10, 'INSCR', 1, 1, 'C', true);

        // Rows
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(80, 80, 80);
        
        if (empty($data)) {
            $this->Cell(190, 20, utf8_decode('Nenhuma atividade registrada para este período.'), 1, 1, 'C');
            return;
        }

        foreach ($data as $row) {
            $this->Cell(25, 8, date('d/m/Y', strtotime($row['data_inicio'])), 1, 0, 'C');
            $this->Cell(20, 8, substr($row['hora_inicio'], 0, 5), 1, 0, 'C');
            $this->Cell(80, 8, utf8_decode($row['nome']), 1, 0, 'L');
            $this->Cell(45, 8, utf8_decode($row['nome_local'] ?: 'Não definido'), 1, 0, 'L');
            $this->Cell(20, 8, $row['total_inscritos'], 1, 1, 'C');
        }
    }
}

// 5. Output
$pdf = new PascomPDF();
$pdf->month_year = "$month_name / $year";
$pdf->p_name = $parish_name;
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->ActivityTable($activities);

$pdf->Output('I', "Relatorio_PasCom_{$year}_{$month}.pdf");
?>
o_{$year}_{$month}.pdf");
?>