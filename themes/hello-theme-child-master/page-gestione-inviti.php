<?php
/**
 * Template Name: Gestione Inviti (Scrivania)
 *
 * Pagina dedicata agli utenti invitati: mostra gli inviti attivi ricevuti
 * (solo l'ultimo invito valido per sessione) e permette di accedere alla sessione.
 */

// Evita che plugin/cache CDN servano una pagina utente a un altro utente.
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
if (function_exists('nocache_headers')) {
    nocache_headers();
}

if (!is_user_logged_in()) {
    $redirect = add_query_arg(null, null);
    wp_redirect(wp_login_url($redirect));
    exit;
}

$current_user = wp_get_current_user();
$invites = array();
if (function_exists('ipt_scrivania_get_active_invites_for_user')) {
    $invites = ipt_scrivania_get_active_invites_for_user($current_user->ID, 50);
}

// Accesso solo se l'utente ha inviti attivi.
if (empty($invites)) {
    status_header(403);
    get_header();
    ?>
    <main id="content" class="site-main">
        <div class="dashboard-pro">
            <h2>Accesso riservato</h2>
            <p>Questa pagina è disponibile solo per utenti che hanno ricevuto un invito attivo.</p>
        </div>
    </main>
    <?php
    get_footer();
    exit;
}

get_header(); ?>

<main id="content" class="site-main">
    <div class="dashboard-pro">
        <h2>I miei inviti (Scrivania)</h2>

        <p style="margin: 0.5rem 0 1rem; color:#444;">
            Qui trovi gli inviti attivi ricevuti per il Tool Scrivania.
        </p>

        <table class="scrivania-invites-table" style="width:100%; border-collapse: collapse; background:#fff;">
            <thead>
                <tr>
                    <th style="border:1px solid #ddd; padding:8px; background:#f3f3f3;">Sessione</th>
                    <th style="border:1px solid #ddd; padding:8px; background:#f3f3f3;">Quando</th>
                    <th style="border:1px solid #ddd; padding:8px; background:#f3f3f3;">Ruolo</th>
                    <th style="border:1px solid #ddd; padding:8px; background:#f3f3f3;">Invitato da</th>
                    <th style="border:1px solid #ddd; padding:8px; background:#f3f3f3;">Azione</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invites as $inv):
                    $session_id = intval($inv['session_id'] ?? 0);
                    $session_name = !empty($inv['session_name']) ? (string) $inv['session_name'] : ('Sessione #' . $session_id);
                    $data = !empty($inv['data_invito']) ? (string) $inv['data_invito'] : '';
                    $ora = !empty($inv['ora_invito']) ? (string) $inv['ora_invito'] : '';
                    $role = !empty($inv['invite_role']) ? (string) $inv['invite_role'] : 'viewer';
                    $inviter_name = !empty($inv['inviter_name']) ? (string) $inv['inviter_name'] : '';
                    $inviter_id = !empty($inv['inviter_user_id']) ? intval($inv['inviter_user_id']) : 0;
                    $invite_token = (string) ($inv['invite_token'] ?? '');

                    $when = '-';
                    if (!empty($data) && !empty($ora)) {
                        $when = date_i18n('d/m/Y', strtotime($data)) . ' ' . esc_html($ora);
                    } elseif (!empty($data)) {
                        $when = date_i18n('d/m/Y', strtotime($data));
                    }

                    // Link relativo: mantiene lo stesso host (evita mismatch www/non-www).
                    $access_url = '/invito-scrivania/?token=' . rawurlencode($invite_token);
                    ?>
                    <tr>
                        <td style="border:1px solid #ddd; padding:8px;">
                            <strong><?php echo esc_html($session_name); ?></strong><br />
                            <span style="color:#666;">Sessione ID <?php echo intval($session_id); ?></span>
                        </td>
                        <td style="border:1px solid #ddd; padding:8px;">
                            <?php echo esc_html($when); ?>
                        </td>
                        <td style="border:1px solid #ddd; padding:8px;">
                            <?php echo esc_html($role); ?>
                        </td>
                        <td style="border:1px solid #ddd; padding:8px;">
                            <?php if (!empty($inviter_name)): ?>
                                <?php echo esc_html($inviter_name); ?>
                                <?php if ($inviter_id > 0): ?>
                                    <span style="color:#666;">(ID <?php echo intval($inviter_id); ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#666;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="border:1px solid #ddd; padding:8px;">
                            <a class="button button-primary" href="<?php echo esc_url($access_url); ?>">Accedi</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<?php get_footer();
