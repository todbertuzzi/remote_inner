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
            <h1>Gestione Abbonamento</h1>
            <p>Il sistema abbonamenti non risulta disponibile in questo momento.</p>
        </div>
    </main>
    <?php
    get_footer();
    exit;
}

$membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
$user_access_tier = function_exists('ipt_get_user_access_tier')
    ? ipt_get_user_access_tier($current_user->ID)
    : '';

if ((empty($membership_level) || $user_access_tier === '') && !current_user_can('manage_options')) {
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

$can_manage_scrivania = function_exists('scrivania_user_can_create_session')
    && scrivania_user_can_create_session($current_user->ID);

$allowed_content_tiers = function_exists('ipt_get_allowed_access_tiers')
    ? ipt_get_allowed_access_tiers($current_user->ID)
    : array();
$limite_giochi = $user_access_tier === 'welcome' ? 1 : -1;

$giochi = [];
$giochi_query_args = [
    'post_type' => 'gioco',
    'posts_per_page' => $limite_giochi,
    'orderby' => 'date',
    'order' => 'DESC',
];
if (!empty($allowed_content_tiers)) {
    $giochi_query_args['tax_query'] = [
        [
            'taxonomy' => 'categoria_giochi',
            'field' => 'slug',
            'terms' => $allowed_content_tiers,
            'operator' => 'IN',
        ]
    ];
} else {
    $giochi_query_args['post__in'] = [0];
}
$giochi_query = new WP_Query($giochi_query_args);
if ($giochi_query->have_posts()) {
    while ($giochi_query->have_posts()) {
        $giochi_query->the_post();
        if (!function_exists('ipt_user_can_access_content') || !ipt_user_can_access_content($current_user->ID, get_the_ID())) {
            continue;
        }

        $game_title = trim((string) get_field('titolo_gioco', get_the_ID()));
        if ($game_title === '') {
            $game_title = get_the_title();
        }

        $giochi[] = [
            'id' => get_the_ID(),
            'title' => $game_title,
        ];
    }
    wp_reset_postdata();
}

$corsi = [];
$corsi_query_args = [
    'post_type' => 'courses',
    'posts_per_page' => -1,
];
if (!empty($allowed_content_tiers)) {
    $corsi_query_args['tax_query'] = [
        [
            'taxonomy' => 'course-category',
            'field' => 'slug',
            'terms' => $allowed_content_tiers,
            'operator' => 'IN',
        ]
    ];
} else {
    $corsi_query_args['post__in'] = [0];
}
$corsi_query = new WP_Query($corsi_query_args);
if ($corsi_query->have_posts()) {
    while ($corsi_query->have_posts()) {
        $corsi_query->the_post();
        if (!function_exists('ipt_user_can_access_content') || !ipt_user_can_access_content($current_user->ID, get_the_ID())) {
            continue;
        }

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

        <p class="dashboard-user-summary">
            <strong>Sei loggato come:</strong>
            <?php echo esc_html($current_user->user_email); ?> (ID <?php echo intval($current_user->ID); ?>)
        </p>

        <div class="pmpro-membership-tabs" role="tablist" aria-label="Gestione Abbonamento">
            <button type="button" class="active" role="tab" data-tab-target="attivita" aria-selected="true" onclick="toggleTab('attivita')">📄 Attività</button>
            <button type="button" role="tab" data-tab-target="rubrica-contatti" aria-selected="false" onclick="toggleTab('rubrica-contatti')">📄 Rubrica Contatti</button>
            <?php if ($can_manage_scrivania): ?>
            <button type="button" role="tab" data-tab-target="gestione-inviti" aria-selected="false" onclick="toggleTab('gestione-inviti')">📄 Gestione inviti</button>
            <?php endif; ?>
            <button type="button" role="tab" data-tab-target="membership-info" aria-selected="false" onclick="toggleTab('membership-info')">📄 Dettagli Abbonamento</button>
            <button type="button" role="tab" data-tab-target="invoice-history" aria-selected="false" onclick="toggleTab('invoice-history')">💳 Storico Pagamenti</button>
            <button type="button" role="tab" data-tab-target="change-level" aria-selected="false" onclick="toggleTab('change-level')">🔁 Cambia Piano</button>
            <button type="button" role="tab" data-tab-target="cancel-membership" aria-selected="false" onclick="toggleTab('cancel-membership')">❌ Disdici Abbonamento</button>
        </div>

        <div id="attivita" class="pmpro-tab-content" role="tabpanel" aria-hidden="false">
            <h3>Giochi disponibili</h3>
            <?php if (!empty($giochi)) : ?>
                <div class="dashboard-content-list dashboard-games">
                    <?php foreach ($giochi as $gioco): ?>
                        <div class="dashboard-content-row">
                            <div class="dashboard-content-info">
                                <span class="dashboard-content-title">
                                    <?php echo esc_html($gioco['title']); ?>
                                </span>
                            </div>

                            <div class="dashboard-content-actions">
                                <form class="dashboard-game-preview-form" action="<?php echo esc_url(get_permalink($gioco['id'])); ?>" method="get">
                                    <button
                                        type="submit"
                                        class="dashboard-content-invite dashboard-content-preview"
                                        aria-label="<?php echo esc_attr('Anteprima: ' . $gioco['title']); ?>"
                                    >
                                        Anteprima
                                    </button>
                                </form>
                                <button
                                    type="button"
                                    class="dashboard-content-invite open-invite-modal"
                                    data-gioco-id="<?php echo intval($gioco['id']); ?>"
                                    data-gioco-title="<?php echo esc_attr($gioco['title']); ?>"
                                >
                                    Invita
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p>Non ci sono giochi disponibili.</p>
            <?php endif; ?>

            <h3>Corsi disponibili</h3>
            <?php if (!empty($corsi)) : ?>
                <div class="dashboard-content-list dashboard-courses">
                    <?php foreach ($corsi as $corso): ?>
                        <div class="dashboard-content-row">
                            <div class="dashboard-content-info">
                                <span class="dashboard-content-title">
                                    <?php echo esc_html($corso['titolo']); ?>
                                </span>
                            </div>

                            <div class="dashboard-content-actions">
                                <a href="<?php echo esc_url($corso['link']); ?>" class="dashboard-content-link">
                                    Vai al corso
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p>Non ci sono corsi disponibili.</p>
            <?php endif; ?>

            <?php if ($can_manage_scrivania): ?>
            <h3>Tool Scrivania</h3>
            <a href="/tool-scrivania">Vai alla Scrivania</a>
            <button id="openScrivaniaModal">Invita al Tool</button>

            <?php endif; ?>

            <?php if (in_array($user_access_tier, array('gold', 'admin'), true)): ?>
                <h3>Contenuti Extra (solo Gold)</h3>
                <a href="/area-gold/" class="dashboard-content-link">
                    Vai ai contenuti extra
                </a>
            <?php endif; ?>
        </div>

        <div id="rubrica-contatti" class="pmpro-tab-content" role="tabpanel" aria-hidden="true" hidden>
            <h3>Aggiungi Contatto</h3>
            <form id="aggiungiContattoForm" action="#" method="post">
                <input type="text" id="contatto_nome" name="nome" placeholder="Nome" required>
                <input type="email" id="contatto_email" name="email" placeholder="Email" required>
                <button id="addContact" type="submit">Aggiungi contatto</button>
            </form>
            <div id="rubrica_msg"></div>

            <h3>Rubrica</h3>
            <div id="rubricaContatti">
                <p>Caricamento contatti...</p>
            </div>
        </div>

        <?php if ($can_manage_scrivania): ?>
        <div id="gestione-inviti" class="pmpro-tab-content" role="tabpanel" aria-hidden="true" hidden>
            <h3>Gestione Inviti (Scrivania)</h3>

            <div class="scrivania-invites-toolbar">
                <div>
                    <label class="scrivania-session-label" for="scrivaniaSessionSelect">Sessione:</label>
                    <select id="scrivaniaSessionSelect"></select>
                </div>

                <a id="scrivaniaOpenToolLink" href="#" target="_blank" rel="noopener noreferrer" hidden>Apri la sessione</a>

                <button id="openScrivaniaModalFromInvites" type="button">Invita al Tool</button>
                <button id="scrivaniaInvitesRefresh" type="button">Aggiorna elenco</button>
            </div>

            <div id="scrivaniaInvitesMsg" class="dashboard-message-region" aria-live="polite"></div>

            <div id="scrivaniaInvitesTableWrap">
                <p>Caricamento inviti...</p>
            </div>
        </div>

        <?php endif; ?>

        <div id="membership-info" class="pmpro-tab-content" role="tabpanel" aria-hidden="true" hidden>
            <h3>Dettagli Attuali</h3>
            <?php echo do_shortcode('[pmpro_account sections="membership"]'); ?>
        </div>

        <div id="invoice-history" class="pmpro-tab-content" role="tabpanel" aria-hidden="true" hidden>
            <h3>Storico Fatture</h3>
            <?php echo do_shortcode('[pmpro_account sections="invoices"]'); ?>
        </div>

        <div id="change-level" class="pmpro-tab-content" role="tabpanel" aria-hidden="true" hidden>
            <h3>Cambia Piano</h3>
            <p><a href="<?php echo esc_url(pmpro_url('levels')); ?>">Vai alla pagina cambio piano</a></p>
        </div>

        <div id="cancel-membership" class="pmpro-tab-content" role="tabpanel" aria-hidden="true" hidden>
            <h3>Disdici Abbonamento</h3>
            <p><a href="<?php echo esc_url(pmpro_url('cancel')); ?>">Vai alla pagina disdetta</a></p>
        </div>
    </div>
</main>

<?php
$scrivania_deck_options = function_exists('scrivania_get_deck_options')
    ? scrivania_get_deck_options()
    : (function_exists('gim_get_scrivania_deck_options')
        ? gim_get_scrivania_deck_options()
    : array(
        array('id' => 0, 'label' => 'Mazzo 0', 'description' => 'Verticale', 'preview_url' => ''),
        array('id' => 1, 'label' => 'Mazzo 1', 'description' => 'Orizzontale 4/3', 'preview_url' => ''),
    ));
$scrivania_initial_deck = !empty($scrivania_deck_options)
    ? reset($scrivania_deck_options)
    : array('id' => '', 'label' => '', 'description' => '', 'preview_url' => '');
?>

<section id="inviteModal" class="dashboard-modal" role="dialog" aria-modal="true" aria-labelledby="inviteModalTitle" data-invite-kind="game" hidden>
    <header class="invite-header">
        <div><p class="invite-eyebrow">Giochi</p><h2 id="inviteModalTitle" tabindex="-1">Invita al gioco</h2>
        <p class="invite-subtitle" id="modalGiocoTitle"></p></div>
        <button type="button" class="invite-close" data-invite-close aria-label="Chiudi la finestra">×</button>
    </header>
    <div class="invite-body">
        <form id="gameInviteForm" class="invite-form" novalidate>
            <div class="invite-contacts">
                <div class="invite-section-heading"><label for="inviteModalSearch">Partecipante</label><span class="invite-count" aria-live="polite">0 selezionati</span></div>
                <p class="invite-hint">Seleziona un contatto dalla rubrica.</p>
                <input id="inviteModalSearch" class="invite-search" type="search" placeholder="Cerca nome o email" autocomplete="off" aria-controls="contattiModalList">
                <div id="contattiModalList" class="invite-contact-list" role="group" aria-label="Contatti da invitare" aria-describedby="inviteModalContactsError"><p>Caricamento contatti…</p></div>
                <p class="invite-no-results" hidden>Nessun contatto corrisponde alla ricerca.</p>
                <p id="inviteModalContactsError" class="invite-field-error"></p>
            </div>
            <div class="invite-settings"><input type="hidden" id="modalGiocoId"></div>
        </form>
        <div class="invite-error" role="alert" hidden></div>
        <div class="invite-result" hidden></div>
    </div>
    <footer class="invite-footer">
        <span class="invite-footer-note">Il contatto riceverà il link via email.</span>
        <button type="button" class="invite-secondary" data-invite-close>Annulla</button>
        <button type="submit" class="invite-primary" form="gameInviteForm" data-invite-submit disabled>Invia invito</button>
    </footer>
</section>

<?php if ($can_manage_scrivania): ?>
<section id="scrivaniaInviteModal" class="dashboard-modal" role="dialog" aria-modal="true" aria-labelledby="scrivaniaInviteModalTitle" data-invite-kind="scrivania" hidden>
    <header class="invite-header">
        <div><p class="invite-eyebrow">Tool carte</p><h2 id="scrivaniaInviteModalTitle" tabindex="-1">Invita alla Scrivania</h2>
        <p class="invite-subtitle">Scegli chi partecipa e prepara la sessione.</p></div>
        <button type="button" class="invite-close" data-invite-close aria-label="Chiudi la finestra">×</button>
    </header>
    <div class="invite-body">
        <form id="scrivaniaInviteForm" class="invite-form invite-form--columns" novalidate>
            <div class="invite-contacts">
                <div class="invite-section-heading"><label for="scrivaniaInviteModalSearch">Partecipanti</label><span class="invite-count" aria-live="polite">0 selezionati</span></div>
                <p class="invite-hint">Seleziona uno o più contatti.</p>
                <input id="scrivaniaInviteModalSearch" class="invite-search" type="search" placeholder="Cerca nome o email" autocomplete="off" aria-controls="scrivaniaContattiList">
                <div id="scrivaniaContattiList" class="invite-contact-list" role="group" aria-label="Contatti da invitare" aria-describedby="scrivaniaInviteModalContactsError"><p>Caricamento contatti…</p></div>
                <p class="invite-no-results" hidden>Nessun contatto corrisponde alla ricerca.</p>
                <p id="scrivaniaInviteModalContactsError" class="invite-field-error"></p>
            </div>
            <div class="invite-settings">
                <div class="scrivania-deck-picker">
                    <label class="dashboard-modal-label" for="scrivania_mazzo">Scegli il mazzo</label>
                    <select
                        id="scrivania_mazzo"
                        class="dashboard-modal-field scrivania-deck-select"
                        aria-describedby="scrivaniaDeckPreview"
                    >
                        <?php foreach ($scrivania_deck_options as $deck_option) : ?>
                            <option
                                value="<?php echo esc_attr($deck_option['id']); ?>"
                                data-label="<?php echo esc_attr($deck_option['label']); ?>"
                                data-description="<?php echo esc_attr($deck_option['description'] ?? ''); ?>"
                                data-preview-url="<?php echo esc_url($deck_option['preview_url'] ?? ''); ?>"
                            ><?php echo esc_html($deck_option['label']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div id="scrivaniaDeckPreview" class="scrivania-deck-preview" aria-live="polite">
                        <img
                            id="scrivaniaDeckPreviewImage"
                            class="scrivania-deck-preview-image"
                            src="<?php echo esc_url($scrivania_initial_deck['preview_url'] ?? ''); ?>"
                            alt="<?php echo esc_attr(!empty($scrivania_initial_deck['label']) ? 'Anteprima ' . $scrivania_initial_deck['label'] : ''); ?>"
                            <?php echo empty($scrivania_initial_deck['preview_url']) ? 'hidden' : ''; ?>
                        >
                        <div class="scrivania-deck-preview-copy">
                            <strong id="scrivaniaDeckPreviewLabel"><?php echo esc_html($scrivania_initial_deck['label'] ?? ''); ?></strong>
                            <span id="scrivaniaDeckPreviewDescription"><?php echo esc_html($scrivania_initial_deck['description'] ?? ''); ?></span>
                        </div>
                    </div>
                </div>
                <div class="invite-schedule">
                    <div>
                        <label for="data_scrivania">Data</label>
                        <input type="date" id="data_scrivania" required aria-describedby="data_scrivania_error">
                        <span class="invite-field-error" id="data_scrivania_error"></span>
                    </div>
                    <div>
                        <label for="ora_scrivania">Ora</label>
                        <input type="time" id="ora_scrivania" required aria-describedby="ora_scrivania_error">
                        <span class="invite-field-error" id="ora_scrivania_error"></span>
                    </div>
                </div>
            </div>
        </form>
        <div class="invite-error" role="alert" hidden></div>
        <div class="invite-result" hidden></div>
    </div>
    <footer class="invite-footer">
        <span class="invite-footer-note">Gli invitati riceveranno un link personale.</span>
        <button type="button" class="invite-secondary" data-invite-close>Annulla</button>
        <button type="submit" class="invite-primary" form="scrivaniaInviteForm" data-invite-submit disabled>Invia invito</button>
    </footer>
</section>
<?php endif; ?>
<div id="modalBackdrop" class="dashboard-modal-backdrop" hidden></div>


<script type="text/javascript">
    var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";
    var scrivaniaDashboardInvitesNonce = "<?php echo esc_js(wp_create_nonce('scrivania_dashboard_invites')); ?>";
    var scrivaniaCreateSessionNonce = "<?php echo esc_js(wp_create_nonce('scrivania_create_session')); ?>";
    var rubricaContattiNonce = "<?php echo esc_js(wp_create_nonce('rubrica_contatti')); ?>";
    var gameInviteNonce = "<?php echo esc_js(wp_create_nonce('ipt_game_invite')); ?>";
</script>

<script>
    function toggleTab(id, sessionId) {
        const tabs = document.querySelectorAll('.pmpro-tab-content');
        const buttons = document.querySelectorAll('.pmpro-membership-tabs [data-tab-target]');

        tabs.forEach(tab => {
            const isActive = tab.id === id;
            tab.hidden = !isActive;
            tab.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });

        buttons.forEach(button => {
            const isActive = button.getAttribute('data-tab-target') === id;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        if (id === 'gestione-inviti' && typeof window.scrivaniaDashboardLoadInvites === 'function') {
            window.scrivaniaDashboardLoadInvites(sessionId);
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
            const allowedTypes = ['error', 'success', 'info'];
            const messageType = allowedTypes.includes(type) ? type : 'info';
            $('#scrivaniaInvitesMsg').html('<div class="dashboard-feedback dashboard-feedback--' + messageType + '">' + html + '</div>');
        }

        function caricaRubrica() {
            $.post(ajaxurl, {
                action: 'carica_contatti_utente'
            }, function(data) {
                $('#rubricaContatti').html(data);
            }).fail(function() {
                $('#rubricaContatti').html('<div class="dashboard-feedback dashboard-feedback--error">Errore nel caricamento contatti. Riprova.</div>');
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
                $('#rubrica_msg').html('<div class="rubrica-feedback dashboard-feedback dashboard-feedback--error" data-status="error">Compila nome ed email.</div>');
                return;
            }

            $submit.prop('disabled', true);
            $('#rubrica_msg').html('<div class="rubrica-feedback dashboard-feedback dashboard-feedback--info" data-status="info">Salvataggio in corso...</div>');

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
                $('#rubrica_msg').html('<div class="rubrica-feedback dashboard-feedback dashboard-feedback--error" data-status="error">Errore di rete durante il salvataggio. Riprova.</div>');
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
                $('#rubrica_msg').html('<div class="rubrica-feedback dashboard-feedback dashboard-feedback--error" data-status="error">Contatto non valido.</div>');
                return;
            }

            if (!window.confirm('Vuoi eliminare questo contatto dalla rubrica?\n' + label)) {
                return;
            }

            $button.prop('disabled', true);
            $('#rubrica_msg').html('<div class="rubrica-feedback dashboard-feedback dashboard-feedback--info" data-status="info">Eliminazione in corso...</div>');

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
                $('#rubrica_msg').html('<div class="rubrica-feedback dashboard-feedback dashboard-feedback--error" data-status="error">Errore di rete durante l\'eliminazione. Riprova.</div>');
            }).always(function() {
                $button.prop('disabled', false);
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
                    meta += '<div class="scrivania-invite-meta">User ID: ' + escapeHtml(inv.invitato_user_id) + '</div>';
                }
                if (inv.last_sent_at) {
                    meta += '<div class="scrivania-invite-meta">Ultimo invio: ' + escapeHtml(inv.last_sent_at) + '</div>';
                } else if (inv.created_at) {
                    meta += '<div class="scrivania-invite-meta">Creato: ' + escapeHtml(inv.created_at) + '</div>';
                }
                if (inv.resend_count !== null && inv.resend_count !== undefined) {
                    meta += '<div class="scrivania-invite-meta">Reinvii: ' + escapeHtml(inv.resend_count) + '</div>';
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
                $link.attr('href', url).prop('hidden', false);
            } else {
                $link.attr('href', '#').prop('hidden', true);
            }
        }

        function loadScrivaniaInvites(sessionId) {
            if (!document.getElementById('scrivaniaInvitesTableWrap')) return;
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




    });
</script>

<?php get_footer(); ?>
