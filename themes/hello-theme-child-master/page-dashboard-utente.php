<?php
/* Template Name: Dashboard Pro Membership */

// Evita che plugin/cache CDN servano una pagina utente a un altro utente.
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
if (function_exists('nocache_headers')) {
    nocache_headers();
}

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();

// Gate: questa dashboard deve essere accessibile solo ad utenti con abbonamento attivo.
// (Gli admin possono entrare comunque.)
if (!function_exists('pmpro_getMembershipLevelForUser')) {
    status_header(500);
    get_header();
    ?>
    <main id="content" class="site-main">
        <div class="dashboard-pro">
            <h2>Gestione Abbonamento</h2>
            <p>Il sistema abbonamenti non risulta disponibile in questo momento.</p>
        </div>
    </main>
    <?php
    get_footer();
    exit;
}

$membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
if (empty($membership_level) && !current_user_can('manage_options')) {
    status_header(403);

    $levels_url = function_exists('pmpro_url') ? pmpro_url('levels') : home_url('/');

    get_header();
    ?>
    <main id="content" class="site-main">
        <div class="dashboard-pro">
            <h2>Accesso riservato</h2>
            <p>Questa pagina è disponibile solo per utenti con un abbonamento attivo.</p>
            <p><a href="<?php echo esc_url($levels_url); ?>">Vai ai piani di abbonamento</a></p>
        </div>
    </main>
    <?php
    get_footer();
    exit;
}

$categoria_slug = '';
$limite_giochi = -1;

if ($membership_level) {
    switch (strtolower($membership_level->name)) {
        case 'welcome':
            $categoria_slug = 'welcome';
            $limite_giochi = 1;
            break;
        case 'professional':
            $categoria_slug = 'professional';
            break;
        case 'gold':
            $categoria_slug = 'gold';
            break;
    }
}

$giochi = [];
$giochi_query = new WP_Query([
    'post_type' => 'gioco',
    'posts_per_page' => $limite_giochi,
    'orderby' => 'date',
    'order' => 'DESC',
    'tax_query' => [
        [
            'taxonomy' => 'categoria_giochi',
            'field' => 'slug',
            'terms' => $categoria_slug
        ]
    ]
]);
if ($giochi_query->have_posts()) {
    while ($giochi_query->have_posts()) {
        $giochi_query->the_post();
        $giochi[] = [
            'id' => get_the_ID(),
            'title' => get_the_title(),
            'permalink' => get_permalink(),
        ];
    }
    wp_reset_postdata();
}

$corsi = [];
$corsi_query = new WP_Query([
    'post_type' => 'courses',
    'posts_per_page' => -1,
    'tax_query' => [
        [
            'taxonomy' => 'course-category',
            'field' => 'slug',
            'terms' => 'professional'
        ]
    ]
]);
if ($corsi_query->have_posts()) {
    while ($corsi_query->have_posts()) {
        $corsi_query->the_post();
        $corsi[] = [
            'id' => get_the_ID(),
            'titolo' => get_the_title(),
            'link' => get_permalink(),
        ];
    }
    wp_reset_postdata();
}

get_header(); ?>

