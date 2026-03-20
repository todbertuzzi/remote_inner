<?php
/**
 * Shortcode [menu_profilo_utente] per mostrare "Accedi" o il menu utente loggato
 */

add_shortcode('menu_profilo_utente', function () {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();

        // Link pagina inviti: mostra solo se l'utente può accedere (ha inviti attivi) e la pagina esiste.
        $inviti_page = get_page_by_path('gestione-inviti');
        if (!$inviti_page) {
            // Fallback: la pagina potrebbe avere uno slug diverso.
            // Cerchiamo la prima pagina pubblicata che usa il template dedicato.
            $pages = get_pages([
                'post_type' => 'page',
                'post_status' => 'publish',
                'number' => 1,
                'meta_key' => '_wp_page_template',
                'meta_value' => 'page-gestione-inviti.php',
            ]);
            if (!empty($pages) && $pages[0] instanceof WP_Post) {
                $inviti_page = $pages[0];
            }
        }
        $inviti_url = $inviti_page ? get_permalink($inviti_page) : '';
        $show_inviti = false;
        if (!empty($inviti_url) && function_exists('ipt_scrivania_user_has_active_invites')) {
            $show_inviti = ipt_scrivania_user_has_active_invites($user->ID);
        }

        ob_start();
        ?>
        <div class="user-dropdown">
            <span class="user-name">Ciao, <?php echo esc_html($user->display_name); ?> ⬇</span>
            <ul class="user-menu">
                <li><a href="<?php echo esc_url(site_url('/dashboard-utente/')); ?>">Dashboard</a></li>
                <?php if ($show_inviti): ?>
                    <li><a href="<?php echo esc_url($inviti_url); ?>">I miei inviti</a></li>
                <?php endif; ?>
                <li><a href="<?php echo esc_url(wp_logout_url(home_url())); ?>">Esci</a></li>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    } else {
        return '<a class="login-link" href="' . esc_url(wp_login_url()) . '">Accedi</a>';
    }
});
