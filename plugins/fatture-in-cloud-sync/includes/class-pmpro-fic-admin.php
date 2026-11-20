<?php
if (!defined('ABSPATH')) exit;

/** Presentation only. GET requests do not call gateways or construct PMPro orders. */
final class PMPro_FIC_Admin {
    const SECTIONS = ['panoramica' => 'Panoramica', 'documenti' => 'Documenti', 'rinnovi' => 'Rinnovi', 'impostazioni' => 'Impostazioni', 'assistenza' => 'Assistenza'];

    public static function url(string $section, array $args = []): string {
        if (!isset(self::SECTIONS[$section])) $section = 'panoramica';
        return admin_url('options-general.php?' . http_build_query(['page' => PMPro_FattureInCloud_Sync::PAGE, 'fic_section' => $section] + $args));
    }

    public static function action_section(string $action): string {
        if (in_array($action, ['plan_rules_save', 'connect', 'auto_accounts', 'auto_enable', 'auto_pause'], true)) return 'impostazioni';
        if (in_array($action, ['renewal_auto_retry', 'renewal_enable', 'renewal_status', 'renewal_pause', 'renewal_enqueue'], true)) return 'rinnovi';
        if (in_array($action, PMPro_FIC_Test_Orders::ACTIONS, true) || in_array($action, ['auto_retry', 'auto_run', 'auto_status'], true)) return 'documenti';
        if ($action === 'simulate' || in_array($action, PMPro_FIC_Diagnostics::ACTIONS, true)) return 'assistenza';
        return 'panoramica';
    }

    public static function section(string $action): string {
        if ($action !== '') return self::action_section($action);
        $section = $_GET['fic_section'] ?? '';
        return is_string($section) && isset(self::SECTIONS[$section]) ? $section : 'panoramica';
    }

    public static function enqueue(string $hook): void {
        if ($hook !== 'settings_page_' . PMPro_FattureInCloud_Sync::PAGE || !current_user_can('manage_options')) return;
        $url = plugin_dir_url(dirname(__DIR__) . '/fatture-in-cloud-sync.php') . 'assets/';
        wp_enqueue_style('pmpro-fic-admin', $url . 'admin.css', [], '0.8.0');
        wp_enqueue_script('pmpro-fic-admin', $url . 'admin.js', [], '0.8.0', true);
    }

