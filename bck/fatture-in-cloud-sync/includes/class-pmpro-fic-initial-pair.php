<?php
if (!defined('ABSPATH')) exit;

/** Riconciliazione assistita: checkout senza incasso + ordine gateway con il primo incasso. */
final class PMPro_FIC_Initial_Pair {
    const ACTIONS = ['inspect_initial_pair', 'confirm_initial_pair'];

    private static function table(): string {
        global $wpdb;
        $table = $wpdb->pmpro_membership_orders ?? '';
        return is_string($table) && preg_match('/^[A-Za-z0-9_]+$/D', $table) ? $table : '';
    }

    /** Lettura diretta: nessun costruttore PMPro, hook o sincronizzazione di abbonamenti. */
    public static function load(int $id) {
        global $wpdb;
        $table = self::table();
        if (!$table || !method_exists($wpdb, 'get_row')) return new WP_Error('pair_storage', 'Lettura ordini non disponibile.');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, code, user_id, membership_id, status, gateway, gateway_environment, total, subscription_transaction_id, payment_transaction_id FROM {$table} WHERE id = %d LIMIT 1", $id));
        if (!empty($wpdb->last_error) || !$row || (int) $row->id !== $id) return new WP_Error('pair_storage', 'Impossibile leggere l’ordine richiesto.');
        return $row;
    }

    private static function fingerprint($order): string {
        return hash('sha256', wp_json_encode([(int) $order->id, (string) $order->code, (int) $order->user_id,
            (int) $order->membership_id, (string) $order->gateway, (string) $order->gateway_environment, (string) $order->status,
            (string) $order->subscription_transaction_id, (string) $order->payment_transaction_id, (string) $order->total]));
    }

    private static function scope(array $context): string {
        return hash('sha256', wp_json_encode([$context, get_option('pmpro_gateway_environment', ''),
            get_option('pmpro_paypal_client_id_sandbox', ''), get_option('pmpro_paypal_client_secret_sandbox', '')]));
    }

    public static function key(int $source): string { return 'pmpro_fic_initial_pair_' . $source; }

