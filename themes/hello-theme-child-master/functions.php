<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {
	$stylesheet_path = get_stylesheet_directory() . '/style.css';
	$stylesheet_version = file_exists( $stylesheet_path )
		? (string) filemtime( $stylesheet_path )
		: HELLO_ELEMENTOR_CHILD_VERSION;

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		$stylesheet_version
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );

/**
 * Carica gli stili della dashboard solo quando è attivo il relativo template.
 */
function hello_elementor_child_dashboard_styles() {
	if ( ! is_page_template( 'page-dashboard-utente.php' ) ) {
		return;
	}

	$stylesheet_path = get_stylesheet_directory() . '/dashboard.css';
	if ( ! file_exists( $stylesheet_path ) ) {
		return;
	}

	wp_enqueue_style(
		'hello-elementor-child-dashboard-style',
		get_stylesheet_directory_uri() . '/dashboard.css',
		array( 'hello-elementor-child-style' ),
		(string) filemtime( $stylesheet_path )
	);
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_dashboard_styles', 25 );

//add_action('init', 'crea_corsi_fake_tutor_lms');

function crea_corsi_fake_tutor_lms() {
    // Esegui solo una volta
    if (get_option('corsi_fake_creati')) return;

    $corsi = [
        ['Corso Introduttivo A', 'welcome'],
        ['Corso Introduttivo B', 'welcome'],
        ['Corso Introduttivo C', 'welcome'],
        ['Corso Base 1', 'professional'],
        ['Corso Base 2', 'professional'],
        ['Corso Avanzato Gold', 'gold'],
    ];

    foreach ($corsi as $c) {
        $titolo = $c[0];
        $livello = $c[1];

        $corso_id = wp_insert_post([
            'post_title'   => $titolo,
            'post_type'    => 'courses',
            'post_status'  => 'publish',
            'post_content' => 'Questo è un corso fake di esempio per livello: ' . ucfirst($livello),
        ]);

        if ($corso_id) {
            // Aggiungi tag per livello
            wp_set_post_tags($corso_id, $livello, true);

            // Aggiungi una lezione
            $lezione_id = wp_insert_post([
                'post_title'    => 'Lezione 1 - Introduzione',
                'post_type'     => 'lesson',
                'post_status'   => 'publish',
                'post_content'  => 'Contenuto della lezione.',
                'post_parent'   => $corso_id,
                'post_author'   => get_current_user_id(),
            ]);

            // Collega la lezione al corso
            update_post_meta($lezione_id, '_tutor_course_id', $corso_id);

            // Aggiungi quiz alla lezione
            $quiz_id = tutor_utils()->create_quiz('Quiz Lezione 1', $corso_id, $lezione_id);

            // Aggiungi una domanda al quiz
            $question_id = tutor_utils()->create_question([
                'post_title'   => 'Qual è la risposta corretta?',
                'post_content' => 'Domanda di esempio',
                'post_type'    => 'tutor_quiz_question',
                'post_status'  => 'publish',
            ], [
                'quiz_id' => $quiz_id,
                'question_type' => 'multiple_choice',
                'question_options' => [
                    [
                        'option_title' => 'Risposta A',
                        'is_correct'   => true,
                    ],
                    [
                        'option_title' => 'Risposta B',
                        'is_correct'   => false,
                    ]
                ],
            ]);
        }
    }

    update_option('corsi_fake_creati', true);
}

/* DOPO LOGIN REDIRECT SULLA DASHBOARD CUSTOM */
function ipt_login_redirect_dashboard($redirect_to, $request, $user) {
    // Controlla che l'utente sia loggato correttamente
    if (isset($user->roles) && is_array($user->roles)) {
        // Se WordPress ha già calcolato un redirect valido per un flusso invito, rispettalo.
        // Nota: wp_login_form passa 'redirect', che finisce in redirect_to.
        $validated_redirect = wp_validate_redirect($redirect_to, '');
        if (!empty($validated_redirect)) {
            $path = wp_parse_url($validated_redirect, PHP_URL_PATH);
            $query = wp_parse_url($validated_redirect, PHP_URL_QUERY);

            $is_invite_flow = (!empty($path) && (
                strpos($path, '/invito-scrivania') !== false ||
                strpos($path, '/tool-scrivania') !== false ||
                strpos($path, '/gioca') !== false
            ));
            $is_course_flow = (!empty($path) && (
                stripos($path, '/Corsi/') !== false ||
                stripos($path, '/lesson/') !== false ||
                stripos($path, '/tutor-quiz/') !== false ||
                stripos($path, '/assignments/') !== false
            ));
            $has_invite_query = (!empty($query) && (
                strpos($query, 'token=') !== false ||
                strpos($query, 'invito=') !== false ||
                strpos($query, 'invito_uuid=') !== false
            ));

            if ($is_invite_flow || $is_course_flow || $has_invite_query) {
                return $validated_redirect;
            }
        }

        // Default: reindirizza alla pagina dashboard personalizzata
        return site_url('/dashboard-utente');
    }
    return $redirect_to;
}
add_filter('login_redirect', 'ipt_login_redirect_dashboard', 10, 3);
/* conto-iscrizione REDIRECT SULLA DASHBOARD CUSTOM */
function ipt_redirect_conto_iscrizione() {
    if (is_page('conto-iscrizione') && is_user_logged_in()) {
        wp_redirect(site_url('/dashboard-utente'));
        exit;
    }
}
add_action('template_redirect', 'ipt_redirect_conto_iscrizione');

/* MENU UTENTE */
require_once get_stylesheet_directory() . '/menu-utente.php';




/**
 * Assegna automaticamente il ruolo "invitato" agli utenti che si registrano
 * tramite un invito (ad esempio alla pagina /invito-scrivania).
 *
 * - Il ruolo viene passato tramite un campo hidden nel form di registrazione.
 * - Utile per distinguere questi utenti da altri ruoli come subscriber o customer.
 */
add_action('user_register', function($user_id) {
    if (isset($_POST['user_role']) && $_POST['user_role'] === 'invitato') {
        $user = new WP_User($user_id);
        $user->set_role('invitato'); // Assicurati che esista il ruolo
    }
});


/**
 * Registra il ruolo personalizzato "invitato" se non esiste già.
 *
 * - Questo ruolo è usato per identificare gli utenti che si registrano
 *   tramite un invito alla piattaforma (es. per accedere al Tool Scrivania).
 * - Ha solo i permessi minimi necessari per accedere (capability 'read').
 */
add_action('init', function() {
    if (!get_role('invitato')) {
        add_role('invitato', 'Utente Invitato', [
            'read' => true,
            'edit_posts' => false,
            'delete_posts' => false
        ]);
    }
});



/**
 * Aggiunge un filtro per il ruolo "invitato" nella pagina utenti di WordPress
 * e una colonna "Tipo Utente" che mostra il ruolo principale di ciascun utente.
 *
 * Utile per identificare e gestire facilmente gli utenti registrati tramite invito.
 */
add_filter('views_users', function($views) {
    $invitati = count_users()['avail_roles']['invitato'] ?? 0;
    $url = add_query_arg('role', 'invitato', 'users.php');
    $views['invitato'] = "<a href=\"$url\">Utenti Invitati <span class=\"count\">($invitati)</span></a>";
    return $views;
});

add_filter('manage_users_columns', function($columns) {
    $columns['tipo_utente'] = 'Tipo Utente';
    return $columns;
});

add_filter('manage_users_custom_column', function($value, $column_name, $user_id) {
    if ($column_name === 'tipo_utente') {
        $user = get_userdata($user_id);
        return ucfirst($user->roles[0] ?? '-');
    }
    return $value;
}, 10, 3);

/**
 * Tool Scrivania: nasconde la WP Admin Bar nel frontend.
 *
 * Alcuni utenti invitati (es. role subscriber) la vedono per impostazione profilo,
 * e su una UI full-screen risulta invasiva.
 */
add_filter('show_admin_bar', function ($show) {
    if (is_admin()) {
        return $show;
    }

    $is_tool = is_page('tool-scrivania') || is_page_template('tool-scrivania.php');
    if ($is_tool) {
        return false;
    }

    return $show;
}, 20);

/**
 * Evita warning/errori CORS per font Elementor caricati da un dominio diverso.
 * Lo facciamo SOLO su /tool-scrivania per non impattare il resto del sito.
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }

    // Siamo sul tool? (supporta sia slug pagina sia template)
    $is_tool = is_page('tool-scrivania') || is_page_template('tool-scrivania.php');
    if (!$is_tool) {
        return;
    }

    global $wp_styles;
    if (empty($wp_styles) || empty($wp_styles->queue) || !is_array($wp_styles->queue)) {
        return;
    }

    foreach ($wp_styles->queue as $handle) {
        if (empty($wp_styles->registered[$handle]) || empty($wp_styles->registered[$handle]->src)) {
            continue;
        }

        $src = (string) $wp_styles->registered[$handle]->src;
        // Match su font Elementor self-hosted (uploads/elementor/google-fonts).
        if (strpos($src, 'uploads/elementor/google-fonts') !== false || strpos($src, 'elementor/google-fonts') !== false) {
            wp_dequeue_style($handle);
        }
    }
}, 999);

/**
 * Tutor LMS (tutor.js / tutor-front.js) su alcune pagine frontend assume che esista `window.wp.i18n`.
 * Sul tool scrivania non ci serve: lo rimuoviamo per evitare errori console e side-effect.
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }

    $is_tool = is_page('tool-scrivania') || is_page_template('tool-scrivania.php');
    if (!$is_tool) {
        return;
    }

    global $wp_scripts;
    if (empty($wp_scripts) || empty($wp_scripts->queue) || !is_array($wp_scripts->queue)) {
        return;
    }

    foreach ($wp_scripts->queue as $handle) {
        if (empty($wp_scripts->registered[$handle]) || empty($wp_scripts->registered[$handle]->src)) {
            continue;
        }

        $src = (string) $wp_scripts->registered[$handle]->src;
        $is_tutor = (strpos($src, 'tutor') !== false);
        $is_tutor_js = (strpos($src, 'tutor.js') !== false || strpos($src, 'tutor-front.js') !== false);

        if ($is_tutor && $is_tutor_js) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
    }
}, 999);

/**
 * Scrivania: ritorna gli inviti attivi per un utente.
 *
 * Regole:
 * - Solo inviti non revocati.
 * - Solo l'ultimo invito "valido" per ogni sessione (MAX(id) per sessione).
 * - Sessioni archiviate (impostazioni.archived=true) escluse.
 * - Matching: invitato_user_id (se presente) OR invitato_email (case-insensitive).
 */
function ipt_scrivania_get_active_invites_for_user($user_id = 0, $limit_sessions = 50) {
    if ($user_id <= 0) {
        $user_id = get_current_user_id();
    }

    $user = get_userdata($user_id);
    if (!$user || empty($user->user_email)) {
        return array();
    }
    $user_email = (string) $user->user_email;

    global $wpdb;
    if (empty($wpdb)) {
        return array();
    }

    $table_invites = $wpdb->prefix . 'scrivania_invitati';
    $table_sessions = $wpdb->prefix . 'scrivania_sessioni';

    // Verifica esistenza tabella inviti
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_invites));
    if (empty($exists)) {
        return array();
    }

    $limit_sessions = intval($limit_sessions);
    if ($limit_sessions <= 0) {
        $limit_sessions = 50;
    }
    $limit_sessions = min(200, $limit_sessions);

    static $invite_cols_cache = null;
    if ($invite_cols_cache === null) {
        $invite_cols_cache = $wpdb->get_col("DESC {$table_invites}", 0);
        if (!is_array($invite_cols_cache)) {
            $invite_cols_cache = array();
        }
    }

    $has_user_id = in_array('invitato_user_id', $invite_cols_cache, true);
    $has_revoked_at = in_array('revoked_at', $invite_cols_cache, true);
    $has_status = in_array('status', $invite_cols_cache, true);
    $has_role = in_array('role', $invite_cols_cache, true);

    $where_match = '';
    $params = array();
    if ($has_user_id) {
        $where_match = '(invitato_user_id = %d OR LOWER(invitato_email) = LOWER(%s))';
        $params[] = intval($user_id);
        $params[] = $user_email;
    } else {
        $where_match = 'LOWER(invitato_email) = LOWER(%s)';
        $params[] = $user_email;
    }

    $where_active_parts = array();
    if ($has_revoked_at) {
        $where_active_parts[] = 'revoked_at IS NULL';
    }
    if ($has_status) {
        $where_active_parts[] = "status <> 'revoked'";
    }
    $where_active = '';
    if (!empty($where_active_parts)) {
        $where_active = ' AND ' . implode(' AND ', $where_active_parts);
    }

    // Ultimo invito attivo per sessione
    $sql =
        "SELECT\n" .
        "  i.id AS invite_id,\n" .
        "  i.token AS invite_token,\n" .
        "  i.sessione_id AS session_id,\n" .
        "  i.invitante_id AS inviter_user_id,\n" .
        "  i.data_invito AS data_invito,\n" .
        "  i.ora_invito AS ora_invito,\n" .
        ($has_role ? "  i.role AS invite_role,\n" : "  'viewer' AS invite_role,\n") .
        ($has_status ? "  i.status AS invite_status,\n" : "  '' AS invite_status,\n") .
        "  i.creato_il AS invite_created_at,\n" .
        "  s.id AS session_id_check,\n" .
        "  s.token AS session_token,\n" .
        "  s.nome AS session_name,\n" .
        "  s.impostazioni AS session_settings,\n" .
        "  s.creatore_id AS session_creator_id,\n" .
        "  s.creato_il AS session_created_at,\n" .
        "  s.modificato_il AS session_updated_at\n" .
        "FROM {$table_invites} i\n" .
        "INNER JOIN (\n" .
        "  SELECT sessione_id, MAX(id) AS id\n" .
        "  FROM {$table_invites}\n" .
        "  WHERE {$where_match}{$where_active}\n" .
        "  GROUP BY sessione_id\n" .
        ") latest ON i.id = latest.id\n" .
        "INNER JOIN {$table_sessions} s ON s.id = i.sessione_id\n" .
        "ORDER BY i.id DESC\n" .
        "LIMIT {$limit_sessions}";

    $prepared = $wpdb->prepare($sql, ...$params);
    $rows = $wpdb->get_results($prepared, ARRAY_A);
    if (!is_array($rows)) {
        return array();
    }

    $invites = array();
    foreach ($rows as $row) {
        $invite_token = isset($row['invite_token']) ? (string) $row['invite_token'] : '';
        if (empty($invite_token)) {
            continue;
        }

        // Filtra sessioni archiviate
        $archived = false;
        if (!empty($row['session_settings'])) {
            $decoded = json_decode((string) $row['session_settings'], true);
            if (is_array($decoded) && !empty($decoded['archived'])) {
                $archived = true;
            }
        }
        if ($archived) {
            continue;
        }

        $inviter_id = intval($row['inviter_user_id'] ?? 0);
        $inviter_name = '';
        if ($inviter_id > 0) {
            $inviter = get_userdata($inviter_id);
            if ($inviter && !empty($inviter->display_name)) {
                $inviter_name = (string) $inviter->display_name;
            }
        }

        $invites[] = array(
            'invite_id' => intval($row['invite_id'] ?? 0),
            'invite_token' => $invite_token,
            'invite_status' => isset($row['invite_status']) ? (string) $row['invite_status'] : '',
            'invite_role' => isset($row['invite_role']) ? (string) $row['invite_role'] : 'viewer',
            'invite_created_at' => $row['invite_created_at'] ?? null,
            'data_invito' => $row['data_invito'] ?? null,
            'ora_invito' => $row['ora_invito'] ?? null,
            'session_id' => intval($row['session_id'] ?? 0),
            'session_name' => isset($row['session_name']) ? (string) $row['session_name'] : '',
            'session_creator_id' => intval($row['session_creator_id'] ?? 0),
            'session_created_at' => $row['session_created_at'] ?? null,
            'session_updated_at' => $row['session_updated_at'] ?? null,
            'inviter_user_id' => $inviter_id ?: null,
            'inviter_name' => $inviter_name,
        );
    }

    return $invites;
}

/**
 * Scrivania: verifica se l'utente ha almeno 1 invito attivo.
 */
function ipt_scrivania_user_has_active_invites($user_id = 0) {
    $invites = ipt_scrivania_get_active_invites_for_user($user_id, 1);
    return !empty($invites);
}

/* Per permettere le richieste cross-origin dal gioco Unity, aggiungi gli header CORS in WordPress: */
add_action('init', function() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type");
});