    public static function render($plugin, $automation, $diagnostics, $orders, $result, string $action): void {
        $section = self::section($action);
        $descriptions = [
            'panoramica' => 'Lo stato della fatturazione e le attività da seguire.',
            'documenti' => 'Controlla la coda, cerca un ordine e verifica il documento associato.',
            'rinnovi' => 'Gestisci la fatturazione dei futuri incassi degli abbonamenti di prova.',
            'impostazioni' => 'Collegamento, regole dei documenti e automazione.',
            'assistenza' => 'Verifiche tecniche, prova dello scheduler e registri diagnostici.',
        ];
        $icons = ['panoramica' => 'dashboard', 'documenti' => 'media-document', 'rinnovi' => 'update', 'impostazioni' => 'admin-settings', 'assistenza' => 'sos'];
        echo '<div class="wrap fic-admin"><a class="screen-reader-text" href="#fic-content">Vai al contenuto della fatturazione</a><div class="fic-shell"><aside class="fic-sidebar"><div class="fic-brand"><span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span><div><strong>Fatturazione</strong><small>PMPro · Fatture in Cloud</small></div></div><nav aria-label="Fatturazione">';
        foreach (self::SECTIONS as $key => $label) echo '<a href="' . esc_url(self::url($key)) . '"' . ($key === $section ? ' aria-current="page"' : '') . '><span class="dashicons dashicons-' . esc_attr($icons[$key]) . '" aria-hidden="true"></span>' . esc_html($label) . '</a>';
        echo '</nav><div class="fic-side-note"><strong>Ambiente di collaudo</strong><p>Documenti /TEST<br>Versione 0.8.0</p></div></aside><main id="fic-content" class="fic-content" tabindex="-1"><header class="fic-heading"><p class="fic-eyebrow">PMPro → Fatture in Cloud</p><h1>' . esc_html(self::SECTIONS[$section]) . '</h1><p>' . esc_html($descriptions[$section]) . '</p></header><div class="fic-sandbox"><strong>Modalità di prova · PayPal Sandbox</strong><span>Documenti nell’azienda di prova, nel sezionale /TEST. Nessun invio allo SDI o via email.</span></div>';
        settings_errors(PMPro_FattureInCloud_Sync::OPTION_NAME);
        $delegated = in_array($action, PMPro_FIC_Test_Orders::ACTIONS, true) || in_array($action, PMPro_FIC_Automation::ACTIONS, true) || in_array($action, PMPro_FIC_Diagnostics::ACTIONS, true);
        if (is_wp_error($result) && !$delegated) echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        elseif ($action === 'connect' && $result !== null) echo '<div class="notice notice-success inline" role="status"><p>Collegamento riuscito. Azienda e aliquote lette correttamente. Seleziona ora l’aliquota e salva.</p></div>';

        if ($section === 'panoramica') self::overview($automation);
        elseif ($section === 'documenti') {
            echo '<section class="fic-card">'; $automation->render($result, $action, $section); echo '</section>';
            echo '<details class="fic-card fic-disclosure"' . (in_array($action, PMPro_FIC_Test_Orders::ACTIONS, true) ? ' open' : '') . '><summary>Operazioni manuali e recupero anomalie</summary><p>Creazione guidata, verifica di invii incerti e riconciliazione del primo incasso su due ordini.</p>';
            $orders->render($result, $action); echo '</details>';
        } elseif ($section === 'rinnovi') {
            echo '<div class="fic-callout"><strong>Autorizzi la fatturazione, non gli addebiti.</strong><p>PayPal gestisce autonomamente i rinnovi degli abbonamenti. Questi comandi autorizzano o sospendono soltanto i documenti /TEST per i nuovi incassi. La prossima data di addebito va verificata in PayPal.</p></div>';
            self::renewal_jobs();
            echo '<section class="fic-card"><h2>Autorizzazioni registrate</h2>'; self::renewal_list($automation); echo '</section>';
            echo '<section class="fic-card">'; $automation->render($result, $action, $section); echo '</section>';
        } elseif ($section === 'impostazioni') {
            echo '<section class="fic-card"><h2>Collegamento e regole dei documenti</h2>'; $plugin->render_connection_settings(); echo '</section>';
            echo '<section class="fic-card"><h2>Automazione dei documenti</h2>'; $automation->render($result, $action, $section); echo '</section>';
        } else {
            echo '<section class="fic-card">'; $diagnostics->render($result, $action); echo '</section>';
            echo '<section class="fic-card fic-events">'; PMPro_FIC_Webhook_Guard::render_status(); echo '</section>';
            echo '<details class="fic-card fic-disclosure"' . ($action === 'simulate' ? ' open' : '') . '><summary>Simulazione locale di una fattura</summary>'; $plugin->render_simulation($result, $action); echo '</details>';
            echo '<p>Per recuperare un documento incerto o analizzare una coppia di ordini, apri <a href="' . esc_url(self::url('documenti')) . '">Documenti → Operazioni manuali</a>.</p>';
        }
        echo '</main></div></div>';
    }

    public static function recent_jobs() {
        global $wpdb;
        $index = get_option(PMPro_FIC_Automation::INDEX, []);
        $error = new WP_Error('ui_queue', 'Coda recente non disponibile o incompleta. Controlla un ordine per ID e verifica il registro.');
        if (!is_array($index) || !empty($wpdb->last_error)) return $error;
        $jobs = [];
        foreach (array_slice($index, 0, 20) as $id) {
            if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/D', (string) $id)) return $error;
            $job = get_option('pmpro_fic_job_' . (int) $id, null);
            if (!empty($wpdb->last_error) || !is_array($job)) return $error;
            foreach (['state', 'message', 'attempts', 'updated_at', 'order_id'] as $key) if (!isset($job[$key]) || !is_scalar($job[$key])) return $error;
            if ((int) $job['order_id'] !== (int) $id) return $error;
            foreach (['document_id', 'payment_id', 'subscription_id', 'company_id', 'kind', 'generation'] as $key) if (isset($job[$key]) && !is_scalar($job[$key])) return $error;
            $linked = PMPro_FIC_Initial_Pair::source_link((int) $id);
            if (is_wp_error($linked)) return $error;
            $job['ui_linked_order'] = $linked;
            $jobs[(int) $id] = $job;
        }
        return $jobs;
    }

