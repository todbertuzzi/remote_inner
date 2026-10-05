<?php

/**
 * Template Name: Tool Scrivania V1.2
 * 
 * Questo template carica l'app React per la scrivania collaborativa
 */

// Evita che plugin/cache CDN servano una pagina con nonce utente "stale".
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
if (function_exists('nocache_headers')) {
    nocache_headers();
}

// Controlla se l'utente è loggato
if (!is_user_logged_in()) {
    get_header();
    $current_url = add_query_arg(null, null);
    echo '<div class="site-main-fw"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
    echo '<h2>Devi effettuare l\'accesso per utilizzare questo strumento</h2>';
    echo '<p>Accedi con l\'account corretto per continuare e riaprire questa sessione.</p>';
    wp_login_form(array(
        'redirect' => esc_url($current_url),
    ));
    echo '</div></div>';
    get_footer();
    exit;
}

// Ottieni informazioni sull'utente
$current_user_id = get_current_user_id();
$user_data = get_userdata($current_user_id);

// Gli invitati mantengono l'accesso alla sessione; il creatore deve avere un piano abilitato.
$can_manage_scrivania = function_exists('scrivania_user_can_create_session')
    && scrivania_user_can_create_session($current_user_id);

// Ottieni il token dall'URL
$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

// Se non c'è token, controlla se l'utente ha una sessione esistente
if (empty($token) && !$can_manage_scrivania) {
    status_header(403);
    get_header();
    echo '<main class="site-main"><div class="container"><h2>Accesso riservato</h2><p>Per utilizzare la tua Scrivania è necessario un piano Professional o Gold. Per partecipare come invitato, apri il link del tuo invito.</p></div></main>';
    get_footer();
    exit;
}

if (empty($token)) {
    global $wpdb;
    $table_sessions = $wpdb->prefix . 'scrivania_sessioni';

    // Cerca una sessione esistente per l'utente corrente
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_sessions WHERE creatore_id = %d ORDER BY id DESC LIMIT 1",
        $current_user_id
    ));

    if ($session) {
        $token = $session->token;
    } else {
        get_header();
        // Se siamo qui, l'utente non ha né token né sessioni esistenti
        echo '<div class="site-main-fw"><div class="container"><h2>Nessuna sessione disponibile</h2><p>Non hai sessioni attive e non hai specificato un token di invito.</p></div></div>';
        get_footer();
        exit;
    }
}

// Ora verifichiamo se il token è valido
global $wpdb;
// Prima controlla se è un token di una sessione
$session_table = $wpdb->prefix . 'scrivania_sessioni';
$session = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $session_table WHERE token = %s",
    $token
));

if (!$session) {
    // Se è un token invito, l'utente deve passare dalla landing invito (verifica email + claim)
    $inviti_table = $wpdb->prefix . 'scrivania_invitati';
    $invito = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $inviti_table WHERE token = %s",
        $token
    ));

    if ($invito) {
        get_header();
        echo '<div class="site-main-fw"><div class="container" style="max-width:800px; margin:0 auto; padding:2rem;">';
        echo '<h2>Link invito</h2>';
        echo '<p>Per accedere devi usare il link di invito e completare la verifica email.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(home_url('/invito-scrivania/?token=' . urlencode($token))) . '">Vai alla pagina invito</a></p>';
        echo '</div></div>';
        get_footer();
        exit;
    }

    get_header();
    echo '<div class="site-main"><div class="container"><h2>Token non valido</h2><p>Il token specificato non corrisponde a nessuna sessione.</p></div></div>';
    get_footer();
    exit;
}
// Autorizzazione: creator o invitato (user_id preferito, fallback email legacy)
$is_creator = (intval($session->creatore_id) === intval($current_user_id));
if ($is_creator && !$can_manage_scrivania) {
    status_header(403);
    get_header();
    echo '<main class="site-main"><div class="container"><h2>Accesso riservato</h2><p>Per aprire le tue sessioni Scrivania è necessario un piano Professional o Gold.</p></div></main>';
    get_footer();
    exit;
}
get_header();

$is_invited = false;
$invito_role = 'viewer';

