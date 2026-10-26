<?php
if (!defined('ABSPATH')) exit;

/**
 * Impedisce il secondo ordine PMPro per il primo incasso di un abbonamento PayPal.
 *
 * Il gestore di PMPro riutilizza un ordine solo se ha lo stesso ID incasso oppure è `pending` senza ID.
 * Se l'attivazione ha chiuso il checkout (`success`) senza ricavare l'ID, la notifica PAYMENT.SALE.COMPLETED
 * del primo incasso crea un nuovo ordine. Questa guardia, eseguita prima del gestore, verifica l'incasso
 * direttamente in PayPal e lo associa al checkout; PMPro lo riconosce poi come già elaborato.
 *
 * La guardia NON è passiva: scrive l'ID incasso sull'ordine, può rispondere HTTP 503 e quindi cambia il momento
 * in cui PayPal considera consegnata la notifica. Non crea, cancella né cambia lo stato di ordini.
 *
 * Regola di decisione, per un ordine candidato (unico ordine dell'abbonamento, success, senza ID):
 *  - esito definitivo (importo, abbonamento o stato diversi; non è il primo incasso; checkout incoerente) → comportamento ufficiale;
 *  - qualunque altro esito (rete, risposta incompleta o malformata, archivio, collegamenti, associazione non riuscita)
 *    e la sale è coeva al checkout → HTTP 503, PayPal riconsegna; mai il passaggio al core con associazione irrisolta;
 *  - esito non verificabile ma la sale è lontana dal checkout → comportamento ufficiale.
 *
 * Finestra unica (WINDOW, 24 ore): un checkout è «coevo» alla sale, e quindi protetto, se i due distano meno di 24 ore.
 * Gli estremi sono gli stessi per associare e per rinviare: |checkout − incasso| < 24 ore (stretto), con l'orario dell'incasso
 * verificato in PayPal per associare e quello della notifica (non autenticato) per decidere il rinvio. In più, per associare,
 * l'incasso deve cadere entro 1 ora dalla creazione dell'abbonamento. Fuori da questo insieme (per esempio prova gratuita o
 * primo incasso oltre 24 ore dal checkout) la guardia non interviene e vale il comportamento ufficiale.
 * Ipotesi, non garanzia: il ciclo minimo di PayPal è giornaliero, quindi un rinnovo dovrebbe cadere a 24 ore o più dal checkout;
 * va confermato con un rinnovo reale in Sandbox. La distanza temporale non prova che un incasso sia un rinnovo.
 */
final class PMPro_FIC_Webhook_Guard {
    const ROUTE = '/pmpro-paypal/v1/webhook';
    const OUTCOME = 'pmpro_fic_webhook_guard_last';
    const LOG = 'pmpro_fic_webhook_guard_log';
    const LOG_LIMIT = 30;
    const INITIAL_WINDOW = 3600;      // la sale deve cadere entro 1 ora dalla creazione dell'abbonamento (come il gateway)
    const WINDOW = 86400;             // stessa finestra per l'associazione (checkout/abbonamento) e per il rinvio (checkout/sale)
    const REPAIR_HOOK = 'pmpro_fic_guard_repair';
    const REPAIR_ATTEMPTS = 5;
    /** Rifiuti certi di dati ben formati. Tutto il resto (struttura, date illeggibili, rete, 403…) è «non verificabile». */
    const DEFINITIVE = ['payment_identity', 'payment_amount', 'payment_state'];

    private $event_id = '';
    /** Candidato individuato (ordine da proteggere): serve a decidere cosa fare se una eccezione interrompe la guardia. */
    private $candidate = null;

    public function __construct() {
        add_filter('rest_pre_dispatch', [$this, 'pre_dispatch'], 5, 3);
        add_action(self::REPAIR_HOOK, [$this, 'cron_repair'], 10, 3);
        add_action('admin_post_pmpro_fic_guard_resume', [__CLASS__, 'handle_resume']);
    }

    public static function enabled(): bool {
        return get_option('pmpro_gateway', '') === 'paypal' && get_option('pmpro_gateway_environment', '') === 'sandbox'
            && (bool) apply_filters('pmpro_fic_webhook_guard_enabled', true);
    }

