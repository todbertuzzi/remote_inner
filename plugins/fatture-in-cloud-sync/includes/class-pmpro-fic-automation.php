<?php
if (!defined('ABSPATH')) exit;

/** Coda opt-in per acquisti e rinnovi autorizzati Sandbox. Nessuna API nei nuovi hook rinnovi. */
final class PMPro_FIC_Automation {
    const CONFIG = 'pmpro_fic_automation';
    const INDEX = 'pmpro_fic_automation_recent';
    const HOOK = 'pmpro_fic_process_order';
    const ACTIONS = ['auto_accounts', 'auto_enable', 'auto_pause', 'auto_retry', 'auto_run', 'auto_status', 'renewal_enable', 'renewal_status', 'renewal_pause', 'renewal_enqueue'];
    private $plugin;

    public function __construct(PMPro_FattureInCloud_Sync $plugin) {
        $this->plugin = $plugin;
        add_action('pmpro_added_order', [$this, 'on_new_order'], 40);
        add_action('pmpro_updated_order', [$this, 'on_updated_order'], 40);
        add_action('pmpro_after_checkout', [$this, 'after_checkout'], 40, 2);
        add_action('pmpro_subscription_payment_completed', [$this, 'on_renewal'], 40);
        add_action(self::HOOK, [$this, 'process'], 10, 1);
    }

    private function config(): array {
        $config = get_option(self::CONFIG, []);
        return is_array($config) ? $config : [];
    }

    private function enabled() {
        $config = $this->config();
        if (empty($config['active'])) return new WP_Error('auto_paused', 'Automazione disattivata.');
        if (get_option('pmpro_gateway', '') !== 'paypal' || get_option('pmpro_gateway_environment', '') !== 'sandbox'
            || strtoupper((string) get_option('pmpro_currency', '')) !== 'EUR') {
            return new WP_Error('auto_sandbox', 'L’automazione richiede gateway PayPal, ambiente Sandbox e valuta EUR.');
        }
        if (($config['scope'] ?? '') !== $this->plugin->automation_scope()) return new WP_Error('auto_config_changed', 'Configurazione modificata. Riconfigura l’automazione prima di proseguire.');
        return $config;
    }

    public function invalidate(): void {
        $config = $this->config();
        if (empty($config['active'])) return;
        $config['active'] = false;
        update_option(self::CONFIG, $config, false);
    }

    private function key(int $id): string { return 'pmpro_fic_job_' . $id; }

    private function job(int $id): array {
        $job = get_option($this->key($id), []);
        return is_array($job) ? $job : [];
    }

    private function save(array $job): bool {
        $job['updated_at'] = time();
        update_option($this->key((int) $job['order_id']), $job, false);
        return $this->job((int) $job['order_id']) === $job;
    }

    private function source($order, bool $reload = true) {
        if (!is_object($order) || empty($order->id) || !class_exists('MemberOrder')) return false;
        if ($reload) $order = new MemberOrder((int) $order->id);
        if (empty($order->id) || $order->gateway !== 'paypal' || $order->gateway_environment !== 'sandbox'
            || !in_array($order->status, ['token', 'pending', 'success'], true) || !is_numeric($order->total)
            || !is_finite((float) $order->total) || (float) $order->total <= 0 || (float) $order->total > 9999999.99) return false;
        $snapshot = PMPro_FIC_Billing::snapshot((int) $order->id);
        if (!$snapshot || is_wp_error($snapshot) || (int) $snapshot['user_id'] !== (int) $order->user_id
            || (int) $snapshot['level_id'] !== (int) $order->membership_id || $snapshot['currency'] !== 'EUR') return false;
        return $order;
    }

    private function identity($order): string {
        return hash('sha256', wp_json_encode([(int) $order->id, (string) $order->code, (int) $order->user_id,
            (int) $order->membership_id, (string) $order->gateway, (string) $order->gateway_environment,
            number_format((float) $order->total, 2, '.', ''), PMPro_FIC_Billing::snapshot((int) $order->id)]));
    }