if (!$is_creator) {
    $inviti_table = $wpdb->prefix . 'scrivania_invitati';

    // Compat schema: alcune installazioni potrebbero non avere tutte le colonne V1.
    $invite_columns = $wpdb->get_col("DESC {$inviti_table}", 0);
    if (!is_array($invite_columns)) {
        $invite_columns = array();
    }

    $has_user_id = in_array('invitato_user_id', $invite_columns, true);
    $has_revoked_at = in_array('revoked_at', $invite_columns, true);
    $has_verified_at = in_array('verified_at', $invite_columns, true);
    $has_claimed_at = in_array('claimed_at', $invite_columns, true);
    $has_consumed_at = in_array('consumed_at', $invite_columns, true);
    $has_status = in_array('status', $invite_columns, true);

    // Fetch “grezzo” dell’ultimo invito (senza condizioni) e valutazione in PHP.
    if ($has_user_id) {
        $invito_any = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $inviti_table WHERE sessione_id = %d AND (\n" .
            " (invitato_user_id IS NOT NULL AND invitato_user_id = %d)\n" .
            " OR\n" .
            " (LOWER(invitato_email) = LOWER(%s))\n" .
            ") ORDER BY id DESC LIMIT 1",
            intval($session->id),
            intval($current_user_id),
            $user_data->user_email
        ));
    } else {
        $invito_any = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $inviti_table WHERE sessione_id = %d AND LOWER(invitato_email) = LOWER(%s) ORDER BY id DESC LIMIT 1",
            intval($session->id),
            $user_data->user_email
        ));
    }

    $invito = null;
    if ($invito_any) {
        $is_revoked = false;
        if ($has_revoked_at && !empty($invito_any->revoked_at)) {
            $is_revoked = true;
        }
        if (!$is_revoked && $has_status && !empty($invito_any->status) && $invito_any->status === 'revoked') {
            $is_revoked = true;
        }

        if (!$is_revoked) {
            // Se non esistono segnali di attivazione nello schema, considera valido (compat legacy).
            if (!$has_verified_at && !$has_claimed_at && !$has_consumed_at && !$has_status) {
                $invito = $invito_any;
            } else {
                $is_active = false;
                if ($has_verified_at && !empty($invito_any->verified_at)) {
                    $is_active = true;
                }
                if (!$is_active && $has_claimed_at && !empty($invito_any->claimed_at)) {
                    $is_active = true;
                }
                if (!$is_active && $has_consumed_at && !empty($invito_any->consumed_at)) {
                    $is_active = true;
                }
                if (!$is_active && $has_status && !empty($invito_any->status) && in_array($invito_any->status, array('verified', 'consumed'), true)) {
                    $is_active = true;
                }

                // In produzione abbiamo visto casi in cui verified_at/status non vengono popolati
                // correttamente (cache/optimizer/schema misto). Se l'invito esiste e non è revocato,
                // consenti comunque l'accesso in lettura e lascia alla UI/ruoli il resto.
                $invito = $invito_any;
            }
        }
    }

    if ($invito) {
        $is_invited = true;
        if (!empty($invito->role)) {
            $invito_role = $invito->role;
        }
    }
}