    public function pre_dispatch($response, $server, $request) {
        if ($response !== null) return $response;
        $this->event_id = '';
        $this->candidate = null;
        try {
            return $this->inspect($request);
        } catch (Throwable $error) {
            $c = $this->candidate;
            if (is_array($c)) {
                $this->note('guard_exception', $c['subscription'], $c['sale'], $c['order']);
                // Con un candidato coevo l'eccezione non può lasciar passare il core con l'associazione irrisolta.
                if ($c['likely']) return $this->response_503();
                return $response;
            }
            $this->note('guard_exception');
            return $response; // Nessun candidato individuato: comportamento ufficiale.
        }
    }

    /** Solo classificazioni e identificativi già validati; nessun payload, credenziale o dato personale. */
    private function note(string $outcome, string $subscription = '', string $sale = '', int $order_id = 0, string $before = '', string $after = ''): void {
        $entry = ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'outcome' => $outcome, 'event_id' => $this->event_id,
            'subscription_id' => $subscription, 'sale_id' => $sale, 'order_id' => $order_id, 'payment_before' => $before, 'payment_after' => $after];
        update_option(self::OUTCOME, $entry, false);
        $log = get_option(self::LOG, []);
        $log = is_array($log) ? $log : [];
        $log[] = $entry;
        update_option(self::LOG, array_slice($log, -self::LOG_LIMIT), false); // Diagnostica: una scrittura persa non cambia l'esito.
    }

    private function response_503() {
        $response = new WP_REST_Response(['message' => 'Primo incasso non ancora riconciliabile; riprovare.'], 503);
        $response->header('Retry-After', '60');
        return $response;
    }

    private function retry(string $outcome, string $subscription, string $sale, int $order_id = 0) {
        $this->note($outcome, $subscription, $sale, $order_id);
        return $this->response_503();
    }

    /** Non verificabile: 503 se probabilmente è il primo incasso, altrimenti comportamento ufficiale. */
    private function uncertain(bool $likely, string $outcome, string $subscription, string $sale, int $order_id) {
        if ($likely) return $this->retry($outcome, $subscription, $sale, $order_id);
        $this->note($outcome . '_passthrough', $subscription, $sale, $order_id);
        return null;
    }

    private static function table(): string {
        global $wpdb;
        $table = $wpdb->pmpro_membership_orders ?? '';
        return is_string($table) && preg_match('/^[A-Za-z0-9_]+$/D', $table) ? $table : '';
    }

    private function inspect($request) {
        if (!is_object($request) || $request->get_method() !== 'POST' || $request->get_route() !== self::ROUTE || !self::enabled()) return null;
        $event = json_decode((string) $request->get_body(), true);
        if (!is_array($event) || ($event['event_type'] ?? '') !== 'PAYMENT.SALE.COMPLETED' || !is_array($event['resource'] ?? null)) return null;
        $sale_id = $event['resource']['id'] ?? '';
        $subscription_id = $event['resource']['billing_agreement_id'] ?? '';
        if (!is_string($sale_id) || !preg_match('/^[A-Z0-9-]{5,64}$/D', $sale_id)
            || !is_string($subscription_id) || !preg_match('/^I-[A-Z0-9]{5,32}$/D', $subscription_id)) return null;
        $this->event_id = is_string($event['id'] ?? null) && preg_match('/^[A-Za-z0-9-]{5,64}$/D', $event['id']) ? $event['id'] : '';

        // Stessa verifica firma del gestore ufficiale; se fallisce, lascia a lui il rifiuto (401).
        if (!function_exists('pmpro_paypal_verify_webhook') && defined('PMPRO_PAYPAL_DIR') && is_readable(PMPRO_PAYPAL_DIR . 'includes/webhook-handler.php')) {
            require_once PMPRO_PAYPAL_DIR . 'includes/webhook-handler.php';
        }
        if (!function_exists('pmpro_paypal_verify_webhook') || !pmpro_paypal_verify_webhook($request)) return null;

        // Un incasso già associato a un ordine: eventuale riparazione dell'audit, a prescindere da quanti ordini ha l'abbonamento.
        $taken = $this->sale_owner($sale_id);
        if (is_wp_error($taken)) return $this->retry('orders_unreadable', $subscription_id, $sale_id);
        if ($taken) return $this->known_sale((int) $taken, $subscription_id, $sale_id);

        $orders = $this->orders($subscription_id);
        if (is_wp_error($orders)) return $this->retry('orders_unreadable', $subscription_id, $sale_id);
        if (count($orders) !== 1) return null; // Nessun ordine, o storia già articolata: comportamento ufficiale.
        $order = $orders[0];
        $oid = (int) $order->id;
        $placed = $this->utc($order->timestamp); // La colonna `timestamp` di PMPro è in UTC (getTimestamp($gmt) la restituisce grezza).
        $sale_at = PMPro_FIC_Payments::timestamp($event['resource']['create_time'] ?? null);
        // Dato del payload non autenticato: serve solo a scegliere tra 503 e comportamento ufficiale, mai a scrivere.
        $likely = $placed !== null && abs(($sale_at === false ? time() : $sale_at) - $placed) < self::WINDOW;

        if (trim((string) $order->payment_transaction_id) !== '') return null; // Ha già un incasso diverso: non è il primo.
        if ($order->status === 'token') {
            // Attivazione ancora in corso: se il checkout si chiude tra ora e il gestore, nascerebbe il doppione.
            return $likely ? $this->retry('token_retry', $subscription_id, $sale_id, $oid) : null;
        }
        if ($order->status !== 'success') return null; // `pending` senza ID è già gestito da PMPro.
        $this->candidate = ['subscription' => $subscription_id, 'sale' => $sale_id, 'order' => $oid, 'likely' => $likely];

        // Collegamento manuale 0.6.1: assenza (null) e collegamento valido non sono errori; ogni errore di lettura sì.
        if (!class_exists('PMPro_FIC_Initial_Pair')) return $this->uncertain($likely, 'pair_unavailable', $subscription_id, $sale_id, $oid);
        $linked = PMPro_FIC_Initial_Pair::source_link($oid);
        if (is_wp_error($linked)) return $this->uncertain($likely, 'pair_link_unreadable', $subscription_id, $sale_id, $oid);
        if ($linked !== null) return null;

        if (!class_exists('PMPro_PayPal_API') || !method_exists('PMPro_PayPal_API', 'get_subscription') || !method_exists('PMPro_PayPal_API', 'get_access_token')
            || !get_option('pmpro_paypal_client_id_sandbox', '') || !get_option('pmpro_paypal_client_secret_sandbox', '')) {
            // Senza credenziali non si può verificare l'incasso, ma il core creerebbe comunque il doppione dai dati della notifica.
            return $this->uncertain($likely, 'gateway_unavailable', $subscription_id, $sale_id, $oid);
        }

        // Prova diretta dell'incasso: GET della singola sale, importo e abbonamento coincidenti con il checkout.
        $candidate = (object) ['gateway' => 'paypal', 'gateway_environment' => 'sandbox', 'payment_transaction_id' => $sale_id,
            'subscription_transaction_id' => $subscription_id, 'total' => $order->total];
        $payment = PMPro_FIC_Payments::verify($candidate);
        if (is_wp_error($payment)) {
            if (in_array($payment->get_error_code(), self::DEFINITIVE, true)) {
                $this->note('sale_rejected_' . $payment->get_error_code(), $subscription_id, $sale_id, $oid);
                return null;
            }
            return $this->uncertain($likely, 'sale_unverifiable_' . $payment->get_error_code(), $subscription_id, $sale_id, $oid);
        }

        // Deve essere il primo incasso, non un rinnovo.
        $subscription = (new PMPro_PayPal_API())->get_subscription($subscription_id);
        if (is_wp_error($subscription)) return $this->uncertain($likely, 'subscription_unavailable', $subscription_id, $sale_id, $oid);
        $created = is_array($subscription) && ($subscription['id'] ?? '') === $subscription_id ? PMPro_FIC_Payments::timestamp($subscription['create_time'] ?? null) : false;
        $paid = PMPro_FIC_Payments::timestamp($payment['paid_at']);
        if ($created === false || $paid === false || $placed === null) return $this->uncertain($likely, 'subscription_unverified', $subscription_id, $sale_id, $oid);
        if (abs($paid - $created) > self::INITIAL_WINDOW) { $this->note('not_initial', $subscription_id, $sale_id, $oid); return null; }
        // Stesso estremo del rinvio (checkout ↔ incasso, stretto), ma sull'orario dell'incasso verificato in PayPal.
        if (abs($placed - $paid) >= self::WINDOW) { $this->note('checkout_mismatch', $subscription_id, $sale_id, $oid); return null; }

        $bound = PMPro_FIC_PayPal_Recovery::bind_from_webhook($order, $payment);
        if (is_wp_error($bound)) {
            if ($bound->get_error_code() === 'paypal_recovery_audit') {
                // L'ID è associato: il core non creerebbe doppioni. Ma l'audit è incompleto e una risposta 2xx chiuderebbe la consegna:
                // si risponde 503 (PayPal riconsegna e la notifica ripara) e si programma un lavoro locale indipendente.
                $this->note('bound_audit_pending', $subscription_id, $sale_id, $oid, '', $sale_id);
                $this->schedule_repair($oid, $sale_id, 1);
                return $this->response_503();
            }
            return $this->uncertain(true, 'bind_' . $bound->get_error_code(), $subscription_id, $sale_id, $oid); // Associazione irrisolta: mai al core.
        }
        $this->note('bound', $subscription_id, $sale_id, $oid, '', $sale_id);
        return null; // Prosegue il gestore ufficiale: troverà l'ordine con questo ID, già `success`.
    }

    /** Sale già associata a un ordine: ripara l'audit se serve. 503 solo se la riparazione è ancora possibile e non è riuscita. */
    private function known_sale(int $order_id, string $subscription_id, string $sale_id) {
        $order = $this->order($order_id);
        if (is_wp_error($order)) return $this->retry('orders_unreadable', $subscription_id, $sale_id, $order_id);
        if (!$order || (string) $order->subscription_transaction_id !== $subscription_id || (string) $order->payment_transaction_id !== $sale_id) return null;
        return $this->repair_audit($order, $subscription_id, $sale_id) === 'failed'
            ? $this->retry('audit_repair_retry', $subscription_id, $sale_id, $order_id) : null;
    }

    /**
     * Riprende un audit lasciato a metà da un'associazione già riuscita, solo per registri creati da questa guardia.
     * Non tocca ID pagamento né ordini e non crea documenti. 'none' = niente da fare, 'done', 'failed' = da riprovare.
     */
    private function repair_audit($order, string $subscription_id, string $sale_id): string {
        $journal = $this->pending_journal($order, $sale_id);
        if ($journal === null) return 'none';
        $payment = PMPro_FIC_Payments::verify($order);
        if (is_wp_error($payment)) {
            $definitive = in_array($payment->get_error_code(), self::DEFINITIVE, true);
            $this->note('audit_repair_' . ($definitive ? 'rejected' : 'unverified'), $subscription_id, $sale_id, (int) $order->id, $sale_id, $sale_id);
            return $definitive ? 'none' : 'failed';
        }
        if ($payment !== $journal['payment']) {
            $this->note('audit_repair_changed', $subscription_id, $sale_id, (int) $order->id, $sale_id, $sale_id);
            return 'none'; // Il pagamento remoto non coincide più con quello verificato: decide una persona.
        }
        $done = PMPro_FIC_PayPal_Recovery::complete_audit($order, $payment, 'webhook');
        $this->note(is_wp_error($done) ? 'audit_repair_failed' : 'audit_repaired', $subscription_id, $sale_id, (int) $order->id, $sale_id, $sale_id);
        return is_wp_error($done) ? 'failed' : 'done';
    }

    /** Registro creato da questa guardia (origin webhook) e non ancora completo; null se non c'è nulla da riparare. */
    private function pending_journal($order, string $sale_id): ?array {
        $journal = get_option('pmpro_fic_paypal_recovery_' . $order->id, []);
        if (!is_array($journal) || ($journal['payment']['payment_id'] ?? '') !== $sale_id || ($journal['origin'] ?? '') !== 'webhook') return null;
        if (($journal['status'] ?? '') === 'updated' && get_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_PayPal_Recovery::META, true) === $journal) return null;
        return $journal;
    }

    /** true solo se il lavoro risulta davvero salvato (o già presente); l'esito va sempre controllato. */
    private function schedule_repair(int $order_id, string $sale_id, int $attempt): bool {
        if (!function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled')) { $this->note('repair_not_scheduled', '', $sale_id, $order_id); return false; }
        $args = [$order_id, $sale_id, $attempt];
        if (wp_next_scheduled(self::REPAIR_HOOK, $args)) return true;
        $scheduled = wp_schedule_single_event(time() + 120 * $attempt, self::REPAIR_HOOK, $args);
        if ($scheduled !== true || !wp_next_scheduled(self::REPAIR_HOOK, $args)) {
            // Il cron di WordPress vive nelle opzioni: se non si salvano, resta la riconsegna PayPal e la ripresa dal backend.
            $this->note('repair_not_scheduled', '', $sale_id, $order_id);
            return false;
        }
        return true;
    }

    /** Ordini con registro di recupero creato dalla guardia, cercati per nome di opzione: non dipende dal registro eventi. [order_id => sale_id] */
    private static function journal_candidates(): array {
        global $wpdb;
        $found = [];
        $table = is_object($wpdb) ? ($wpdb->options ?? '') : '';
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table) || !method_exists($wpdb, 'get_col')) return $found;
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$table} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 100", 'pmpro\\_fic\\_paypal\\_recovery\\_%'));
        foreach (is_array($names) ? $names : [] as $name) {
            if (!is_string($name) || !preg_match('/^pmpro_fic_paypal_recovery_(\d+)$/D', $name, $m)) continue;
            $journal = get_option($name, []);
            if (is_array($journal) && ($journal['origin'] ?? '') === 'webhook' && is_string($journal['payment']['payment_id'] ?? null)) $found[(int) $m[1]] = $journal['payment']['payment_id'];
        }
        return $found;
    }

    /** Ordini con audit lasciato a metà da questa guardia (registri e ultimi eventi). Sola lettura, nessuna chiamata a PayPal. */
    public static function pending_audits(): array {
        $guard = new self();
        $candidates = self::journal_candidates();
        $log = get_option(self::LOG, []);
        foreach (is_array($log) ? $log : [] as $entry) {
            if (is_array($entry) && is_int($entry['order_id'] ?? null) && $entry['order_id'] > 0 && is_string($entry['sale_id'] ?? null) && $entry['sale_id'] !== '') $candidates[$entry['order_id']] = $entry['sale_id'];
        }
        $found = [];
        foreach ($candidates as $order_id => $sale_id) {
            $order = $guard->order((int) $order_id);
            if (is_object($order) && (string) $order->payment_transaction_id === $sale_id && $guard->pending_journal($order, $sale_id) !== null) $found[(int) $order_id] = $sale_id;
        }
        return $found;
    }

    /** Ripresa manuale dal backend: ripete l'audit degli ordini in sospeso. Ritorna [completati, ancora in sospeso]. */
    public static function resume_pending(): array {
        $done = 0;
        $guard = new self();
        foreach (self::pending_audits() as $order_id => $sale_id) {
            $order = $guard->order((int) $order_id);
            if (is_object($order) && $guard->repair_audit($order, (string) $order->subscription_transaction_id, (string) $sale_id) === 'done') $done++;
        }
        return [$done, count(self::pending_audits())];
    }

    /** Lavoro durevole: completa l'audit anche se PayPal non riconsegna più la notifica. Idempotente, limitato a 5 tentativi. */
    public function cron_repair($order_id = 0, $sale_id = '', $attempt = 1): void {
        try {
            if (!self::enabled() || !is_int($order_id) || $order_id <= 0 || !is_string($sale_id) || !preg_match('/^[A-Z0-9-]{5,64}$/D', $sale_id) || !is_int($attempt)) return;
            $order = $this->order($order_id);
            if (is_wp_error($order)) { if ($attempt < self::REPAIR_ATTEMPTS) $this->schedule_repair($order_id, $sale_id, $attempt + 1); return; }
            if (!$order || (string) $order->payment_transaction_id !== $sale_id) return;
            if ($this->repair_audit($order, (string) $order->subscription_transaction_id, $sale_id) === 'failed' && $attempt < self::REPAIR_ATTEMPTS) {
                $this->schedule_repair($order_id, $sale_id, $attempt + 1);
            }
        } catch (Throwable $error) {
            $this->note('guard_exception', '', $sale_id, is_int($order_id) ? $order_id : 0);
            // Un'eccezione nel lavoro non deve spegnerlo: si riprova entro il limite (e resta la ripresa dal backend).
            if (is_int($order_id) && $order_id > 0 && is_string($sale_id) && is_int($attempt) && $attempt < self::REPAIR_ATTEMPTS) {
                try { $this->schedule_repair($order_id, $sale_id, $attempt + 1); } catch (Throwable $again) { /* resta la ripresa dal backend */ }
            }
        }
    }

    private const COLUMNS = 'id, code, user_id, membership_id, status, gateway, gateway_environment, total, subscription_transaction_id, payment_transaction_id, timestamp';

    private function orders(string $subscription_id) {
        global $wpdb;
        $table = self::table();
        if (!$table || !method_exists($wpdb, 'get_results')) return new WP_Error('guard_storage', 'Ordini non leggibili.');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ' . self::COLUMNS . " FROM {$table} WHERE subscription_transaction_id = %s AND gateway = 'paypal' AND gateway_environment = 'sandbox' ORDER BY id ASC LIMIT 3", $subscription_id));
        return !empty($wpdb->last_error) || !is_array($rows) ? new WP_Error('guard_storage', 'Ordini non leggibili.') : array_values($rows);
    }

    private function order(int $id) {
        global $wpdb;
        $table = self::table();
        if (!$table || !method_exists($wpdb, 'get_results')) return new WP_Error('guard_storage', 'Ordini non leggibili.');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d AND gateway = 'paypal' AND gateway_environment = 'sandbox' LIMIT 1", $id));
        return !empty($wpdb->last_error) || !is_array($rows) ? new WP_Error('guard_storage', 'Ordini non leggibili.') : ($rows[0] ?? null);
    }

    private function sale_owner(string $sale_id) {
        global $wpdb;
        $table = self::table();
        $owner = $table ? $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE payment_transaction_id = %s AND gateway_environment = 'sandbox' LIMIT 1", $sale_id)) : false;
        return !$table || !empty($wpdb->last_error) ? new WP_Error('guard_storage', 'Ordini non leggibili.') : $owner;
    }

    /** Colonna `timestamp` raw di PMPro (datetime UTC) → Unix; null se illeggibile. Indipendente dal fuso del sito. */
    private function utc($datetime): ?int {
        if (!is_string($datetime) || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $datetime)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $datetime, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        return !$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) ? null : $date->getTimestamp();
    }

    public static function handle_resume(): void {
        if (!current_user_can('manage_options')) wp_die('Permesso negato.', '', ['response' => 403]);
        check_admin_referer('pmpro_fic_guard_resume');
        [$done, $left] = self::resume_pending();
        wp_safe_redirect(add_query_arg(['guard_resumed' => $done, 'guard_pending' => $left], wp_get_referer() ?: admin_url()));
        exit;
    }

    /** Stato sola lettura per il collaudo: configurazione effettiva e ultimi eventi, senza payload né dati personali. */
    public static function render_status(): void {
        $gateway = get_option('pmpro_gateway', '');
        $environment = get_option('pmpro_gateway_environment', '');
        $filter = (bool) apply_filters('pmpro_fic_webhook_guard_enabled', true);
        $active = $gateway === 'paypal' && $environment === 'sandbox' && $filter;
        echo '<hr><h2>Guardia webhook primo incasso</h2><p>Interviene sul webhook PayPal Sandbox <code>PAYMENT.SALE.COMPLETED</code>: associa al checkout l’incasso iniziale verificato in PayPal, può rispondere HTTP 503 per far riprovare PayPal e non crea né cancella ordini.</p>';
        echo '<p><strong>Stato effettivo: ' . ($active ? 'attiva' : 'NON attiva') . '</strong> — gateway <code>' . esc_html((string) $gateway) . '</code>, ambiente <code>'
            . esc_html((string) $environment) . '</code>, filtro <code>pmpro_fic_webhook_guard_enabled</code> ' . ($filter ? 'consente' : 'disattiva') . ' la guardia.</p>';
        $pending = self::pending_audits();
        if ($pending) {
            echo '<p><strong>Audit incompleti: ' . esc_html((string) count($pending)) . '</strong> (ordini ' . esc_html(implode(', ', array_map('strval', array_keys($pending)))) . '). L’ID incasso è già associato; manca solo la conferma del registro, che non crea fatture.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="pmpro_fic_guard_resume">';
            wp_nonce_field('pmpro_fic_guard_resume');
            echo '<p><button class="button" type="submit">Riprova gli audit incompleti</button></p></form>';
        }
        $log = get_option(self::LOG, []);
        if (!is_array($log) || !$log) { echo '<p>Nessun evento registrato. Le uscite anticipate (firma non valida, evento non pertinente, abbonamento con più ordini) non vengono registrate: l’elenco vuoto non prova che non sia arrivato alcun webhook.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>UTC</th><th>Esito</th><th>Ordine</th><th>Abbonamento</th><th>Incasso</th><th>Evento</th></tr></thead><tbody>';
        foreach (array_reverse(array_slice($log, -10)) as $entry) {
            if (!is_array($entry)) continue;
            echo '<tr><td>' . esc_html((string) ($entry['at'] ?? '')) . '</td><td>' . esc_html((string) ($entry['outcome'] ?? '')) . '</td><td>'
                . esc_html((string) ($entry['order_id'] ?? '')) . '</td><td>' . esc_html((string) ($entry['subscription_id'] ?? '')) . '</td><td>'
                . esc_html((string) ($entry['sale_id'] ?? '')) . '</td><td>' . esc_html((string) ($entry['event_id'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
