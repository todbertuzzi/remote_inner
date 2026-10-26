<?php
if (!defined('ABSPATH')) exit;

/** Recupero verificato del primo incasso Sandbox, manuale o autorizzato dalla coda. */
final class PMPro_FIC_PayPal_Recovery {
    const META = '_pmpro_fic_paypal_recovery';
    private $load;
    private $fingerprint;

    public function __construct(callable $load, callable $fingerprint) {
        $this->load = $load;
        $this->fingerprint = $fingerprint;
    }

    private function scope(): string {
        return hash('sha256', wp_json_encode([
            get_option('pmpro_gateway_environment', ''), get_option('pmpro_paypal_client_id_sandbox', ''),
            get_option('pmpro_paypal_client_secret_sandbox', ''),
        ]));
    }

    private function eligible(string $id, bool $background = false) {
        if (!current_user_can('manage_options') && (!$background || !(defined('DOING_CRON') && DOING_CRON))) return new WP_Error('forbidden', 'Operazione riservata agli amministratori o alla coda autorizzata.');
        $order = call_user_func($this->load, $id);
        if (is_wp_error($order)) return $order;
        if (strtolower((string) $order->gateway) !== 'paypal' || get_option('pmpro_gateway_environment', '') !== 'sandbox') {
            return new WP_Error('paypal_recovery_gateway', 'Il recupero richiede il nuovo gateway PayPal configurato in Sandbox.');
        }
        if (trim((string) $order->payment_transaction_id) !== '') return new WP_Error('payment_present', 'L’ID pagamento è già presente. Ricarica l’ordine per proseguire.');
        if (!preg_match('/^I-[A-Z0-9]{5,32}$/D', (string) $order->subscription_transaction_id)) {
            return new WP_Error('paypal_recovery_subscription', 'Nell’ordine manca un ID abbonamento PayPal valido.');
        }
        $linked = PMPro_FIC_Initial_Pair::source_link((int) $order->id);
        if (is_wp_error($linked)) return $linked;
        if ($linked !== null) return new WP_Error('paypal_recovery_linked', 'Checkout già collegato all’ordine #' . $linked . '. Usa quell’ordine per il documento.');
        $snapshot = PMPro_FIC_Billing::snapshot((int) $order->id);
        if (!$snapshot || is_wp_error($snapshot) || (int) $snapshot['user_id'] !== (int) $order->user_id
            || (int) $snapshot['level_id'] !== (int) $order->membership_id || $snapshot['currency'] !== 'EUR') {
            return new WP_Error('paypal_recovery_snapshot', 'Il recupero richiede la copia fiscale valida del checkout originale.');
        }
        if (get_option('pmpro_fic_test_order_' . $order->id, []) || get_option('pmpro_fic_lock_' . $order->id, false)
            || get_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_Test_Orders::META, true)) {
            return new WP_Error('paypal_recovery_document', 'Esiste già un tentativo o un documento Fatture in Cloud per questo ordine. Verifica l’associazione prima del recupero.');
        }
        if (!class_exists('PMPro_PayPal_API') || !method_exists('PMPro_PayPal_API', 'get_subscription')
            || !method_exists('PMPro_PayPal_API', 'get_subscription_transactions') || !method_exists('MemberOrder', 'get_orders')) {
            return new WP_Error('paypal_recovery_unavailable', 'Il gateway PMPro PayPal installato non espone i metodi necessari al recupero.');
        }
        if (!get_option('pmpro_paypal_client_id_sandbox', '') || !get_option('pmpro_paypal_client_secret_sandbox', '')) {
            return new WP_Error('paypal_recovery_credentials', 'Configura le credenziali Sandbox nel gateway PMPro PayPal.');
        }
        $siblings = MemberOrder::get_orders(['subscription_transaction_id' => (string) $order->subscription_transaction_id,
            'gateway' => 'paypal', 'gateway_environment' => 'sandbox', 'limit' => 2]);
        if (!is_array($siblings) || count($siblings) !== 1 || (int) reset($siblings)->id !== (int) $order->id) {
            return new WP_Error('paypal_recovery_multiple_orders', 'L’abbonamento ha più ordini o un’associazione non univoca. Verifica gli ordini prima di recuperare l’ID.');
        }
        return $order;
    }

    private function timestamp($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            return $errors && ($errors['warning_count'] || $errors['error_count']) ? false : $date->getTimestamp();
        } catch (Exception $e) { return false; }
    }

    private function money($value) {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', $value, $parts)) return false;
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /** Lettura del primo incasso per la riconciliazione assistita; nessun costruttore MemberOrder. */
    public function inspect_first_payment($order) {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        if (!is_object($order) || ($order->gateway ?? '') !== 'paypal' || ($order->gateway_environment ?? '') !== 'sandbox'
            || get_option('pmpro_gateway_environment', '') !== 'sandbox'
            || !preg_match('/^I-[A-Z0-9]{5,32}$/D', (string) ($order->subscription_transaction_id ?? ''))
            || !class_exists('PMPro_PayPal_API') || !method_exists('PMPro_PayPal_API', 'get_subscription')
            || !method_exists('PMPro_PayPal_API', 'get_subscription_transactions')
            || !get_option('pmpro_paypal_client_id_sandbox', '') || !get_option('pmpro_paypal_client_secret_sandbox', '')) {
            return new WP_Error('pair_gateway', 'Servono ordine e credenziali PayPal Sandbox validi.');
        }
        return $this->first_payment($order);
    }

    /** Legge risorse PayPal; nessuna cattura, rimborso o ripetizione di webhook. */
    private function first_payment($order) {
        $api = new PMPro_PayPal_API();
        $subscription_id = (string) $order->subscription_transaction_id;
        $subscription = $api->get_subscription($subscription_id);
        if (is_wp_error($subscription)) return new WP_Error('paypal_recovery_api', 'Impossibile leggere l’abbonamento da PayPal Sandbox. Verifica il gateway PMPro PayPal e riprova.');
        if (!is_array($subscription) || ($subscription['id'] ?? '') !== $subscription_id) {
            return new WP_Error('paypal_recovery_remote_subscription', 'La risposta PayPal non corrisponde all’abbonamento dell’ordine.');
        }
        $created = $this->timestamp($subscription['create_time'] ?? null);
        $snapshot = PMPro_FIC_Billing::snapshot((int) $order->id);
        $captured = false;
        if (!is_wp_error($snapshot) && is_string($snapshot['captured_at'] ?? null)) {
            $zone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $snapshot['captured_at'], $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) $captured = $date->getTimestamp();
        }
        if ($created === false || $captured === false || abs($created - $captured) > 86400) {
            return new WP_Error('paypal_recovery_time', 'La data di creazione dell’abbonamento non corrisponde al checkout. Verifica manualmente il collegamento.');
        }
        // Finestra del primo incasso, come nel gateway: mai cercare tra i rinnovi successivi.
        $start = $created - 3600;
        $end = $created + 3600;
        $response = $api->get_subscription_transactions($subscription_id, gmdate('Y-m-d\TH:i:s\Z', $start), gmdate('Y-m-d\TH:i:s\Z', $end));
        if (is_wp_error($response)) return new WP_Error('paypal_recovery_api', 'Impossibile leggere i pagamenti da PayPal Sandbox. Nessun ID aggiornato.');
        if (!is_array($response) || !is_array($response['transactions'] ?? null)) return new WP_Error('paypal_recovery_incomplete', 'PayPal non ha restituito un elenco di pagamenti utilizzabile. Nessun ID aggiornato; la coda riprova con un numero limitato di tentativi.');
        $rows = $response['transactions'];
        if ((isset($response['total_pages']) && (!is_int($response['total_pages']) || $response['total_pages'] < 0 || $response['total_pages'] > 1
                || (count($rows) > 0 && $response['total_pages'] === 0)))
            || (isset($response['total_items']) && (!is_int($response['total_items']) || $response['total_items'] !== count($rows)))) {
            return new WP_Error('paypal_recovery_pagination', 'L’elenco dei pagamenti non è completo. Il recupero automatico dell’ID resta bloccato.');
        }
        if (isset($response['links']) && !is_array($response['links'])) return new WP_Error('paypal_recovery_response', 'L’elenco dei pagamenti PayPal non è valido.');
        foreach ($response['links'] ?? [] as $link) if (is_array($link) && ($link['rel'] ?? '') === 'next') {
            return new WP_Error('paypal_recovery_pagination', 'Sono presenti altre pagine di pagamenti. Verifica il primo incasso manualmente.');
        }
        $completed = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['status'] ?? '', ['COMPLETED','DECLINED','PARTIALLY_REFUNDED','PENDING','REFUNDED','FAILED'], true)) {
                return new WP_Error('paypal_recovery_response', 'Lo stato di un pagamento PayPal non è verificabile.');
            }
            if (in_array($row['status'], ['REFUNDED','PARTIALLY_REFUNDED'], true)) return new WP_Error('paypal_recovery_refunded', 'Il primo incasso comprende un rimborso. Verifica il pagamento prima di creare il documento.');
            if ($row['status'] !== 'COMPLETED') continue;
            $time = $this->timestamp($row['time'] ?? null);
            $cents = $this->money($row['amount_with_breakdown']['gross_amount']['value'] ?? null);
            $currency = $row['amount_with_breakdown']['gross_amount']['currency_code'] ?? '';
            if (!is_string($row['id'] ?? null) || !preg_match('/^[A-Z0-9]{8,50}$/D', $row['id']) || $time === false
                || $time < $start || $time > $end || $cents === false || $cents <= 0 || !is_string($currency)) {
                return new WP_Error('paypal_recovery_response', 'I dati di un pagamento completato sono incompleti o fuori dalla finestra del primo incasso.');
            }
            $completed[] = ['payment_id' => $row['id'], 'subscription_id' => $subscription_id, 'cents' => $cents,
                'currency' => $currency, 'paid_at' => gmdate('Y-m-d\TH:i:s\Z', $time), 'status' => 'COMPLETED'];
        }
        if (!$completed) return new WP_Error('paypal_recovery_not_found', 'Nessun primo pagamento completato disponibile in PayPal. Attendi la disponibilità dei dati e ripeti la verifica.');
        if (count($completed) !== 1) return new WP_Error('paypal_recovery_ambiguous', 'Sono presenti più pagamenti completati nella finestra del primo incasso. Il recupero resta bloccato.');
        $payment = $completed[0];
        if ($payment['currency'] !== 'EUR' || $payment['cents'] !== (int) round((float) $order->total * 100)) {
            return new WP_Error('paypal_recovery_amount', 'Importo o valuta del pagamento PayPal non corrispondono all’ordine PMPro.');
        }
        return $payment;
    }

    private function verify($order) {
        $payment = $this->first_payment($order);
        if (is_wp_error($payment)) return $payment;
        $other = MemberOrder::get_orders(['payment_transaction_id' => $payment['payment_id'], 'gateway_environment' => 'sandbox', 'limit' => 2]);
        if (is_array($other) && count($other) === 1 && (int) reset($other)->id === (int) $order->id) {
            return new WP_Error('payment_present', 'Il gateway ha già associato l’ID pagamento durante la verifica. Ricarica l’ordine.');
        }
        if (!is_array($other) || $other) return new WP_Error('paypal_recovery_duplicate', 'L’ID pagamento è già associato a un ordine PMPro. Verifica gli ordini senza creare una seconda associazione.');
        return $payment;
    }

    public function prepare(string $order_id) {
        $order = $this->eligible($order_id);
        if (is_wp_error($order)) return $order;
        $payment = $this->verify($order);
        if (is_wp_error($payment)) return $payment;
        $id = wp_generate_uuid4();
        $review = ['stage' => 'payment_review', 'recovery_id' => $id, 'order_id' => (int) $order->id,
            'admin_id' => get_current_user_id(), 'scope' => $this->scope(),
            'fingerprint' => call_user_func($this->fingerprint, $order), 'payment' => $payment];
        set_transient('pmpro_fic_paypal_review_' . $id, $review, 10 * MINUTE_IN_SECONDS);
        return $review;
    }

    public function confirm(string $id) {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) return new WP_Error('paypal_recovery_expired', 'Verifica del pagamento non valida o scaduta.');
        $review = get_transient('pmpro_fic_paypal_review_' . $id);
        if (!is_array($review) || $review['admin_id'] !== get_current_user_id() || $review['scope'] !== $this->scope()) {
            return new WP_Error('paypal_recovery_expired', 'Verifica scaduta o configurazione PayPal modificata. Ripeti la verifica.');
        }
        return $this->associate($review);
    }

    private function authorized(?callable $guard) {
        if ($guard === null) return true;
        $allowed = $guard();
        return $allowed === true || is_wp_error($allowed) ? $allowed : new WP_Error('forbidden', 'Recupero non autorizzato dalla coda.');
    }

    public function automatic(int $order_id, callable $guard) {
        if (!(defined('DOING_CRON') && DOING_CRON) && !current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata alla coda o agli amministratori.');
        $allowed = $this->authorized($guard);
        if (is_wp_error($allowed)) return $allowed;
        $order = $this->eligible((string) $order_id, true);
        if (is_wp_error($order)) return $order;
        $scope = $this->scope();
        $fingerprint = call_user_func($this->fingerprint, $order);
        $payment = $this->verify($order);
        if (is_wp_error($payment)) return $payment;
        $review = ['recovery_id' => wp_generate_uuid4(), 'order_id' => $order_id, 'scope' => $scope,
            'fingerprint' => $fingerprint, 'payment' => $payment];
        return $this->associate($review, true, $guard);
    }

    private function associate(array $review, bool $background = false, ?callable $guard = null) {
        $allowed = $this->authorized($guard);
        if (is_wp_error($allowed)) return $allowed;
        if ($this->scope() !== $review['scope']) return new WP_Error('paypal_recovery_expired', 'Configurazione PayPal modificata. Ripeti la verifica.');
        $id = $review['recovery_id'];
        $order = $this->eligible((string) $review['order_id'], $background);
        if (is_wp_error($order)) return $order;
        if (call_user_func($this->fingerprint, $order) !== $review['fingerprint']) return new WP_Error('order_changed', 'L’ordine è cambiato dopo la verifica. Ricaricalo prima di recuperare l’ID.');
        $lock = 'pmpro_fic_paypal_recovery_lock_' . $order->id;
        if (!add_option($lock, $id, '', false)) return new WP_Error('paypal_recovery_locked', 'È già in corso un recupero per questo ordine. Ricaricalo prima di riprovare.');
        try {
            $payment = $this->verify($order); // Ricontrolla anche rimborsi / cambiamenti remoti.
            if (is_wp_error($payment)) return $payment;
            if ($payment !== $review['payment']) return new WP_Error('paypal_recovery_changed', 'Il pagamento PayPal è cambiato dopo la verifica. Ripeti la verifica.');
            $fresh = $this->eligible((string) $review['order_id'], $background);
            if (is_wp_error($fresh)) return $fresh;
            if ($this->scope() !== $review['scope']) return new WP_Error('paypal_recovery_expired', 'La configurazione PayPal è cambiata durante il recupero. Ripeti la verifica.');
            if (call_user_func($this->fingerprint, $fresh) !== $review['fingerprint']) return new WP_Error('order_changed', 'L’ordine è cambiato durante il recupero. Nessun ID aggiornato.');
            $allowed = $this->authorized($guard);
            if (is_wp_error($allowed)) return $allowed;
            global $wpdb;
            $table = $wpdb->pmpro_membership_orders ?? '';
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table)) return new WP_Error('paypal_recovery_storage', 'Tabella ordini PMPro non disponibile.');
            $audit = ['status' => 'verified', 'order_id' => (int) $order->id, 'admin_id' => get_current_user_id(),
                'verified_at' => current_time('mysql'), 'origin' => $background ? 'automation' : 'manual', 'payment' => $payment];
            $journal_key = 'pmpro_fic_paypal_recovery_' . $order->id;
            update_option($journal_key, $audit, false);
            if (get_option($journal_key, []) !== $audit) return new WP_Error('paypal_recovery_storage', 'Impossibile registrare la verifica. Nessun ID aggiornato.');
            $allowed = $this->authorized($guard);
            if (is_wp_error($allowed)) return $allowed;
            // Aggiorna solo il riferimento vuoto, con confronto atomico dell'identità ordine.
            // La self join blocca un ID che nel frattempo sia stato salvato su un altro ordine.
            // PMPro total è varchar(16): confrontare il valore letto, senza riformattarlo.
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} AS target LEFT JOIN {$table} AS taken
                 ON taken.payment_transaction_id = %s AND taken.gateway_environment = 'sandbox' AND taken.id <> target.id
                 SET target.payment_transaction_id = %s
                 WHERE target.id = %d AND target.code = %s AND target.user_id = %d AND target.membership_id = %d
                 AND target.gateway = 'paypal' AND target.gateway_environment = 'sandbox' AND target.status = 'success'
                 AND target.subscription_transaction_id = %s AND target.total = %s
                 AND (target.payment_transaction_id = '' OR target.payment_transaction_id IS NULL) AND taken.id IS NULL",
                $payment['payment_id'], $payment['payment_id'], (int) $order->id, (string) $order->code,
                (int) $order->user_id, (int) $order->membership_id, (string) $order->subscription_transaction_id,
                (string) $fresh->total
            ));
            if ($changed === false) {
                return new WP_Error('paypal_recovery_query', 'Il database non ha eseguito l’associazione dell’ID pagamento (PAYPAL-DB-QUERY). Ricarica l’ordine e verifica la configurazione del database.');
            }
            if ($changed !== 1) {
                return new WP_Error('paypal_recovery_storage', 'ID non aggiornato: nessuna riga corrisponde alle condizioni verificate. L’ordine potrebbe essere cambiato o il pagamento già associato. Ricaricalo e verifica gli ordini.');
            }
            $saved = new MemberOrder((int) $order->id);
            if ((int) $saved->id !== (int) $order->id || (string) $saved->payment_transaction_id !== $payment['payment_id']) {
                return new WP_Error('paypal_recovery_readback', 'Il database segnala un aggiornamento, ma la rilettura non conferma l’ID pagamento (PAYPAL-DB-READBACK). Verifica l’ordine in PMPro prima di proseguire.');
            }
            $audit['status'] = 'updated';
            update_option($journal_key, $audit, false);
            update_pmpro_membership_order_meta((int) $order->id, self::META, $audit);
            delete_transient('pmpro_fic_paypal_review_' . $id);
            return ['stage' => 'payment_saved', 'order_id' => (int) $order->id, 'payment' => $payment];
        } finally {
            delete_option($lock);
        }
    }
}