<main id="content" class="site-main">
    <div class="dashboard-pro">
        <h2>Gestione Abbonamento</h2>

        <p style="margin: 0.5rem 0 1rem; color:#444;">
            <strong>Sei loggato come:</strong>
            <?php echo esc_html($current_user->user_email); ?> (ID <?php echo intval($current_user->ID); ?>)
        </p>

        <div class="pmpro-membership-tabs">
            <button onclick="toggleTab('attivita')">📄 Attività</button>
            <button onclick="toggleTab('rubrica-contatti')">📄 Rubrica Contatti</button>
            <button onclick="toggleTab('gestione-inviti')">📄 Gestione inviti</button>
            <button onclick="toggleTab('membership-info')">📄 Dettagli Abbonamento</button>
            <button onclick="toggleTab('invoice-history')">💳 Storico Pagamenti</button>
            <button onclick="toggleTab('change-level')">🔁 Cambia Piano</button>
            <button onclick="toggleTab('cancel-membership')">❌ Disdici Abbonamento</button>
        </div>

        <div id="attivita" class="pmpro-tab-content">
            <h3>Giochi disponibili</h3>
            <ul class="giochi-list">
                <?php foreach ($giochi as $gioco): ?>
                    <li class="gioco-item">
                        <h3><?php echo esc_html($gioco['title']); ?></h3>
                        <a href="<?php echo esc_url($gioco['permalink']); ?>">Vai al gioco</a>
                        <button class="open-invite-modal" data-gioco-id="<?php echo $gioco['id']; ?>" data-gioco-title="<?php echo esc_attr($gioco['title']); ?>">Invita</button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h3>Corsi disponibili</h3>
            <div class="d-flex" style="gap:10px; flex-wrap: wrap;">
                <?php foreach ($corsi as $corso): ?>
                    <div class="corso-box mr-2" style="margin-bottom:20px;">
                        <strong><?php echo esc_html($corso['titolo']); ?></strong><br>
                        <a href="<?php echo esc_url($corso['link']); ?>">Vai al corso</a>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3>Tool Scrivania</h3>
            <a href="/tool-scrivania">Vai alla Scrivania</a>
            <button id="openScrivaniaModal">Invita al Tool</button>

            <?php if (pmpro_hasMembershipLevel("Gold")): ?>
                <h3>🎁 Contenuti Extra (solo Gold)</h3>
                <p>Accesso a contenuti esclusivi in arrivo...</p>
            <?php endif; ?>
        </div>

        <div id="rubrica-contatti" class="pmpro-tab-content" style="display:none;">
            <h3>Aggiungi Contatto</h3>
            <form id="aggiungiContattoForm" action="#" method="post">
                <input type="text" id="contatto_nome" name="nome" placeholder="Nome" required>
                <input type="email" id="contatto_email" name="email" placeholder="Email" required>
                <button type="submit">Aggiungi contatto</button>
            </form>
            <div id="rubrica_msg"></div>

            <h3>Rubrica</h3>
            <div id="rubricaContatti">
                <p>Caricamento contatti...</p>
            </div>
        </div>

        <div id="gestione-inviti" class="pmpro-tab-content" style="display:none;">
            <h3>Gestione Inviti (Scrivania)</h3>

            <div style="display:flex; gap:12px; align-items:center; flex-wrap: wrap; margin: 0.75rem 0 0.5rem;">
                <div>
                    <label for="scrivaniaSessionSelect" style="font-weight:600;">Sessione:</label>
                    <select id="scrivaniaSessionSelect" style="margin-left:6px;"></select>
                </div>

                <a id="scrivaniaOpenToolLink" href="#" target="_blank" rel="noopener noreferrer" style="display:none;">Apri la sessione</a>

                <button id="openScrivaniaModalFromInvites" type="button">Invita al Tool</button>
                <button id="scrivaniaInvitesRefresh" type="button">Aggiorna elenco</button>
            </div>

            <div id="scrivaniaInvitesMsg" style="margin: 0.5rem 0;"></div>

            <div id="scrivaniaInvitesTableWrap">
                <p>Caricamento inviti...</p>
            </div>
        </div>

        <div id="membership-info" class="pmpro-tab-content" style="display:none;">
            <h3>Dettagli Attuali</h3>
            <?php echo do_shortcode('[pmpro_account sections="membership"]'); ?>
        </div>

        <div id="invoice-history" class="pmpro-tab-content" style="display:none;">
            <h3>Storico Fatture</h3>
            <?php echo do_shortcode('[pmpro_account sections="invoices"]'); ?>
        </div>

        <div id="change-level" class="pmpro-tab-content" style="display:none;">
            <h3>Cambia Piano</h3>
            <p><a href="<?php echo esc_url(pmpro_url('levels')); ?>">Vai alla pagina cambio piano</a></p>
        </div>

        <div id="cancel-membership" class="pmpro-tab-content" style="display:none;">
            <h3>Disdici Abbonamento</h3>
            <p><a href="<?php echo esc_url(pmpro_url('cancel')); ?>">Vai alla pagina disdetta</a></p>
        </div>
    </div>
</main>

<?php
$scrivania_deck_options = function_exists('gim_get_scrivania_deck_options')
    ? gim_get_scrivania_deck_options()
    : array(
        array('id' => 0, 'label' => 'Mazzo 0 (verticale)'),
        array('id' => 1, 'label' => 'Mazzo 1 (orizzontale 4/3)'),
    );