    private static function overview($automation): void {
        $jobs = self::recent_jobs();
        echo '<h2>La coda recente</h2><p class="fic-muted">Contatori riferiti agli ultimi 20 ordini in coda. Non rappresentano lo storico completo né il totale delle fatture.</p>';
        if (is_wp_error($jobs)) echo '<div class="notice notice-warning inline"><p>' . esc_html($jobs->get_error_message()) . '</p></div>';
        else {
            $counts = ['created' => 0, 'pending' => 0, 'review' => 0];
            $linked = 0;
            foreach ($jobs as $job) {
                if (is_int($job['ui_linked_order'])) { $linked++; continue; }
                $key = $job['state'] === 'created' ? 'created' : (in_array($job['state'], ['queued', 'waiting', 'processing'], true) ? 'pending' : 'review');
                $counts[$key]++;
            }
            echo '<div class="fic-metrics">';
            foreach (['created' => 'Documenti associati', 'pending' => 'In attesa o in lavorazione', 'review' => 'Da verificare'] as $key => $label) echo '<a class="fic-metric" href="' . esc_url(self::url('documenti')) . '"><span>' . esc_html($label) . '</span><strong>' . (int) $counts[$key] . '</strong><small>Nel campione di ' . count($jobs) . ' ordini</small></a>';
            echo '</div>';
            if ($linked) echo '<p class="fic-muted">Nel campione sono inoltre presenti ' . $linked . ' checkout collegati a un altro ordine con incasso. Consulta i collegamenti in Documenti.</p>';
        }
        self::renewal_jobs();
        $status = $automation->ui_status();
        $config = get_option(PMPro_FIC_Automation::CONFIG, []);
        echo '<div class="fic-grid"><section class="fic-card"><h2>Automazione</h2><p><span class="fic-badge">' . (is_wp_error($status) ? 'Non operativa' : 'Attiva') . '</span></p><p>' . esc_html(is_wp_error($status) ? $status->get_error_message() : 'Configurazione valida per i nuovi acquisti Sandbox e per i rinnovi autorizzati.') . '</p><p class="fic-muted">Attivazione salvata: ' . (!empty($config['active']) ? 'sì' : 'no') . '.</p><a href="' . esc_url(self::url('impostazioni')) . '">Gestisci impostazioni →</a></section>';
        echo '<section class="fic-card"><h2>Ultima prova cron</h2>';
        $probe = get_option(PMPro_FIC_Diagnostics::CRON_OPTION, []);
        if (!is_array($probe) || empty($probe['due_at'])) echo '<p>Nessuna prova registrata.</p>';
        else {
            echo '<p>Scadenza: ' . esc_html(gmdate('d/m/Y H:i:s', (int) $probe['due_at'])) . ' UTC</p>';
            echo empty($probe['ran_at']) ? '<p>In attesa di esecuzione.</p>' : '<p>Eseguita: ' . esc_html(gmdate('d/m/Y H:i:s', (int) $probe['ran_at'])) . ' UTC</p>';
        }
        echo '<p class="fic-muted">È l’esito di una prova, non un monitoraggio continuo dello scheduler.</p><a href="' . esc_url(self::url('assistenza')) . '">Apri le verifiche →</a></section></div>';
        echo '<section class="fic-card"><h2>Segui i documenti</h2><p>Controlla l’esito di ogni ordine, apri i riferimenti tecnici o verifica gli ordini precedenti con il loro ID.</p><a class="button button-primary" href="' . esc_url(self::url('documenti')) . '">Apri documenti</a></section>';
    }

    public static function queue_filters(): void {
        echo '<div class="fic-filters" data-fic-filters hidden><label>Cerca nel campione<input type="search" data-fic-search placeholder="Ordine, documento o ID incasso"></label><label>Esito<select data-fic-filter><option value="all">Tutti gli esiti</option><option value="created">Documento associato</option><option value="pending">In lavorazione</option><option value="review">Da verificare o correggere</option><option value="linked">Checkout collegato</option></select></label><span data-fic-count role="status" aria-live="polite"></span></div>';
    }

