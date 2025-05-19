<?php
/**
 * Template Name: Tool Scrivania
 * 
 * Questo template carica l'app React per la scrivania collaborativa
 */

get_header();

// Controlla se l'utente è loggato
if (!is_user_logged_in()) {
    echo '<div class="site-main"><div class="container"><h2>Devi effettuare l\'accesso per utilizzare questo strumento</h2></div></div>';
    get_footer();
    exit;
}

// Ottieni informazioni sull'utente
$current_user = get_current_user_id();
$user_data = get_userdata($current_user);

// Verifica se l'utente ha un abbonamento valido (solo se non è amministratore)
$has_access = current_user_can('administrator');

if (!$has_access && function_exists('pmpro_hasMembershipLevel')) {
    $has_access = pmpro_hasMembershipLevel();
}

if (!$has_access) {
    echo '<div class="site-main"><div class="container"><h2>Non hai i permessi necessari</h2><p>È richiesto un abbonamento attivo.</p></div></div>';
    get_footer();
    exit;
}

// Ottieni il token dall'URL
$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

// Se non c'è token, controlla se l'utente ha una sessione esistente
if (empty($token)) {
    global $wpdb;
    $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
    
    // Cerca una sessione esistente per l'utente corrente
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_sessions WHERE creatore_id = %d ORDER BY id DESC LIMIT 1",
        $current_user
    ));
    
    if ($session) {
        $token = $session->token;
    } else {
        // Se siamo qui, l'utente non ha né token né sessioni esistenti
        echo '<div class="site-main"><div class="container"><h2>Nessuna sessione disponibile</h2><p>Non hai sessioni attive e non hai specificato un token di invito.</p></div></div>';
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

// Se non è una sessione, controlla se è un invito
if (!$session) {
    $inviti_table = $wpdb->prefix . 'scrivania_invitati';
    $invito = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $inviti_table WHERE token = %s",
        $token
    ));
    
    if (!$invito) {
        echo '<div class="site-main"><div class="container"><h2>Token non valido</h2><p>Il token specificato non corrisponde a nessuna sessione o invito.</p></div></div>';
        get_footer();
        exit;
    }
    
    // Se è un invito, recupera la sessione associata
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $session_table WHERE id = %d",
        $invito->sessione_id
    ));
    
    if (!$session) {
        echo '<div class="site-main"><div class="container"><h2>Sessione non trovata</h2><p>La sessione associata all\'invito non esiste più.</p></div></div>';
        get_footer();
        exit;
    }
    
    // Verifica che l'utente corrente sia autorizzato (creatore o invitato)
    $is_creator = ($session->creatore_id == $current_user);
    $is_invited = ($invito->invitato_email == $user_data->user_email);
    
    if (!$is_creator && !$is_invited) {
        echo '<div class="site-main"><div class="container"><h2>Non autorizzato</h2><p>Non sei autorizzato a partecipare a questa sessione.</p></div></div>';
        get_footer();
        exit;
    }
}

// A questo punto abbiamo un token valido e l'utente è autorizzato
// Mostriamo l'interfaccia principale

?>
<main class="site-main">
    <div class="container" style="max-width:1200px; margin:0 auto; padding:1rem;">
        <h1 class="page-title">Tool Scrivania</h1>
        
        <div id="react-tool-root" 
             data-token="<?php echo esc_attr($token); ?>"
             data-user-id="<?php echo esc_attr($current_user); ?>"
             data-user-name="<?php echo esc_attr($user_data->display_name); ?>"
             style="width: 100%; min-height: 600px; border: 1px solid #eee; background-color: #f9f9f9;">
            <div class="loading-message" style="text-align: center; padding: 50px;">
                <p>Caricamento della scrivania collaborativa...</p>
                <div style="display: inline-block; width: 40px; height: 40px; border: 4px solid rgba(0,0,0,.1); border-radius: 50%; border-top-color: #09d; animation: spin 1s linear infinite;"></div>
            </div>
        </div>
        
        <style>
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
        </style>
        
        <script>
            // Script di debug
            document.addEventListener('DOMContentLoaded', function() {
                console.log('DOM caricato nella pagina tool-scrivania.php');
                console.log('Elemento #react-tool-root:', !!document.getElementById('react-tool-root'));
            });
        </script>
    </div>
</main>

<?php
// Assicurati che gli script necessari siano caricati
function load_scrivania_scripts() {
    // Carica Pusher
    wp_enqueue_script(
        'pusher-js',
        'https://js.pusher.com/7.0/pusher.min.js',
        array(),
        '7.0',
        true
    );
    
    // Carica script di configurazione Pusher
    wp_enqueue_script(
        'scrivania-pusher-config',
        plugin_dir_url(dirname(__FILE__) . '/../plugins/scrivania-collaborativa-api') . 'js/pusher-config.js',
        array('pusher-js'),
        '1.0',
        true
    );
    
    // Pass configuration values
    wp_localize_script(
        'scrivania-pusher-config',
        'scrivaniaPusherConfig',
        array(
            'app_key' => get_option('scrivania_pusher_app_key', '36cf02242d86c80d6e7b'),
            'cluster' => get_option('scrivania_pusher_cluster', 'eu'),
            'auth_endpoint' => rest_url('scrivania/v1/pusher-auth'),
            'nonce' => wp_create_nonce('wp_rest')
        )
    );
    
    // Load React app
    wp_enqueue_script(
        'scrivania-app',
        plugin_dir_url(dirname(__FILE__) . '/../plugins/scrivania-collaborativa-api') . 'js/app/scrivania-app.js',
        array('pusher-js', 'scrivania-pusher-config'),
        '1.0',
        true
    );
}

// Carica gli script
load_scrivania_scripts();

get_footer();
?>