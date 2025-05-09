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
        // Reindirizza alla pagina dashboard personalizzata
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