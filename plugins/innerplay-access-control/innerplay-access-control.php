<?php
/**
 * Plugin Name: Innerplay - Controllo Accessi
 * Description: Centralizza gli accessi cumulativi a corsi e giochi in base ai livelli Paid Memberships Pro.
 * Version: 1.0.0
 * Author: Innerplay
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mappa stabile fra livello applicativo e ID Paid Memberships Pro.
 *
 * Gli ID correnti sul sito sono Welcome=3, Professional=4 e Gold=5.
 */
function ipt_access_membership_level_ids() {
    return apply_filters('ipt_access_membership_level_ids', array(
        'welcome' => 3,
        'professional' => 4,
        'gold' => 5,
    ));
}

function ipt_access_tier_ranks() {
    return array(
        'welcome' => 10,
        'professional' => 20,
        'gold' => 30,
    );
}

/**
 * Restituisce il livello applicativo più alto dell'utente.
 */
function ipt_get_user_access_tier($user_id = 0) {
    $user_id = $user_id > 0 ? intval($user_id) : get_current_user_id();
    if ($user_id <= 0) {
        return '';
    }

    if (user_can($user_id, 'manage_options')) {
        return 'admin';
    }

    $memberships = array();
    if (function_exists('pmpro_getMembershipLevelsForUser')) {
        $memberships = pmpro_getMembershipLevelsForUser($user_id, false);
    } elseif (function_exists('pmpro_getMembershipLevelForUser')) {
        $membership = pmpro_getMembershipLevelForUser($user_id);
        if (!empty($membership)) {
            $memberships = array($membership);
        }
    }

    if (!is_array($memberships)) {
        return '';
    }

    $ids = ipt_access_membership_level_ids();
    $ranks = ipt_access_tier_ranks();
    $highest_tier = '';
    $highest_rank = 0;

    foreach ($memberships as $membership) {
        $membership_id = is_object($membership) && isset($membership->id)
            ? intval($membership->id)
            : 0;

        foreach ($ids as $tier => $configured_id) {
            $rank = isset($ranks[$tier]) ? intval($ranks[$tier]) : 0;
            if ($membership_id === intval($configured_id) && $rank > $highest_rank) {
                $highest_tier = $tier;
                $highest_rank = $rank;
            }
        }
    }

    return $highest_tier;
}

/**
 * Elenco cumulativo dei livelli accessibili dall'utente.
 */
function ipt_get_allowed_access_tiers($user_id = 0) {
    $tier = ipt_get_user_access_tier($user_id);
    $ranks = ipt_access_tier_ranks();

    if ($tier === 'admin') {
        return array_keys($ranks);
    }

    if (!isset($ranks[$tier])) {
        return array();
    }

    $allowed = array();
    foreach ($ranks as $candidate => $rank) {
        if ($rank <= $ranks[$tier]) {
            $allowed[] = $candidate;
        }
    }

    return $allowed;
}

/**
 * Risale dal contenuto Tutor LMS al corso principale.
 */
function ipt_get_course_id_for_content($post_id) {
    $post_id = intval($post_id);
    if ($post_id <= 0) {
        return 0;
    }

    if (get_post_type($post_id) === 'courses') {
        return $post_id;
    }

    if (function_exists('tutor_utils')) {
        $utils = tutor_utils();
        if (is_object($utils) && method_exists($utils, 'get_course_id_by_subcontent')) {
            $course_id = intval($utils->get_course_id_by_subcontent($post_id));
            if ($course_id > 0) {
                return $course_id;
            }
        }
    }

    $course_id = intval(get_post_meta($post_id, '_tutor_course_id', true));
    if ($course_id > 0 && get_post_type($course_id) === 'courses') {
        return $course_id;
    }

    $parent_id = intval(wp_get_post_parent_id($post_id));
    $visited = array();
    while ($parent_id > 0 && !isset($visited[$parent_id])) {
        $visited[$parent_id] = true;
        if (get_post_type($parent_id) === 'courses') {
            return $parent_id;
        }
        $parent_id = intval(wp_get_post_parent_id($parent_id));
    }

    return 0;
}

/**
 * Restituisce l'unico livello riconosciuto associato al contenuto.
 * Contenuti non classificati o con più livelli sono intenzionalmente negati.
 */