    private function context_scope(array $context): string {
        $parts = [$context['company_id'], $context['token'], $context['vat'], $context['description']];
        if (!empty($context['plan_descriptions'])) $parts[] = $context['plan_descriptions'];
        return hash('sha256', wp_json_encode($parts));
    }

    private function schedule(int $id, int $delay): bool {
        if (wp_next_scheduled(self::HOOK, [$id])) return true;
        $result = wp_schedule_single_event(time() + $delay, self::HOOK, [$id], true);
        return $result === true;
    }

    public function on_new_order($order): void {
        $config = $this->enabled();
        if (is_wp_error($config) || !is_object($order) || empty($order->id)) return;
        if (!PMPro_FIC_Billing::snapshot((int) $order->id)) {
            if (($order->status ?? '') !== 'success' || empty($order->payment_transaction_id)) return;
            $captured = PMPro_FIC_Renewals::capture($order, $config);
            if (is_wp_error($captured)) {
                update_option('pmpro_fic_renewal_error', 'Ordine #' . (int) $order->id . ': ' . $captured->get_error_message(), false);
                return;
            }
        }
        // Negli hook usiamo l'oggetto appena salvato: il costruttore PMPro può sincronizzare il gateway.
        if (!($order = $this->source($order, false))) return;
        $snapshot = PMPro_FIC_Billing::snapshot((int) $order->id);
        $renewal = ($snapshot['origin'] ?? '') === 'renewal';
        if ($renewal && is_wp_error(PMPro_FIC_Renewals::guard($order, $config))) return;
        $id = (int) $order->id;
        // L'ordine iniziale viene salvato prima che PayPal assegni l'ID abbonamento.
        $job = ['order_id' => $id, 'state' => 'queued', 'attempts' => 0, 'generation' => $config['generation'],
            'scope' => $config['scope'], 'context_scope' => $config['context_scope'], 'company_id' => $config['company_id'],
            'account_id' => $config['account_id'], 'identity' => $this->identity($order),
            'recover_missing_payment' => !$renewal && !empty($config['recover_missing_payment']),
            'kind' => $renewal ? 'renewal' : 'checkout', 'payment_id' => $renewal ? (string) $order->payment_transaction_id : '',
            'subscription_id' => (string) $order->subscription_transaction_id, 'created_at' => time(), 'updated_at' => time(),
            'message' => $renewal ? 'Rinnovo in attesa della verifica dell’incasso PayPal.' : 'In attesa della conferma del primo pagamento.'];
        if (!add_option($this->key($id), $job, '', false)) return;
        $index = get_option(self::INDEX, []);
        $index = is_array($index) ? $index : [];
        array_unshift($index, $id);
        update_option(self::INDEX, array_slice(array_values(array_unique($index)), 0, 20), false);
        if (!$this->schedule($id, 30)) {
            $job['state'] = 'blocked'; $job['message'] = 'Impossibile programmare la coda. Usa Riprova dopo aver verificato WP-Cron.'; $this->save($job);
        }
    }

    public function on_updated_order($order): void {
        if (!is_object($order) || empty($order->id)) return;
        $job = $this->job((int) $order->id);
        if (!$job) { $this->on_renewal($order); return; }
        if (!$job || !in_array($job['state'], ['queued', 'waiting'], true) || is_wp_error($this->enabled())) return;
        if ($this->source($order, false) && $this->identity($order) === $job['identity'] && empty($job['subscription_id']) && !empty($order->subscription_transaction_id)) {
            $job['subscription_id'] = (string) $order->subscription_transaction_id;
            $this->save($job);
        }
        $this->schedule((int) $order->id, 30);
    }

    public function after_checkout($user_id, $order): void { $this->on_updated_order($order); }

    public function on_renewal($order): void {
        if (!is_object($order) || empty($order->id) || ($order->status ?? '') !== 'success'
            || empty($order->payment_transaction_id) || $this->job((int) $order->id)) return;
        $policy = PMPro_FIC_Renewals::policy($order);
        if (!is_array($policy) || $policy['source_order_id'] === (int) $order->id) return;
        $this->on_new_order($order);
    }

