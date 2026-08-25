<?php
get_header();

// Gating: login obbligatorio
if (!is_user_logged_in()) {
  wp_redirect(wp_login_url(add_query_arg(null, null)));
  exit;
}

$invito_uuid = isset($_GET['invito_uuid']) ? sanitize_text_field($_GET['invito_uuid']) : '';
if (!$invito_uuid) {
  status_header(403);
  echo '<main class="site-main"><div class="container"><h2>Accesso non autorizzato (invito mancante)</h2></div></main>';
  get_footer();
  exit;
}

// Carica sessione da UUID
global $wpdb;
$session = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}game_sessions WHERE invito_uuid = %s",
  $invito_uuid
));
if (!$session) {
  status_header(404);
  echo '<main class="site-main"><div class="container"><h2>Sessione non trovata</h2></div></main>';
  get_footer();
  exit;
}
if (!empty($session->expires_at) && current_time('timestamp') > strtotime($session->expires_at)) {
  status_header(410);
  echo '<main class="site-main"><div class="container"><h2>Sessione scaduta</h2></div></main>';
  get_footer();
  exit;
}

// Se la sessione appartiene a un altro gioco, redirigi al gioco corretto
if (intval($session->gioco_id) !== get_the_ID()) {
  wp_redirect(add_query_arg(['invito_uuid' => $invito_uuid], get_permalink(intval($session->gioco_id))));
  exit;
}

// Verifica permessi: accesso consentito solo all'utente invitato per questa sessione
$current_user = wp_get_current_user();
$can_access = function_exists('gim_game_user_can_access_session')
  ? gim_game_user_can_access_session($session, $current_user)
  : false;

if (!$can_access) {
  status_header(403);
  echo '<main class="site-main"><div class="container"><h2>Non autorizzato</h2></div></main>';
  get_footer();
  exit;
}

if (function_exists('gim_game_bind_invited_user')) {
  gim_game_bind_invited_user($session, $current_user);
}

// Da qui in poi: utente autorizzato
$unity_nonce = wp_create_nonce('wp_rest');
$titolo_gioco = get_field('titolo_gioco', $session->gioco_id);
$descrizione_gioco = get_field('descrizione_gioco', $session->gioco_id);
$iframe_src = trim((string) get_field('unity_build_url', $session->gioco_id));

if ($iframe_src === '') {
  status_header(500);
  echo '<main class="site-main"><div class="container"><h2>Percorso Build Unity non configurato per questo gioco.</h2></div></main>';
  get_footer();
  exit;
}
?>
<main class="site-main">
  <div class="container">
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
