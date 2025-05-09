<?php
/**
 * Shortcode [menu_profilo_utente] per mostrare "Accedi" o il menu utente loggato
 */

add_shortcode('menu_profilo_utente', function () {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        ob_start();
        ?>
        <div class="user-dropdown">
            <span class="user-name">Ciao, <?php echo esc_html($user->display_name); ?> ⬇</span>
            <ul class="user-menu">
                <li><a href="<?php echo esc_url(site_url('/dashboard/')); ?>">Dashboard</a></li>
                <li><a href="#">Gestione Inviti</a></li>
                <li><a href="<?php echo esc_url(wp_logout_url(home_url())); ?>">Esci</a></li>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    } else {
        return '<a class="login-link" href="' . esc_url(wp_login_url()) . '">Accedi</a>';
    }
});
