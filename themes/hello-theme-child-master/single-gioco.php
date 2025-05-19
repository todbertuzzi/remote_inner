<?php
get_header();

if (have_posts()) : while (have_posts()) : the_post();

    $titolo_gioco = get_field('titolo_gioco');
    $descrizione_gioco = get_field('descrizione_gioco');
    $iframe_src = '/wp-content/uploads/giochi/memory-test/WebGL/index.html';

    // wp_nonce per Unity
    $unity_nonce = wp_create_nonce('wp_rest');
    $invito_token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    
?>

<?php
echo '<div style="padding:1em; background:#f3f3f3; border:1px solid #ccc; margin-bottom:1em;">';

echo '<strong>Utente corrente:</strong> ' . get_current_user_id() . '<br>';

global $wpdb;
$token = $invito_token;//'oetEYnFDRS0UGCPD';
echo '<pre>Token passato: [' . $token . ']</pre>';
$invito = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}giochi_invitati WHERE token = %s",
    $token
));

if ($invito) {
    echo '<strong>Token assegnato a utente:</strong> ' . $invito->token . '<br>';
} else {
    echo '<strong>Token non trovato nel database</strong><br>';
}

echo '</div>';
?>

    <main class="site-main">
      <div class="container">
        <h1><?php echo esc_html($titolo_gioco ?: get_the_title()); ?></h1>

        <?php if ($descrizione_gioco): ?>
          <p><?php echo esc_html($descrizione_gioco); ?></p>
        <?php endif; ?>

        <!-- iframe Unity -->
        <iframe
          id="unityGameFrame"
          src="<?php echo esc_url($iframe_src); ?>"
          width="100%"
          height="650"
          style="border:0;"
          allowfullscreen></iframe>

        <script>
          // Invia nonce e token a Unity (quando richiesto)
          const UNITY_NONCE = "<?php echo esc_js($unity_nonce); ?>";
          const INVITO_TOKEN = "<?php echo esc_js($invito_token); ?>";

          window.addEventListener("message", function(event) {
            if (event.data === "richiediNonce") {
              const iframe = document.getElementById("unityGameFrame");
              iframe.contentWindow.postMessage({
                tipo: "wp_nonce",
                valore: UNITY_NONCE
              }, "*");
            }

            if (event.data === "richiediToken") {
              const iframe = document.getElementById("unityGameFrame");
              iframe.contentWindow.postMessage({
                tipo: "invito_token",
                valore: INVITO_TOKEN
              }, "*");
            }
          });

          

          // ✅ Codice di test da aggiungere qui:
          async function testValidaToken() {
            if (!INVITO_TOKEN || !UNITY_NONCE) {
              console.warn("Token o nonce mancanti.");
              return;
            }

            try {
              const response = await fetch('/wp-json/giochi/v1/valida-token', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-WP-Nonce': UNITY_NONCE
                },
                body: JSON.stringify({
                  token: INVITO_TOKEN
                })
              });

              const data = await response.json();
              console.log("Risposta valida-token:", data);
              alert("Risultato:\n" + JSON.stringify(data, null, 2));
            } catch (err) {
              console.error("Errore nella richiesta:", err);
              alert("Errore di rete");
            }
          }

         /*  testValidaToken(); */
        </script>
      </div>
    </main>

<?php
  endwhile;
endif;

get_footer();
?>