    public static function renewal_job_status(array $job): void {
        if (!isset($job['renewal_auto'])) return;
        $state = $job['renewal_auto']['state'] ?? '';
        $message = $job['renewal_auto']['message'] ?? '';
        if (!empty($job['subscription_id']) && get_option(PMPro_FIC_Renewals::key($job['subscription_id']) . '_paused', false)) {
            $state = 'suspended'; $message = 'Sospensione manuale attiva. Gli addebiti PayPal non vengono modificati.';
        } elseif ($state === 'pending' && in_array($job['state'] ?? '', ['blocked', 'attention'], true)) {
            $state = 'attention'; $message = 'Prima correggi il documento iniziale in Documenti. ' . ($job['message'] ?? '');
        }
        $labels = ['pending'=>'Abilitazione rinnovi in attesa', 'authorized'=>'Rinnovi autorizzati', 'attention'=>'Abilitazione rinnovi da correggere', 'suspended'=>'Fatturazione rinnovi sospesa'];
        echo '<p><strong>' . esc_html($labels[$state] ?? 'Abilitazione da verificare') . '</strong><br>' . esc_html($message) . '</p>';
        if (($job['state'] ?? '') === 'created' && in_array($state, ['pending', 'attention'], true)) {
            echo '<form method="post" action="' . esc_url(self::url('rinnovi') . '#fic-auto-result') . '">';
            wp_nonce_field(PMPro_FattureInCloud_Sync::NONCE);
            echo '<input type="hidden" name="fic_action" value="renewal_auto_retry"><input type="hidden" name="order_id" value="' . (int) $job['order_id'] . '">';
            submit_button('Riprova solo abilitazione rinnovi', 'secondary'); echo '</form>';
        }
    }

    private static function renewal_jobs(): void {
        $error = get_option('pmpro_fic_plan_error', '');
        if (is_string($error) && $error !== '') echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p><p>Verifica le regole in Impostazioni. Questo avviso resta fino a un salvataggio riuscito; gli ordini saltati non vengono importati automaticamente.</p></div>';
        $jobs = self::recent_jobs();
        if (is_wp_error($jobs)) return;
        $jobs = array_filter($jobs, function ($job) { return isset($job['renewal_auto']) && in_array($job['renewal_auto']['state'], ['pending', 'attention'], true) && (empty($job['subscription_id']) || !get_option(PMPro_FIC_Renewals::key($job['subscription_id']) . '_paused', false)); });
        if (!$jobs) return;
        echo '<section class="fic-card"><h2>Abilitazioni dei rinnovi da seguire</h2><p>Esiti degli ultimi 20 lavori in coda. Per un ordine precedente usa Controlla ordine in coda in Documenti. Un documento associato può avere un’abilitazione ai rinnovi ancora da completare.</p>';
        foreach ($jobs as $job) { echo '<article class="fic-policy"><h3>Ordine #' . (int) $job['order_id'] . '</h3><div>'; self::renewal_job_status($job); echo '</div></article>'; }
        echo '</section>';
    }

    public static function job_details(array $job): void {
        echo '<details class="fic-job-detail"><summary>Dettagli</summary><dl><dt>Ultimo aggiornamento UTC</dt><dd>' . esc_html(gmdate('d/m/Y H:i:s', (int) $job['updated_at'])) . '</dd>';
        foreach (['order_id' => 'Ordine PMPro', 'document_id' => 'Documento FIC', 'payment_id' => 'ID incasso registrato', 'subscription_id' => 'Abbonamento', 'company_id' => 'Azienda FIC'] as $key => $label) {
            if (isset($job[$key]) && is_scalar($job[$key]) && (string) $job[$key] !== '') echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html((string) $job[$key]) . '</dd>';
        }
        echo '</dl><p>Riferimenti salvati nella coda. Per verificare i dati dell’ordine usa le operazioni manuali.</p></details>';
    }

