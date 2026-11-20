<?php
if (!defined('ABSPATH')) exit;

/** Abilitazione per singolo abbonamento Sandbox; dati fiscali confermati e immutabili. */
final class PMPro_FIC_Renewals {
    public static function key(string $subscription): string { return 'pmpro_fic_renewal_' . hash('sha256', $subscription); }

    public static function policy($order) {
        if (!is_object($order) || ($order->gateway ?? '') !== 'paypal' || ($order->gateway_environment ?? '') !== 'sandbox'
            || !preg_match('/^I-[A-Z0-9]{5,32}$/D', (string) ($order->subscription_transaction_id ?? ''))) return null;
        $policy = PMPro_FIC_Atomic_Store::read(self::key((string) $order->subscription_transaction_id));
        if ($policy === null || is_wp_error($policy)) return $policy;
        foreach (['source_order_id', 'user_id', 'level_id', 'company_id', 'account_id', 'effective_from', 'authorized_by'] as $key) {
            if (!isset($policy[$key]) || !is_int($policy[$key]) || $policy[$key] <= 0) return new WP_Error('renewal_policy', 'Autorizzazione rinnovi non valida.');
        }
        if (($policy['schema_version'] ?? 0) !== 1 || ($policy['subscription_id'] ?? '') !== (string) $order->subscription_transaction_id
            || !is_string($policy['scope'] ?? null) || !is_string($policy['source_payment_id'] ?? null)
            || !is_array($policy['snapshot']['data'] ?? null) || is_wp_error(PMPro_FIC_Billing::validate($policy['snapshot']['data']))) {
            return new WP_Error('renewal_policy', 'Autorizzazione rinnovi incompleta o non valida.');
        }
        return $policy;
    }

