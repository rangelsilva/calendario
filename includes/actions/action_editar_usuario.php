<?php
/**
 * SOLID: Single Responsibility Principle
 * Controlador de Submissão - Editar Usuário
 * Isolado da Camada de View HTML para limitação de Carga Cognitiva.
 */

if (!defined('ABSPATH')) {
    // Apenas para mitigar acessos direitos caso tentem rodar isso manualmente fora da aplicacao
}

require_csrf_token();
$data = sanitize_post($_POST);

if (!$is_self && !$can_manage_target) {
    $error = 'Voce nao tem permissao para editar este usuario.';
} elseif ($is_same_level_peer) {
    $error = 'Voce nao pode editar ou excluir usuarios do mesmo nivel.';
} elseif (isset($data['delete_request'])) {
    if (!$can_delete_target) {
        $error = 'Voce nao tem permissao para excluir este usuario.';
    } else {
        $_SESSION['pending_delete_user_id'] = $id;
        $_SESSION['pending_delete_user_name'] = (string)($user['nome'] ?? '');
        header('Location: editar_usuario.php?id=' . $id . '&delete_confirm=1');
        exit();
    }
} elseif (isset($data['final_delete'])) {
    $pendingId = (int)($_SESSION['pending_delete_user_id'] ?? 0);
    $confirmText = strtoupper(trim((string)($data['delete_confirm_text'] ?? '')));

    if (!$can_delete_target || $pendingId !== $id) {
        $error = 'Confirmacao de exclusao invalida.';
    } elseif ($confirmText !== 'EXCLUIR USUARIO') {
        $error = 'Digite EXCLUIR USUARIO para confirmar a exclusao.';
    } else {
        $res = db_query($conn, "SELECT * FROM usuarios WHERE id = ?", [$id]);
        $oldState = $res ? $res->fetch_assoc() : null;
        $oldPhoto = trim((string)($oldState['foto_perfil'] ?? ''));
        if ($oldPhoto !== '' && str_starts_with($oldPhoto, 'img/usuarios/')) {
            $oldPhotoFs = __DIR__ . '/../../' . $oldPhoto;
            if (is_file($oldPhotoFs)) {
                @unlink($oldPhotoFs);
            }
        }

        $stmtDelete = $conn->prepare('DELETE FROM usuarios WHERE id = ?');
        if ($stmtDelete) {
            $stmtDelete->bind_param('i', $id);
            if ($stmtDelete->execute()) {
                unset($_SESSION['pending_delete_user_id'], $_SESSION['pending_delete_user_name']);
                logAction($conn, 'EXCLUIR_USUARIO', 'usuarios', $id, $oldState ?: []);
                header('Location: usuarios.php?msg=Usuario excluido com sucesso!');
                exit();
            }
        }

        $error = 'Nao foi possivel excluir o usuario.';
    }
} else {
    $nome = trim((string)($data['nome'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $telefone = trim((string)($data['telefone'] ?? ''));
    $sexo = trim((string)($data['sexo'] ?? ''));
    $dt_nasc = !empty($data['data_nascimento']) ? $data['data_nascimento'] : null;
    $novaSenha = (string)($data['nova_senha'] ?? '');
    $confirmarNovaSenha = (string)($data['confirmar_nova_senha'] ?? '');
    $palavraChave = trim((string)($data['palavra_chave'] ?? ''));
    $paroquiaId = $can_edit_parish_for_target ? (int)($data['paroquia_id'] ?? $user['paroquia_id']) : (int)$user['paroquia_id'];
    $perfil_id = (int)($user['perfil_id'] ?? 3);
    $perfil_nome = (string)($user['perfil_nome'] ?? '');
    $nivel_acesso = (int)($user['nivel_acesso'] ?? $max_access_level);

    if ($can_manage_target && !$is_self) {
        $nivel_raw = trim((string)($data['nivel_acesso'] ?? ''));
        if ($nivel_raw !== '') {
            $nivel_candidate = (int)$nivel_raw;
            if ($nivel_candidate < 0 || $nivel_candidate > $max_access_level) {
                $error = 'Nivel de acesso invalido.';
            } elseif (!$is_master && $nivel_candidate < $my_level) {
                $error = 'Nivel de acesso invalido para o seu usuario.';
            } else {
                $nivel_acesso = $nivel_candidate;
            }
        }

        $perfil_raw = trim((string)($data['perfil_id'] ?? ''));
        if ($perfil_raw !== '') {
            $perfil_candidate = (int)$perfil_raw;
            if (!isset($allowedPerfilMap[$perfil_candidate])) {
                $error = 'Perfil selecionado invalido para o seu nivel.';
            } else {
                $perfil_id = $perfil_candidate;
                $perfil_nome = (string)($allowedPerfilMap[$perfil_candidate]['nome'] ?? $perfil_nome);
            }
        }
    }

    if ($nome === '') {
        $error = 'Nome obrigatorio.';
    } elseif ($can_edit_email_for_target && $email === '') {
        $error = 'E-mail obrigatorio.';
    } elseif ($can_edit_password_for_target && ($novaSenha !== '' || $confirmarNovaSenha !== '') && strlen($novaSenha) < 6) {
        $error = 'A nova senha precisa ter no minimo 6 caracteres.';
    } elseif ($can_edit_password_for_target && $novaSenha !== '' && $novaSenha !== $confirmarNovaSenha) {
        $error = 'As senhas informadas nao coincidem.';
    } else {
        $resOld = db_query($conn, "SELECT * FROM usuarios WHERE id = ?", [$id]);
        $oldState = $resOld ? $resOld->fetch_assoc() : null;
        $emailToSave = $can_edit_email_for_target ? $email : (string)($user['email'] ?? '');

        $permValues = [];
        foreach ($visiblePermissions as $field => $visible) {
            $permValues[$field] = $visible ? (isset($_POST[$field]) ? 1 : 0) : (int)($user[$field] ?? 0);
        }

        $sql = "UPDATE usuarios SET 
                nome = ?, email = ?, sexo = ?, telefone = ?, data_nascimento = ?, 
                paroquia_id = ?, perfil_id = ?, perfil_nome = ?, nivel_acesso = ?, 
                perm_ver_calendario = ?, perm_criar_eventos = ?, perm_editar_eventos = ?, perm_excluir_eventos = ?,
                perm_ver_restritos = ?, perm_cadastrar_usuario = ?, perm_admin_usuarios = ?, perm_admin_sistema = ?, perm_ver_logs = ?,
                perm_gerenciar_catalogo = ?, perm_gerenciar_grupos = ?
                WHERE id = ?";

        $stmtUpdate = $conn->prepare($sql);
        if ($stmtUpdate) {
            $stmtUpdate->bind_param(
                'sssssiisiiiiiiiiiiiii',
                $nome,
                $emailToSave,
                $sexo,
                $telefone,
                $dt_nasc,
                $paroquiaId,
                $perfil_id,
                $perfil_nome,
                $nivel_acesso,
                $permValues['perm_ver_calendario'],
                $permValues['perm_criar_eventos'],
                $permValues['perm_editar_eventos'],
                $permValues['perm_excluir_eventos'],
                $permValues['perm_ver_restritos'],
                $permValues['perm_cadastrar_usuario'],
                $permValues['perm_admin_usuarios'],
                $permValues['perm_admin_sistema'],
                $permValues['perm_ver_logs'],
                $permValues['perm_gerenciar_catalogo'],
                $permValues['perm_gerenciar_grupos'],
                $id
            );

            if ($stmtUpdate->execute()) {
                if ($can_edit_keyword_for_target && $palavraChave !== '') {
                    $stmtKey = $conn->prepare('UPDATE usuarios SET palavra_chave = ? WHERE id = ?');
                    if ($stmtKey) {
                        $stmtKey->bind_param('si', $palavraChave, $id);
                        $stmtKey->execute();
                    }
                }

                if ($can_edit_password_for_target && $novaSenha !== '') {
                    $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
                    $stmtPass = $conn->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
                    if ($stmtPass) {
                        $stmtPass->bind_param('si', $hash, $id);
                        $stmtPass->execute();
                    }
                }

                if ($can_edit_photo_for_target && isset($_POST['remover_foto']) && $_POST['remover_foto'] === '1') {
                    $oldPhoto = trim((string)($oldState['foto_perfil'] ?? ''));
                    if ($oldPhoto !== '' && str_starts_with($oldPhoto, 'img/usuarios/')) {
                        $oldPhotoFs = __DIR__ . '/../../' . $oldPhoto;
                        if (is_file($oldPhotoFs)) {
                            @unlink($oldPhotoFs);
                        }
                    }
                    $stmtRemovePhoto = $conn->prepare('UPDATE usuarios SET foto_perfil = NULL WHERE id = ?');
                    if ($stmtRemovePhoto) {
                        $stmtRemovePhoto->bind_param('i', $id);
                        $stmtRemovePhoto->execute();
                    }
                    if ($is_self) {
                        $_SESSION['usuario_foto'] = '';
                    }
                }

                if (
                    $can_edit_photo_for_target &&
                    isset($_FILES['foto_perfil']) &&
                    (int)($_FILES['foto_perfil']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
                ) {
                    $tmpPath = (string)$_FILES['foto_perfil']['tmp_name'];
                    if (is_uploaded_file($tmpPath)) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = $finfo ? (string)finfo_file($finfo, $tmpPath) : '';
                        if ($finfo) {
                            finfo_close($finfo);
                        }
                        if (str_starts_with($mime, 'image/')) {
                            // __DIR__ agora aponta para includes/actions, vamos ajustar o uploadDir
                            $uploadDir = __DIR__ . '/../../img/usuarios';
                            if (!is_dir($uploadDir)) {
                                @mkdir($uploadDir, 0777, true);
                            }
                            if (is_dir($uploadDir) && is_writable($uploadDir)) {
                                $ext = strtolower(pathinfo((string)($_FILES['foto_perfil']['name'] ?? ''), PATHINFO_EXTENSION));
                                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                                    $ext = preg_replace('/[^a-z0-9]+/i', '', substr($mime, 6));
                                    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                                        $ext = 'png';
                                    }
                                }
                                $fileName = 'user_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                                $targetPath = $uploadDir . '/' . $fileName;
                                if (move_uploaded_file($tmpPath, $targetPath)) {
                                    $oldPhoto = trim((string)($oldState['foto_perfil'] ?? ''));
                                    if ($oldPhoto !== '' && str_starts_with($oldPhoto, 'img/usuarios/')) {
                                        $oldPhotoFs = __DIR__ . '/../../' . $oldPhoto;
                                        if (is_file($oldPhotoFs)) {
                                            @unlink($oldPhotoFs);
                                        }
                                    }
                                    $newPhoto = 'img/usuarios/' . $fileName;
                                    $stmtPhoto = $conn->prepare('UPDATE usuarios SET foto_perfil = ? WHERE id = ?');
                                    if ($stmtPhoto) {
                                        $stmtPhoto->bind_param('si', $newPhoto, $id);
                                        $stmtPhoto->execute();
                                        if ($is_self) {
                                            $_SESSION['usuario_foto'] = $newPhoto;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $resNew = db_query($conn, "SELECT * FROM usuarios WHERE id = ?", [$id]);
                $newState = $resNew ? $resNew->fetch_assoc() : null;
                
                // SAVE WORKING GROUPS (Scoped)
                if ($can_manage_target) {
                    $groupIds = isset($_POST['grupos_trabalho']) && is_array($_POST['grupos_trabalho']) ? $_POST['grupos_trabalho'] : [];
                    
                    // Use context variables defined above
                    $manageableIds = $is_master_global_ctx ? array_column($allGroups_ctx, 'id') : $adminGroups_ctx;
                    
                    saveUserGroupsScoped($conn, $id, $groupIds, $manageableIds, $paroquiaId);
                    ensureDefaultVisitorGroup($conn, $paroquiaId);
                }

                logAction($conn, 'EDITAR_USUARIO', 'usuarios', $id, ['antigo' => $oldState, 'novo' => $newState]);
                header('Location: usuarios.php?msg=Usuario atualizado com sucesso!');
                exit();
            }
        }

        $error = 'Erro ao atualizar dados. O e-mail pode ja estar em uso.';
    }
}
