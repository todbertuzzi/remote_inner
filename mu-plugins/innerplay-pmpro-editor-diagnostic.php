<?php
/**
 * Plugin Name: Innerplay - Diagnostica temporanea editor PMPro
 * Description: Diagnostica di sola lettura, visibile agli admin nella modifica livelli PMPro. Rimuovere dopo la verifica.
 * Version: 1.1.0
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function () {
    if (!current_user_can('manage_options')
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || ($_GET['page'] ?? '') !== 'pmpro-membershiplevels'
        || !isset($_GET['edit'])
        || wp_doing_ajax()) {
        return;
    }

    // Conferma il caricamento prima del contenuto PMPro, indipendentemente
    // dall'esecuzione del footer e delle funzioni di shutdown.
    add_action('admin_notices', function () {
        echo '<div id="innerplay-pmpro-diagnostic-loaded" class="notice notice-warning" style="display:block;padding:12px;border-left:4px solid #946200;background:#fff8e5;color:#222;">';
        echo '<p><strong>Diagnostica PMPro attiva (v1.1).</strong> Il file diagnostico è stato caricato. Il rapporto finale, se eseguito, apparirà in basso a destra.</p>';
        echo '</div>';
    }, 1);

    // Una piccola riserva permette di mostrare il messaggio anche dopo molti
    // errori di memoria. Non vengono scritti log, opzioni o contenuti del sito.
    $reserve = str_repeat(' ', 65536);
    register_shutdown_function(function () use (&$reserve) {
        $reserve = null;
        $error = error_get_last();
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        $fatal = $error && in_array($error['type'], $fatal_types, true);
        $footer = did_action('admin_footer');
        $footer_scripts = did_action('admin_print_footer_scripts');
        $editor_class = class_exists('_WP_Editors', false);

        $lines = array();
        if ($fatal) {
            $lines[] = 'ERRORE PHP: ' . strtok($error['message'], "\n");
            $lines[] = 'FILE: ' . $error['file'];
            $lines[] = 'RIGA: ' . $error['line'];
        } elseif (!$footer || !$footer_scripts) {
            $lines[] = 'Il footer WordPress non ha completato i passaggi previsti.';
            $lines[] = 'Nessun errore PHP fatale rilevato: verificare anche exit/die o interruzioni deliberate.';
        } else {
            $lines[] = 'I passaggi del footer risultano eseguiti. Verificare gli script stampati nella pagina.';
        }

        $lines[] = '';
        $lines[] = 'admin_footer: ' . $footer;
        $lines[] = 'admin_print_footer_scripts: ' . $footer_scripts;
        $lines[] = 'Classe editor caricata: ' . ($editor_class ? 'si' : 'no');
        if ($editor_class) {
            $priority = has_action('admin_print_footer_scripts', array('_WP_Editors', 'editor_js'));
            $lines[] = 'Callback configurazione editor: ' . ($priority === false ? 'assente' : 'priorita ' . $priority);
        }
        foreach (array('editor', 'quicktags', 'wp-tinymce') as $handle) {
            $lines[] = $handle . ': in coda=' . (wp_script_is($handle, 'enqueued') ? 'si' : 'no')
                . ', stampato=' . (wp_script_is($handle, 'done') ? 'si' : 'no');
        }

        // Un exit dentro un hook puo lasciare lo stack attivo fino allo shutdown.
        $hooks = array_values(array_filter($GLOBALS['wp_current_filter'] ?? array(), function ($hook) {
            return $hook !== 'shutdown';
        }));
        if ($hooks) {
            $lines[] = 'Hook ancora attivi: ' . implode(' > ', $hooks);
        }

        $report = htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<aside role="note" aria-label="Diagnostica editor PMPro" style="position:fixed;bottom:16px;right:16px;z-index:999999;max-width:760px;width:calc(100% - 32px);max-height:55vh;overflow:auto;padding:16px;box-sizing:border-box;background:#fff8e5;border:2px solid #946200;border-radius:8px;color:#222;box-shadow:0 6px 30px #0003;font:14px/1.5 sans-serif;">';
        echo '<strong>Diagnostica temporanea editor PMPro</strong>';
        echo '<pre style="white-space:pre-wrap;overflow-wrap:anywhere;margin:12px 0;color:#222;font:12px/1.5 monospace;">' . $report . '</pre>';
        echo '<small>Visibile solo agli amministratori. Rimuovi il file diagnostico dopo la verifica.</small></aside>';
    });
}, 1);
