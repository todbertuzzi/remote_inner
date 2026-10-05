/* Invitation dialogs: selection, sending and a persistent result screen. */
jQuery(function ($) {
    'use strict';
    const $dialogs = $('.dashboard-modal[data-invite-kind]');
    if (!$dialogs.length) return;
    let active = null;
    let opener = null;
    let contactsRequest = null;
    let inertElements = [];

    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
    const selected = $dialog => $dialog.find('.invite-contact-list input:checked').map(function () { return this.value; }).get();
    const isDesk = $dialog => $dialog.data('invite-kind') === 'scrivania';
    const safeUrl = value => {
        try {
            const url = new URL(value);
            return ['https:', 'http:'].includes(url.protocol) ? url.href : '';
        } catch (_) { return ''; }
    };
    function focusTitle($dialog) {
        $dialog.find('.invite-body').scrollTop(0);
        $dialog.find('h2').trigger('focus');
    }
    function updateSelection($dialog) {
        const count = selected($dialog).length;
        $dialog.find('.invite-count').text(count === 1 ? '1 selezionato' : count + ' selezionati');
        $dialog.find('[data-invite-submit]').prop('disabled', !count || !!$dialog.data('sending'))
            .text($dialog.data('sending') ? 'Invio in corso…' : count > 1 ? 'Invia ' + count + ' inviti' : 'Invia invito');
        if (count) $dialog.find('.invite-contacts .invite-field-error').text('');
    }
    function updateDeckPreview() {
        const $option = $('#scrivania_mazzo option:selected');
        const url = $option.attr('data-preview-url') || '';
        const label = $option.attr('data-label') || $option.text();
        $('#scrivaniaDeckPreviewLabel').text(label);
        $('#scrivaniaDeckPreviewDescription').text($option.attr('data-description') || '');
        $('#scrivaniaDeckPreviewImage').attr({ src: url, alt: url ? 'Anteprima ' + label : '' }).prop('hidden', !url);
    }
    function loadContacts($dialog) {
        const $list = $dialog.find('.invite-contact-list');
        $list.html('<p class="invite-hint" role="status">Caricamento contatti…</p>');
        $dialog.find('.invite-no-results').prop('hidden', true);
        $dialog.find('.invite-search').val('');
        updateSelection($dialog);
        contactsRequest = $.post(ajaxurl, {
            action: 'carica_contatti_utente', modal: true, single_select: isDesk($dialog) ? 0 : 1
        }).done(function (html) {
            $list.html(html);
            if (!$list.find('input').length) {
                // A successful empty list is different from a server/authentication error.
                const empty = $list.text().includes('Nessun contatto trovato');
                $list.empty().append($('<p>', { class: 'invite-hint', text: empty ? 'La rubrica è vuota. Aggiungi un contatto per invitarlo.' : 'Impossibile caricare i contatti.' }));
                $list.append(empty ? '<button type="button" class="invite-secondary" data-open-rubrica>Apri rubrica</button>' : '<button type="button" class="invite-secondary" data-retry-contacts>Riprova</button>');
            }
            updateSelection($dialog);
        }).fail(function (_, status) {
            if (status === 'abort') return;
            $list.html('<p class="invite-hint">Impossibile caricare i contatti.</p><button type="button" class="invite-secondary" data-retry-contacts>Riprova</button>');
        });
    }
    function openDialog($dialog, trigger) {
        if (active) return;
        active = $dialog;
        opener = trigger;
        $dialog.removeData('result-session').data('sending', false).removeAttr('aria-busy');
        $dialog.find('form')[0].reset();
        $dialog.find('.invite-form, .invite-subtitle').prop('hidden', false);
        $dialog.find('.invite-result, .invite-error').empty().prop('hidden', true);
        $dialog.find('.invite-field-error').text('');
        $dialog.find('[aria-invalid]').removeAttr('aria-invalid');
        $dialog.find('.invite-footer-note, [data-invite-submit]').prop('hidden', false);
        $dialog.find('.invite-footer [data-invite-close]').text('Annulla');
        $dialog.find('h2').text(isDesk($dialog) ? 'Invita alla Scrivania' : 'Invita al gioco');
        if (!isDesk($dialog)) {
            $('#modalGiocoId').val($(trigger).data('gioco-id'));
            $('#modalGiocoTitle').text($(trigger).data('gioco-title'));
        }
        $dialog.prop('hidden', false);
        $('#modalBackdrop').prop('hidden', false);
        $('body').addClass('invite-dialog-open');
        // Make the background unavailable to keyboard and assistive technology.
        inertElements = [];
        let branch = $dialog[0];
        while (branch.parentElement && branch !== document.body) {
            Array.from(branch.parentElement.children).forEach(element => {
                if (element !== branch && element.id !== 'modalBackdrop' && !element.inert && !['SCRIPT', 'STYLE', 'LINK'].includes(element.tagName)) {
                    element.inert = true;
                    inertElements.push(element);
                }
            });
            branch = branch.parentElement;
        }
        updateDeckPreview();
        focusTitle($dialog);
        loadContacts($dialog);
    }
    function closeDialog() {
        if (!active || active.data('sending')) return;
        if (contactsRequest) contactsRequest.abort();
        active.prop('hidden', true);
        $('#modalBackdrop').prop('hidden', true);
        $('body').removeClass('invite-dialog-open');
        inertElements.forEach(element => { element.inert = false; });
        inertElements = [];
        active = null;
        if (opener && opener.isConnected) opener.focus();
    }
    function showError($dialog, message) {
        $dialog.find('.invite-error').text(message).prop('hidden', false).attr('tabindex', '-1').trigger('focus');
    }
    function responseError(response) {
        if (response && typeof response === 'object') {
            return typeof response.data === 'string' ? response.data : response.data?.message || 'Impossibile creare l’invito. Ricarica la pagina e riprova.';
        }
        const text = new DOMParser().parseFromString(String(response || ''), 'text/html').body.textContent.trim();
        return text && !['0', '-1'].includes(text) && text.length < 500 ? text : 'Impossibile creare l’invito. Ricarica la pagina e riprova.';
    }
    function showResult($dialog, data) {
        const desk = isDesk($dialog);
        const url = safeUrl(data.url);
        const failed = Array.isArray(data.failed) ? data.failed : [];
        const sent = Number(data.sent) || 0;
        const recipients = Array.isArray(data.recipients) ? data.recipients : [];
        const title = desk ? 'Sessione pronta' : 'Invito creato';
        const when = data.date ? data.date.split('-').reverse().join('/') + ' · ore ' + data.time : '';
        const summary = desk ? $('#scrivania_mazzo option:selected').text() + (when ? ' · ' + when : '') : $('#modalGiocoTitle').text();
        const mailSummary = sent === 1 ? '1 invito inviato via email' : sent + ' inviti inviati via email';
        const openSession = desk ? '<a class="invite-primary" href="' + escape(url) + '" target="_blank" rel="noopener noreferrer">Apri la tua sessione</a>' : '';
        const shareNote = desk ? '' : '<p class="invite-hint"><em>Copia e condividi questo link con la persona invitata per consentirle di accedere alla partita</em></p>';
        const links = url ? '<div class="invite-result-actions">' + openSession + '<button type="button" class="invite-secondary" data-copy-url="' + escape(url) + '">' + (desk ? 'Copia il tuo link' : 'Copia link invito') + '</button></div>' + shareNote : '<p class="invite-warning">Il link di accesso non è disponibile. Recuperalo dalla gestione inviti.</p>';
        const warning = failed.length ? '<div class="invite-warning"><strong>' + (desk ? 'Sessione creata, ma alcuni inviti non sono stati inviati.' : 'Partita creata, ma l’email non è stata inviata.') + '</strong><p>' + failed.map(escape).join('<br>') + '</p><p>' + (desk ? 'Controlla i destinatari in Gestisci inviti. Per gli inviti presenti puoi reinviare l’email.' : 'Puoi copiare il link e condividerlo con il destinatario.') + '</p></div>' : '';
        $dialog.data('result-session', data.session_id);
        $dialog.find('.invite-form, .invite-subtitle, .invite-error, .invite-footer-note, [data-invite-submit]').prop('hidden', true);
        $dialog.find('h2').text(title);
        $dialog.find('.invite-footer [data-invite-close]').text('Chiudi');
        $dialog.find('.invite-result').html('<div class="invite-success-icon" aria-hidden="true">✓</div><p class="invite-result-summary">' + escape(summary) + '</p><p class="invite-result-mail">' + escape(mailSummary) + '</p><p class="invite-result-recipients">' + recipients.map(escape).join(' · ') + '</p>' + links + warning + (desk ? '<p class="invite-hint">Il tuo link è riservato a te. Ogni partecipante accede dal proprio invito personale.</p><button type="button" class="invite-text-button" data-manage-invites>Gestisci inviti →</button>' : '') + '<p class="invite-copy-status" role="status"></p>').prop('hidden', false);
        focusTitle($dialog);
    }
    function setSending($dialog, sending) {
        $dialog.data('sending', sending).attr('aria-busy', String(sending));
        $dialog.find('form :input, [data-invite-close]').prop('disabled', sending);
        updateSelection($dialog);
    }
    $dialogs.on('submit', 'form', function (event) {
        event.preventDefault();
        const $dialog = $(this).closest('.dashboard-modal');
        if ($dialog.data('sending')) return;
        $dialog.find('.invite-error').prop('hidden', true);
        $dialog.find('.invite-field-error').text('');
        $dialog.find('[aria-invalid]').removeAttr('aria-invalid');
        const emails = selected($dialog);
        if (!emails.length) {
            $dialog.find('.invite-contacts .invite-field-error').text('Seleziona almeno un contatto.');
            $dialog.find('.invite-search').trigger('focus');
            return;
        }
        const desk = isDesk($dialog);
        const payload = { response_format: 'json', email_destinatario: emails.join(',') };
        if (desk) {
            let invalid = null;
            ['data_scrivania', 'ora_scrivania'].forEach(id => {
                const field = document.getElementById(id);
                if (!field.value || !field.validity.valid) {
                    $('#' + id + '_error').text(id === 'data_scrivania' ? 'Scegli una data valida.' : 'Scegli un orario valido.');
                    $(field).attr('aria-invalid', 'true');
                    invalid = invalid || field;
                }
            });
            if (invalid) { invalid.focus(); return; }
            if ($('#scrivania_mazzo').val() === null) { showError($dialog, 'Nessun mazzo disponibile.'); return; }
            Object.assign(payload, { action: 'attiva_scrivania', nonce: scrivaniaCreateSessionNonce, data_invito: $('#data_scrivania').val(), ora_invito: $('#ora_scrivania').val(), mazzo_id: $('#scrivania_mazzo').val() });
        } else {
            Object.assign(payload, { action: 'attiva_gioco', nonce: gameInviteNonce, gioco_id: $('#modalGiocoId').val() });
        }
        setSending($dialog, true);
        $.post(ajaxurl, payload).done(function (response) {
            if (response && response.success === true && response.data?.session_id) {
                showResult($dialog, response.data);
                if (desk && !$('#gestione-inviti').prop('hidden') && typeof window.scrivaniaDashboardLoadInvites === 'function') window.scrivaniaDashboardLoadInvites(response.data.session_id);
            } else {
                showError($dialog, responseError(response));
            }
        }).fail(function (xhr) {
            // A lost response can happen after the server has created the session.
            showError($dialog, xhr.status === 0 || xhr.status >= 500 ? 'Non è stato possibile verificare l’esito. La richiesta potrebbe essere stata completata: verifica prima di inviare di nuovo.' : responseError(xhr.responseJSON || xhr.responseText));
        }).always(function () { setSending($dialog, false); });
    });
    $('#openScrivaniaModal, #openScrivaniaModalFromInvites').on('click', function () { openDialog($('#scrivaniaInviteModal'), this); });
    $('.open-invite-modal').on('click', function () { openDialog($('#inviteModal'), this); });
    $dialogs.on('click', '[data-invite-close]', closeDialog);
    $('#modalBackdrop').on('click', function () {
        // Keep the completed result visible until explicitly dismissed.
        if (active && active.find('.invite-result').prop('hidden')) closeDialog();
    });
    $dialogs.on('change', '.invite-contact-list input', function () { updateSelection($(this).closest('.dashboard-modal')); });
    $dialogs.on('input', '.invite-search', function () {
        const $dialog = $(this).closest('.dashboard-modal');
        const query = this.value.trim().toLocaleLowerCase('it');
        const $rows = $dialog.find('.invite-contact-list li');
        $rows.each(function () { this.hidden = !this.textContent.toLocaleLowerCase('it').includes(query); });
        $dialog.find('.invite-no-results').prop('hidden', !$rows.length || $rows.filter(function () { return !this.hidden; }).length > 0);
    });
    $dialogs.on('click', '[data-retry-contacts]', function () { loadContacts($(this).closest('.dashboard-modal')); });
    $dialogs.on('click', '[data-open-rubrica], [data-manage-invites]', function () {
        const manage = this.hasAttribute('data-manage-invites');
        const session = active.data('result-session');
        closeDialog();
        toggleTab(manage ? 'gestione-inviti' : 'rubrica-contatti', manage ? session : undefined);
        const tab = document.querySelector('.pmpro-membership-tabs [data-tab-target="' + (manage ? 'gestione-inviti' : 'rubrica-contatti') + '"]');
        if (tab) tab.focus();
    });
    $dialogs.on('click', '[data-copy-url]', async function () {
        const button = this;
        const $result = $(button).closest('.invite-result');
        try {
            if (!navigator.clipboard) throw new Error('Clipboard unavailable');
            await navigator.clipboard.writeText(button.dataset.copyUrl);
            $(button).text('Link copiato');
            $result.find('.invite-copy-status').text('Link copiato negli appunti.');
        } catch (_) {
            $result.find('.invite-copy-status').html('<label>Copia questo link:<input class="invite-copy-fallback" type="text" readonly value="' + escape(button.dataset.copyUrl) + '"></label>');
            $result.find('.invite-copy-fallback').trigger('focus').trigger('select');
        }
    });
    $(document).on('keydown.dashboardInvites', function (event) {
        if (!active) return;
        if (event.key === 'Escape') { event.preventDefault(); closeDialog(); }
        if (event.key === 'Tab') {
            const $focusable = active.find('a[href], button, input, select, [tabindex="0"]').filter(':visible').filter(':not(:disabled)');
            const first = $focusable[0];
            const last = $focusable[$focusable.length - 1];
            if (!first) { event.preventDefault(); return; }
            if (event.shiftKey && (document.activeElement === first || !$focusable.is(document.activeElement))) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || !$focusable.is(document.activeElement))) { event.preventDefault(); first.focus(); }
        }
    });
    $('#scrivania_mazzo').on('change', updateDeckPreview);
    updateDeckPreview();
});
