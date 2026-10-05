<?php
/**
 * Template Name: Invito Tool Scrivania

 * - Riconosce l'invito tramite token (?token=...)
 * - Se l'utente non è loggato, mostra login e form di registrazione (con ruolo 'invitato')
 * - Verifica che l'email dell'utente loggato corrisponda all'invitato
 * - Mostra un messaggio se l'invito è scaduto o non ancora attivo
 * - Se l'orario è valido, carica il componente React per il Tool
 
 * Questo template gestisce la verifica dell'invito e il reindirizzamento a tool-scrivania
 */

// Evita che plugin/cache CDN servano una pagina con nonce utente "stale".
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
if (function_exists('nocache_headers')) {
    nocache_headers();
}

// Mantieni i redirect sullo stesso host della richiesta (evita mismatch cookie tra www/non-www).
function scrivania_invite_build_url_on_request_host($path_with_query) {
    $scheme = is_ssl() ? 'https' : 'http';
    $raw = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $raw = strtolower(preg_replace('/:\\d+$/', '', $raw));

    $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $site_host = wp_parse_url(site_url('/'), PHP_URL_HOST);
    $allowed = array_filter(array_map('strtolower', array($home_host, $site_host)));
    $normalize = function ($h) {
        return preg_replace('/^www\\./', '', (string) $h);
    };

    $host = !empty($home_host) ? $home_host : $site_host;
    if (!empty($raw)) {
        if (in_array($raw, $allowed, true)) {
            $host = $raw;
        } else {
            $raw_n = $normalize($raw);
            foreach ($allowed as $h) {
                if ($raw_n === $normalize($h)) {
                    $host = $raw;
                    break;
                }
            }
        }
    }

    $path_with_query = '/' . ltrim((string) $path_with_query, '/');
    return $scheme . '://' . $host . $path_with_query;
}

function scrivania_invite_get_wp_timezone() {
    if (function_exists('wp_timezone')) {
        return wp_timezone();
    }

    $timezone_string = function_exists('wp_timezone_string') ? wp_timezone_string() : '';
    if (empty($timezone_string)) {
        $timezone_string = 'UTC';
    }

    try {
        return new DateTimeZone($timezone_string);
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
}

function scrivania_invite_parse_schedule($data, $ora) {
    $date = trim((string) $data);
    $time = trim((string) $ora);

    if ($date === '' || $time === '') {
        return null;
    }

    $timezone = scrivania_invite_get_wp_timezone();
    $formats = array('Y-m-d H:i:s', 'Y-m-d H:i');

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $date . ' ' . $time, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        $has_errors = is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count']));
        if ($dt instanceof DateTimeImmutable && !$has_errors) {
            return $dt;
        }
    }

    return null;
}

function scrivania_invite_format_schedule_label($scheduled_at) {
    if (!$scheduled_at instanceof DateTimeImmutable) {
        return '';
    }

    $date_label = function_exists('wp_date')
        ? wp_date('d/m/Y', $scheduled_at->getTimestamp(), scrivania_invite_get_wp_timezone())
        : date_i18n('d/m/Y', $scheduled_at->getTimestamp());

    return $date_label . ' alle ' . $scheduled_at->format('H:i');
}

function scrivania_invite_render_not_active_yet($scheduled_at) {
    $when_label = scrivania_invite_format_schedule_label($scheduled_at);
    get_header();
    echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
    echo '<h2>Invito non ancora attivo</h2>';
    if ($when_label !== '') {
        echo '<p>Questa scrivania sara disponibile dal <strong>' . esc_html($when_label) . '</strong>.</p>';
    } else {
        echo '<p>Questa scrivania non e ancora disponibile.</p>';
    }
    echo '<p>Torna su questa pagina all\'orario previsto per accedere.</p>';
    echo '</div></div>';
    get_footer();
    exit;
}