    private function guard(array $job) {
        $linked = PMPro_FIC_Initial_Pair::source_link((int) $job['order_id']);
        if (is_wp_error($linked)) return $linked;
        if ($linked !== null) return new WP_Error('auto_initial_linked', 'Checkout collegato all’ordine con incasso #' . $linked . '. Usa quell’ordine per il documento.');
        $config = $this->enabled();
        if (is_wp_error($config)) return $config;
        foreach (['generation', 'scope', 'context_scope', 'company_id', 'account_id'] as $key) {
            if (($job[$key] ?? null) !== ($config[$key] ?? null)) return new WP_Error('auto_config_changed', 'La configurazione della coda è cambiata. Verifica il lavoro prima di riprovarlo.');
        }
        if (($job['kind'] ?? '') !== 'renewal' && !empty($job['recover_missing_payment']) !== !empty($config['recover_missing_payment'])) {
            return new WP_Error('auto_config_changed', 'L’autorizzazione al recupero PayPal è cambiata. Verifica il lavoro prima di riprovarlo.');
        }
        $order = new MemberOrder((int) $job['order_id']);
        if (($job['kind'] ?? '') === 'renewal') {
            $allowed = PMPro_FIC_Renewals::guard($order, $config);
            if (is_wp_error($allowed)) return $allowed;
            if (($job['payment_id'] ?? '') !== (string) $order->payment_transaction_id) return new WP_Error('auto_order_changed', 'L’ID incasso del rinnovo è cambiato.');
        }
        if (!$this->source($order) || $this->identity($order) !== $job['identity']
            || (!empty($job['subscription_id']) && $job['subscription_id'] !== (string) $order->subscription_transaction_id)) {
            return new WP_Error('auto_order_changed', 'L’ordine o i dati fiscali sono cambiati. Verifica manualmente.');
        }
        return $order;
    }

    public function process($order_id): void {
        if (!(defined('DOING_CRON') && DOING_CRON) && !current_user_can('manage_options')) return;
        $id = (int) $order_id;
        $job = $this->job($id);
        if (!$job || !in_array($job['state'], ['queued', 'waiting'], true)) return;
        $lock = 'pmpro_fic_job_lock_' . $id;
        if (!add_option($lock, time(), '', false)) return;
        try {
            $job = $this->job($id);
            if (!in_array($job['state'] ?? '', ['queued', 'waiting'], true)) return;
            $job['attempts']++;
            $job['state'] = 'processing';
            if (!$this->save($job)) return;
            $order = $this->guard($job);
            if (is_wp_error($order)) { $this->finish_error($job, $order); return; }
            $service = new PMPro_FIC_Test_Orders([$this->plugin, 'background_context']);
            if ($order->status !== 'success') {
                $this->retry_later($job, 'In attesa della conferma del pagamento in PMPro.'); return;
            }
            if (trim((string) $order->payment_transaction_id) === '') {
                if (($job['kind'] ?? '') === 'renewal') { $this->finish_error($job, new WP_Error('renewal_payment', 'ID incasso mancante: il recupero del primo pagamento non si applica ai rinnovi.')); return; }
                if (empty($job['recover_missing_payment'])) {
                    $this->retry_later($job, 'Ordine pagato in PMPro, ma ID incasso mancante. Il recupero automatico PayPal è disattivato.'); return;
                }
                $recovered = $service->reconcile_payment($id, function () use ($job) {
                    $order = $this->guard($job);
                    if (is_wp_error($order)) return $order;
                    return $order->status === 'success' && !empty($job['recover_missing_payment']) ? true
                        : new WP_Error('auto_order_changed', 'Recupero non autorizzato o ordine non più pagato.');
                });
                if (is_wp_error($recovered)) { $this->finish_error($job, $recovered); return; }
                $job['payment_id'] = $recovered['payment']['payment_id'];
                $job['payment_recovered'] = true;
                if (!$this->save($job)) return;
            }
            $eligible = $service->automation_order($id);
            if (is_wp_error($eligible)) { $this->finish_error($job, $eligible); return; }
            $context = $this->plugin->background_context();
            if (is_wp_error($context)) { $this->finish_error($job, $context); return; }
            if ($this->context_scope($context) !== $job['context_scope']) {
                $this->finish_error($job, new WP_Error('auto_config_changed', 'Azienda o aliquota remota modificata. Riconfigura l’automazione.')); return;
            }
            $result = $service->automate($id, $context, $job['account_id'], function () use ($job) {
                $order = $this->guard($job);
                if (is_wp_error($order)) return $order;
                return $order->status === 'success' && trim((string) $order->payment_transaction_id) !== '' ? true
                    : new WP_Error('auto_order_changed', 'Pagamento non più confermato. Nessun nuovo documento inviato.');
            });
            if (is_wp_error($result)) { $this->finish_error($job, $result); return; }
            $document = $result['document'];
            $job['state'] = $document['status'] === 'created' ? 'created' : 'attention';
            $job['document_id'] = $document['document_id'];
            $job['message'] = $document['status'] === 'created' ? 'Documento /TEST creato e associato all’ordine.' : 'Documento creato con totali da verificare. Controlla Fatture in Cloud.';
            $this->save($job);
        } catch (Throwable $error) {
            // Un errore inatteso può essere successivo al POST: mai ripeterlo alla cieca.
            $job['state'] = 'attention'; $job['message'] = 'Elaborazione interrotta. Verifica ordine ed esito remoto nella sezione 3 prima di riprovare.'; $this->save($job);
        } finally { delete_option($lock); }
    }