?>

<!-- Modale per invito ai Giochi  -->
<div id="inviteModal" style="display:none; position:fixed; top:10%; left:50%; transform:translateX(-50%); background:#fff; padding:20px; box-shadow:0 0 10px rgba(0,0,0,0.2); z-index:1000; max-width:400px; width:100%;">
    <h3 id="modalGiocoTitle"></h3>
    <p>Seleziona i contatti da invitare:</p>
    <div id="contattiModalList">
        <p>Caricamento contatti...</p>
    </div>
    <input type="hidden" id="modalGiocoId">
    <button id="sendInvites">Invia inviti</button>
    <button id="closeModal">Chiudi</button>
    <div id="inviteResponse" style="margin-top:10px;"></div>
</div>

<!-- Modale per invito al Tool Scrivania -->
<div id="scrivaniaInviteModal" style="display:none; position:fixed; top:10%; left:50%; transform:translateX(-50%); background:#fff; padding:20px; box-shadow:0 0 10px rgba(0,0,0,0.2); z-index:1000; max-width:400px; width:100%;">
    <h3>Invita al Tool Scrivania</h3>
    <div id="scrivaniaContattiList">
        <p>Caricamento contatti...</p>
    </div>
    <label for="scrivania_mazzo" style="display:block; margin-top:12px;">Mazzo:</label>
    <select id="scrivania_mazzo" style="display:block; width:100%; margin-bottom:10px;">
        <?php foreach ($scrivania_deck_options as $deck_option) : ?>
            <option value="<?php echo esc_attr($deck_option['id']); ?>"><?php echo esc_html($deck_option['label']); ?></option>
        <?php endforeach; ?>
    </select>
    <label for="data_scrivania" style="display:block;">Data:</label>
    <input type="date" id="data_scrivania" style="display:block; width:100%; margin-bottom:10px;">
    <label for="ora_scrivania" style="display:block;">Orario:</label>
    <input type="time" id="ora_scrivania" style="display:block; width:100%; margin-bottom:10px;">
    <button id="sendScrivaniaInvites">Invia inviti</button>
    <button id="closeScrivaniaModal">Chiudi</button>
    <div id="scrivaniaInviteResponse"></div>
</div>
<div id="modalBackdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:999;"></div>


<style>

    #contatto_nome, #contatto_email {
        margin-bottom: 5px;
    }
    .pmpro-membership-tabs button {
        margin: 0.5rem;
        padding: 0.5rem 1rem;
        font-weight: bold;
    }

    .pmpro-tab-content {
        margin-top: 1rem;
        padding: 1rem;
        border: 1px solid #ccc;
        background-color: #f9f9f9;
    } 

    .rubrica-contact-list {
        display: grid;
        gap: 12px;
        margin-top: 0.75rem;
    }
    .rubrica-contact-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 14px;
        border: 1px solid #ddd;
        border-radius: 10px;
        background: #fff;
    }
    .rubrica-contact-main {
        display: flex;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
    }
    .rubrica-contact-name {
        font-weight: 600;
        color: #111;
    }
    .rubrica-contact-email {
        color: #555;
        word-break: break-all;
    }
    .rubrica-delete-btn {
        border: 1px solid #d9a29a;
        background: #fff2ef;
        color: #9f2d1b;
        padding: 8px 12px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
    }
    .rubrica-delete-btn:disabled {
        opacity: 0.65;
        cursor: wait;
    }
    .rubrica-empty-state {
        margin: 0;
        color: #666;
    }
    @media (max-width: 640px) {
        .rubrica-contact-item {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    .scrivania-invites-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 0.75rem;
        background: #fff;
    }
    .scrivania-invites-table th,
    .scrivania-invites-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
        vertical-align: top;
        font-size: 14px;
    }
    .scrivania-invites-table th {
        background: #f3f3f3;
        font-weight: 600;
    }
    .scrivania-invites-status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        border: 1px solid #ddd;
        background: #fafafa;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .scrivania-invites-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }
</style>

<script type="text/javascript">
    var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";
    var scrivaniaDashboardInvitesNonce = "<?php echo esc_js(wp_create_nonce('scrivania_dashboard_invites')); ?>";
    var rubricaContattiNonce = "<?php echo esc_js(wp_create_nonce('rubrica_contatti')); ?>";
