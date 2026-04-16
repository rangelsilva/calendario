<?php
session_start();
require_once 'conexao.php';
require_once 'functions.php';

if (empty($_SESSION['usuario_id']) || !has_level(1)) {
    header('Location: index.php?msg=acesso_negado');
    exit;
}

$paroquia_id = current_paroquia_id();
$msg = '';
$msg_type = 'danger';

$stmt = $conn->prepare('SELECT * FROM cores_sistema WHERE paroquia_id=? LIMIT 1');
$stmt->bind_param('i', $paroquia_id);
$stmt->execute();
$core = $stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['nome_modelo', 'cor_primaria', 'cor_secundaria', 'cor_texto', 'cor_fundo', 'cor_sucesso', 'cor_erro'];
    $values = [];
    foreach ($fields as $f) {
        $values[$f] = $_POST[$f] ?? ($core[$f] ?? '');
    }

    // Processamento do Logo
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($ext, $allowed)) {
            $novo_nome = 'logo_paroquia_' . $paroquia_id . '_' . time() . '.' . $ext;
            if (!is_dir('img/logos')) {
                mkdir('img/logos', 0777, true);
            }
            $destino = 'img/logos/' . $novo_nome;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $destino)) {
                // Remove logo antigo se houver
                $stmtLogo = $conn->prepare('SELECT imagem_paroquia FROM logo_paroquias WHERE paroquia_id=?');
                $stmtLogo->bind_param('i', $paroquia_id);
                $stmtLogo->execute();
                $resLogo = $stmtLogo->get_result();
                if ($resLogo->num_rows > 0) {
                    $oldLogo = $resLogo->fetch_assoc();
                    if (file_exists($oldLogo['imagem_paroquia'])) {
                        unlink($oldLogo['imagem_paroquia']);
                    }
                    $updLogo = $conn->prepare('UPDATE logo_paroquias SET imagem_paroquia=? WHERE paroquia_id=?');
                    $updLogo->bind_param('si', $destino, $paroquia_id);
                    $updLogo->execute();
                } else {
                    $insLogo = $conn->prepare('INSERT INTO logo_paroquias (paroquia_id, imagem_paroquia) VALUES (?, ?)');
                    $insLogo->bind_param('is', $paroquia_id, $destino);
                    $insLogo->execute();
                }

                // Também atualiza id_imagem em paroquias (se necessário pelo DB original)
                db_execute($conn, "UPDATE paroquias SET id_imagem = (SELECT id FROM logo_paroquias WHERE paroquia_id = ? LIMIT 1) WHERE id = ?", [$paroquia_id, $paroquia_id]);
            }
        }
    }

    if ($core) {
        $sql = 'UPDATE cores_sistema SET nome_modelo=?, cor_primaria=?, cor_secundaria=?, cor_texto=?, cor_fundo=?, cor_sucesso=?, cor_erro=? WHERE paroquia_id=?';
        $upd = $conn->prepare($sql);
        $upd->bind_param('sssssssi', $values['nome_modelo'], $values['cor_primaria'], $values['cor_secundaria'], $values['cor_texto'], $values['cor_fundo'], $values['cor_sucesso'], $values['cor_erro'], $paroquia_id);
        if ($upd->execute()) {
            $msg = 'Cores atualizadas com sucesso!';
            $msg_type = 'success';
        } else {
            $msg = 'Erro ao atualizar as cores.';
        }
    } else {
        $sql = 'INSERT INTO cores_sistema (paroquia_id, nome_modelo, cor_primaria, cor_secundaria, cor_texto, cor_fundo, cor_sucesso, cor_erro) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $ins = $conn->prepare($sql);
        $ins->bind_param('isssssss', $paroquia_id, $values['nome_modelo'], $values['cor_primaria'], $values['cor_secundaria'], $values['cor_texto'], $values['cor_fundo'], $values['cor_sucesso'], $values['cor_erro']);
        if ($ins->execute()) {
            $msg = 'Esquema de cores criado com sucesso!';
            $msg_type = 'success';
        } else {
            $msg = 'Erro ao criar o esquema de cores.';
        }
    }
    // Recarrega as cores após a alteração
    $stmt->execute();
    $core = $stmt->get_result()->fetch_assoc();
}

$field_labels = [
    'nome_modelo' => 'Nome do Tema',
    'cor_primaria' => 'Cor Primária',
    'cor_secundaria' => 'Cor Secundária',
    'cor_texto' => 'Cor do Texto',
    'cor_fundo' => 'Cor de Fundo',
    'cor_sucesso' => 'Cor de Sucesso',
    'cor_erro' => 'Cor de Erro'
];
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Configurar Cores - Calendário PasCom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

    <?php include 'includes/navbar_sidebar.php'; // Reutilizando a navbar e sidebar ?>

    <main class="container pt-5">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8 mt-4">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h3 class="mb-0">Configurar Cores do Sistema</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($msg): ?>
                            <div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data">
                            <div class="mb-4">
                                <label for="logo" class="form-label">Logotipo da Paróquia (Opcional, Máx 2MB)</label>
                                <input type="file" id="logo" name="logo" class="form-control"
                                    accept="image/png, image/jpeg, image/gif">
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label for="nome_modelo"
                                    class="form-label"><?php echo $field_labels['nome_modelo']; ?></label>
                                <input type="text" id="nome_modelo" name="nome_modelo" class="form-control"
                                    value="<?php echo htmlspecialchars($core['nome_modelo'] ?? 'Padrão'); ?>">
                            </div>
                            <div class="row">
                                <?php foreach ($field_labels as $key => $label): ?>
                                    <?php if (str_starts_with($key, 'cor_')): ?>
                                        <div class="col-md-6 col-lg-4 mb-3">
                                            <label for="<?php echo $key; ?>" class="form-label"><?php echo $label; ?></label>
                                            <input type="color" id="<?php echo $key; ?>" name="<?php echo $key; ?>"
                                                class="form-control form-control-color"
                                                value="<?php echo htmlspecialchars($core[$key] ?? '#ffffff'); ?>">
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">Salvar Cores</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>