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

// Verifica permessi: host o invitato
$current_user = wp_get_current_user();
$isHost = intval($session->host_user_id) === intval($current_user->ID);
if (!$isHost) {
    $isInvited = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}giochi_invitati
         WHERE session_id = %d AND (utente_id = %d OR invitato_email = %s)",
        $session->id, $current_user->ID, $current_user->user_email
    ));
    if (!$isInvited) {
        status_header(403);
        echo '<main class="site-main"><div class="container"><h2>Non autorizzato</h2></div></main>';
        get_footer();
        exit;
    }
    // Bind utente_id alla prima visita (se necessario)
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}giochi_invitati
         SET utente_id = %d
         WHERE session_id = %d AND utente_id IS NULL AND invitato_email = %s",
        $current_user->ID, $session->id, $current_user->user_email
    ));
}

// Da qui in poi: utente autorizzato
$unity_nonce = wp_create_nonce('wp_rest');
$titolo_gioco = get_field('titolo_gioco');
$descrizione_gioco = get_field('descrizione_gioco');
$iframe_src = '/wp-content/uploads/giochi/memory-test/WebGL/index.html';
?>
    <main class="site-main">
      <div class="container">
        <h1><?php echo esc_html($titolo_gioco ?: get_the_title()); ?></h1>

        <?php if ($descrizione_gioco): ?>
          <p><?php echo esc_html($descrizione_gioco); ?></p>
        <?php endif; ?>

        <iframe
          id="unityGameFrame"
          src="<?php echo esc_url($iframe_src . '?token=' . $full_token); ?>"
          width="100%"
          height="650"
          style="border:0;"
          allowfullscreen></iframe>

        <script>
          const UNITY_NONCE = "<?php echo esc_js($unity_nonce); ?>";
          const INVITO_UUID = "<?php echo esc_js($invito_uuid); ?>";

          window.addEventListener("message", function(event) {
            if (event.data === "richiediNonce") {
              document.getElementById("unityGameFrame").contentWindow.postMessage({
                tipo: "wp_nonce", valore: UNITY_NONCE
              }, "*");
            }
            if (event.data === "richiediUUID") {
              document.getElementById("unityGameFrame").contentWindow.postMessage({
                tipo: "invito_uuid", valore: INVITO_UUID
              }, "*");
            }
          });

          async function testGetJoinCode() {
            try {
              const r = await fetch('/wp-json/game/v1/get-join-code?invito_uuid=' + encodeURIComponent(INVITO_UUID), {
                method: 'GET',
                headers: { 'X-WP-Nonce': UNITY_NONCE }
              });
              const data = await r.json();
              console.log("Risposta get-join-code:", data);
            } catch (e) { console.error(e); }
          }
          if (INVITO_UUID) testGetJoinCode();
        </script>
      </div>
    </main>
<?php
get_footer();