    private function retry_later(array $job, string $message): void {
        $delays = [60, 120, 300, 600, 1800];
        $job['state'] = $job['attempts'] <= count($delays) ? 'waiting' : 'blocked';
        $job['message'] = $message;
        if (!$this->save($job)) return;
        if ($job['state'] === 'waiting' && !$this->schedule($job['order_id'], $delays[$job['attempts'] - 1])) {
            $job['state'] = 'blocked'; $job['message'] = 'Programmazione non riuscita. Verifica WP-Cron e usa Riprova.'; $this->save($job);
        }
    }

    private function finish_error(array $job, WP_Error $error): void {
        $journal = get_option('pmpro_fic_test_order_' . $job['order_id'], []);
        $code = $error->get_error_code();
        $uncertain = in_array($code, ['uncertain', 'locked', 'payment_locked', 'payment_storage', 'storage_uncertain', 'metadata', 'paypal_recovery_storage', 'paypal_recovery_query', 'paypal_recovery_readback'], true)
            || (is_array($journal) && in_array($journal['status'] ?? '', ['pending', 'uncertain', 'created', 'created_mismatch'], true));
        // Ritenta solo letture/preflight falliti o un POST esplicitamente rifiutato con 429.
        $retry = !$uncertain && (in_array($code, ['connection', 'api_429', 'api_500', 'api_502', 'api_503', 'api_504', 'unknown_result', 'transaction_missing',
                'paypal_recovery_api', 'paypal_recovery_incomplete', 'paypal_recovery_not_found', 'paypal_recovery_changed', 'paypal_recovery_locked', 'payment_present',
                'payment_api', 'payment_incomplete', 'payment_pending', 'payment_changed'], true)
            || ($code === 'totals_error' && !preg_match('/HTTP (400|401|403|404|422)\./', $error->get_error_message()))
            || ($code === 'rejected' && strpos($error->get_error_message(), 'HTTP 429.') !== false));
        if ($retry) { $this->retry_later($job, $error->get_error_message()); return; }
        $job['state'] = $uncertain ? 'attention' : 'blocked';
        $job['message'] = $error->get_error_message();
        $this->save($job);
    }

    private function field(array $input, string $key): string { return isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : ''; }

