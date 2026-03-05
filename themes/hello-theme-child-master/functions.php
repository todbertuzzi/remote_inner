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

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		HELLO_ELEMENTOR_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );

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
        // Se WordPress ha già calcolato un redirect valido (es. flusso invito/tool), rispettalo.
        // Nota: wp_login_form passa 'redirect', che finisce in redirect_to.
        $validated_redirect = wp_validate_redirect($redirect_to, '');
        if (!empty($validated_redirect)) {
            $path = wp_parse_url($validated_redirect, PHP_URL_PATH);
            $query = wp_parse_url($validated_redirect, PHP_URL_QUERY);

            $is_invite_flow = (!empty($path) && (strpos($path, '/invito-scrivania') !== false || strpos($path, '/tool-scrivania') !== false));
            $has_token_query = (!empty($query) && strpos($query, 'token=') !== false);

            if ($is_invite_flow || $has_token_query) {
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

/* Per permettere le richieste cross-origin dal gioco Unity, aggiungi gli header CORS in WordPress: */
add_action('init', function() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type");
});