$token = isset($_GET['token']) && is_string($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
if (!function_exists('gim_get_invite_registration_context')) {
    wp_die('Registrazione invitati non disponibile. Aggiorna il plugin Inviti Manager.', 'Servizio non disponibile', array('response' => 503));
}
$registration_context = gim_get_invite_registration_context('scrivania', $token);
if (is_wp_error($registration_context)) gim_render_invite_error($registration_context);

// Il contesto condiviso ha già verificato token, invito e sessione.
global $wpdb;
$table = $wpdb->prefix . 'scrivania_invitati';
$invito = $registration_context['invite'];

$scheduled_at = scrivania_invite_parse_schedule($invito->data_invito ?? '', $invito->ora_invito ?? '');
$now = function_exists('current_datetime')
    ? current_datetime()
    : new DateTimeImmutable('now', scrivania_invite_get_wp_timezone());
$invite_not_active_yet = $scheduled_at instanceof DateTimeImmutable && $scheduled_at > $now;

// Consumato?
if (!empty($invito->consumed_at) || (!empty($invito->status) && $invito->status === 'consumed')) {
    // Se l'utente non è loggato, chiedi login.
    if (!is_user_logged_in()) {
        get_header();
        echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
        echo '<h2>Invito già utilizzato</h2>';
        echo '<p>Questo link invito è stato già utilizzato. Accedi con l’account invitato per entrare nella scrivania.</p>';
        wp_login_form([ 'redirect' => esc_url(add_query_arg(null, null)) ]);
        echo '</div></div>';
        get_footer();
        exit;
    }

    // Se è loggato con l'email invitata, vai direttamente al tool.
    $current_user = wp_get_current_user();
    if (!$current_user || !gim_scrivania_invite_matches_user($invito, $current_user)) {
        $logout_url = wp_logout_url(add_query_arg(null, null));
        status_header(403);
        get_header();
        echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
        echo '<h2>Account errato</h2>';
        echo '<p>Sei loggato con un account diverso da quello invitato. Per continuare devi uscire e accedere con l’email invitata.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($logout_url) . '">Esci e continua</a></p>';
        echo '</div></div>';
        get_footer();
        exit;
    }

    // Redirect al tool con token sessione
    if ($invite_not_active_yet) {
        scrivania_invite_render_not_active_yet($scheduled_at);
    }

    $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
    $session = $wpdb->get_row($wpdb->prepare("SELECT token FROM $table_sessions WHERE id = %d", intval($invito->sessione_id)));
    if ($session && !empty($session->token)) {
        wp_redirect(scrivania_invite_build_url_on_request_host('/tool-scrivania/?token=' . urlencode($session->token) . '&invite_token=' . urlencode($token)));
        exit;
    }

    get_header();
    echo '<div class="site-main"><div class="container"><h2>Sessione non trovata</h2></div></div>';
    get_footer();
    exit;
}

// STEP 1: Conferma email (per evitare prefetch e garantire azione esplicita)
if (empty($invito->verified_at)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scrivania_verify_invite'])) {
        check_admin_referer('scrivania_verify_invite_' . intval($invito->id));

        $updated = $wpdb->update(
            $table,
            [
                'verified_at' => current_time('mysql'),
                'status' => 'verified',
            ],
            ['id' => intval($invito->id)],
            ['%s', '%s'],
            ['%d']
        );

        // Ricarica pagina
        wp_redirect(add_query_arg(null, null));
        exit;
    }

    get_header();
    echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
    echo '<h2>Conferma la tua email</h2>';
    echo '<p>Per continuare devi confermare di avere accesso alla casella email invitata.</p>';
    echo '<p><strong>Email invitata:</strong> ' . esc_html($invito->invitato_email) . '</p>';
    echo '<form method="post" style="margin-top:1.5rem;">';
    wp_nonce_field('scrivania_verify_invite_' . intval($invito->id));
    echo '<input type="hidden" name="scrivania_verify_invite" value="1" />';
    echo '<button type="submit" class="button button-primary">Confermo</button>';
    echo '</form>';
    echo '</div></div>';
    get_footer();
    exit;
}

// Stesso percorso di registrazione senza piano utilizzato dagli inviti ai giochi.
gim_require_invited_account('scrivania', $token);

// Verifica che l'utente loggato sia l'invitato (obbligo logout se account sbagliato)
$current_user = wp_get_current_user();
if (!gim_scrivania_invite_matches_user($invito, $current_user)) {
    $logout_url = wp_logout_url(add_query_arg(null, null));
    status_header(403);
    get_header();
    echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
    echo '<h2>Account errato</h2>';
    echo '<p>Sei loggato con un account diverso da quello invitato. Per continuare devi uscire e accedere con l’email invitata.</p>';
    echo '<p><a class="button button-primary" href="' . esc_url($logout_url) . '">Esci e continua</a></p>';
    echo '</div></div>';
    get_footer();
    exit;
}

if ($invite_not_active_yet) {
    scrivania_invite_render_not_active_yet($scheduled_at);
}

// Claim + consume (one-time): lega l'invito al user_id e consuma il token
$now = current_time('mysql');

// Compat schema: aggiorna solo colonne esistenti.
$invite_columns = $wpdb->get_col("DESC {$table}", 0);
if (!is_array($invite_columns)) {
    $invite_columns = array();
}

$set_parts = array();
$params = array();

if (in_array('invitato_user_id', $invite_columns, true)) {
    $set_parts[] = 'invitato_user_id = %d';
    $params[] = intval($current_user->ID);
}
if (in_array('claimed_at', $invite_columns, true)) {
    $set_parts[] = 'claimed_at = %s';
    $params[] = $now;
}
if (in_array('consumed_at', $invite_columns, true)) {
    $set_parts[] = 'consumed_at = %s';
    $params[] = $now;
}
if (in_array('status', $invite_columns, true)) {
    $set_parts[] = 'status = %s';
    $params[] = 'consumed';
}

// Difensivo: se c'è verified_at ma per qualche ragione è vuoto, valorizzalo qui.
if (in_array('verified_at', $invite_columns, true)) {
    $set_parts[] = 'verified_at = COALESCE(verified_at, %s)';
    $params[] = $now;
}

if (!empty($set_parts)) {
    $sql = "UPDATE {$table} SET " . implode(', ', $set_parts) . ' WHERE id = %d';
    $params[] = intval($invito->id);

    // Se esiste consumed_at nello schema, mantieni l'idempotenza one-time.
    if (in_array('consumed_at', $invite_columns, true)) {
        $sql .= ' AND consumed_at IS NULL';
    }

    $updated = $wpdb->query($wpdb->prepare($sql, ...$params));
}

// Recupera token sessione e reindirizza al tool
$table_sessions = $wpdb->prefix . 'scrivania_sessioni';
$session = $wpdb->get_row($wpdb->prepare("SELECT token FROM $table_sessions WHERE id = %d", intval($invito->sessione_id)));
if (!$session || empty($session->token)) {
    get_header();
    echo '<div class="site-main"><div class="container"><h2>Sessione non trovata</h2></div></div>';
    get_footer();
    exit;
}

$redirect_url = scrivania_invite_build_url_on_request_host('/tool-scrivania/?token=' . urlencode($session->token) . '&invite_token=' . urlencode($token));
wp_redirect($redirect_url);
exit;