    public static function authorize($order, array $config, ?array $automatic = null, ?callable $guard = null) {
        $snapshot = PMPro_FIC_Billing::snapshot((int) $order->id);
        if (is_wp_error($snapshot) || !$snapshot || !in_array($snapshot['origin'] ?? '', ['', 'initial_pair'], true)
            || (int) $snapshot['user_id'] !== (int) $order->user_id || (int) $snapshot['level_id'] !== (int) $order->membership_id
            || $snapshot['currency'] !== 'EUR') return new WP_Error('renewal_source', 'Serve un ordine iniziale con copia fiscale valida del checkout.');
        $document = get_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_Test_Orders::META, true);
        if (!is_array($document) || ($document['status'] ?? '') !== 'created' || ($document['company_id'] ?? 0) !== $config['company_id']) {
            return new WP_Error('renewal_source', 'Completa e verifica prima il documento /TEST dell’ordine iniziale nella stessa azienda.');
        }
        if ($order->gateway !== 'paypal' || $order->gateway_environment !== 'sandbox' || $order->status !== 'success') return new WP_Error('renewal_source', 'Serve un ordine iniziale PayPal Sandbox pagato.');
        $payment = PMPro_FIC_Payments::verify($order);
        if (is_wp_error($payment)) return $payment;
        if (($snapshot['origin'] ?? '') === 'initial_pair') {
            $allowed = PMPro_FIC_Initial_Pair::guard($order, null, $payment);
            if (is_wp_error($allowed)) return $allowed;
        }
        if ($automatic !== null) {
            if (!$guard || ($automatic['renewals'] ?? false) !== true || ($automatic['first'] ?? false) !== true
                || ($automatic['level_id'] ?? 0) !== (int) $order->membership_id || !is_int($automatic['authorized_by'] ?? null) || $automatic['authorized_by'] < 1
                || !isset($automatic['after_order_id']) || (int) $order->id <= $automatic['after_order_id']) return new WP_Error('plan_authorization', 'Regola del piano non valida per questo nuovo abbonamento.');
            $fresh = $guard();
            if (is_wp_error($fresh)) return $fresh;
            if (!is_object($fresh) || (string) $fresh->payment_transaction_id !== $payment['payment_id']
                || (string) $fresh->subscription_transaction_id !== $payment['subscription_id'] || $fresh->status !== 'success'
                || PMPro_FIC_Billing::snapshot((int) $order->id) !== $snapshot
                || get_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_Test_Orders::META, true) !== $document) return new WP_Error('plan_changed', 'Ordine, incasso o dati fiscali cambiati durante l’abilitazione. Verifica manualmente.');
            if (get_option(self::key((string) $order->subscription_transaction_id) . '_paused', false)) return ['state'=>'suspended', 'message'=>'Fatturazione sospesa manualmente. Nessuna riattivazione automatica.'];
        }
        $policy = ['schema_version' => 1, 'source_order_id' => (int) $order->id, 'user_id' => (int) $order->user_id, 'level_id' => (int) $order->membership_id,
            'subscription_id' => (string) $order->subscription_transaction_id, 'source_payment_id' => $payment['payment_id'],
            'scope' => $config['scope'], 'company_id' => $config['company_id'], 'account_id' => $config['account_id'],
            'effective_from' => time(), 'authorized_by' => $automatic !== null ? $automatic['authorized_by'] : get_current_user_id(), 'snapshot' => $snapshot];
        if ($automatic !== null) $policy['authorization_origin'] = ['type'=>'plan', 'revision'=>$automatic['revision'], 'saved_at'=>$automatic['saved_at']];
        $key = self::key($policy['subscription_id']);
        $existing = PMPro_FIC_Atomic_Store::read($key);
        if (is_wp_error($existing)) return $existing;
        if ($existing !== null) return new WP_Error('renewal_exists', 'Abbonamento già autorizzato. La copia fiscale non viene sovrascritta; usa Controlla autorizzazione.');
        if (!PMPro_FIC_Atomic_Store::insert($key, $policy) || PMPro_FIC_Atomic_Store::read($key) !== $policy) return new WP_Error('payment_storage', 'Impossibile registrare l’autorizzazione ai rinnovi.');
        return ['message' => 'Rinnovi autorizzati per ' . $policy['subscription_id'] . ', solo per incassi successivi a ' . gmdate('Y-m-d H:i:s', $policy['effective_from']) . ' UTC. Dati fiscali dell’ordine #' . $policy['source_order_id'] . ' confermati e bloccati.'];
    }

    /** Called only after a confirmed initial document; never rewrites an existing policy or pause flag. */
    public static function authorize_automatic($order, array $config, array $rule, callable $guard) {
        if (!(defined('DOING_CRON') && DOING_CRON) && !current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata alla coda.');
        $policy = self::policy($order);
        if (is_wp_error($policy)) return $policy;
        if (get_option(self::key((string) $order->subscription_transaction_id) . '_paused', false)) return ['state'=>'suspended', 'message'=>'Fatturazione sospesa manualmente. Nessuna riattivazione automatica.'];
        $snapshot = PMPro_FIC_Billing::snapshot((int) $order->id);
        if ($policy) {
            if ($policy['source_order_id'] !== (int) $order->id || $policy['source_payment_id'] !== (string) $order->payment_transaction_id
                || $policy['scope'] !== $config['scope'] || $policy['account_id'] !== $config['account_id'] || $policy['company_id'] !== $config['company_id']
                || $policy['snapshot'] !== $snapshot) return new WP_Error('plan_conflict', 'Esiste un’autorizzazione diversa. Nessun dato sovrascritto; controlla l’abbonamento.');
            return ['state'=>'authorized', 'message'=>'Fatturazione dei rinnovi già autorizzata. Copia fiscale conservata.'];
        }
        // An automatic enrolment must be a new checkout, never a reconstructed pair or a renewal.
        if (is_wp_error($snapshot) || !$snapshot || !empty($snapshot['origin'])) return new WP_Error('plan_source', 'L’abilitazione automatica richiede un nuovo checkout con copia fiscale valida.');
        $orders = method_exists('MemberOrder', 'get_orders') ? MemberOrder::get_orders(['subscription_transaction_id'=>(string) $order->subscription_transaction_id, 'gateway'=>'paypal', 'gateway_environment'=>'sandbox', 'limit'=>2]) : null;
        if (!is_array($orders) || count($orders) !== 1 || (int) reset($orders)->id !== (int) $order->id) return new WP_Error('plan_ambiguous', 'L’abbonamento ha più ordini o un’associazione ambigua. Controlla prima di autorizzare i rinnovi.');
        $owner = PMPro_FIC_Atomic_Store::read(PMPro_FIC_Payments::key($order));
        if (is_wp_error($owner)) return $owner;
        if (!is_array($owner) || ($owner['order_id'] ?? 0) !== (int) $order->id || ($owner['company_id'] ?? 0) !== $config['company_id']
            || ($owner['subscription_id'] ?? '') !== (string) $order->subscription_transaction_id
            || ($owner['cents'] ?? 0) !== (int) round((float) $order->total * 100)) return new WP_Error('plan_payment', 'Associazione tra incasso e documento iniziale non confermata. Verifica l’ordine.');
        $result = self::authorize($order, $config, $rule, $guard);
        return is_wp_error($result) ? $result : $result + ['state'=>'authorized'];
    }

    private static function matches($order, array $policy, array $config): bool {
        return (int) $order->id !== $policy['source_order_id'] && (int) $order->user_id === $policy['user_id']
            && (int) $order->membership_id === $policy['level_id'] && $order->gateway === 'paypal' && $order->gateway_environment === 'sandbox'
            && (string) $order->subscription_transaction_id === $policy['subscription_id']
            && $policy['scope'] === $config['scope'] && $policy['company_id'] === $config['company_id'] && $policy['account_id'] === $config['account_id'];
    }

    /** Nessuna API in questo hook: la copia fiscale proviene esclusivamente dall'autorizzazione. */
    public static function capture($order, array $config) {
        $policy = self::policy($order);
        if (is_wp_error($policy) || !$policy) return $policy;
        if (get_option(self::key($policy['subscription_id']) . '_paused', false)) return null;
        if (!self::matches($order, $policy, $config) || (string) $order->payment_transaction_id === $policy['source_payment_id']) return null;
        $key = 'pmpro_fic_renewal_snapshot_' . (int) $order->id;
        $copy = $policy['snapshot'];
        $copy['order_id'] = (int) $order->id;
        $copy['origin'] = 'renewal';
        $copy['source_order_id'] = $policy['source_order_id'];
        $copy['subscription_id'] = $policy['subscription_id'];
        $copy['policy_hash'] = hash('sha256', wp_json_encode($policy));
        $copy['captured_at'] = gmdate('Y-m-d H:i:s');
        $copy['captured_timezone'] = 'UTC';
        PMPro_FIC_Atomic_Store::insert($key, $copy);
        $saved = PMPro_FIC_Atomic_Store::read($key);
        if (is_wp_error($saved)) return $saved;
        if (!is_array($saved) || ($saved['policy_hash'] ?? '') !== $copy['policy_hash'] || ($saved['data'] ?? []) !== $copy['data']) {
            return new WP_Error('renewal_snapshot', 'Copia fiscale del rinnovo non disponibile o incoerente.');
        }
        $existing = get_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_Billing::META, true);
        if (!$existing && function_exists('add_pmpro_membership_order_meta')) add_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_Billing::META, $saved, true);
        if (get_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_Billing::META, true) !== $saved) return new WP_Error('renewal_snapshot', 'Salvataggio copia fiscale del rinnovo incompleto. Controlla l’ordine e ripeti l’acquisizione.');
        return true;
    }

    public static function guard($order, array $config, ?array $payment = null) {
        $policy = self::policy($order);
        if (is_wp_error($policy)) return $policy;
        if (!$policy || !self::matches($order, $policy, $config) || get_option(self::key($policy['subscription_id']) . '_paused', false)) {
            return new WP_Error('renewal_policy', 'Rinnovo non autorizzato o autorizzazione sospesa/modificata.');
        }
        $snapshot = PMPro_FIC_Billing::snapshot((int) $order->id);
        $canonical = PMPro_FIC_Atomic_Store::read('pmpro_fic_renewal_snapshot_' . (int) $order->id);
        if (is_wp_error($snapshot) || is_wp_error($canonical) || !$snapshot || $snapshot !== $canonical
            || ($snapshot['policy_hash'] ?? '') !== hash('sha256', wp_json_encode($policy))) return new WP_Error('renewal_snapshot', 'Copia fiscale del rinnovo modificata o non valida.');
        if ((string) $order->payment_transaction_id === $policy['source_payment_id']) return new WP_Error('payment_duplicate', 'L’incasso iniziale non è un nuovo rinnovo.');
        if ($payment !== null && (PMPro_FIC_Payments::timestamp($payment['paid_at'] ?? null) < $policy['effective_from']
            || ($payment['payment_id'] ?? '') === $policy['source_payment_id'])) return new WP_Error('renewal_historical', 'Incasso precedente all’autorizzazione dei rinnovi. Nessuna importazione automatica dello storico.');
        return true;
    }
}