function ipt_get_content_required_tier($post_id) {
    $post_id = intval($post_id);
    if ($post_id <= 0) {
        return '';
    }

    $post_type = get_post_type($post_id);
    $taxonomy = '';
    $content_id = $post_id;

    if ($post_type === 'gioco') {
        $taxonomy = 'categoria_giochi';
    } else {
        $course_id = ipt_get_course_id_for_content($post_id);
        if ($course_id <= 0) {
            return '';
        }
        $content_id = $course_id;
        $taxonomy = 'course-category';
    }

    $slugs = wp_get_object_terms($content_id, $taxonomy, array('fields' => 'slugs'));
    if (is_wp_error($slugs) || !is_array($slugs)) {
        return '';
    }

    $recognized = array_values(array_unique(array_intersect($slugs, array_keys(ipt_access_tier_ranks()))));
    return count($recognized) === 1 ? $recognized[0] : '';
}

/**
 * Unica regola di autorizzazione per corsi e giochi del catalogo.
 */
function ipt_user_can_access_content($user_id, $post_id) {
    $user_id = intval($user_id);
    $post_id = intval($post_id);

    if ($user_id <= 0 || $post_id <= 0) {
        return false;
    }

    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    $user_tier = ipt_get_user_access_tier($user_id);
    $required_tier = ipt_get_content_required_tier($post_id);
    $ranks = ipt_access_tier_ranks();

    if (!isset($ranks[$user_tier]) || !isset($ranks[$required_tier])) {
        return false;
    }

    return $ranks[$user_tier] >= $ranks[$required_tier];
}

function ipt_user_can_invite_game($user_id, $game_id) {
    return get_post_type($game_id) === 'gioco'
        && get_post_status($game_id) === 'publish'
        && ipt_user_can_access_content($user_id, $game_id);
}

/**
 * Valida integralmente l'accesso dell'invitato a una sessione gioco.
 * Ritorna true oppure WP_Error con lo status HTTP appropriato.
 */
function ipt_validate_game_session_access($session, $current_user, $expected_game_id = 0) {
    if (!is_object($session)) {
        return new WP_Error('session_not_found', 'Sessione non trovata.', array('status' => 404));
    }

    if (!($current_user instanceof WP_User) || intval($current_user->ID) <= 0) {
        return new WP_Error('auth_required', 'Autenticazione richiesta.', array('status' => 401));
    }

    $status = isset($session->status) ? strtolower(trim((string) $session->status)) : '';
    if (in_array($status, array('revoked', 'cancelled', 'canceled'), true)) {
        return new WP_Error('session_forbidden', 'Sessione non più disponibile.', array('status' => 403));
    }

    if ($status === 'expired' || (!empty($session->expires_at) && current_time('timestamp') > strtotime($session->expires_at))) {
        return new WP_Error('session_expired', 'Sessione scaduta.', array('status' => 410));
    }

    $expected_game_id = intval($expected_game_id);
    if ($expected_game_id > 0 && intval($session->gioco_id) !== $expected_game_id) {
        return new WP_Error('wrong_game', 'La sessione appartiene a un altro gioco.', array('status' => 409));
    }

    $invited_user_id = isset($session->invited_user_id) ? intval($session->invited_user_id) : 0;
    $invited_email = isset($session->invited_email) ? sanitize_email((string) $session->invited_email) : '';

    if ($invited_user_id > 0) {
        $identity_matches = $invited_user_id === intval($current_user->ID);
    } elseif ($invited_email !== '') {
        $identity_matches = strcasecmp($invited_email, (string) $current_user->user_email) === 0;
    } elseif (function_exists('gim_game_user_can_access_session')) {
        $identity_matches = gim_game_user_can_access_session($session, $current_user);
    } else {
        $identity_matches = false;
    }

    if (!$identity_matches) {
        return new WP_Error('session_forbidden', 'Utente non autorizzato per questa sessione.', array('status' => 403));
    }

    return true;
}

function ipt_access_error_status($error, $fallback = 403) {
    if (!is_wp_error($error)) {
        return intval($fallback);
    }
    $data = $error->get_error_data();
    return is_array($data) && !empty($data['status']) ? intval($data['status']) : intval($fallback);
}