</script>

<script>
    function toggleTab(id) {
        const tabs = document.querySelectorAll('.pmpro-tab-content');
        tabs.forEach(tab => tab.style.display = 'none');
        document.getElementById(id).style.display = 'block';

        if (id === 'gestione-inviti' && typeof window.scrivaniaDashboardLoadInvites === 'function') {
            window.scrivaniaDashboardLoadInvites();
        }

        if (id === 'rubrica-contatti' && typeof window.dashboardCaricaRubrica === 'function') {
            window.dashboardCaricaRubrica();
        }
    }
    jQuery(document).ready(function($) {
        function escapeHtml(str) {
            return String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function setScrivaniaInvitesMsg(html, type) {
            let color = '#444';
            if (type === 'error') color = 'red';
            if (type === 'success') color = 'green';
            $('#scrivaniaInvitesMsg').html('<div style="color:' + color + ';">' + html + '</div>');
        }

        function openScrivaniaInviteModal() {
            $.post(ajaxurl, {
                action: 'carica_contatti_utente',
                modal: true
            }, function(data) {
                $('#scrivaniaContattiList').html(data);
                $('#scrivaniaInviteResponse').html('');
                $('#scrivaniaInviteModal, #modalBackdrop').show();
            });
        }

        function caricaRubrica() {
            $.post(ajaxurl, {
                action: 'carica_contatti_utente'
            }, function(data) {
                $('#rubricaContatti').html(data);
            }).fail(function() {
                $('#rubricaContatti').html('<div style="color:red;">Errore nel caricamento contatti. Riprova.</div>');
            });
        }

        // Espone la funzione per usarla da toggleTab()
        window.dashboardCaricaRubrica = caricaRubrica;

        function getRubricaFeedbackStatus(html) {
            return $('<div>').html(String(html ?? '')).find('.rubrica-feedback').first().data('status') || '';
        }

        $('#aggiungiContattoForm').on('submit', function(e) {
            e.preventDefault();

            const $form = $(this);
            const $submit = $form.find('button[type="submit"]');
            const nome = $.trim($('#contatto_nome').val());
            const email = $.trim($('#contatto_email').val());

            if (!nome || !email) {
                $('#rubrica_msg').html('<div class="rubrica-feedback" data-status="error" style="color:red;">Compila nome ed email.</div>');
                return;
            }

            $submit.prop('disabled', true);
            $('#rubrica_msg').html('<div class="rubrica-feedback" data-status="info" style="color:#444;">Salvataggio in corso...</div>');

            $.post(ajaxurl, {
                action: 'aggiungi_contatto_utente',
                nonce: rubricaContattiNonce,
                nome: nome,
                email: email
            }, function(response) {
                const feedbackHtml = String(response ?? '');

                $('#rubrica_msg').html(feedbackHtml);

                if (getRubricaFeedbackStatus(feedbackHtml) === 'success') {
                    if ($form.length && $form[0]) {
                        $form[0].reset();
                    }
                    caricaRubrica();
                }
            }).fail(function() {
                $('#rubrica_msg').html('<div class="rubrica-feedback" data-status="error" style="color:red;">Errore di rete durante il salvataggio. Riprova.</div>');
            }).always(function() {
                $submit.prop('disabled', false);
            });
        });

        $('#rubricaContatti').on('click', '.rubrica-delete-btn', function() {
            const $button = $(this);
            const email = String($button.data('contact-email') || '');
            const name = String($button.data('contact-name') || '').trim();
            const label = name ? (name + ' <' + email + '>') : email;

            if (!email) {
                $('#rubrica_msg').html('<div class="rubrica-feedback" data-status="error" style="color:red;">Contatto non valido.</div>');
                return;
            }

            if (!window.confirm('Vuoi eliminare questo contatto dalla rubrica?\n' + label)) {
                return;
            }

            $button.prop('disabled', true);
            $('#rubrica_msg').html('<div class="rubrica-feedback" data-status="info" style="color:#444;">Eliminazione in corso...</div>');

            $.post(ajaxurl, {
                action: 'elimina_contatto_utente',
                nonce: rubricaContattiNonce,
                email: email
            }, function(response) {
                const feedbackHtml = String(response ?? '');

                $('#rubrica_msg').html(feedbackHtml);

                if (getRubricaFeedbackStatus(feedbackHtml) === 'success') {
                    caricaRubrica();
                }
            }).fail(function() {
                $('#rubrica_msg').html('<div class="rubrica-feedback" data-status="error" style="color:red;">Errore di rete durante l\'eliminazione. Riprova.</div>');
            }).always(function() {
                $button.prop('disabled', false);
            });
        });

        function caricaContattiPerModale() {
            $.post(ajaxurl, {
                action: 'carica_contatti_utente',
                modal: true
            }, function(data) {
                $('#contattiModalList').html(data);
            });
        }

            $('#openScrivaniaModal').on('click', openScrivaniaInviteModal);
            $('#openScrivaniaModalFromInvites').on('click', openScrivaniaInviteModal);

        $('#closeScrivaniaModal, #modalBackdrop').on('click', function() {
            $('#scrivaniaInviteModal, #modalBackdrop').hide();
        });

        $('#sendScrivaniaInvites').on('click', function() {
            let emails = [];
            $('input[name="contatto_modal_check[]"]:checked').each(function() {
                emails.push($(this).val());
            });
            let data = $('#data_scrivania').val();
            let ora = $('#ora_scrivania').val();
            let mazzoId = $('#scrivania_mazzo').val();
            if (emails.length === 0 || !data || !ora) {
                $('#scrivaniaInviteResponse').html('<div style="color:red;">Seleziona almeno un contatto e inserisci data e orario.</div>');
                return;
            }
            $.post(ajaxurl, {
                action: 'attiva_scrivania',
                email_destinatario: emails.join(','),
                data_invito: data,
                ora_invito: ora,
                mazzo_id: mazzoId
            }, function(response) {
                $('#scrivaniaInviteResponse').html(response);

                // Aggiorna la tab gestione-inviti se è aperta
                if ($('#gestione-inviti').is(':visible') && typeof window.scrivaniaDashboardLoadInvites === 'function') {
                    window.scrivaniaDashboardLoadInvites($('#scrivaniaSessionSelect').val());
                }
            });
        });

        function renderScrivaniaSessions(sessions, selectedSessionId) {
            const $sel = $('#scrivaniaSessionSelect');
            const current = String(selectedSessionId ?? '');

            $sel.empty();
            if (!sessions || sessions.length === 0) {
                $sel.append('<option value="">Nessuna sessione</option>');
                return;
            }

            sessions.forEach(s => {
                const id = String(s.id);
                const archived = s.archived ? ' (archiviata)' : '';
                const name = s.name ? s.name : ('Sessione ' + id);
                const created = s.created_at ? (' - ' + String(s.created_at).slice(0, 10)) : '';
                const label = escapeHtml(name + ' #' + id + archived + created);
                const selectedAttr = (id === current) ? ' selected' : '';
                $sel.append('<option value="' + escapeHtml(id) + '"' + selectedAttr + '>' + label + '</option>');
            });
        }

        function renderScrivaniaInvites(invites, features) {
            if (!invites || invites.length === 0) {
                $('#scrivaniaInvitesTableWrap').html('<p>Nessun invito trovato per questa sessione.</p>');
                return;
            }

            const canRole = !!(features && features.role);
            let html = '';
            html += '<table class="scrivania-invites-table">';
            html += '<thead><tr>';
            html += '<th>Email</th>';
            html += '<th>Ruolo</th>';
            html += '<th>Stato</th>';
            html += '<th>Invito</th>';
            html += '<th>Azioni</th>';
            html += '</tr></thead>';
            html += '<tbody>';

            invites.forEach(inv => {
                const id = inv.id;
                const email = escapeHtml(inv.email || '');
                const role = String(inv.role || 'viewer');
                const status = String(inv.status || 'pending');

                const statusLabel = escapeHtml(status);
                const statusPill = '<span class="scrivania-invites-status">' + statusLabel + '</span>';

                const whenParts = [];
                if (inv.data_invito) whenParts.push(escapeHtml(inv.data_invito));
                if (inv.ora_invito) whenParts.push(escapeHtml(inv.ora_invito));
                const whenStr = whenParts.length ? whenParts.join(' ') : '-';

                let meta = '';
                if (inv.invitato_name) {
                    meta += '<div><strong>' + escapeHtml(inv.invitato_name) + '</strong></div>';
                }
                if (inv.invitato_user_id) {
                    meta += '<div style="color:#666;">User ID: ' + escapeHtml(inv.invitato_user_id) + '</div>';
                }
                if (inv.last_sent_at) {
                    meta += '<div style="color:#666;">Ultimo invio: ' + escapeHtml(inv.last_sent_at) + '</div>';
                } else if (inv.created_at) {
                    meta += '<div style="color:#666;">Creato: ' + escapeHtml(inv.created_at) + '</div>';
                }
                if (inv.resend_count !== null && inv.resend_count !== undefined) {
                    meta += '<div style="color:#666;">Reinvii: ' + escapeHtml(inv.resend_count) + '</div>';
                }

                let roleCell = '-';
                if (canRole) {
                    const viewerSel = role === 'viewer' ? ' selected' : '';
                    const editorSel = role === 'editor' ? ' selected' : '';
                    roleCell = '' +
                        '<select class="scrivaniaRoleSelect" data-invite-id="' + escapeHtml(id) + '">' +
                        '<option value="viewer"' + viewerSel + '>viewer</option>' +
                        '<option value="editor"' + editorSel + '>editor</option>' +
                        '</select>';
                }

                const resendLabel = (status === 'revoked') ? 'Reinvita' : 'Reinvia';
                const revokeDisabled = (status === 'revoked') ? ' disabled' : '';

                html += '<tr>';
                html += '<td>' + email + '</td>';
                html += '<td>' + roleCell + '</td>';
                html += '<td>' + statusPill + '</td>';
                html += '<td>';
                html += '<div><strong>Quando:</strong> ' + whenStr + '</div>';
                html += meta;
                html += '</td>';
                html += '<td>';
                html += '<div class="scrivania-invites-actions">';
                html += '<button type="button" class="scrivaniaResendBtn" data-invite-id="' + escapeHtml(id) + '">' + resendLabel + '</button>';
                html += '<button type="button" class="scrivaniaRevokeBtn" data-invite-id="' + escapeHtml(id) + '"' + revokeDisabled + '>Revoca</button>';
                html += '</div>';
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            $('#scrivaniaInvitesTableWrap').html(html);
        }

        function updateScrivaniaToolLink(sessions, selectedSessionId) {
            const $link = $('#scrivaniaOpenToolLink');
            let url = null;
            if (sessions && sessions.length) {
                const sid = String(selectedSessionId);
                const found = sessions.find(s => String(s.id) === sid);
                if (found && found.tool_link) {
                    url = found.tool_link;
                }
            }

            if (url) {
                $link.attr('href', url).show();
            } else {
                $link.attr('href', '#').hide();
            }
        }

        function loadScrivaniaInvites(sessionId) {
            setScrivaniaInvitesMsg('', '');
            $('#scrivaniaInvitesTableWrap').html('<p>Caricamento inviti...</p>');

            $.post(ajaxurl, {
                action: 'scrivania_dashboard_get_invites',
                nonce: scrivaniaDashboardInvitesNonce,
                session_id: sessionId || ''
            }, function(resp) {
                if (!resp || !resp.success) {
                    const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Errore nel caricamento inviti.';
                    setScrivaniaInvitesMsg(escapeHtml(msg), 'error');
                    $('#scrivaniaInvitesTableWrap').html('<p>Impossibile caricare.</p>');
                    return;
                }

                const data = resp.data || {};
                renderScrivaniaSessions(data.sessions || [], data.selected_session_id || 0);
                updateScrivaniaToolLink(data.sessions || [], data.selected_session_id || 0);
                renderScrivaniaInvites(data.invites || [], data.features || {});
            }, 'json').fail(function() {
                setScrivaniaInvitesMsg('Errore di rete durante il caricamento.', 'error');
                $('#scrivaniaInvitesTableWrap').html('<p>Impossibile caricare.</p>');
            });
        }

        // Expose per toggleTab()
        window.scrivaniaDashboardLoadInvites = loadScrivaniaInvites;

        $('#scrivaniaInvitesRefresh').on('click', function() {
            loadScrivaniaInvites($('#scrivaniaSessionSelect').val());
        });

        $('#scrivaniaSessionSelect').on('change', function() {
            loadScrivaniaInvites($(this).val());
        });

        $('#scrivaniaInvitesTableWrap').on('change', '.scrivaniaRoleSelect', function() {
            const inviteId = $(this).data('invite-id');
            const role = $(this).val();
            setScrivaniaInvitesMsg('Salvataggio ruolo...', '');

            $.post(ajaxurl, {
                action: 'scrivania_dashboard_update_invite_role',
                nonce: scrivaniaDashboardInvitesNonce,
                invite_id: inviteId,
                role: role
            }, function(resp) {
                if (!resp || !resp.success) {
                    const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Errore nel salvataggio del ruolo.';
                    setScrivaniaInvitesMsg(escapeHtml(msg), 'error');
                    return;
                }
                setScrivaniaInvitesMsg('Ruolo aggiornato.', 'success');
            }, 'json').fail(function() {
                setScrivaniaInvitesMsg('Errore di rete durante il salvataggio.', 'error');
            });
        });

        $('#scrivaniaInvitesTableWrap').on('click', '.scrivaniaRevokeBtn', function() {
            const inviteId = $(this).data('invite-id');
            if (!inviteId) return;
            if (!confirm('Vuoi revocare questo invito?')) return;

            setScrivaniaInvitesMsg('Revoca in corso...', '');
            $.post(ajaxurl, {
                action: 'scrivania_dashboard_revoke_invite',
                nonce: scrivaniaDashboardInvitesNonce,
                invite_id: inviteId
            }, function(resp) {
                if (!resp || !resp.success) {
                    const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Errore durante la revoca.';
                    setScrivaniaInvitesMsg(escapeHtml(msg), 'error');
                    return;
                }
                setScrivaniaInvitesMsg('Invito revocato.', 'success');
                loadScrivaniaInvites($('#scrivaniaSessionSelect').val());
            }, 'json').fail(function() {
                setScrivaniaInvitesMsg('Errore di rete durante la revoca.', 'error');
            });
        });

        $('#scrivaniaInvitesTableWrap').on('click', '.scrivaniaResendBtn', function() {
            const inviteId = $(this).data('invite-id');
            if (!inviteId) return;

            setScrivaniaInvitesMsg('Invio email in corso...', '');
            $.post(ajaxurl, {
                action: 'scrivania_dashboard_resend_invite',
                nonce: scrivaniaDashboardInvitesNonce,
                invite_id: inviteId
            }, function(resp) {
                if (!resp || !resp.success) {
                    const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Errore durante l\'invio.';
                    setScrivaniaInvitesMsg(escapeHtml(msg), 'error');
                    return;
                }
                setScrivaniaInvitesMsg('Email inviata.', 'success');
                loadScrivaniaInvites($('#scrivaniaSessionSelect').val());
            }, 'json').fail(function() {
                setScrivaniaInvitesMsg('Errore di rete durante l\'invio.', 'error');
            });
        });

        $('.open-invite-modal').on('click', function() {
            let giocoId = $(this).data('gioco-id');
            let giocoTitle = $(this).data('gioco-title');
            $('#modalGiocoId').val(giocoId);
            $('#modalGiocoTitle').text("Invita a: " + giocoTitle);
            $('#inviteResponse').html('');
            caricaContattiPerModale();
            $('#inviteModal, #modalBackdrop').show();
        });

        $('#closeModal, #modalBackdrop').on('click', function() {
            $('#inviteModal, #modalBackdrop').hide();
        });

        $('#sendInvites').on('click', function() {
            let giocoId = $('#modalGiocoId').val();
            let emails = [];
            $('input[name="contatto_modal_check[]"]:checked').each(function() {
                emails.push($(this).val());
            });
            if (emails.length === 0) {
                $('#inviteResponse').html('<div style="color:red;">Seleziona almeno un contatto.</div>');
                return;
            }
            $.post(ajaxurl, {
                action: 'attiva_gioco',
                gioco_id: giocoId,
                email_destinatario: emails.join(',')
            }, function(response) {
                $('#inviteResponse').html(response);
            });
        });


    });
</script>

<?php get_footer(); ?>