    private static function clean($order): bool {
        return !get_option('pmpro_fic_test_order_' . $order->id, []) && !get_option('pmpro_fic_lock_' . $order->id, false)
            && !get_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_Test_Orders::META, true);
    }

    private static function pair(int $source_id) {
        global $wpdb;
        $source = self::load($source_id);
        if (is_wp_error($source)) return $source;
        if (get_option('pmpro_gateway', '') !== 'paypal' || get_option('pmpro_gateway_environment', '') !== 'sandbox'
            || get_option('pmpro_currency', '') !== 'EUR' || $source->gateway !== 'paypal' || $source->gateway_environment !== 'sandbox'
            || $source->status !== 'success' || trim((string) $source->payment_transaction_id) !== ''
            || !preg_match('/^I-[A-Z0-9]{5,32}$/D', (string) $source->subscription_transaction_id)) {
            return new WP_Error('pair_source', 'Serve il checkout PayPal Sandbox pagato, con ID incasso vuoto e abbonamento valido.');
        }
        $snapshot = PMPro_FIC_Billing::snapshot($source_id);
        if (is_wp_error($snapshot) || !$snapshot || !empty($snapshot['origin']) || $snapshot['currency'] !== 'EUR'
            || (int) $snapshot['user_id'] !== (int) $source->user_id || (int) $snapshot['level_id'] !== (int) $source->membership_id) {
            return new WP_Error('pair_snapshot', 'Manca la copia fiscale originale e valida del checkout.');
        }
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE subscription_transaction_id = %s AND gateway = 'paypal' AND gateway_environment = 'sandbox' ORDER BY id ASC LIMIT 3", $source->subscription_transaction_id));
        if (!empty($wpdb->last_error) || !is_array($rows) || count($rows) !== 2 || (int) $rows[0]->id !== $source_id) {
            return new WP_Error('pair_ambiguous', 'La riconciliazione assistita richiede esattamente due ordini: checkout originale e ordine del primo incasso.');
        }
        $target = self::load((int) $rows[1]->id);
        if (is_wp_error($target)) return $target;
        if ($target->status !== 'success' || (int) $target->user_id !== (int) $source->user_id
            || (int) $target->membership_id !== (int) $source->membership_id
            || !preg_match('/^[A-Z0-9]{8,50}$/D', (string) $target->payment_transaction_id)
            || !is_numeric($source->total) || !is_numeric($target->total) || (float) $source->total <= 0
            || !is_finite((float) $source->total) || !is_finite((float) $target->total)
            || (int) round((float) $source->total * 100) !== (int) round((float) $target->total * 100)) {
            return new WP_Error('pair_mismatch', 'Gli ordini non hanno lo stesso cliente, piano e totale o l’incasso non è valido.');
        }
        $owners = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE payment_transaction_id = %s AND gateway_environment = 'sandbox' LIMIT 2", $target->payment_transaction_id));
        if (!empty($wpdb->last_error) || !is_array($owners) || count($owners) !== 1 || (int) $owners[0]->id !== (int) $target->id) {
            return new WP_Error('pair_ambiguous', 'L’incasso non appartiene a un unico ordine Sandbox.');
        }
        return ['source' => $source, 'target' => $target, 'snapshot' => $snapshot];
    }

    private static function candidate(int $source_id, array $context) {
        $pair = self::pair($source_id);
        if (is_wp_error($pair)) return $pair;
        foreach (['source', 'target'] as $role) if (!self::clean($pair[$role])) return new WP_Error('pair_document', 'Uno degli ordini ha già un tentativo o documento FIC. Verificare l’esito senza creare un nuovo collegamento.');
        $target_copy = get_pmpro_membership_order_meta((int) $pair['target']->id, PMPro_FIC_Billing::META, true);
        if ($target_copy) return new WP_Error('pair_snapshot', 'L’ordine con incasso possiede già dati fiscali: non verranno sovrascritti.');
        $claim = PMPro_FIC_Atomic_Store::read(PMPro_FIC_Payments::key($pair['target']));
        if (is_wp_error($claim)) return $claim;
        if ($claim !== null) return new WP_Error('pair_document', 'Incasso già prenotato per una fattura. Verificare il registro prima della riconciliazione.');
        if (!class_exists('PMPro_PayPal_API') || !method_exists('PMPro_PayPal_API', 'get_subscription_transactions')) return new WP_Error('pair_gateway', 'API PayPal del gateway non disponibili.');
        $recovery = new PMPro_FIC_PayPal_Recovery(function ($id) { return self::load((int) $id); }, function ($order) { return self::fingerprint($order); });
        $first = $recovery->inspect_first_payment($pair['source']);
        if (is_wp_error($first)) return $first;
        $sale = PMPro_FIC_Payments::verify($pair['target']);
        if (is_wp_error($sale)) return $sale;
        ksort($sale); ksort($first);
        if ($sale !== $first) return new WP_Error('pair_not_initial', 'L’incasso del secondo ordine non coincide con l’unico primo incasso PayPal verificato.');
        return ['source_order_id' => $source_id, 'target_order_id' => (int) $pair['target']->id,
            'source_fingerprint' => self::fingerprint($pair['source']), 'target_fingerprint' => self::fingerprint($pair['target']),
            'source_snapshot_hash' => hash('sha256', wp_json_encode($pair['snapshot'])),
            'company_id' => $context['company_id'], 'scope' => self::scope($context), 'payment' => $first, 'snapshot' => $pair['snapshot']];
    }

    public static function dispatch(string $action, array $input, array $context) {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        if ($action === 'inspect_initial_pair') {
            $id = $input['order_id'] ?? '';
            if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)) return new WP_Error('invalid_order', 'Inserisci l’ID numerico del checkout originale.');
            $existing = PMPro_FIC_Atomic_Store::read(self::key((int) $id));
            if (is_wp_error($existing)) return $existing;
            if ($existing !== null && (!self::valid_record($existing) || $existing['binding']['source_order_id'] !== (int) $id)) return new WP_Error('pair_storage', 'Collegamento memorizzato non valido.');
            // Analisi in sola lettura anche quando un salvataggio precedente è incompleto.
            $candidate = $existing !== null ? $existing['binding'] : self::candidate((int) $id, $context);
            if (is_array($candidate) && $candidate['scope'] !== self::scope($context)) return new WP_Error('pair_changed', 'Configurazione diversa dal collegamento confermato.');
            if (is_wp_error($candidate)) return $candidate;
            $token = wp_generate_uuid4();
            $review = ['stage' => 'pair_review', 'review_id' => $token, 'admin_id' => get_current_user_id(), 'binding' => $candidate];
            set_transient('pmpro_fic_pair_review_' . $token, $review, 10 * MINUTE_IN_SECONDS);
            return $review;
        }
        $token = $input['review_id'] ?? '';
        if ($action !== 'confirm_initial_pair' || !is_string($token) || !preg_match('/^[a-f0-9-]{36}$/D', $token)) return new WP_Error('pair_expired', 'Verifica non valida. Ripeti l’analisi della coppia.');
        $review = get_transient('pmpro_fic_pair_review_' . $token);
        if (!is_array($review) || ($review['admin_id'] ?? null) !== get_current_user_id()
            || !self::valid_record(['schema_version' => 1, 'binding' => $review['binding'] ?? null])
            || $review['binding']['scope'] !== self::scope($context)) return new WP_Error('pair_expired', 'Verifica scaduta o configurazione modificata.');
        $binding = $review['binding'];
        $existing = PMPro_FIC_Atomic_Store::read(self::key($binding['source_order_id']));
        if (is_wp_error($existing)) return $existing;
        if ($existing !== null) return self::valid_record($existing) && $existing['binding'] === $binding ? self::complete($existing, $context) : new WP_Error('pair_changed', 'Esiste un collegamento diverso. Verifica gli ordini.');
        $fresh = self::candidate($binding['source_order_id'], $context);
        if (is_wp_error($fresh)) return $fresh;
        if ($fresh !== $binding) return new WP_Error('pair_changed', 'Ordini o incasso cambiati dopo l’analisi. Ripeti la verifica.');
        // Rilettura dopo le API: nessuna modifica a righe ordini PMPro o ID pagamento.
        $pair = self::pair($binding['source_order_id']);
        if (is_wp_error($pair)) return $pair;
        if (self::fingerprint($pair['source']) !== $binding['source_fingerprint'] || self::fingerprint($pair['target']) !== $binding['target_fingerprint']
            || hash('sha256', wp_json_encode($pair['snapshot'])) !== $binding['source_snapshot_hash'] || self::scope($context) !== $binding['scope']) return new WP_Error('pair_changed', 'La coppia è cambiata durante la verifica.');
        $record = ['schema_version' => 1, 'binding' => $binding, 'confirmed_by' => get_current_user_id(), 'confirmed_at' => gmdate('Y-m-d H:i:s')];
        if (!PMPro_FIC_Atomic_Store::insert(self::key($binding['source_order_id']), $record)) return new WP_Error('pair_storage', 'Collegamento concorrente o archivio non disponibile. Ripeti l’analisi.');
        delete_transient('pmpro_fic_pair_review_' . $token);
        return self::complete($record, $context);
    }

    private static function valid_record(array $record): bool {
        $binding = $record['binding'] ?? null;
        if (($record['schema_version'] ?? 0) !== 1 || !is_array($binding)) return false;
        foreach (['source_order_id', 'target_order_id', 'company_id'] as $key) if (!is_int($binding[$key] ?? null) || $binding[$key] <= 0) return false;
        foreach (['source_fingerprint', 'target_fingerprint', 'source_snapshot_hash', 'scope'] as $key) if (!is_string($binding[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $binding[$key])) return false;
        return is_array($binding['snapshot'] ?? null) && is_array($binding['payment'] ?? null);
    }

    /** Un collegamento confermato blocca sempre il recupero ID sul checkout originale. */
    public static function source_link(int $id) {
        $record = PMPro_FIC_Atomic_Store::read(self::key($id));
        if (is_wp_error($record) || $record === null) return $record;
        if (!self::valid_record($record) || $record['binding']['source_order_id'] !== $id) return new WP_Error('pair_storage', 'Collegamento memorizzato non valido.');
        return $record['binding']['target_order_id'];
    }

    private static function copy(array $record): array {
        $copy = $record['binding']['snapshot'];
        $copy['order_id'] = $record['binding']['target_order_id'];
        $copy['origin'] = 'initial_pair';
        $copy['source_order_id'] = $record['binding']['source_order_id'];
        $copy['pair_hash'] = hash('sha256', wp_json_encode($record));
        return $copy;
    }

    /** Riprende solo la copia fiscale di un collegamento già confermato, mai la creazione di una fattura. */
    private static function complete(array $record, array $context) {
        $binding = $record['binding'] ?? [];
        if (!self::valid_record($record) || ($binding['scope'] ?? '') !== self::scope($context)) return new WP_Error('pair_changed', 'Collegamento non valido o appartenente a un’altra configurazione.');
        $pair = self::pair($binding['source_order_id']);
        if (is_wp_error($pair)) return $pair;
        if (self::fingerprint($pair['source']) !== $binding['source_fingerprint'] || self::fingerprint($pair['target']) !== $binding['target_fingerprint']
            || hash('sha256', wp_json_encode($pair['snapshot'])) !== $binding['source_snapshot_hash']) return new WP_Error('pair_changed', 'Gli ordini o la copia fiscale originale sono cambiati.');
        $copy = self::copy($record);
        $stored = get_pmpro_membership_order_meta($binding['target_order_id'], PMPro_FIC_Billing::META, true);
        if (!$stored) {
            if (!self::clean($pair['source']) || !self::clean($pair['target'])) return new WP_Error('pair_document', 'È comparso un tentativo FIC: verifica prima l’esito.');
            $claim = PMPro_FIC_Atomic_Store::read(PMPro_FIC_Payments::key($pair['target']));
            if (is_wp_error($claim)) return $claim;
            if ($claim !== null) return new WP_Error('pair_document', 'Incasso già prenotato per un documento. Verifica l’esito.');
            if (function_exists('add_pmpro_membership_order_meta')) add_pmpro_membership_order_meta($binding['target_order_id'], PMPro_FIC_Billing::META, $copy, true);
            $stored = get_pmpro_membership_order_meta($binding['target_order_id'], PMPro_FIC_Billing::META, true);
        }
        if ($stored !== $copy) return new WP_Error('pair_snapshot', 'Copia fiscale non salvata o già differente. Ripeti l’analisi per riprendere il collegamento senza sovrascrivere dati.');
        $allowed = self::guard($pair['target'], $context);
        if (is_wp_error($allowed)) return $allowed;
        return ['stage' => 'pair_linked', 'source_order_id' => $binding['source_order_id'], 'order_id' => $binding['target_order_id'], 'payment' => $binding['payment']];
    }

    /** Valida la provenienza prima di nuovi invii; i vecchi documenti non vengono modificati. */
    public static function guard($order, ?array $context = null, ?array $payment = null) {
        $snapshot = PMPro_FIC_Billing::snapshot((int) $order->id);
        if (is_wp_error($snapshot) || ($snapshot['origin'] ?? '') !== 'initial_pair') return new WP_Error('pair_snapshot', 'Copia fiscale riconciliata non disponibile.');
        $record = PMPro_FIC_Atomic_Store::read(self::key((int) ($snapshot['source_order_id'] ?? 0)));
        if (is_wp_error($record)) return $record;
        if (!is_array($record) || !self::valid_record($record) || $snapshot !== self::copy($record)) return new WP_Error('pair_snapshot', 'Provenienza della copia fiscale non verificabile.');
        $binding = $record['binding'];
        if ($payment !== null) ksort($payment);
        $source = self::load($binding['source_order_id']);
        if (is_wp_error($source)) return $source;
        if (self::fingerprint($source) !== $binding['source_fingerprint'] || self::fingerprint($order) !== $binding['target_fingerprint']
            || hash('sha256', wp_json_encode(PMPro_FIC_Billing::snapshot((int) $source->id))) !== $binding['source_snapshot_hash']
            || !self::clean($source) || ($context !== null && self::scope($context) !== $binding['scope'])
            || ($payment !== null && $payment !== $binding['payment'])) return new WP_Error('pair_changed', 'Il collegamento fra checkout, incasso e dati fiscali è cambiato. Nessun nuovo documento inviato.');
        return true;
    }
}