    /** Enumerate canonical policies, excluding snapshots and pause flags. No cached mirror or migration. */
    public static function policies(int $page) {
        global $wpdb;
        if (!isset($wpdb->options) || !method_exists($wpdb, 'get_col')) return new WP_Error('ui_policies', 'Elenco autorizzazioni non disponibile. Usa Controlla autorizzazione per un singolo ordine.');
        $prefix = 'pmpro_fic_renewal_';
        $keys = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE SUBSTRING(option_name, 1, %d) = %s AND LENGTH(option_name) = %d ORDER BY option_name LIMIT 21 OFFSET %d", strlen($prefix), $prefix, strlen($prefix) + 64, (max(1, min(100000, $page)) - 1) * 20));
        if (!empty($wpdb->last_error) || !is_array($keys)) return new WP_Error('ui_policies', 'Impossibile leggere l’elenco autorizzazioni. Riprova o controlla un ordine per ID.');
        $rows = [];
        foreach (array_slice($keys, 0, 20) as $key) {
            $stored = PMPro_FIC_Atomic_Store::read($key);
            if (is_wp_error($stored) || !is_array($stored) || !is_string($stored['subscription_id'] ?? null) || PMPro_FIC_Renewals::key($stored['subscription_id']) !== $key) {
                $rows[] = new WP_Error('ui_policy', 'Un’autorizzazione non è leggibile. Verifica il registro prima di procedere.'); continue;
            }
            $policy = PMPro_FIC_Renewals::policy((object) ['gateway' => 'paypal', 'gateway_environment' => 'sandbox', 'subscription_transaction_id' => $stored['subscription_id']]);
            $rows[] = !$policy || is_wp_error($policy) ? new WP_Error('ui_policy', 'Autorizzazione incompleta o non valida: ' . $stored['subscription_id']) : ['policy' => $policy, 'paused' => (bool) get_option($key . '_paused', false)];
        }
        return ['rows' => $rows, 'more' => count($keys) > 20];
    }

    private static function renewal_list($automation): void {
        $raw = $_GET['fic_policies_page'] ?? '1';
        $page = is_scalar($raw) ? max(1, min(100000, (int) $raw)) : 1;
        $list = self::policies($page);
        if (is_wp_error($list)) { echo '<p>' . esc_html($list->get_error_message()) . '</p>'; return; }
        if (!$list['rows']) echo '<p>Nessuna autorizzazione in questa pagina.</p>';
        $config = get_option(PMPro_FIC_Automation::CONFIG, []);
        $enabled = $automation->ui_status();
        foreach ($list['rows'] as $row) {
            if (is_wp_error($row)) { echo '<p class="notice notice-warning inline">' . esc_html($row->get_error_message()) . '</p>'; continue; }
            $policy = $row['policy'];
            $matches = ($policy['scope'] === ($config['scope'] ?? null) && $policy['company_id'] === ($config['company_id'] ?? null) && $policy['account_id'] === ($config['account_id'] ?? null));
            $label = $row['paused'] ? 'Fatturazione sospesa' : (!$matches ? 'Configurazione da verificare' : (is_wp_error($enabled) ? 'Autorizzata · automazione non operativa' : 'Fatturazione autorizzata'));
            echo '<article class="fic-policy"><div><h3>Ordine iniziale #' . (int) $policy['source_order_id'] . '</h3><p>' . esc_html($policy['subscription_id']) . '</p></div><div><span class="fic-badge">' . esc_html($label) . '</span><p>Incassi successivi al ' . esc_html(gmdate('d/m/Y H:i:s', $policy['effective_from'])) . ' UTC.</p><p class="fic-muted">Copia fiscale dell’ordine #' . (int) $policy['source_order_id'] . '.</p><p class="fic-muted">' . (($policy['authorization_origin']['type'] ?? '') === 'plan' ? 'Abilitazione automatica dal piano #' . (int) $policy['level_id'] : 'Autorizzazione manuale') . '</p></div></article>';
        }
        echo '<div class="fic-pagination"><span>Pagina ' . $page . ' · fino a 20 autorizzazioni</span>';
        if ($page > 1) echo '<a href="' . esc_url(self::url('rinnovi', ['fic_policies_page' => $page - 1])) . '">← Precedenti</a>';
        if ($list['more']) echo '<a href="' . esc_url(self::url('rinnovi', ['fic_policies_page' => $page + 1])) . '">Successive →</a>';
        echo '</div>';
    }
}