// Fallback robusto: se arrivi dalla landing invito, porta anche invite_token.
// In quel caso possiamo validare direttamente il token invito senza dipendere dalla lookup per email/sessione.
if (!$is_creator && !$is_invited) {
    $invite_token = isset($_GET['invite_token']) ? sanitize_text_field($_GET['invite_token']) : '';
    if (!empty($invite_token)) {
        $inviti_table = $wpdb->prefix . 'scrivania_invitati';
        $invito_by_token = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $inviti_table WHERE token = %s ORDER BY id DESC LIMIT 1",
            $invite_token
        ));

        if ($invito_by_token
            && intval($invito_by_token->sessione_id) === intval($session->id)
            && $user_data
            && !empty($user_data->user_email)
            && strtolower((string) $invito_by_token->invitato_email) === strtolower((string) $user_data->user_email)
        ) {
            $is_revoked = false;
            if (property_exists($invito_by_token, 'revoked_at') && !empty($invito_by_token->revoked_at)) {
                $is_revoked = true;
            }
            if (property_exists($invito_by_token, 'status') && !empty($invito_by_token->status) && $invito_by_token->status === 'revoked') {
                $is_revoked = true;
            }

            if (!$is_revoked) {
                $is_invited = true;
                if (property_exists($invito_by_token, 'role') && !empty($invito_by_token->role)) {
                    $invito_role = $invito_by_token->role;
                }

                // Se lo schema supporta invitato_user_id, prova a bindare l’invito all’utente corrente.
                $invite_columns = $wpdb->get_col("DESC {$inviti_table}", 0);
                if (is_array($invite_columns) && in_array('invitato_user_id', $invite_columns, true) && empty($invito_by_token->invitato_user_id)) {
                    $wpdb->update(
                        $inviti_table,
                        array('invitato_user_id' => intval($current_user_id)),
                        array('id' => intval($invito_by_token->id)),
                        array('%d'),
                        array('%d')
                    );
                }
            }
        }
    }
}

if (!$is_creator && !$is_invited) {
    $who = '';
    if ($user_data && !empty($user_data->user_email)) {
        $who = esc_html($user_data->user_email) . ' (ID ' . intval($current_user_id) . ')';
    } else {
        $who = 'utente ID ' . intval($current_user_id);
    }

    // Messaggio pensato per ridurre i falsi “bug”: spesso si apre il link sessione (del creatore)
    // con un account diverso da quello che ha creato la sessione o da quello invitato.
    echo '<div class="site-main-fw"><div class="container" style="max-width:900px; margin:0 auto; padding:2rem;">';
    echo '<h2>Non autorizzato</h2>';
    echo '<p>Non sei autorizzato a partecipare a questa sessione.</p>';
    echo '<p><strong>Sei loggato come:</strong> ' . $who . '</p>';
    echo '<p><strong>Sessione:</strong> ID ' . intval($session->id) . ' &middot; <strong>Creatore:</strong> utente ID ' . intval($session->creatore_id) . '</p>';
    if (!empty($_SERVER['HTTP_HOST'])) {
        echo '<p><strong>Host:</strong> ' . esc_html((string) $_SERVER['HTTP_HOST']) . '</p>';
    }

    echo '<hr style="margin:1.5rem 0;" />';
    echo '<p><strong>Possibili cause:</strong></p>';
    echo '<ul style="margin-left:1.2rem; list-style:disc;">';
    echo '<li>Questo è un <strong>link di sessione</strong> (usato dal creatore) e stai usando un account diverso dal creatore.</li>';
    echo '<li>Non risulti tra gli <strong>invitati</strong> (oppure l’invito è stato revocato).</li>';
    echo '</ul>';

    echo '<p style="margin-top:1rem;"><strong>Cosa fare:</strong></p>';
    echo '<ul style="margin-left:1.2rem; list-style:disc;">';
    echo '<li>Se hai ricevuto un invito via email, apri il link <strong>/invito-scrivania/?token=...</strong> (quello dell’invito), non questo.</li>';
    echo '<li>Se sei il creatore della sessione, prova ad aprire <a href="' . esc_url(home_url('/tool-scrivania/')) . '">Tool Scrivania</a> senza token.</li>';
    echo '</ul>';

    $logout_url = wp_logout_url(add_query_arg(null, null));
    echo '<p style="margin-top:1rem;"><a class="button button-primary" href="' . esc_url($logout_url) . '">Esci e accedi con un altro account</a></p>';
    echo '</div></div>';
    get_footer();
    exit;
}

// A questo punto abbiamo un token valido e l'utente è autorizzato
// Mostriamo l'interfaccia principale
$safe_token = isset($token) ? $token : '';
$safe_user_id = isset($current_user_id) ? intval($current_user_id) : 0;
$safe_user_name = isset($user_data) && $user_data ? $user_data->display_name : '';
$safe_session_id = isset($session) && $session ? $session->id : '';
$safe_user_role = $is_creator ? 'admin' : ($invito_role ?: 'viewer');

$safe_rest_nonce = wp_create_nonce('wp_rest');
$safe_ajax_url = admin_url('admin-ajax.php');



