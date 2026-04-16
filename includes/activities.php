<?php
function seedDefaultEventActivities(mysqli $db, int $paroquiaId): void {
    if ($paroquiaId <= 0 || !ensureEventActivitiesStructure($db)) {
        return;
    }

    $check = $db->prepare("SELECT id FROM atividades_catalogo WHERE paroquia_id = ? LIMIT 1");
    if (!$check) {
        return;
    }
    $check->bind_param('i', $paroquiaId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        return;
    }

    $defaults = [
        ['Liturgia', 'OrganizaÃ§Ã£o litÃºrgica do evento'],
        ['Leitura', 'Leitores e proclamadores'],
        ['Canto', 'Equipe de mÃºsica e canto'],
        ['Acolhida', 'RecepÃ§Ã£o e apoio aos participantes'],
        ['ComunicaÃ§Ã£o', 'Cobertura, avisos e apoio da PASCOM'],
    ];

    $insert = $db->prepare("INSERT INTO atividades_catalogo (paroquia_id, nome, descricao) VALUES (?, ?, ?)");
    if (!$insert) {
        return;
    }

    foreach ($defaults as [$nome, $descricao]) {
        $insert->bind_param('iss', $paroquiaId, $nome, $descricao);
        $insert->execute();
    }
    $insert->close();
}

