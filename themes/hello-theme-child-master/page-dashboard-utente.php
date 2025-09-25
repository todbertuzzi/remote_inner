<?php
/* Template Name: Dashboard Pro Membership */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();
$membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
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

        <div class="pmpro-membership-tabs">
            <button onclick="toggleTab('attivita')">📄 Attività</button>
            <button onclick="toggleTab('rubrica-contatti')">📄 Rubrica Contatti</button>
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
            <form id="aggiungiContattoForm">
                <input type="text" id="contatto_nome" placeholder="Nome" required>
                <input type="email" id="contatto_email" placeholder="Email" required>
                <button type="submit">Aggiungi contatto</button>
            </form>
            <div id="rubrica_msg"></div>

            <h3>Rubrica</h3>
            <div id="rubricaContatti">
                <p>Caricamento contatti...</p>
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
    <label for="data_scrivania">Data:</label>
    <input type="date" id="data_scrivania">
    <label for="ora_scrivania">Orario:</label>
    <input type="time" id="ora_scrivania">
    <button id="sendScrivaniaInvites">Invia inviti</button>
    <button id="closeScrivaniaModal">Chiudi</button>
    <div id="scrivaniaInviteResponse"></div>
</div>
<div id="modalBackdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:999;"></div>


<style>
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
</style>

<script type="text/javascript">
    var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";
</script>

<script>
    function toggleTab(id) {
        const tabs = document.querySelectorAll('.pmpro-tab-content');
        tabs.forEach(tab => tab.style.display = 'none');
        document.getElementById(id).style.display = 'block';
    }
    jQuery(document).ready(function($) {
        function caricaRubrica() {
            $.post(ajaxurl, {
                action: 'carica_contatti_utente'
            }, function(data) {
                $('#rubricaContatti').html(data);
            });
        }

        function caricaContattiPerModale() {
            $.post(ajaxurl, {
                action: 'carica_contatti_utente',
                modal: true
            }, function(data) {
                $('#contattiModalList').html(data);
            });
        }
       
       

        $('#openScrivaniaModal').on('click', function() {
            $.post(ajaxurl, {
                action: 'carica_contatti_utente',
                modal: true
            }, function(data) {
                $('#scrivaniaContattiList').html(data);
                $('#scrivaniaInviteModal, #modalBackdrop').show();
            });
        });

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
            if (emails.length === 0 || !data || !ora) {
                $('#scrivaniaInviteResponse').html('<div style="color:red;">Seleziona almeno un contatto e inserisci data e orario.</div>');
                return;
            }
            $.post(ajaxurl, {
                action: 'attiva_scrivania',
                email_destinatario: emails,
                data_invito: data,
                ora_invito: ora
            }, function(response) {
                $('#scrivaniaInviteResponse').html(response);
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
                email_destinatario: emails
            }, function(response) {
                $('#inviteResponse').html(response);
            });
        });


    });
</script>

<?php get_footer(); ?>