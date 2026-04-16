<?php
require_once 'functions.php';
requireLogin();

// Apenas administradores e pascom devem ter acesso aos relatórios completos
if (!can('admin_sistema') && !can('ver_logs')) {
    header('Location: index.php?error=unauthorized');
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Central de Relatórios — PASCOM</title>
    <link rel="stylesheet" href="style.css?v=2.4.5"
        <link rel="stylesheet" href="css/responsive.css?v=2.4.5">
    <style>
        .app-shell { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-w); padding: 3rem; transition: margin 0.3s; }
        .header-stack { display: flex; justify-content: space-between; align-items: flex-end; gap: 1.5rem; margin-bottom: 2rem; }
        
        .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; }
        .report-card { padding: 2.5rem; display: flex; flex-direction: column; gap: 1.5rem; }
        
        .report-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
        .report-icon { width: 56px; height: 56px; border-radius: 16px; background: rgba(var(--primary-rgb), 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; }
        
        .report-title { font-size: 1.3rem; font-weight: 900; }
        .report-desc { color: var(--text-dim); font-size: 0.95rem; line-height: 1.5; margin-top: 0.5rem; }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        
        .export-actions { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.8rem; margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1.5rem; }
        
        .btn-export { padding: 0.8rem; font-size: 0.8rem; flex-direction: column; gap: 0.4rem; height: 70px; }
        .btn-export svg { width: 20px; height: 20px; }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; padding: 1.5rem; padding-top: 5rem; }
            .header-stack { flex-direction: column; align-items: flex-start; }
            .report-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="bg-mesh"></div>
    <div class="app-shell">
        <?php include 'sidebar.php'; ?>
        <main class="main-content">
            <header class="calendar-header animate-in" style="margin-bottom: 2rem; display: flex; align-items: center; padding: 0 1rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 1rem;">
                    <h1 class="gradient-text" style="font-size: 1.15rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Central de Relatórios</h1>
                    <div style="display: flex; gap: 0.5rem; align-items: stretch; flex-shrink: 0;">
                        <a href="index.php" class="hide-on-desktop btn btn-ghost" style="background: #ef4444; color: #fff; border: none; padding: 0 0.8rem; min-height: 44px; border-radius: 10px; display: flex; align-items: center; gap: 0.4rem; font-weight: 800; font-size: 0.75rem; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); justify-content: center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                            SAIR
                        </a>
                    </div>
                </div>
            </header>
            <p class="hide-on-mobile" style="color:var(--text-dim); font-size:0.95rem; margin-top:-1rem; margin-bottom: 2rem; padding: 0 1rem;"><?= h('Gere e exporte dados da paróquia em múltiplos formatos para análise e arquivamento.') ?></p>

            <section class="report-grid animate-in" style="animation-delay: 0.1s;">
                
                <!-- RELATÓRIO DE EVENTOS -->
                <form action="gerar_relatorio.php" method="GET" target="_blank" class="glass report-card">
                    <input type="hidden" name="tipo" value="eventos">
                    
                    <div class="report-header">
                        <div class="report-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <div>
                            <div class="report-title">Relatório de Eventos</div>
                            <div class="report-desc">Filtre eventos da agenda paroquial e exporte a lista de atividades cadastradas.</div>
                        </div>
                    </div>

                    <div class="form-group" style="margin: 0;">
                            <label>CATEGORIA / TIPO DE EVENTO</label>
                        <select name="tipo_id" style="color: #fff; background: rgba(255,255,255,0.05);">
                            <option value="" style="color: #000;">Todas as categorias</option>
                            <?php
                                $pid = current_paroquia_id();
                                $tipos_r = db_query($conn, "SELECT * FROM tipos_atividade WHERE paroquia_id = ? ORDER BY nome_tipo", [$pid]);
                                if ($tipos_r) while ($tr = $tipos_r->fetch_assoc()) {
                                    echo '<option value="' . $tr["id"] . '" style="color: #000;">' . h($tr["nome_tipo"]) . '</option>';
                                }
                            ?>
                        </select>
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label>CONTEÚDO DO RELATÓRIO</label>
                            <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem; font-size: 0.85rem;">
                                <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-dim); text-transform: none; letter-spacing: normal;"><input type="checkbox" name="inc_inscritos" value="1" checked> Incluir Inscritos</label>
                                <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-dim); text-transform: none; letter-spacing: normal;"><input type="checkbox" name="inc_stats" value="1" checked> Estatísticas</label>
                            </div>
                        </div>

                        <div class="form-row">
                        <div class="form-group" style="margin: 0;">
                            <label>DATA INICIAL (OPCIONAL)</label>
                            <input type="date" name="data_inicio">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label>DATA FINAL (OPCIONAL)</label>
                            <input type="date" name="data_fim">
                        </div>
                    </div>

                    <div class="export-actions" style="grid-template-columns: 1fr;">
                        <button type="submit" name="formato" value="pdf" class="btn btn-primary shimmer btn-export" style="background: #ef4444; border-color: #ef4444; color: white; box-shadow: 0 0 20px rgba(239, 68, 68, 0.4); width: 100%;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M10 13H8v4"/><path d="M14 13h-2v4"/><path d="M18 13h-2v4"/></svg>
                            PDF
                        </button>
                    </div>
                </form>

                <!-- RELATÓRIO DE CONTATOS -->
                <form action="gerar_relatorio.php" method="GET" target="_blank" class="glass report-card">
                    <input type="hidden" name="tipo" value="contatos">
                    
                    <div class="report-header">
                        <div class="report-icon" style="color: #06b6d4; background: rgba(6, 182, 212, 0.1);">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <div>
                            <div class="report-title">Relatório de Contatos</div>
                            <div class="report-desc">Exporte os dados dos usuários e membros registrados no sistema da paróquia.</div>
                        </div>
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label>SITUAÇÃO DO USUÁRIO</label>
                        <select name="status" style="color: #fff; background: rgba(255,255,255,0.05);">
                            <option value="todos" style="color: #000;">Todos os registros</option>
                            <option value="1" style="color: #000;">Apenas Ativos</option>
                            <option value="0" style="color: #000;">Apenas Inativos / Bloqueados</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label>INFORMAÇÕES A EXPORTAR</label>
                        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem; font-size: 0.85rem;">
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-dim); text-transform: none; letter-spacing: normal;"><input type="checkbox" name="cols[]" value="Nome" checked> Nome</label>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-dim); text-transform: none; letter-spacing: normal;"><input type="checkbox" name="cols[]" value="Email" checked> <span style="white-space: nowrap;">E-mail</span></label>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-dim); text-transform: none; letter-spacing: normal;"><input type="checkbox" name="cols[]" value="Telefone" checked> Telefone</label>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-dim); text-transform: none; letter-spacing: normal;"><input type="checkbox" name="cols[]" value="Sexo" checked> Sexo</label>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-dim); text-transform: none; letter-spacing: normal;"><input type="checkbox" name="cols[]" value="Nascimento" checked> Nascimento</label>
                            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-dim); text-transform: none; letter-spacing: normal;"><input type="checkbox" name="cols[]" value="Status" checked> Situação</label>
                        </div>
                    </div>

                    <div class="export-actions" style="grid-template-columns: 1fr;">
                        <button type="submit" name="formato" value="pdf" class="btn btn-primary shimmer btn-export" style="background: #ef4444; border-color: #ef4444; color: white; box-shadow: 0 0 20px rgba(239, 68, 68, 0.4);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M10 13H8v4"/><path d="M14 13h-2v4"/><path d="M18 13h-2v4"/></svg>
                            PDF
                        </button>
                    </div>
                </form>

            </section>
        </main>
    </div>
</body>
</html>
