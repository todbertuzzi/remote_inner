<?php
/**
 * Shortcode [menu_profilo_utente] per mostrare "Accedi" o il menu utente loggato
 */

add_shortcode('menu_profilo_utente', function () {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $dashboard_url = function_exists('ipt_get_user_dashboard_url')
            ? ipt_get_user_dashboard_url($user->ID)
            : site_url('/dashboard-utente/');
        $inviti_url = function_exists('ipt_get_invited_dashboard_url')
            ? ipt_get_invited_dashboard_url(false)
            : '';
        $has_received_invites = function_exists('gim_user_has_received_invites')
            ? gim_user_has_received_invites($user->ID)
            : false;

        if ($inviti_url === '' && function_exists('ipt_scrivania_user_has_active_invites') && ipt_scrivania_user_has_active_invites($user->ID)) {
            $inviti_url = function_exists('ipt_get_invited_dashboard_url')
                ? ipt_get_invited_dashboard_url(true)
                : '';
            $has_received_invites = true;
        }

        $show_inviti = $inviti_url !== ''
            && $has_received_invites
            && untrailingslashit($dashboard_url) !== untrailingslashit($inviti_url);

        ob_start();
        ?>
        <div class="user-dropdown">
            <span class="user-name">Ciao, <?php echo esc_html($user->display_name); ?> ⬇</span>
            <ul class="user-menu">
                <li><a href="<?php echo esc_url($dashboard_url); ?>">Dashboard</a></li>
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
