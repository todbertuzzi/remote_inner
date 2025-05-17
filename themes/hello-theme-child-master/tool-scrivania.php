<?php
/* Template Name: Tool Scrivania */

get_header();

// Controlla se l'utente è loggato
if (!is_user_logged_in()) {
    echo '<div class="site-main"><div class="container"><h2>Devi effettuare l\'accesso per utilizzare questo strumento</h2></div></div>';
    get_footer();
    exit;
}

// Ottieni informazioni sull'utente
$user_id = get_current_user_id();
$user_data = get_userdata($user_id);

// Verifica se l'utente è un amministratore o ha un abbonamento valido
$has_access = current_user_can('administrator');
if (!$has_access && function_exists('pmpro_hasMembershipLevel')) {
    $has_access = pmpro_hasMembershipLevel();
}

if (!$has_access) {
    echo '<div class="site-main"><div class="container"><h2>Non hai i permessi necessari per utilizzare questo strumento</h2><p>È richiesto un abbonamento attivo.</p></div></div>';
    get_footer();
    exit;
}

// Ottieni o crea una sessione per questo utente
global $wpdb;
$table_sessions = $wpdb->prefix . 'scrivania_sessioni';

$session = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_sessions WHERE creatore_id = %d ORDER BY id DESC LIMIT 1",
    $user_id
));

if (!$session) {
    // Se non esiste una sessione, ne creiamo una nuova
    $token = wp_generate_password(24, false);
    $nome = 'Sessione di ' . $user_data->display_name;
    
    // Impostazioni iniziali
    $impostazioni = array(
        'attiva' => false,
        'iniziata' => null,
        'mazzoId' => 0,
        'sfondo' => null
    );
    
    $wpdb->insert($table_sessions, array(
        'token' => $token,
        'creatore_id' => $user_id,
        'nome' => $nome,
        'impostazioni' => wp_json_encode($impostazioni),
        'creato_il' => current_time('mysql'),
        'modificato_il' => current_time('mysql')
    ));
    
    $session_id = $wpdb->insert_id;
    $token = $token;
} else {
    $session_id = $session->id;
    $token = $session->token;
}

// Main content
?>
<main class="site-main">
    <div class="container">
        <h1 class="page-title">Tool Scrivania</h1>
        
        <?php if (!empty($token)): ?>
            <!-- Il div che conterrà l'app React -->
            <div id="react-tool-root" 
                 data-token="<?php echo esc_attr($token); ?>"
                 data-user-id="<?php echo esc_attr($user_id); ?>"
                 data-user-name="<?php echo esc_attr($user_data->display_name); ?>">
            </div>
            
            <!-- Qui verrà caricato l'app React dal plugin -->
            <?php
                // Il plugin dovrebbe caricare i file JS automaticamente se è configurato correttamente
                // Non è necessario aggiungere script manualmente qui perché
                // il plugin dovrebbe farlo tramite wp_enqueue_script
            ?>
            
            <div class="loading-message" style="text-align: center; padding: 50px;">
                <p>Caricamento del Tool Scrivania in corso...</p>
            </div>
        <?php else: ?>
            <div class="error-message">
                <p>Si è verificato un errore nel caricamento della sessione. Riprova più tardi.</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php get_footer(); ?>