/**
 * La pagina Elementor dedicata mantiene il layout del sito, ma conserva la
 * semantica HTTP di un accesso negato e non deve essere memorizzata in cache.
 */
function ipt_prepare_access_denied_page() {
    if (!is_page('accesso-riservato')) {
        return;
    }

    // Elementor e l'anteprima WordPress richiedono una risposta 200 per l'editor.
    if (is_preview() || isset($_GET['elementor-preview'])) {
        return;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    status_header(403);
    nocache_headers();
    header('X-Robots-Tag: noindex, follow', true);
}
add_action('template_redirect', 'ipt_prepare_access_denied_page', 0);

function ipt_access_denied_page_robots($robots) {
    if (is_page('accesso-riservato')) {
        unset($robots['index']);
        $robots['noindex'] = true;
        $robots['follow'] = true;
    }

    return $robots;
}
add_filter('wp_robots', 'ipt_access_denied_page_robots');

/**
 * Protegge corso, lezioni, quiz e assegnazioni anche tramite URL diretto.
 */
function ipt_protect_tutor_content_request() {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    $protected_types = array('courses', 'topics', 'lesson', 'tutor_quiz', 'tutor_assignments');
    if (!is_singular($protected_types)) {
        return;
    }

    $post_id = intval(get_queried_object_id());
    if (ipt_user_can_access_content(get_current_user_id(), $post_id)) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(get_permalink($post_id)));
        exit;
    }

    $access_page = get_page_by_path('accesso-riservato', OBJECT, 'page');
    if ($access_page instanceof WP_Post && $access_page->post_status === 'publish') {
        wp_safe_redirect(get_permalink($access_page), 302, 'Innerplay Access Control');
        exit;
    }

    $levels_url = function_exists('pmpro_url') ? pmpro_url('levels') : home_url('/livelli/');
    $message = sprintf(
        'Il tuo piano non consente di accedere a questo contenuto. <a href="%s">Consulta i piani disponibili</a>.',
        esc_url($levels_url)
    );
    wp_die(wp_kses_post($message), 'Accesso riservato', array('response' => 403));
}
add_action('template_redirect', 'ipt_protect_tutor_content_request', 1);

/**
 * Le collezioni REST mostrano solo i corsi consentiti all'utente corrente.
 */
function ipt_filter_courses_rest_query($args, $request) {
    if (user_can(get_current_user_id(), 'manage_options')) {
        return $args;
    }

    $allowed = ipt_get_allowed_access_tiers(get_current_user_id());
    if (empty($allowed)) {
        $args['post__in'] = array(0);
        return $args;
    }

    if (empty($args['tax_query']) || !is_array($args['tax_query'])) {
        $args['tax_query'] = array();
    }
    $args['tax_query'][] = array(
        'taxonomy' => 'course-category',
        'field' => 'slug',
        'terms' => $allowed,
        'operator' => 'IN',
    );

    return $args;
}
add_filter('rest_courses_query', 'ipt_filter_courses_rest_query', 10, 2);

/**
 * Protegge anche l'endpoint REST del singolo corso.
 */
function ipt_protect_single_course_rest_request($response, $server, $request) {
    if (!($request instanceof WP_REST_Request) || strtoupper($request->get_method()) !== 'GET') {
        return $response;
    }

    $route = $request->get_route();
    $content_id = 0;

    if (preg_match('#^/wp/v2/courses/(\d+)(?:/|$)#', $route, $matches)) {
        $content_id = intval($matches[1]);
    } elseif (preg_match('#^/tutor/v1/(?:courses|course-contents)/(\d+)(?:/|$)#', $route, $matches)) {
        $content_id = intval($matches[1]);
    } elseif (preg_match('#^/tutor/v1/(?:topics|lessons|quizzes|assignments)/(\d+)(?:/|$)#', $route, $matches)) {
        $content_id = intval($matches[1]);
    }

    if ($content_id <= 0) {
        return $response;
    }

    if (ipt_user_can_access_content(get_current_user_id(), $content_id)) {
        return $response;
    }

    $status = is_user_logged_in() ? 403 : 401;
    return new WP_Error(
        $status === 401 ? 'rest_not_logged_in' : 'rest_forbidden_course',
        $status === 401 ? 'Autenticazione richiesta.' : 'Corso non disponibile per il piano attivo.',
        array('status' => $status)
    );
}
add_filter('rest_pre_dispatch', 'ipt_protect_single_course_rest_request', 10, 3);

