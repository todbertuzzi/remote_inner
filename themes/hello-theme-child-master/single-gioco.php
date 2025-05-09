<?php
get_header();

if (have_posts()) : while (have_posts()) : the_post();

  $titolo_gioco = get_field('titolo_gioco');
  $descrizione_gioco = get_field('descrizione_gioco');
  $iframe_src = '/wp-content/uploads/giochi/memory-test/WebGL/index.html';

  // wp_nonce per Unity
  $unity_nonce = wp_create_nonce('wp_rest');
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
      allowfullscreen
    ></iframe>

    <script>
      // Invia il nonce a Unity (quando Unity lo richiede)
      const UNITY_NONCE = "<?php echo esc_js($unity_nonce); ?>";

      window.addEventListener("message", function(event) {
        if (event.data === "richiediNonce") {
          const iframe = document.getElementById("unityGameFrame");
          iframe.contentWindow.postMessage({
            tipo: "wp_nonce",
            valore: UNITY_NONCE
          }, "*");
        }
      });
    </script>
  </div>
</main>

<?php
endwhile;
endif;

get_footer();
?>
