<?php
if (!defined('ABSPATH')) exit;

/** Osservatore passivo e prove amministrative esplicite, senza modifiche a ordini o pagamenti. */
final class PMPro_FIC_Diagnostics {
    const OPTION = 'pmpro_fic_diagnostics';
    const FILE = 'fic-paypal-api.php';
    const ACTIONS = ['diag_enable', 'diag_disable', 'diag_compare', 'diag_cron'];
    const CRON_HOOK = 'pmpro_fic_diagnostic_tick';
    const CRON_OPTION = 'pmpro_fic_diagnostic_cron';
    const LIMIT = 1048576;
    private $requests = [];
    private $event = [];
    private $comparing = false;

    public function __construct() {
        add_filter('http_request_args', [$this, 'request_args'], PHP_INT_MAX, 2);
        add_filter('pre_http_request', [$this, 'pre_request'], PHP_INT_MAX, 3);
        add_action('http_api_debug', [$this, 'http_debug'], PHP_INT_MAX, 5);
        add_filter('http_response', [$this, 'http_response'], PHP_INT_MAX, 3);
        add_filter('rest_pre_dispatch', [$this, 'rest_before'], PHP_INT_MAX, 3);
        add_action('pmpro_paypal_webhook_processed', [$this, 'webhook_processed'], PHP_INT_MAX, 3);
        add_filter('rest_post_dispatch', [$this, 'rest_after'], PHP_INT_MAX, 3);
        add_action(self::CRON_HOOK, [$this, 'cron_tick'], 10, 1);
    }

    private function active(): bool {
        $config = get_option(self::OPTION, []);
        return is_array($config) && (int) ($config['until'] ?? 0) > time()
            && get_option('pmpro_gateway', '') === 'paypal' && get_option('pmpro_gateway_environment', '') === 'sandbox';
    }

    private function safe($value, string $pattern): string {
        return is_scalar($value) && preg_match($pattern, (string) $value) ? (string) $value : '';
    }

    private function date($value): string {
        return $this->safe($value, '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D');
    }

    private function id($value): string {
        return $this->safe($value, '/^[A-Z0-9][A-Z0-9-]{4,79}$/D');
    }

    private function status($value): string {
        return in_array($value, ['COMPLETED', 'PENDING', 'DENIED', 'FAILED', 'REFUNDED', 'PARTIALLY_REFUNDED',
            'CREATED', 'APPROVAL_PENDING', 'APPROVED', 'ACTIVE', 'SUSPENDED', 'CANCELLED', 'EXPIRED'], true) ? $value : 'OTHER';
    }

