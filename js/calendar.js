(() => {
            const backdrop = document.getElementById('eventModalBackdrop');
            const closeButton = document.getElementById('closeEventModal');
            const joinButton = document.getElementById('eventJoinButton');
            const leaveButton = document.getElementById('eventLeaveButton');
            const viewButton = document.getElementById('eventViewButton');
            const noteBox = document.getElementById('eventModalNote');
            const feedbackBox = document.getElementById('eventModalFeedback');
            const itemsWrap = document.getElementById('eventModalItemsWrap');
            const itemsContainer = document.getElementById('eventModalItems');
            const participantsWrap = document.getElementById('eventModalParticipantsWrap');
            let currentActivityId = null;

            function setFeedback(type, message) {
                if (!message) {
                    feedbackBox.className = 'modal-feedback';
                    feedbackBox.innerHTML = '';
                    return;
                }
                feedbackBox.className = 'modal-feedback show';
                feedbackBox.innerHTML = window.MSG_INFO_ALERT.replace('__MSG__', message);
                if (type === 'error') {
                    feedbackBox.innerHTML = window.MSG_ERROR_ALERT.replace('__MSG__', message);
                }
                if (type === 'success') {
                    feedbackBox.innerHTML = window.MSG_SUCCESS_ALERT.replace('__MSG__', message);
                }
            }

            function closeModal() {
                backdrop.classList.remove('open');
                currentActivityId = null;
                setFeedback('', '');
                noteBox.style.display = 'none';
                noteBox.textContent = '';
                itemsWrap.classList.remove('show');
                itemsContainer.innerHTML = '';
                participantsWrap.style.display = 'block';
            }

            async function loadActivity(id) {
                const response = await fetch(`atividade_json.php?id=${id}`, { headers: { 'Accept': 'application/json' } });
                const payload = await response.json();
                if (!payload.success) {
                    throw new Error(payload.message || 'Falha ao carregar atividade.');
                }
                return payload.data.activity;
            }

            function renderParticipants(participants) {
                const container = document.getElementById('eventModalParticipants');
                if (!participants.length) {
                    container.innerHTML = '<div class="participant-chip">Nenhum inscrito ainda</div>';
                    return;
                }
                container.innerHTML = participants.map((participant) => (
                    `<div class="participant-chip">${participant.nome}</div>`
                )).join('');
            }

            function renderEventItems(activity) {
                const items = Array.isArray(activity.event_items) ? activity.event_items : [];
                if (!items.length) {
                    itemsWrap.classList.remove('show');
                    itemsContainer.innerHTML = '';
                    participantsWrap.style.display = 'block';
                    return false;
                }

                participantsWrap.style.display = 'none';
                itemsWrap.classList.add('show');
                itemsContainer.innerHTML = items.map((item) => {
                    const participants = Array.isArray(item.participants) && item.participants.length
                        ? item.participants.map((participant) => `<div class="participant-chip">${participant.nome}</div>`).join('')
                        : '<div class="participant-chip">Nenhum inscrito nesta atividade</div>';
                    const joinAction = activity.can_interact && !item.usuario_inscrito
                        ? `<button type="button" class="btn btn-primary shimmer event-item-action" data-action="join" data-item-id="${item.id}">Inscrever-me</button>`
                        : '';
                    const leaveAction = activity.can_interact && item.usuario_inscrito && activity.can_cancel_now
                        ? `<button type="button" class="btn btn-ghost event-item-action" data-action="leave" data-item-id="${item.id}">Desistir</button>`
                        : '';
                    const note = item.usuario_inscrito && !activity.can_cancel_now
                        ? `<div class="event-item-note">${activity.deadline_message}</div>`
                        : '';

                    return `
                        <div class="event-item-card">
                            <div class="event-item-head">
                                <div class="event-item-title">${item.nome}</div>
                                <div class="event-item-count">${item.total_inscritos} inscrito(s)</div>
                            </div>
                            <div class="event-item-actions">${joinAction}${leaveAction}</div>
                            <div class="event-item-participants">${participants}</div>
                            ${note}
                        </div>
                    `;
                }).join('');
                return true;
            }

            function fillModal(activity) {
                const hasEventItems = renderEventItems(activity);
                currentActivityId = activity.id;
                document.getElementById('eventModalType').textContent = activity.nome_tipo || 'Evento';
                document.getElementById('eventModalTitle').textContent = activity.nome;
                document.getElementById('eventModalDate').textContent = `${activity.data_inicio} Ã s ${String(activity.hora_inicio || '00:00').slice(0, 5)}`;
                document.getElementById('eventModalLocation').textContent = activity.local_nome || 'Local nÃ£o definido';
                document.getElementById('eventModalDescription').textContent = activity.descricao || 'Sem descriÃ§Ã£o.';
                viewButton.href = `ver_atividade.php?id=${activity.id}`;
                if (!hasEventItems) {
                    renderParticipants(activity.participants || []);
                }

                joinButton.style.display = !hasEventItems && activity.can_interact && !activity.usuario_inscrito ? 'inline-flex' : 'none';
                leaveButton.style.display = !hasEventItems && activity.can_interact && activity.usuario_inscrito ? 'inline-flex' : 'none';

                noteBox.style.display = !hasEventItems && !activity.can_cancel_now && activity.usuario_inscrito ? 'block' : 'none';
                noteBox.textContent = !hasEventItems && !activity.can_cancel_now && activity.usuario_inscrito ? activity.deadline_message : '';
                setFeedback('', '');
            }

            async function refreshModal() {
                if (!currentActivityId) return;
                const activity = await loadActivity(currentActivityId);
                fillModal(activity);
            }

            async function submitEnrollment(action, itemId = null) {
                if (!currentActivityId) return;
                const response = await fetch('inscrever.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': window.CSRF_TOKEN
                    },
                    body: new URLSearchParams({
                        id: String(currentActivityId),
                        action,
                        item_id: itemId ? String(itemId) : ''
                    })
                });
                const payload = await response.json();
                setFeedback(payload.success ? 'success' : 'error', payload.message || '');
                if (payload.success) {
                    await refreshModal();
                    setTimeout(() => window.location.reload(), 1000);
                }
            }

            document.querySelectorAll('.event-trigger').forEach((button) => {
                button.addEventListener('click', async () => {
                    try {
                        const activity = await loadActivity(button.dataset.activityId);
                        fillModal(activity);
                        backdrop.classList.add('open');
                    } catch (error) {
                        if (typeof showToast === 'function') {
                            showToast(error.message, 'error');
                        } else {
                            alert(error.message);
                        }
                    }
                });
            });

            closeButton.addEventListener('click', closeModal);
            backdrop.addEventListener('click', (event) => {
                if (event.target === backdrop) {
                    closeModal();
                }
            });
            joinButton.addEventListener('click', () => submitEnrollment('join'));
            leaveButton.addEventListener('click', () => submitEnrollment('leave'));
            itemsContainer.addEventListener('click', (event) => {
                const button = event.target.closest('.event-item-action');
                if (!button) {
                    return;
                }
                submitEnrollment(button.dataset.action, button.dataset.itemId);
            });

            if (window.AUTO_REFRESH) { setTimeout(() => { const url = new URL(window.location.href); url.searchParams.delete('refresh'); window.location.replace(url.toString()); }, 1000); }
        })();
    

        document.addEventListener('DOMContentLoaded', () => {
            const ctxMsg = document.getElementById('ctxMsg');
            if (ctxMsg) {
                setTimeout(() => {
                    ctxMsg.style.transition = 'all 0.5s ease';
                    ctxMsg.style.opacity = '0';
                    ctxMsg.style.transform = 'translateX(-10px)';
                    setTimeout(() => ctxMsg.remove(), 500);
                }, 4000);
            }

            const statusAlert = document.querySelector('.status-alert');
            if (statusAlert) {
                setTimeout(() => {
                    statusAlert.style.transition = 'all 0.5s ease';
                    statusAlert.style.opacity = '0';
                    statusAlert.style.transform = 'translateY(-10px)';
                    setTimeout(() => statusAlert.remove(), 500);
                }, 3000);
            }
            // --- LÃ³gica do Popup de AniversÃ¡rio (Mobile/Desktop) ---
            const bdayBackdrop = document.getElementById('bdayModalBackdrop');
            const bdayName     = document.getElementById('bdayModalName');
            let bdayTimer      = null;

            document.addEventListener('click', (e) => {
                const trigger = e.target.closest('.bday-trigger');
                if (!trigger) return;

                // Detecta se Ã© mobile (largura < 1024px ou se possui touch)
                const isMobile = window.innerWidth <= 1024 || ('ontouchstart' in window);

                if (isMobile) {
                    const fullName = trigger.getAttribute('data-full-name');
                    bdayName.textContent = fullName;
                    bdayBackdrop.classList.add('open');

                    // Fecha automaticamente apÃ³s 3 segundos
                    if (bdayTimer) clearTimeout(bdayTimer);
                    bdayTimer = setTimeout(() => {
                        bdayBackdrop.classList.remove('open');
                    }, 3000);
                }
            });

            // Permite fechar manualmente clicando fora (mesmo no mobile)
            if (bdayBackdrop) {
                bdayBackdrop.onclick = (e) => {
                    if (e.target === bdayBackdrop) {
                        bdayBackdrop.classList.remove('open');
                        if (bdayTimer) clearTimeout(bdayTimer);
                    }
                };
            }
        });
    


