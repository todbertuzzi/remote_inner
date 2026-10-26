<?php
if (!defined('ABSPATH')) exit;

/** Prenotazioni senza upsert o cache: option_name ha un indice UNIQUE in WordPress. */
final class PMPro_FIC_Atomic_Store {
    public static function read(string $key) {
        global $wpdb;
        if (!isset($wpdb->options) || !method_exists($wpdb, 'get_var')) return new WP_Error('payment_storage', 'Archivio incassi non disponibile.');
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
        if (!empty($wpdb->last_error)) return new WP_Error('payment_storage', 'Impossibile leggere l’archivio incassi.');
        if ($raw === null) return null;
        $value = json_decode($raw, true);
        return is_array($value) ? $value : new WP_Error('payment_storage', 'Registro incassi non valido. Verifica manualmente.');
    }

    public static function insert(string $key, array $value): bool {
        global $wpdb;
        if (!isset($wpdb->options) || !method_exists($wpdb, 'query')) return false;
        // Non usare add_option: il suo ON DUPLICATE KEY UPDATE può sostituire il proprietario.
        return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key, wp_json_encode($value))) === 1;
    }

    public static function release(string $key, array $value): bool {
        global $wpdb;
        return $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $key, wp_json_encode($value))) === 1;
    }
}

/** Verifica degli incassi e vincolo persistente condiviso da coda e flusso manuale. */
final class PMPro_FIC_Payments {
    public static function timestamp($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            return $errors && ($errors['warning_count'] || $errors['error_count']) ? false : $date->getTimestamp();
        } catch (Exception $error) { return false; }
    }

    public static function verify($order) {
        if ($order->gateway !== 'paypal') return []; // Flusso manuale legacy invariato.
        if ($order->gateway_environment !== 'sandbox' || get_option('pmpro_gateway_environment', '') !== 'sandbox'
            || !class_exists('PMPro_PayPal_API') || !method_exists('PMPro_PayPal_API', 'get_access_token')) {
            return new WP_Error('payment_gateway', 'La verifica richiede il gateway PMPro PayPal configurato in Sandbox.');
        }
        $id = (string) $order->payment_transaction_id;
        if (!preg_match('/^[A-Z0-9-]{5,64}$/D', $id) || !preg_match('/^I-[A-Z0-9]{5,32}$/D', (string) $order->subscription_transaction_id)) {
            return new WP_Error('payment_identity', 'Identificativi dell’incasso o dell’abbonamento non validi.');
        }
        $token = (new PMPro_PayPal_API())->get_access_token();
        if (is_wp_error($token) || !is_string($token) || $token === '') return new WP_Error('payment_api', 'Autenticazione PayPal Sandbox non disponibile.');
        // I rinnovi Subscriptions sono sale, non capture Orders v2. Solo GET; mai catture/rimborsi.
        $response = wp_remote_get('https://api-m.sandbox.paypal.com/v1/payments/sale/' . rawurlencode($id), [
            'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 131072,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']]);
        if (is_wp_error($response)) return new WP_Error('payment_api', 'Lettura incasso PayPal non disponibile.');
        $status = (int) wp_remote_retrieve_response_code($response);
        if (in_array($status, [404, 429, 500, 502, 503, 504], true)) return new WP_Error('payment_api', 'Lettura incasso PayPal temporaneamente non disponibile (HTTP ' . $status . ').');
        if ($status !== 200) return new WP_Error('payment_access', 'Verifica incasso PayPal rifiutata (HTTP ' . $status . ').');
        $sale = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($sale) || !isset($sale['id'], $sale['state'], $sale['billing_agreement_id'], $sale['amount'], $sale['create_time'])) {
            return new WP_Error('payment_incomplete', 'Risposta PayPal incompleta. Nessun documento inviato.');
        }
        if ($sale['id'] !== $id || $sale['billing_agreement_id'] !== (string) $order->subscription_transaction_id) return new WP_Error('payment_identity', 'L’incasso PayPal non corrisponde all’abbonamento dell’ordine.');
        if ($sale['state'] === 'pending') return new WP_Error('payment_pending', 'Incasso ancora in attesa su PayPal.');
        if ($sale['state'] !== 'completed' || !empty($sale['reason_code']) || !empty($sale['payment_hold_status'])) {
            return new WP_Error('payment_state', 'Incasso PayPal non completato, rimborsato o soggetto a verifica.');
        }
        $amount = $sale['amount']['total'] ?? null;
        $currency = $sale['amount']['currency'] ?? null;
        // Struttura mancante o illeggibile = risposta incompleta (da ripetere); solo valori ben formati ma diversi sono un mismatch.
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', $amount, $parts) || !is_string($currency) || $currency === '') {
            return new WP_Error('payment_incomplete', 'Importo o valuta PayPal mancanti o illeggibili. Nessun documento inviato.');
        }
        if ($currency !== 'EUR') return new WP_Error('payment_amount', 'Importo o valuta PayPal non validi per questo collaudo.');
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($cents <= 0 || $cents !== (int) round((float) $order->total * 100)) return new WP_Error('payment_amount', 'Importo PayPal diverso dal totale dell’ordine.');
        $at = self::timestamp($sale['create_time']);
        if ($at === false) return new WP_Error('payment_incomplete', 'Data dell’incasso PayPal mancante o illeggibile. Nessun documento inviato.');
        if ($at > time() + 300) return new WP_Error('payment_time', 'Data dell’incasso PayPal non valida.');
        return ['payment_id' => $id, 'subscription_id' => $sale['billing_agreement_id'], 'currency' => 'EUR',
            'cents' => $cents, 'status' => 'COMPLETED', 'paid_at' => gmdate('Y-m-d\TH:i:s\Z', $at)];
    }

    public static function key($order): string {
        // Indipendente da azienda FIC/credenziali: cambiarle non permette di rifatturare lo stesso incasso.
        return 'pmpro_fic_payment_' . hash('sha256', 'paypal:sandbox:' . (string) $order->payment_transaction_id);
    }

    public static function reserve($order, int $company_id) {
        if (!method_exists('MemberOrder', 'get_orders')) return new WP_Error('payment_identity', 'Impossibile verificare gli ordini dell’incasso.');
        $others = MemberOrder::get_orders(['payment_transaction_id' => (string) $order->payment_transaction_id, 'gateway_environment' => 'sandbox', 'limit' => 2]);
        if (!is_array($others) || count($others) !== 1 || (int) reset($others)->id !== (int) $order->id) {
            return new WP_Error('payment_duplicate', 'Incasso associato a più ordini o non verificabile. Nessun nuovo documento inviato.');
        }
        $key = self::key($order);
        $owner = ['order_id' => (int) $order->id, 'company_id' => $company_id, 'subscription_id' => (string) $order->subscription_transaction_id,
            'cents' => (int) round((float) $order->total * 100)];
        PMPro_FIC_Atomic_Store::insert($key, $owner);
        $saved = PMPro_FIC_Atomic_Store::read($key);
        if (is_wp_error($saved)) return $saved;
        if ($saved !== $owner) return new WP_Error('payment_duplicate', 'Incasso già riservato a un altro ordine o a una diversa configurazione.');
        $attempt = ['token' => wp_generate_uuid4(), 'order_id' => (int) $order->id];
        if (!PMPro_FIC_Atomic_Store::insert($key . '_attempt', $attempt)) return new WP_Error('payment_locked', 'Incasso già in elaborazione, fatturato o con esito incerto. Verifica l’esito remoto.');
        return ['key' => $key . '_attempt', 'value' => $attempt];
    }

    /** Solo prima di un POST o dopo un rifiuto certo. Il proprietario dell'incasso resta persistente. */
    public static function release(array $reservation): void {
        PMPro_FIC_Atomic_Store::release($reservation['key'], $reservation['value']);
    }
}