    public function dispatch(string $action, array $input) {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        if (in_array($action, ['renewal_enable', 'renewal_status', 'renewal_pause', 'renewal_enqueue'], true)) {
            $id = $this->field($input, 'order_id');
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || !class_exists('MemberOrder')) return new WP_Error('invalid_order', 'Inserisci l’ID numerico dell’ordine.');
            $order = new MemberOrder((int) $id);
            if (empty($order->id)) return new WP_Error('order_missing', 'Ordine non trovato.');
            $policy = PMPro_FIC_Renewals::policy($order);
            if (is_wp_error($policy)) return $policy;
            if ($action === 'renewal_pause') {
                if (!$policy) return new WP_Error('renewal_policy', 'Abbonamento non autorizzato.');
                update_option(PMPro_FIC_Renewals::key($policy['subscription_id']) . '_paused', true, false);
                return get_option(PMPro_FIC_Renewals::key($policy['subscription_id']) . '_paused', false)
                    ? ['message' => 'Fatturazione dei rinnovi sospesa per questo abbonamento. Gli addebiti PayPal non vengono modificati.']
                    : new WP_Error('storage', 'Impossibile sospendere l’autorizzazione.');
            }
            if ($action === 'renewal_status') return ['message' => $policy
                ? 'Abbonamento ' . $policy['subscription_id'] . ' · copia fiscale ordine #' . $policy['source_order_id'] . ' · incassi dal ' . gmdate('Y-m-d H:i:s', $policy['effective_from']) . ' UTC · '
                    . (get_option(PMPro_FIC_Renewals::key($policy['subscription_id']) . '_paused', false) ? 'Sospeso' : 'Autorizzato (richiede automazione attiva e configurazione invariata).')
                : 'Nessuna autorizzazione ai rinnovi per questo abbonamento.'];
            $config = $this->enabled();
            if (is_wp_error($config)) return $config;
            if ($action === 'renewal_enqueue') {
                $this->on_renewal($order);
                return $this->job((int) $id) ? ['message' => 'Ordine presente in coda. Consulta il suo stato prima di riprovare.']
                    : new WP_Error('renewal_ineligible', 'Rinnovo non acquisito: verifica autorizzazione, copia fiscale, stato e ID incasso.');
            }
            if ($this->field($input, 'confirm_renewal') !== '1') return new WP_Error('confirm_test', 'Conferma abbonamento di prova e validità dei dati fiscali per i rinnovi.');
            return PMPro_FIC_Renewals::authorize($order, $config);
        }
        if ($action === 'auto_pause') { $this->invalidate(); return ['message' => 'Automazione disattivata. I lavori già in corso vanno verificati.']; }
        if (in_array($action, ['auto_accounts', 'auto_enable'], true)) {
            if (get_option('pmpro_gateway', '') !== 'paypal' || get_option('pmpro_gateway_environment', '') !== 'sandbox'
                || strtoupper((string) get_option('pmpro_currency', '')) !== 'EUR') return new WP_Error('auto_sandbox', 'Configura PayPal Sandbox e valuta EUR prima di attivare l’automazione.');
            $context = $this->plugin->order_context();
            if (is_wp_error($context)) return $context;
            $cache_key = 'pmpro_fic_auto_accounts_' . $this->plugin->automation_scope();
            if ($action === 'auto_accounts') {
                $accounts = (new PMPro_FIC_Test_Orders([$this->plugin, 'order_context']))->payment_accounts($context);
                if (is_wp_error($accounts)) return $accounts;
                set_transient($cache_key, $accounts, HOUR_IN_SECONDS);
                return ['message' => 'Conti caricati. Seleziona il conto di prova e attiva l’automazione.'];
            }
            if ($this->field($input, 'confirm_test') !== '1') return new WP_Error('confirm_test', 'Conferma che l’azienda Fatture in Cloud e il conto sono destinati al collaudo.');
            $account_id = 0;
            foreach (get_transient($cache_key) ?: [] as $account) if ((string) $account['id'] === $this->field($input, 'account_id')) $account_id = $account['id'];
            if (!$account_id) return new WP_Error('account_required', 'Carica i conti e seleziona un conto di prova disponibile.');
            $config = ['active' => true, 'generation' => wp_generate_uuid4(), 'scope' => $this->plugin->automation_scope(),
                'context_scope' => $this->context_scope($context), 'company_id' => $context['company_id'], 'company_name' => $context['company_name'],
                'account_id' => $account_id, 'recover_missing_payment' => $this->field($input, 'recover_missing_payment') === '1', 'activated_at' => time()];
            $previous = $this->config();
            if (!empty($previous['active']) && ($previous['scope'] ?? '') === $config['scope']
                && ($previous['context_scope'] ?? '') === $config['context_scope'] && ($previous['account_id'] ?? 0) === $account_id
                && !empty($previous['recover_missing_payment']) === $config['recover_missing_payment']) {
                $config['generation'] = $previous['generation'];
                $config['activated_at'] = $previous['activated_at'];
            }
            update_option(self::CONFIG, $config, false);
            if ($this->config() !== $config) return new WP_Error('storage', 'Impossibile salvare l’attivazione.');
            return ['message' => 'Automazione Sandbox attiva per i nuovi primi acquisti.'];
        }
        $id = $this->field($input, 'order_id');
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id)) return new WP_Error('invalid_order', 'Inserisci l’ID numerico dell’ordine.');
        $linked = PMPro_FIC_Initial_Pair::source_link((int) $id);
        if (is_wp_error($linked)) return $linked;
        if ($linked !== null) return ['message' => 'Checkout collegato all’ordine con incasso #' . $linked . '. Usa la sezione 3 per completare la riconciliazione e il documento.'];
        $job = $this->job((int) $id);
        if (!$job) return new WP_Error('auto_no_job', 'Ordine non presente in coda. L’attivazione non importa gli ordini precedenti.');
        if ($action === 'auto_retry') {
            $config = $this->enabled();
            if (is_wp_error($config)) return $config;
            $lock_key = 'pmpro_fic_job_lock_' . $id;
            $lock = get_option($lock_key, false);
            if ($lock !== false) {
                $journal = get_option('pmpro_fic_test_order_' . $id, []);
                if (!is_numeric($lock) || time() - (int) $lock < 900
                    || ($journal && !in_array($journal['status'] ?? '', ['rejected', 'created', 'created_mismatch'], true))) {
                    return new WP_Error('auto_locked', 'Elaborazione in corso o interrotta. Dopo 15 minuti puoi riprovare solo se non esiste un invio incerto; verifica prima l’esito remoto nella sezione 3.');
                }
                // Lock abbandonato: un POST non può precedere il journal persistente.
                global $wpdb;
                if (!isset($wpdb->options) || !method_exists($wpdb, 'delete')
                    || $wpdb->delete($wpdb->options, ['option_name' => $lock_key, 'option_value' => (string) $lock], ['%s', '%s']) !== 1) {
                    return new WP_Error('auto_locked', 'Il lock è cambiato. Ricarica la coda prima di riprovare.');
                }
                wp_cache_delete($lock_key, 'options');
            }
            foreach (['scope', 'context_scope', 'company_id', 'account_id'] as $key) if ($job[$key] !== $config[$key]) return new WP_Error('auto_config_changed', 'Il lavoro appartiene a una configurazione diversa. Verifica manualmente.');
            if ($job['state'] === 'created') return ['message' => 'Documento già associato. Nessun nuovo invio.'];
            $journal = get_option('pmpro_fic_test_order_' . $id, []);
            if ($journal && in_array($journal['status'] ?? '', ['pending', 'uncertain'], true)) return new WP_Error('uncertain', 'Esito remoto incerto. Usa Verifica esito remoto nella sezione 3.');
            $job['generation'] = $config['generation']; $job['recover_missing_payment'] = ($job['kind'] ?? '') !== 'renewal' && !empty($config['recover_missing_payment']);
            $job['state'] = 'queued'; $job['attempts'] = 0; $job['message'] = 'Nuova elaborazione richiesta dall’amministratore.';
            if (!$this->save($job)) return new WP_Error('storage', 'Impossibile salvare il lavoro.');
            if (!$this->schedule((int) $id, 1)) return new WP_Error('auto_schedule', 'Impossibile programmare il lavoro. Verifica WP-Cron.');
            return ['message' => 'Lavoro riprogrammato.'];
        }
        if ($action === 'auto_run') { $this->process((int) $id); return ['message' => 'Controllo completato. Consulta l’esito in coda.']; }
        if ($action === 'auto_status') return ['message' => 'Ordine #' . $id . ': ' . $job['state'] . '. ' . $job['message']];
        return new WP_Error('invalid_action', 'Operazione non disponibile.');
    }

    private function form(string $action, ?int $id = null): void {
        echo '<form method="post" action="' . esc_url(admin_url('options-general.php?page=' . PMPro_FattureInCloud_Sync::PAGE) . '#fic-auto-result') . '">';
        wp_nonce_field(PMPro_FattureInCloud_Sync::NONCE);
        echo '<input type="hidden" name="fic_action" value="' . esc_attr($action) . '">';
        if ($id !== null) echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $id) . '">';
    }

    public function render($result, string $action): void {
        echo '<hr><h2>4. Automazione PayPal Sandbox</h2><div id="fic-auto-result" tabindex="-1" style="scroll-margin-top:48px">';
        echo '<p>Crea un documento /TEST per i nuovi acquisti e per i rinnovi degli abbonamenti autorizzati qui sotto. Verifica gli incassi PayPal prima dell’invio. L’elaborazione usa WP-Cron e dipende dalla frequenza dello scheduler.</p>';
        $config = $this->config();
        $enabled = $this->enabled();
        echo '<p><strong>Stato:</strong> ' . esc_html(is_wp_error($enabled) ? $enabled->get_error_message() : 'Attiva — ' . $config['company_name'] . ' — conto #' . $config['account_id']) . '</p>';
        if (in_array($action, self::ACTIONS, true)) {
            echo '<div class="notice ' . (is_wp_error($result) ? 'notice-error' : 'notice-success') . ' inline"><p>'
                . esc_html(is_wp_error($result) ? $result->get_error_message() : ($result['message'] ?? '')) . '</p></div>';
        }
        $this->form('auto_accounts'); submit_button('Carica conti per automazione', 'secondary'); echo '</form>';
        $accounts = get_transient('pmpro_fic_auto_accounts_' . $this->plugin->automation_scope());
        if (is_array($accounts) && $accounts) {
            $context = $this->plugin->order_context();
            if (!is_wp_error($context)) echo '<p><strong>Azienda destinataria:</strong> ' . esc_html($context['company_name']) . ' · Codice Cliente '
                . esc_html((string) $context['company_id']) . ' · IVA ' . esc_html((string) $context['vat']['value']) . '%.</p>';
            $this->form('auto_enable'); echo '<label>Conto d’incasso di prova <select name="account_id" required><option value="">Seleziona</option>';
            foreach ($accounts as $account) echo '<option value="' . esc_attr((string) $account['id']) . '" ' . selected((string) ($config['account_id'] ?? ''), (string) $account['id'], false) . '>' . esc_html($account['name']) . '</option>';
            echo '</select></label><p><label><input type="checkbox" name="recover_missing_payment" value="1"' . (!empty($config['recover_missing_payment']) ? ' checked' : '') . '> Recupera automaticamente l’ID incasso mancante verificandolo in PayPal Sandbox.</label></p>';
            echo '<p class="description">Per gli ordini già pagati, verifica un unico incasso completato con importo e abbonamento corrispondenti, poi associa il suo ID a PMPro. Non effettua nuovi addebiti. Un cambio di questa opzione richiede Riprova per i lavori precedenti.</p>';
            echo '<p><label><input type="checkbox" name="confirm_test" value="1" required> Confermo che azienda e conto sono destinati al collaudo.</label></p>';
            submit_button('Attiva automazione Sandbox', 'primary'); echo '</form>';
        }
        if (!empty($config['active'])) { $this->form('auto_pause'); submit_button('Disattiva automazione', 'secondary'); echo '</form>'; }
        echo '<h3>Rinnovi di un abbonamento Sandbox</h3><p>Prima completa il primo acquisto su un piano dedicato al collaudo e verifica i dati fiscali nel suo documento /TEST in Fatture in Cloud: saranno copiati in ogni rinnovo, senza leggere il profilo corrente. L’autorizzazione vale solo per gli incassi successivi e non importa lo storico. In caso di variazioni fiscali sospendi l’autorizzazione; questa versione non modifica la copia confermata.</p>';
        $this->form('renewal_enable');
        echo '<p><label>ID ordine iniziale <input type="number" min="1" name="order_id" required></label></p><p><label><input type="checkbox" name="confirm_renewal" value="1" required> Confermo che questo è un abbonamento di prova e che i suoi dati fiscali sono validi anche per i rinnovi.</label></p>';
        submit_button('Autorizza rinnovi di questo abbonamento', 'secondary'); echo '</form>';
        foreach (['renewal_status' => 'Controlla autorizzazione', 'renewal_pause' => 'Sospendi fatturazione rinnovi', 'renewal_enqueue' => 'Acquisisci un rinnovo già pagato'] as $operation => $label) {
            $this->form($operation); echo '<p><label>ID ordine ' . ($operation === 'renewal_enqueue' ? 'di rinnovo' : 'dell’abbonamento') . ' <input type="number" min="1" name="order_id" required></label></p>';
            submit_button($label, 'secondary'); echo '</form>';
        }
        $capture_error = get_option('pmpro_fic_renewal_error', '');
        if (is_string($capture_error) && $capture_error !== '') echo '<p>Ultimo problema di acquisizione (storico): ' . esc_html($capture_error) . '</p>';
        echo '<h3>Ultimi 20 ordini in coda</h3><table class="widefat striped"><thead><tr><th>Ordine</th><th>Esito</th><th>Tentativi</th><th>Documento FIC</th><th>Azioni</th></tr></thead><tbody>';
        $labels = ['queued'=>'In coda', 'waiting'=>'In attesa', 'processing'=>'In elaborazione', 'created'=>'Documento associato', 'blocked'=>'Da correggere', 'attention'=>'Da verificare'];
        foreach (get_option(self::INDEX, []) ?: [] as $id) {
            $job = $this->job((int) $id);
            if (!$job) continue;
            $linked = PMPro_FIC_Initial_Pair::source_link((int) $id);
            if (is_int($linked)) {
                echo '<tr><td>#' . esc_html((string) $id) . ' · Checkout</td><td>Collegamento confermato<br>Incasso sull’ordine #' . esc_html((string) $linked) . '. Completa la riconciliazione e il documento nella sezione 3.</td><td>' . esc_html((string) $job['attempts']) . '</td><td>Vedi ordine #' . esc_html((string) $linked) . '</td><td>Non recuperare l’ID su questo checkout.</td></tr>';
                continue;
            }
            echo '<tr><td>#' . esc_html((string) $id) . (($job['kind'] ?? '') === 'renewal' ? ' · Rinnovo' : ' · Acquisto') . '</td><td>' . esc_html($labels[$job['state']] ?? $job['state']) . '<br>' . esc_html($job['message'])
                . '</td><td>' . esc_html((string) $job['attempts']) . '</td><td>' . esc_html((string) ($job['document_id'] ?? '')) . '</td><td>';
            if (!empty($job['payment_recovered'])) echo '<p>ID incasso recuperato: ' . esc_html($job['payment_id']) . '</p>';
            if (in_array($job['state'], ['queued', 'waiting'], true)) {
                $rebind = ($job['generation'] ?? '') !== ($config['generation'] ?? '');
                $this->form($rebind ? 'auto_retry' : 'auto_run', (int) $id);
                submit_button($rebind ? 'Riprova' : 'Elabora ora', 'secondary'); echo '</form>';
            }
            elseif (in_array($job['state'], ['blocked', 'attention'], true) || ($job['state'] === 'processing' && time() - $job['updated_at'] >= 900)) { $this->form('auto_retry', (int) $id); submit_button('Riprova', 'secondary'); echo '</form>'; }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $this->form('auto_status'); echo '<p><label>ID ordine <input type="number" min="1" name="order_id" required></label></p>'; submit_button('Controlla ordine in coda', 'secondary'); echo '</form></div>';
    }
}
