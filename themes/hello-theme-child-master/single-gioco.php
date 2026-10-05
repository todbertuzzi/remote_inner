<?php
// La pagina contiene credenziali di sessione personali, anche nelle risposte negate.
if (!defined('DONOTCACHEPAGE')) {
  define('DONOTCACHEPAGE', true);
}
nocache_headers();

// Gating: login obbligatorio
if (!is_user_logged_in()) {
  if (!empty($_GET['invito_uuid']) && is_string($_GET['invito_uuid'])) {
    wp_safe_redirect(add_query_arg('invito', sanitize_text_field(wp_unslash($_GET['invito_uuid'])), home_url('/gioca/')));
    exit;
  }

  wp_redirect(wp_login_url(add_query_arg(null, null)));
  exit;
}

$current_user = wp_get_current_user();
$invito_uuid = isset($_GET['invito_uuid']) ? sanitize_text_field(wp_unslash($_GET['invito_uuid'])) : '';
// Il permalink senza invito apre un'anteprima personale, autorizzata dal plugin.
// I link con invito continuano a seguire i normali controlli della sessione.
global $wpdb;
if (!$invito_uuid) {
  $session = function_exists('gim_get_game_preview_session')
    ? gim_get_game_preview_session(get_the_ID(), $current_user)
    : new WP_Error('preview_unavailable', 'Anteprima non disponibile: aggiorna il plugin Inviti Manager.', array('status' => 503));
  if (is_wp_error($session)) {
    status_header(function_exists('ipt_access_error_status') ? ipt_access_error_status($session) : 503);
    get_header();
    echo '<main class="site-main"><div class="container"><h2>' . esc_html($session->get_error_message()) . '</h2></div></main>';
    get_footer();
    exit;
  }
  $invito_uuid = $session->invito_uuid;
} else {
  $session = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}game_sessions WHERE invito_uuid = %s",
    $invito_uuid
  ));
}
if (!$session) {
  status_header(404);
  get_header();
  echo '<main class="site-main"><div class="container"><h2>Sessione non trovata</h2></div></main>';
  get_footer();
  exit;
}
$access_result = function_exists('ipt_validate_game_session_access')
  ? ipt_validate_game_session_access($session, $current_user)
  : new WP_Error('access_control_unavailable', 'Controllo accessi non disponibile.', array('status' => 503));

if (is_wp_error($access_result)) {
  $status = function_exists('ipt_access_error_status')
    ? ipt_access_error_status($access_result)
    : 403;
  status_header($status);
  get_header();
  echo '<main class="site-main"><div class="container"><h2>' . esc_html($access_result->get_error_message()) . '</h2></div></main>';
  get_footer();
  exit;
}

// Dopo aver validato l'utente, porta la sessione al gioco corretto.
if (intval($session->gioco_id) !== get_the_ID()) {
  wp_safe_redirect(add_query_arg(['invito_uuid' => $invito_uuid], get_permalink(intval($session->gioco_id))));
  exit;
}

if (function_exists('gim_game_bind_invited_user')) {
  gim_game_bind_invited_user($session, $current_user);
}

$is_admin_preview = ($session->status ?? '') === 'admin_preview';
$is_game_preview = in_array($session->status ?? '', array('admin_preview', 'member_preview'), true);

// Da qui in poi: utente autorizzato
$unity_nonce = wp_create_nonce('wp_rest');
$titolo_gioco = get_field('titolo_gioco', $session->gioco_id);
$descrizione_gioco = get_field('descrizione_gioco', $session->gioco_id);
$iframe_src = trim((string) get_field('unity_build_url', $session->gioco_id));

if ($iframe_src === '') {
  status_header(500);
  get_header();
  echo '<main class="site-main"><div class="container"><h2>Percorso Build Unity non configurato per questo gioco.</h2></div></main>';
  get_footer();
  exit;
}

get_header();
?>
<main class="site-main">
  <div class="container">
    <?php if ($is_game_preview): ?>
      <p role="note" style="padding:12px 16px; border:1px solid #cddde8; border-radius:10px; background:#f0f6fa; color:#183858;">
        <strong><?php echo $is_admin_preview ? 'Anteprima amministratore' : 'Anteprima del gioco'; ?></strong> · Puoi provare il gioco senza inviare inviti. L’anteprima è valida per un’ora.
        <a href="<?php echo esc_url(get_permalink(intval($session->gioco_id))); ?>">Riapri anteprima</a>
        · <a href="<?php echo esc_url(home_url('/dashboard-utente/')); ?>">Torna alla dashboard</a>
      </p>
    <?php endif; ?>
    <h1><?php echo esc_html($titolo_gioco ?: get_the_title()); ?></h1>

    <?php if ($descrizione_gioco): ?>
      <p><?php echo esc_html($descrizione_gioco); ?></p>
    <?php endif; ?>

    <iframe
      id="unityGameFrame"
      src="<?php echo esc_url($iframe_src); ?>"
      width="100%"
      height="650"
      style="border:0;"
      allowfullscreen></iframe>


    <script>
      const UNITY_NONCE = "<?php echo esc_js($unity_nonce); ?>";
      const INVITO_UUID = "<?php echo esc_js($invito_uuid); ?>";
      const USER_PRERENDER = {
        id: <?php echo intval($current_user->ID); ?>,
        username: "<?php echo esc_js($current_user->user_login); ?>",
        display_name: "<?php echo esc_js($current_user->display_name); ?>",
        role: "guest"
      };
      window.addEventListener("message", function(ev) {
        if (ev.data === "richiediNonce") {
          unityPost({
            tipo: "wp_nonce",
            valore: UNITY_NONCE
          });
        }
        if (ev.data === "richiediUUID") {
          unityPost({
            tipo: "invito_uuid",
            valore: INVITO_UUID
          });
        }
        if (ev.data === "richiediUser") {
          unityPost({
            tipo: "user_info",
            valore: USER_PRERENDER
          });
        }
      });

      function unityPost(payload) {
        document.getElementById("unityGameFrame").contentWindow.postMessage(payload, "*");
      }

      // Handshake automatico (Unity può ascoltare)
      unityPost({
        tipo: "bootstrap",
        nonce: UNITY_NONCE,
        invito_uuid: INVITO_UUID,
        user: USER_PRERENDER
      });
    </script>
  </div>
</main>
<?php
get_footer();