function getEventActivityCatalog(mysqli $db, int $paroquiaId): array {
    if ($paroquiaId <= 0 || !ensureEventActivitiesStructure($db)) {
        return [];
    }

    seedDefaultEventActivities($db, $paroquiaId);

    $stmt = $db->prepare("
        SELECT id, nome, descricao
        FROM atividades_catalogo
        WHERE paroquia_id = ? AND ativo = 1
        ORDER BY nome ASC
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $paroquiaId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    return $items;
}

function normalizeEventActivityCatalogIds(mixed $rawIds): array {
    if (!is_array($rawIds)) {
        return [];
    }

    $normalized = [];
    foreach ($rawIds as $rawId) {
        $id = (int)$rawId;
        if ($id > 0) {
            $normalized[] = $id;
        }
    }

    return array_values(array_unique($normalized));
}

function saveEventActivityItems(mysqli $db, int $eventoId, int $paroquiaId, mixed $rawIds, bool $forceReset = false): void {
    if ($eventoId <= 0 || $paroquiaId <= 0 || !ensureEventActivitiesStructure($db)) {
        return;
    }

    $newCatalogIds = normalizeEventActivityCatalogIds($rawIds);

    // 1. Obter itens atuais vinculados ao evento
    $currentItems = [];
    $stmt = $db->prepare("SELECT id, atividade_catalogo_id FROM atividade_evento_itens WHERE evento_id = ?");
    $stmt->bind_param('i', $eventoId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $currentItems[(int)$row['atividade_catalogo_id']] = (int)$row['id'];
    }
    $stmt->close();

    // 2. Identificar o que fazer
    $catalogIdsToRemove = array_keys(array_diff_key($currentItems, array_flip($newCatalogIds)));
    
    // Se forceReset for true (mudou data/hora/local), removemos TODOS para garantir notificaÃ§Ã£o
    if ($forceReset) {
        $catalogIdsToRemove = array_keys($currentItems);
        // O que sobrar em newCatalogIds serÃ¡ "adicionado" (criado do zero)
        $catalogIdsRemaining = []; 
    } else {
        $catalogIdsRemaining = array_intersect($newCatalogIds, array_keys($currentItems));
    }
    
    $catalogIdsToAdd = array_diff($newCatalogIds, $forceReset ? [] : array_keys($currentItems));
    if ($forceReset) $catalogIdsToAdd = $newCatalogIds;

    // 3. Processar RemoÃ§Ãµes e NotificaÃ§Ãµes
    if ($catalogIdsToRemove) {
        foreach ($catalogIdsToRemove as $catId) {
            $itemId = $currentItems[$catId];
            
            // Buscar participantes antes de deletar
            $sub = $db->prepare("SELECT usuario_id FROM atividade_evento_inscricoes WHERE evento_item_id = ?");
            $sub->bind_param('i', $itemId);
            $sub->execute();
            $pRes = $sub->get_result();
            
            $uids = [];
            while($p = $pRes->fetch_assoc()) $uids[] = (int)$p['usuario_id'];
            $sub->close();

            if ($uids) {
                // Buscar nomes para a mensagem
                $stmtDetails = $db->prepare("
                    SELECT a.nome AS evento_nome, ac.nome AS atividade_nome
                    FROM atividades a
                    JOIN atividades_catalogo ac ON ac.id = ?
                    WHERE a.id = ?
                    LIMIT 1
                ");
                $details = null;
                if ($stmtDetails) {
                    $stmtDetails->bind_param('ii', $catId, $eventoId);
                    $stmtDetails->execute();
                    $details = $stmtDetails->get_result()->fetch_assoc();
                    $stmtDetails->close();
                }
                
                $eventoNome = $details['evento_nome'] ?? 'Evento';
                $atividadeNome = $details['atividade_nome'] ?? 'Atividade';

                $msg = $forceReset 
                    ? "VocÃª foi removido da atividade '{$atividadeNome}' no evento '{$eventoNome}' porque houve alteraÃ§Ã£o crÃ­tica (Data, Hora ou Local) no evento."
                    : "A atividade '{$atividadeNome}' foi removida do evento '{$eventoNome}' e sua inscriÃ§Ã£o foi cancelada.";

                foreach ($uids as $uid) {
                    addNotification($db, $uid, $msg);
                }
            }

            $stmtDelItem = $db->prepare("DELETE FROM atividade_evento_itens WHERE id = ?");
            if ($stmtDelItem) {
                $stmtDelItem->bind_param('i', $itemId);
                $stmtDelItem->execute();
                $stmtDelItem->close();
            }
        }
    }

    // 4. Inserir Novos e Reordenar
    $insert = $db->prepare("INSERT INTO atividade_evento_itens (evento_id, atividade_catalogo_id, ordem) VALUES (?, ?, ?)");
    $update = $db->prepare("UPDATE atividade_evento_itens SET ordem = ? WHERE id = ?");

    foreach ($newCatalogIds as $index => $catId) {
        $ordem = $index + 1;
        if (isset($currentItems[$catId]) && !$forceReset) {
            // Apenas atualiza a ordem
            $update->bind_param('ii', $ordem, $currentItems[$catId]);
            $update->execute();
        } else {
            // Insere novo (ou re-insere se foi forÃ§ado o reset)
            $insert->bind_param('iii', $eventoId, $catId, $ordem);
            $insert->execute();
        }
    }
    $insert->close();
    $update->close();
}

function getEventActivityItems(mysqli $db, int $eventoId, int $usuarioId = 0): array {
    if ($eventoId <= 0 || !ensureEventActivitiesStructure($db)) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT
            ei.id,
            ei.atividade_catalogo_id,
            ei.ordem,
            ac.nome,
            ac.descricao,
            (
                SELECT COUNT(*)
                FROM atividade_evento_inscricoes aei_count
                WHERE aei_count.evento_item_id = ei.id
            ) AS total_inscritos,
            EXISTS(
                SELECT 1
                FROM atividade_evento_inscricoes aei_user
                WHERE aei_user.evento_item_id = ei.id AND aei_user.usuario_id = ?
            ) AS usuario_inscrito
        FROM atividade_evento_itens ei
        INNER JOIN atividades_catalogo ac ON ac.id = ei.atividade_catalogo_id
        WHERE ei.evento_id = ?
        ORDER BY ei.ordem ASC, ac.nome ASC
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $usuarioId, $eventoId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['atividade_catalogo_id'] = (int)$row['atividade_catalogo_id'];
        $row['ordem'] = (int)$row['ordem'];
        $row['total_inscritos'] = (int)$row['total_inscritos'];
        $row['usuario_inscrito'] = (int)$row['usuario_inscrito'] === 1;
        $row['participants'] = [];
        $items[$row['id']] = $row;
        $ids[] = $row['id'];
    }
    $stmt->close();

    if (!$ids) {
        return [];
    }

    $participantIds = implode(',', array_map('intval', $ids));
    $participantsRes = $db->query("
        SELECT aei.evento_item_id, u.nome, u.foto_perfil
        FROM atividade_evento_inscricoes aei
        INNER JOIN usuarios u ON u.id = aei.usuario_id
        WHERE aei.evento_item_id IN ($participantIds)
        ORDER BY u.nome ASC
    ");

    if ($participantsRes) {
        while ($participant = $participantsRes->fetch_assoc()) {
            $itemId = (int)$participant['evento_item_id'];
            if (isset($items[$itemId])) {
                $items[$itemId]['participants'][] = [
                    'nome' => $participant['nome'],
                    'foto_perfil' => $participant['foto_perfil'],
                ];
            }
        }
    }

    return array_values($items);
}

function activityStartTimestamp(array $activity): int {
    $date = $activity['data_inicio'] ?? date('Y-m-d');
    $time = $activity['hora_inicio'] ?? '00:00:00';
    return strtotime(trim($date . ' ' . $time));
}