/**
 * Ultimo filtro fail-closed sulle collezioni, utile anche in caso di contenuti
 * configurati per errore con più livelli di accesso contemporaneamente.
 */
function ipt_filter_courses_rest_response($response, $server, $request) {
    if (!($response instanceof WP_REST_Response) || !($request instanceof WP_REST_Request)) {
        return $response;
    }

    if (strtoupper($request->get_method()) !== 'GET' || $request->get_route() !== '/wp/v2/courses') {
        return $response;
    }

    if (user_can(get_current_user_id(), 'manage_options')) {
        return $response;
    }

    $data = $response->get_data();
    if (!is_array($data)) {
        return $response;
    }

    $filtered = array();
    foreach ($data as $item) {
        $course_id = is_array($item) && isset($item['id']) ? intval($item['id']) : 0;
        if ($course_id > 0 && ipt_user_can_access_content(get_current_user_id(), $course_id)) {
            $filtered[] = $item;
        }
    }

    $response->set_data($filtered);
    return $response;
}
add_filter('rest_post_dispatch', 'ipt_filter_courses_rest_response', 10, 3);

/**
 * Tutor LMS considera tecnicamente gratuiti i corsi inclusi nei piani PMPro.
 * Nel singolo corso mostriamo una dicitura coerente con l'abbonamento, senza
 * modificare le traduzioni globali o i file di Tutor LMS.
 */
function ipt_customize_tutor_course_labels($translated, $text, $domain) {
    if ($domain !== 'tutor' || !is_singular('courses')) {
        return $translated;
    }

    $labels = array(
        'Free' => 'Incluso nel tuo piano',
        'Free access this course' => 'Accesso incluso nel tuo piano',
    );

    return isset($labels[$text]) ? $labels[$text] : $translated;
}
add_filter('gettext', 'ipt_customize_tutor_course_labels', 20, 3);

/**
 * Applica la stessa matrice cumulativa anche all'archivio /Corsi e alle altre
 * liste frontend generate da Tutor LMS o da page builder.
 *
 * Il catalogo anonimo resta pubblico; una volta autenticato, l'utente vede
 * soltanto i corsi compresi nel proprio piano.
 */
function ipt_filter_frontend_course_queries($query) {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    if (!is_user_logged_in() || user_can(get_current_user_id(), 'manage_options')) {
        return;
    }

    if ($query->is_singular('courses')) {
        return;
    }

    $post_type = $query->get('post_type');
    $is_courses_query = $post_type === 'courses'
        || (is_array($post_type) && in_array('courses', $post_type, true))
        || $query->is_post_type_archive('courses');

    if (!$is_courses_query) {
        return;
    }

    $allowed = ipt_get_allowed_access_tiers(get_current_user_id());
    if (empty($allowed)) {
        $query->set('post__in', array(0));
        $query->set('ipt_access_filtered_courses', true);
        return;
    }

    $tax_query = $query->get('tax_query');
    if (!is_array($tax_query)) {
        $tax_query = array();
    }
    $tax_query[] = array(
        'taxonomy' => 'course-category',
        'field' => 'slug',
        'terms' => $allowed,
        'operator' => 'IN',
    );

    $query->set('tax_query', $tax_query);
    $query->set('ipt_access_filtered_courses', true);
}
add_action('pre_get_posts', 'ipt_filter_frontend_course_queries', 20);

/**
 * Ultima verifica sui risultati per escludere configurazioni ambigue con più
 * livelli riconosciuti, che una semplice tax_query non può distinguere.
 */
function ipt_filter_frontend_course_results($posts, $query) {
    if (!$query->get('ipt_access_filtered_courses') || !is_array($posts)) {
        return $posts;
    }

    $user_id = get_current_user_id();
    return array_values(array_filter($posts, function ($post) use ($user_id) {
        return $post instanceof WP_Post && ipt_user_can_access_content($user_id, $post->ID);
    }));
}
add_filter('the_posts', 'ipt_filter_frontend_course_results', 20, 2);