?>


<main class="site-main-fw">
   
    <div id="root" class="scrivania-container"
        data-token="<?php echo esc_attr($safe_token); ?>"
        data-user-id="<?php echo esc_attr($safe_user_id); ?>"
        data-user-name="<?php echo esc_attr($safe_user_name); ?>"
        data-session-id="<?php echo esc_attr($safe_session_id); ?>"
        data-user-role="<?php echo esc_attr($safe_user_role); ?>"
        data-rest-nonce="<?php echo esc_attr($safe_rest_nonce); ?>"
        data-ajax-url="<?php echo esc_url($safe_ajax_url); ?>">

        <!--  data-user-id="<?php //echo esc_attr($current_user); 
                            ?>" -->
        <div id="scrivania-loading" class="loading-message" style="text-align: center; padding: 50px;">
            <p>Caricamento della scrivania collaborativa...</p>
            <div style="display: inline-block; width: 40px; height: 40px; border: 4px solid rgba(0,0,0,.1); border-radius: 50%; border-top-color: #09d; animation: spin 1s linear infinite;"></div>
        </div>





        <style>
            @keyframes spin {
                to {
                    transform: rotate(360deg);
                }
            }
        </style>

        <script>
            // Script di debug
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    const reactRoot = document.getElementById('root');
                    if (reactRoot && reactRoot.children.length > 0) {
                        const loadingEl = document.getElementById('scrivania-loading');
                        if (loadingEl) {
                            loadingEl.style.display = 'none';
                        }
                    }
                }, 2000); // Aspetta 2 secondi per il mounting
            });
        </script>
    </div>
</main>

<?php
// Assicurati che gli script necessari siano caricati
function load_scrivania_scripts()
{
    $plugin_url = plugins_url('scrivania-collaborativa-api/');
    $plugin_dir = WP_PLUGIN_DIR . '/scrivania-collaborativa-api/';

    // CSS build (Vite)
    $css_rel = 'js/app/scrivania-assets/index.css';
    $css_path = $plugin_dir . $css_rel;
    if (file_exists($css_path)) {
        wp_enqueue_style(
            'scrivania-app-css',
            $plugin_url . $css_rel,
            array(),
            (string) filemtime($css_path)
        );
    }

    // JS build (Vite)
    $js_rel = 'js/app/scrivania-app.js';
    $js_path = $plugin_dir . $js_rel;
    wp_enqueue_script(
        'scrivania-app',
        $plugin_url . $js_rel,
        array(),
        file_exists($js_path) ? (string) filemtime($js_path) : '1.0',
        true
    );

    // Config globale usata dal client Pusher (bundle React)
    $app_key = (string) get_option('scrivania_pusher_app_key', '');
    $app_id = (string) get_option('scrivania_pusher_app_id', '');
    $app_secret = (string) get_option('scrivania_pusher_app_secret', '');

    $pusher_php_loaded = class_exists('\\Pusher\\Pusher');
    $has_app_id = !empty($app_id);
    $has_app_secret = !empty($app_secret);
    $has_app_key = !empty($app_key);
    $server_ready = ($has_app_key && $has_app_id && $has_app_secret && $pusher_php_loaded);

    $cfg = array(
        'app_key' => $app_key,
        'cluster' => get_option('scrivania_pusher_cluster', 'eu'),
        'auth_endpoint' => rest_url('scrivania/v1/pusher-auth'),
        'nonce' => wp_create_nonce('wp_rest'),
        // Il client non deve tentare auth se il server non può firmare le richieste.
        'server_ready' => $server_ready,
        // Debug non sensibile (non esponiamo mai app_secret/app_id)
        'pusher_php_loaded' => $pusher_php_loaded,
        'has_app_key' => $has_app_key,
        'has_app_id' => $has_app_id,
        'has_app_secret' => $has_app_secret,
    );

    wp_add_inline_script(
        'scrivania-app',
        'window.scrivaniaPusherConfig = ' . wp_json_encode($cfg) . ';',
        'before'
    );
}

// Carica gli script
load_scrivania_scripts();

get_footer();
?>