    /** URL e body grezzi non sono mai scritti nel registro. */
    private function endpoint(string $url, array $args): array {
        if (!$this->active()) return [];
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api-m.sandbox.paypal.com'
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) return [];
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $path = $parts['path'] ?? '';
        if ($method === 'GET' && preg_match('#^/v1/billing/subscriptions/(I-[A-Z0-9]{5,32})(/transactions)?$#D', $path, $match)) {
            $meta = ['endpoint' => empty($match[2]) ? 'subscription' : 'transactions', 'subscription_id' => $match[1]];
            parse_str($parts['query'] ?? '', $query);
            foreach (['start_time', 'end_time'] as $key) if ($this->date($query[$key] ?? '') !== '') $meta[$key] = $this->date($query[$key]);
            return $meta;
        }
        if ($method === 'POST' && $path === '/v1/oauth2/token') return ['endpoint' => 'oauth'];
        if ($method === 'POST' && $path === '/v1/notifications/verify-webhook-signature') return ['endpoint' => 'verify_signature'];
        return [];
    }

    private function context(): string {
        if ($this->comparing) return 'diagnostic_comparison';
        $activation = $poll = $recovery = $worker = $queue_hook = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 45) as $frame) {
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            if ($function === 'pmpro_paypal_handle_subscription_activated') $activation = true;
            if ($class === 'PMPro_FIC_PayPal_Recovery') $recovery = true;
            if ($class === 'PMPro_FIC_Automation') {
                $queue_hook = true;
                if ($function === 'process') $worker = true;
            }
            if ($class === 'PMProGateway_paypal' && $function === 'check_token_order') $poll = true;
        }
        if ($recovery) return $worker ? 'queue_recovery' : 'manual_recovery';
        if ($activation) return 'subscription_activation';
        if ($poll) return 'checkout_poll';
        if ($worker) return 'queue_worker';
        if ($queue_hook) return 'queue_order_hook';
        return $this->event ? 'webhook' : 'other';
    }

    private function response_summary($response, string $endpoint): array {
        if (is_wp_error($response)) {
            // Error messages can contain URLs, credentials and remote body excerpts.
            $code = $response->get_error_code();
            return ['transport_error' => in_array($code, ['http_request_failed', 'http_request_not_executed', 'https_ssl_verify_failed'], true) ? $code : 'other'];
        }
        if (!is_array($response)) return ['invalid_http_response' => true];
        $summary = ['http_status' => (int) wp_remote_retrieve_response_code($response)];
        $raw = wp_remote_retrieve_body($response);
        if (!is_string($raw) || strlen($raw) > 2097152) return $summary + ['json_readable' => false];
        $body = json_decode($raw, true);
        $summary['json_readable'] = is_array($body);
        if (!is_array($body)) return $summary;
        $summary['json_root'] = substr(ltrim($raw), 0, 1) === '{' ? 'object' : 'array';
        // Fixed key names only: neither arbitrary property names nor their values.
        $summary['known_keys'] = array_values(array_filter(['transactions', 'total_items', 'total_pages', 'links', 'id', 'status',
            'create_time', 'name', 'error', 'message', 'details', 'debug_id', 'access_token', 'verification_status'],
            function ($key) use ($body) { return array_key_exists($key, $body); }));
        $name = $body['name'] ?? $body['error'] ?? '';
        if (in_array($name, ['AUTHENTICATION_FAILURE', 'AUTHORIZATION_ERROR', 'RESOURCE_NOT_FOUND', 'INVALID_REQUEST',
            'UNPROCESSABLE_ENTITY', 'RATE_LIMIT_REACHED', 'INTERNAL_SERVER_ERROR', 'invalid_client', 'invalid_token'], true)) $summary['api_error'] = $name;
        if ($endpoint === 'oauth') return $summary + ['token_present' => is_string($body['access_token'] ?? null) && $body['access_token'] !== ''];
        if ($endpoint === 'verify_signature') return $summary + ['verification_status' => in_array($body['verification_status'] ?? '', ['SUCCESS', 'FAILURE'], true) ? $body['verification_status'] : 'OTHER'];
        if ($endpoint === 'subscription') return $summary + ['id' => $this->id($body['id'] ?? ''), 'status' => $this->status($body['status'] ?? ''), 'create_time' => $this->date($body['create_time'] ?? '')];
        $items = $body['transactions'] ?? null;
        $summary['transactions_array'] = is_array($items);
        $summary['transactions_type'] = !array_key_exists('transactions', $body) ? 'missing' : ($items === null ? 'null' : (is_array($items) ? 'array' : 'other'));
        if (!is_array($items)) return $summary;
        $summary['count'] = count($items);
        $summary['completed_count'] = count(array_filter($items, function ($item) { return is_array($item) && ($item['status'] ?? '') === 'COMPLETED'; }));
        foreach (['total_items', 'total_pages'] as $key) {
            if (isset($body[$key]) && $this->safe($body[$key], '/^[0-9]{1,7}$/D') !== '') $summary[$key] = (int) $body[$key];
        }
        $summary['transactions'] = [];
        foreach (array_slice($items, 0, 20) as $item) {
            if (!is_array($item)) continue;
            $gross = $item['amount_with_breakdown']['gross_amount'] ?? [];
            if (!is_array($gross)) $gross = [];
            $summary['transactions'][] = ['id' => $this->id($item['id'] ?? ''), 'status' => $this->status($item['status'] ?? ''),
                'time' => $this->date($item['time'] ?? ''), 'amount' => $this->safe($gross['value'] ?? '', '/^[0-9]{1,12}(?:\.[0-9]{1,4})?$/D'),
                'currency' => $this->safe($gross['currency_code'] ?? '', '/^[A-Z]{3}$/D')];
        }
        return $summary;
    }

    public function request_args($args, $url) {
        try {
            $meta = $this->endpoint((string) $url, (array) $args);
            if ($meta) $this->requests[$url][] = $meta + ['request_id' => bin2hex(random_bytes(8)), 'started' => microtime(true),
                'started_unix_ms' => (int) round(microtime(true) * 1000), 'context' => $this->context(), 'event' => $this->event];
        } catch (Throwable $ignored) {}
        return $args;
    }

    private function finish($response, $args, $url, string $source): void {
        if (!$this->active()) return;
        $pending = $this->requests[$url] ?? [];
        if (!$pending) return;
        $request = array_pop($this->requests[$url]);
        $started = $request['started'];
        unset($request['started']);
        $this->write(['phase' => 'api_response', 'source' => $source, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]
            + $request + ['response' => $this->response_summary($response, $request['endpoint'])]);
    }

    public function pre_request($response, $args, $url) {
        try { if ($response !== false) $this->finish($response, $args, $url, 'pre_http_request'); } catch (Throwable $ignored) {}
        return $response;
    }

    public function http_debug($response, $context, $class, $args, $url): void {
        try {
            if ($context !== 'response' || empty($this->requests[$url])) return;
            if (is_wp_error($response)) $this->finish($response, $args, $url, 'transport_error');
            else {
                $index = count($this->requests[$url]) - 1;
                $this->requests[$url][$index]['transport_response'] = $this->response_summary($response, $this->requests[$url][$index]['endpoint']);
            }
        } catch (Throwable $ignored) {}
    }

    public function http_response($response, $args, $url) {
        try { $this->finish($response, $args, $url, 'http_response'); } catch (Throwable $ignored) {}
        return $response;
    }

    public function rest_before($response, $server, $request) {
        try {
            if (!$this->active() || $request->get_route() !== '/pmpro-paypal/v1/webhook' || $response !== null) return $response;
            $payload = $request->get_json_params();
            if (!is_array($payload)) return $response;
            $type = $payload['event_type'] ?? '';
            $known = ['BILLING.SUBSCRIPTION.CREATED', 'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.UPDATED',
                'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.EXPIRED',
                'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'PAYMENT.SALE.COMPLETED', 'PAYMENT.SALE.REFUNDED', 'PAYMENT.SALE.DENIED',
                'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.REFUNDED', 'CHECKOUT.ORDER.APPROVED'];
            $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
            $this->event = ['correlation_id' => bin2hex(random_bytes(8)), 'event_id' => $this->id($payload['id'] ?? ''),
                'event_type' => in_array($type, $known, true) ? $type : 'OTHER', 'resource_id' => $this->id($resource['id'] ?? ''),
                'subscription_id' => $this->safe($resource['billing_agreement_id'] ?? $resource['id'] ?? '', '/^I-[A-Z0-9]{5,32}$/D')];
            $this->event['resource_create_time'] = $this->date($resource['create_time'] ?? '');
            $this->write(['phase' => 'webhook_received', 'signature_checked' => false, 'event' => $this->event]);
        } catch (Throwable $ignored) {}
        return $response;
    }

    public function webhook_processed($type, $resource, $message): void {
        try {
            if (!$this->active() || !$this->event) return;
            // Store classifications, never the gateway's arbitrary message text.
            $result = 'other';
            if (is_string($message)) {
                if (stripos($message, 'Could not find') !== false || stripos($message, 'not found') !== false) $result = 'local_reference_not_found';
                elseif (stripos($message, 'error') !== false || stripos($message, 'failed') !== false) $result = 'error';
                elseif (stripos($message, 'already') !== false) $result = 'already_processed';
                elseif (stripos($message, 'completed') !== false) $result = 'completed';
                elseif (stripos($message, 'Unhandled') !== false) $result = 'unhandled';
            }
            $entry = ['phase' => 'webhook_processed', 'event' => $this->event, 'signature_verified' => true, 'result' => $result];
            if (is_string($message) && preg_match('/[Oo]rder\s*#?([1-9][0-9]{0,9})\b/', $message, $match)) {
                $entry['order_id'] = (int) $match[1];
                // MemberOrder::__construct() can CREATE a subscription and sync its gateway.
                // Read just these fields directly; never invoke a domain constructor here.
                global $wpdb;
                $table = isset($wpdb->pmpro_membership_orders) ? $wpdb->pmpro_membership_orders : '';
                if (is_string($table) && preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
                    $order = $wpdb->get_row($wpdb->prepare("SELECT id, status, payment_transaction_id FROM {$table} WHERE id = %d LIMIT 1", $entry['order_id']));
                } else $order = null;
                if (is_object($order) && (int) ($order->id ?? 0) === $entry['order_id']) {
                    $entry['order_status'] = in_array($order->status, ['success', 'pending', 'token', 'error', 'cancelled', 'refunded'], true) ? $order->status : 'other';
                    $entry['payment_id_present'] = !empty($order->payment_transaction_id);
                    $entry['payment_id'] = $this->id($order->payment_transaction_id ?? '');
                }
            }
            $this->write($entry);
        } catch (Throwable $ignored) {}
    }

    public function rest_after($response, $server, $request) {
        try {
            if ($this->event && $request->get_route() === '/pmpro-paypal/v1/webhook') {
                $this->write(['phase' => 'webhook_http_response', 'event' => $this->event,
                    'http_status' => is_object($response) && method_exists($response, 'get_status') ? (int) $response->get_status() : 0]);
                $this->event = [];
            }
        } catch (Throwable $ignored) {}
        return $response;
    }

    private function path(): string {
        return function_exists('pmpro_get_restricted_file_path') ? (string) pmpro_get_restricted_file_path('logs', self::FILE) : '';
    }

    /** Bounded, non-blocking, locked append. Direct PHP requests exit without output. */
    private function write(array $entry): bool {
        if (!$this->active()) return false;
        $path = $this->path();
        if ($path === '') return false;
        $stream = @fopen($path, 'c+b');
        if (!$stream) return false;
        try {
            if (!flock($stream, LOCK_EX | LOCK_NB)) return false;
            $stat = fstat($stream);
            $line = wp_json_encode(['time_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'unix_ms' => (int) round(microtime(true) * 1000), 'version' => '0.7.3'] + $entry, JSON_UNESCAPED_SLASHES) . "\n";
            if (!$stat || $stat['size'] + strlen($line) + 16 > self::LIMIT) return false;
            fseek($stream, 0, SEEK_END);
            if ($stat['size'] === 0 && fwrite($stream, "<?php exit; ?>\n") === false) return false;
            return fwrite($stream, $line) === strlen($line);
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }

    /** Existing orders, including already invoiced ones. No MemberOrder constructor. */
    private function compare(array $input) {
        $id = $input['order_id'] ?? null;
        if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)) return new WP_Error('invalid_order', 'Inserisci l’ID numerico dell’ordine.');
        if (!class_exists('PMPro_PayPal_API') || !method_exists('PMPro_PayPal_API', 'get_subscription')
            || !method_exists('PMPro_PayPal_API', 'get_subscription_transactions')) return new WP_Error('gateway_missing', 'API del nuovo gateway PayPal non disponibili.');
        global $wpdb;
        $table = $wpdb->pmpro_membership_orders ?? '';
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table)) return new WP_Error('order_unavailable', 'Tabella ordini non disponibile.');
        $order = $wpdb->get_row($wpdb->prepare("SELECT id, gateway, gateway_environment, status, subscription_transaction_id, payment_transaction_id FROM {$table} WHERE id = %d LIMIT 1", (int) $id));
        if (!is_object($order) || (int) $order->id !== (int) $id || $order->gateway !== 'paypal' || $order->gateway_environment !== 'sandbox'
            || !is_string($order->subscription_transaction_id) || !preg_match('/^I-[A-Z0-9]{5,32}$/D', $order->subscription_transaction_id)) {
            return new WP_Error('diagnostic_order', 'Serve un ordine del nuovo gateway PayPal Sandbox con ID abbonamento valido.');
        }
        $this->comparing = true;
        try {
            $api = new PMPro_PayPal_API();
            $subscription = $api->get_subscription($order->subscription_transaction_id);
            if (is_wp_error($subscription) || !is_array($subscription) || ($subscription['id'] ?? '') !== $order->subscription_transaction_id
                || $this->date($subscription['create_time'] ?? '') === '') return new WP_Error('diagnostic_subscription', 'Impossibile verificare l’abbonamento e la sua data di creazione. Nessun ordine modificato.');
            $created = new DateTimeImmutable($subscription['create_time']);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) return new WP_Error('diagnostic_date', 'Data PayPal non valida.');
            $report = ['order_id' => (int) $id, 'order_status' => in_array($order->status, ['success', 'pending', 'token', 'error', 'cancelled', 'refunded'], true) ? $order->status : 'other',
                'payment_id_present' => !empty($order->payment_transaction_id), 'subscription_id' => $order->subscription_transaction_id,
                'subscription_status' => $this->status($subscription['status'] ?? ''), 'readings' => []];
            // Same instants and window as the gateway, sequential reads. Not a causal experiment.
            foreach (['Z', '+00:00'] as $suffix) {
                $start = gmdate('Y-m-d\TH:i:s', $created->getTimestamp() - 3600) . $suffix;
                $end = gmdate('Y-m-d\TH:i:s', $created->getTimestamp() + 3600) . $suffix;
                $body = $api->get_subscription_transactions($order->subscription_transaction_id, $start, $end);
                if (is_wp_error($body)) $summary = ['gateway_error' => true];
                else {
                    // Decoded gateway result has no HTTP status. Never invent an HTTP 200.
                    $summary = $this->response_summary(['body' => wp_json_encode($body)], 'transactions');
                    unset($summary['http_status']);
                }
                $report['readings'][] = ['start_time' => $start, 'end_time' => $end, 'decoded_gateway_response' => $summary];
            }
            $this->write(['phase' => 'diagnostic_comparison', 'report' => $report]);
            return ['message' => 'Letture completate. Nessun ordine o documento modificato. Le due richieste sono successive: eventuali differenze non provano una causa legata al formato delle date.', 'report' => $report];
        } catch (Throwable $ignored) {
            return new WP_Error('diagnostic_failed', 'Lettura diagnostica interrotta. Nessuna associazione richiesta; i dettagli grezzi non vengono mostrati.');
        } finally { $this->comparing = false; }
    }

    /** A dedicated harmless event proves cron execution without calling the invoice worker. */
    public function cron_tick($id): void {
        if (!(defined('DOING_CRON') && DOING_CRON)) return;
        $probe = get_option(self::CRON_OPTION, []);
        if (!is_array($probe) || ($probe['id'] ?? '') !== $id || !empty($probe['ran_at'])) return;
        $probe['ran_at'] = time();
        update_option(self::CRON_OPTION, $probe, false);
    }

    private function schedule_probe() {
        $previous = get_option(self::CRON_OPTION, []);
        if (is_array($previous) && !empty($previous['id']) && wp_next_scheduled(self::CRON_HOOK, [$previous['id']])) {
            return ['message' => 'Prova già programmata. Attendi l’esecuzione e ricarica questa sezione.'];
        }
        $probe = ['id' => bin2hex(random_bytes(16)), 'scheduled_at' => time(), 'due_at' => time() + 60, 'ran_at' => 0];
        update_option(self::CRON_OPTION, $probe, false);
        if (get_option(self::CRON_OPTION) !== $probe) return new WP_Error('diagnostic_storage', 'Impossibile salvare la prova cron.');
        $scheduled = wp_schedule_single_event($probe['due_at'], self::CRON_HOOK, [$probe['id']], true);
        if ($scheduled !== true) return new WP_Error('diagnostic_schedule', 'Programmazione cron non riuscita. Nessuna fattura elaborata dalla prova.');
        return ['message' => 'Prova cron programmata tra un minuto. Chiudi le pagine del sito e verifica dopo almeno due intervalli dello scheduler esterno.'];
    }

    public function dispatch(string $action, array $input = []) {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        check_admin_referer(PMPro_FattureInCloud_Sync::NONCE);
        if ($action === 'diag_cron') return $this->schedule_probe();
        if ($action === 'diag_disable') {
            update_option(self::OPTION, ['until' => 0], false);
            return ['message' => 'Diagnostica disattivata. Il registro resta disponibile.'];
        }
        if (!in_array($action, ['diag_enable', 'diag_compare'], true)) return new WP_Error('invalid_action', 'Operazione non disponibile.');
        if (get_option('pmpro_gateway', '') !== 'paypal' || get_option('pmpro_gateway_environment', '') !== 'sandbox') {
            return new WP_Error('sandbox_required', 'La diagnostica richiede il nuovo gateway PayPal in modalità Sandbox.');
        }
        if ($action === 'diag_compare') return $this->compare($input);
        if ($this->path() === '') return new WP_Error('log_unavailable', 'La cartella riservata dei log PMPro non è disponibile.');
        update_option(self::OPTION, ['until' => time() + 3600], false);
        if (!$this->write(['phase' => 'diagnostics_enabled', 'duration_minutes' => 60])) {
            update_option(self::OPTION, ['until' => 0], false);
            return new WP_Error('log_unwritable', 'Registro non scrivibile, occupato o pieno (limite 1 MB). Diagnostica non attivata.');
        }
        return ['message' => 'Diagnostica attiva per 60 minuti. Puoi confrontare le letture di un ordine esistente senza nuovi acquisti.'];
    }

    public function render($result, string $action): void {
        $diag_action = in_array($action, self::ACTIONS, true);
        echo '<hr><section id="fic-diagnostics"><h2 tabindex="-1">5. Diagnostica PayPal Sandbox</h2>';
        echo '<p>Registra gli esiti delle API e dei webhook per capire perché un ordine completato può non avere l’ID dell’incasso. Non cambia il checkout o i pagamenti. Non registra token, credenziali o dati anagrafici.</p>';
        if ($diag_action && $result !== null) echo '<div class="notice ' . (is_wp_error($result) ? 'notice-error' : 'notice-success') . ' inline"><p>' . esc_html(is_wp_error($result) ? $result->get_error_message() : $result['message']) . '</p></div>';
        if ($action === 'diag_compare' && is_array($result) && isset($result['report'])) echo '<pre style="white-space:pre-wrap">' . esc_html(wp_json_encode($result['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        $config = get_option(self::OPTION, []);
        echo '<p><strong>Stato:</strong> ' . ($this->active() ? 'attiva, ancora ' . (int) ceil(((int) $config['until'] - time()) / 60) . ' minuti.' : 'disattivata.') . '</p>';
        foreach (['diag_enable' => 'Attiva diagnostica per 60 minuti', 'diag_disable' => 'Disattiva diagnostica'] as $name => $label) {
            echo '<form method="post" action="' . esc_url(admin_url('options-general.php?page=' . PMPro_FattureInCloud_Sync::PAGE) . '#fic-diagnostics') . '">';
            wp_nonce_field(PMPro_FattureInCloud_Sync::NONCE);
            echo '<input type="hidden" name="fic_action" value="' . esc_attr($name) . '">';
            submit_button($label, 'secondary');
            echo '</form>';
        }
        foreach (['diag_compare' => 'Confronta letture PayPal (sola lettura)', 'diag_cron' => 'Programma prova cron senza fatture'] as $name => $label) {
            echo '<form method="post" action="' . esc_url(admin_url('options-general.php?page=' . PMPro_FattureInCloud_Sync::PAGE) . '#fic-diagnostics') . '">';
            wp_nonce_field(PMPro_FattureInCloud_Sync::NONCE);
            echo '<input type="hidden" name="fic_action" value="' . esc_attr($name) . '">';
            if ($name === 'diag_compare') echo '<label>ID ordine PMPro <input type="number" name="order_id" min="1" required></label>';
            submit_button($label, 'secondary'); echo '</form>';
        }
        $probe = get_option(self::CRON_OPTION, []);
        if (is_array($probe) && !empty($probe['due_at'])) {
            echo '<p>Prova cron: scadenza UTC ' . esc_html(gmdate('Y-m-d H:i:s', $probe['due_at'])) . '. ';
            echo !empty($probe['ran_at']) ? 'Eseguita UTC ' . esc_html(gmdate('Y-m-d H:i:s', $probe['ran_at'])) . '.' : 'Esecuzione non ancora registrata.';
            echo '</p>';
        }
        echo '<p>Cron da visite: ' . ((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'disabilitato' : 'abilitato') . '. La prova non certifica da sola uno scheduler esterno: confronta l’orario con i log del servizio e con la prima visita successiva.</p>';
        // Do not create directories while rendering a disabled panel.
        if (function_exists('pmpro_get_restricted_file_path') && get_option(self::OPTION, null) !== null) {
            echo '<p><a href="' . esc_url(admin_url('?pmpro_restricted_file_dir=logs&pmpro_restricted_file=' . self::FILE)) . '">Scarica registro diagnostico PayPal</a></p>';
        }
        echo '<p>Il registro è riservato agli amministratori e si ferma a 1 MB. Conserva gli ID tecnici di eventi, abbonamenti e incassi. Per confrontare i risultati, conserva anche il log webhook del gateway.</p></section>';
        if ($diag_action) echo '<script>document.getElementById("fic-diagnostics").scrollIntoView();document.querySelector("#fic-diagnostics h2").focus({preventScroll:true});</script>';
    }
}
