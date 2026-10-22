<?php
if (!defined('ABSPATH')) exit;

/** Documenti Sandbox: totali, journal e lock condivisi tra flusso manuale e coda. */
final class PMPro_FIC_Test_Orders {
    const META = '_pmpro_fic_test_document';
    const ACTIONS = ['load_order', 'preview_order', 'create_order', 'recover_order', 'inspect_paypal_payment', 'confirm_paypal_payment', 'inspect_initial_pair', 'confirm_initial_pair'];
    private $context;
    private $edit = [];
    private $last_order_id = '';
    private $missing_payment_order = null;

    public function __construct(callable $context) { $this->context = $context; }

    private function text(array $input, string $key): string {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
    }

    private function scope(array $context): string {
        $parts = [$context['vat'], $context['description']];
        if (!empty($context['plan_descriptions'])) $parts[] = $context['plan_descriptions'];
        return hash('sha256', $context['company_id'] . ':' . $context['token'] . ':' . wp_json_encode($parts));
    }

    private function journal_key(int $order_id): string { return 'pmpro_fic_test_order_' . $order_id; }

    private function journal(int $order_id): array {
        $value = get_option($this->journal_key($order_id), []);
        if (is_array($value) && $value) return $value;
        $meta = function_exists('get_pmpro_membership_order_meta') ? get_pmpro_membership_order_meta($order_id, self::META, true) : [];
        return is_array($meta) && !empty($meta['document_id']) ? array_merge($meta, ['order_id' => $order_id]) : [];
    }

    private function save_journal(int $order_id, array $value): bool {
        update_option($this->journal_key($order_id), $value, false);
        return $this->journal($order_id) === $value;
    }

    private function load_order(string $id, bool $require_payment = true) {
        if (!class_exists('MemberOrder') || !function_exists('get_pmpro_membership_order_meta') || !function_exists('update_pmpro_membership_order_meta')) {
            return new WP_Error('pmpro_missing', 'Attiva una versione aggiornata di Paid Memberships Pro per leggere gli ordini.');
        }
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id)) return new WP_Error('invalid_order', 'Inserisci l’ID numerico dell’ordine PMPro.');
        $order = new MemberOrder((int) $id);
        if ((int) $order->id !== (int) $id) return new WP_Error('order_missing', 'Ordine PMPro non trovato.');
        if (strtolower((string) $order->gateway_environment) !== 'sandbox'
            || !in_array(strtolower((string) $order->gateway), ['paypal', 'paypalexpress', 'paypalstandard'], true)) {
            return new WP_Error('sandbox_required', 'Il test accetta soltanto ordini PayPal registrati in ambiente Sandbox.');
        }
        if ((string) $order->status !== 'success') return new WP_Error('not_paid', 'L’ordine deve risultare pagato (success). Attendi la conferma PayPal e poi ricaricalo.');
        global $pmpro_currency;
        $currency = $pmpro_currency ?: get_option('pmpro_currency', '');
        if (strtoupper((string) $currency) !== 'EUR') return new WP_Error('currency', 'Questo collaudo supporta soltanto ordini in euro. La valuta corrente di PMPro deve essere EUR.');
        if (!is_numeric($order->total) || !is_finite((float) $order->total) || (float) $order->total <= 0 || (float) $order->total > 9999999.99) {
            return new WP_Error('amount', 'Il totale dell’ordine deve essere positivo e valido. Gli ordini gratuiti sono esclusi.');
        }
        if (trim((string) $order->payment_transaction_id) === '') {
            if (strtolower((string) $order->gateway) === 'paypal') $this->missing_payment_order = $order;
            if ($require_payment) return new WP_Error('transaction_missing', 'Nell’ordine manca l’ID della transazione PayPal. Verifica la notifica del pagamento Sandbox.');
        }
        return $order;
    }

    private function order_fingerprint($order): string {
        return hash('sha256', wp_json_encode([(int) $order->id, (int) $order->user_id, (int) $order->membership_id,
            (string) $order->code, (string) $order->status, (string) $order->gateway, (string) $order->gateway_environment,
            (string) $order->payment_transaction_id, (string) ($order->subscription_transaction_id ?? ''), number_format((float) $order->total, 2, '.', ''),
            get_pmpro_membership_order_meta((int) $order->id, PMPro_FIC_Billing::META, true), $this->payment_recovery((int) $order->id)]));
    }

    private function payment_recovery(int $order_id) {
        $saved = get_pmpro_membership_order_meta($order_id, PMPro_FIC_PayPal_Recovery::META, true);
        return $saved ?: get_option('pmpro_fic_paypal_recovery_' . $order_id, []);
    }

    private function paid_date($order) {
        $recovery = $this->payment_recovery((int) $order->id);
        if (!$recovery) return is_numeric($order->timestamp) && (int) $order->timestamp > 0
            ? wp_date('Y-m-d', (int) $order->timestamp) : current_time('Y-m-d');
        $payment = is_array($recovery) ? ($recovery['payment'] ?? []) : [];
        if (!is_array($payment) || ($recovery['status'] ?? '') !== 'updated' || (int) ($recovery['order_id'] ?? 0) !== (int) $order->id
            || ($payment['payment_id'] ?? '') !== (string) $order->payment_transaction_id
            || ($payment['subscription_id'] ?? '') !== (string) $order->subscription_transaction_id
            || ($payment['currency'] ?? '') !== 'EUR' || ($payment['status'] ?? '') !== 'COMPLETED'
            || ($payment['cents'] ?? null) !== (int) round((float) $order->total * 100)
            || !is_string($payment['paid_at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $payment['paid_at'])) {
            return new WP_Error('payment_recovery_data', 'I dati del recupero PayPal non corrispondono all’ordine. Verifica il pagamento prima di preparare il documento.');
        }
        try {
            $date = new DateTimeImmutable($payment['paid_at']);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) return new WP_Error('payment_recovery_data', 'La data del pagamento recuperato non è valida.');
            return wp_date('Y-m-d', $date->getTimestamp());
        } catch (Exception $e) { return new WP_Error('payment_recovery_data', 'La data del pagamento recuperato non è valida.'); }
    }

    private function billing_snapshot($order) {
        $saved = PMPro_FIC_Billing::snapshot((int) $order->id);
        if (is_wp_error($saved) || !$saved) return $saved;
        if ((int) $saved['user_id'] !== (int) $order->user_id || (int) $saved['level_id'] !== (int) $order->membership_id || $saved['currency'] !== 'EUR') {
            return new WP_Error('billing_snapshot', 'La copia dei dati fiscali non corrisponde all’utente, al piano o alla valuta dell’ordine.');
        }
        return $saved;
    }

    /** Restituisce solo il JSON atteso. Un errore di POST può aver lasciato un documento remoto. */
    private function request(string $method, string $path, array $context, array $payload = []) {
        $args = ['timeout' => 25, 'redirection' => 0, 'limit_response_size' => 2097152,
            'headers' => ['Authorization' => 'Bearer ' . $context['token'], 'Accept' => 'application/json']];
        if ($method === 'POST') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($payload);
            $response = wp_remote_post('https://api-v2.fattureincloud.it' . $path, $args);
        } else {
            $response = wp_remote_get('https://api-v2.fattureincloud.it' . $path, $args);
        }
        if (is_wp_error($response)) return new WP_Error('unknown_result', 'Connessione interrotta: l’esito remoto non è confermato.');
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $rejected = in_array($status, [400, 401, 403, 404, 422, 429], true);
            $message = 'Fatture in Cloud ha risposto HTTP ' . $status . '.';
            if (in_array($status, [400, 422], true)) {
                $details = $this->validation_details(wp_remote_retrieve_body($response), $context['token']);
                $message .= $details !== '' ? ' Dettaglio: ' . $details : ' La risposta non contiene dettagli di validazione leggibili.';
            } elseif ($status === 401 || $status === 403) {
                $message .= ' Controlla token, azienda autorizzata e permessi di scrittura.';
            } else {
                $message .= ' Controlla la configurazione o riprova più tardi.';
            }
            return new WP_Error($rejected ? 'rejected' : 'unknown_result', $message);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) return new WP_Error('unknown_result', 'Risposta remota incompleta: verifica l’esito prima di riprovare.');
        return $body;
    }

    /** Estrae solo messaggio e campi di validazione; non mostra né salva il body remoto. */
    private function validation_details(string $body, string $token): string {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
        $clean = function (string $message) use ($token): string {
            if ($token !== '') {
                $message = str_replace([$token, rawurlencode($token), urlencode($token)], '[token oscurato]', $message);
            }
            $message = preg_replace('/Bearer\s+[^\s"<>]+/i', 'Bearer [oscurato]', $message);
            $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email oscurata]', $message);
            $message = sanitize_text_field($message);
            return function_exists('mb_substr') ? mb_substr($message, 0, 600) : substr($message, 0, 600);
        };
        $parts = [];
        if (isset($error['message']) && is_string($error['message'])) $parts[] = $clean($error['message']);
        $walk = function ($value, string $path = '', int $depth = 0) use (&$walk, &$parts, $clean): void {
            if (count($parts) >= 10 || $depth > 3) return;
            if (is_string($value)) {
                $parts[] = $clean(($path !== '' ? $path . ': ' : '') . $value);
            } elseif (is_array($value)) {
                foreach ($value as $key => $item) {
                    if (count($parts) >= 10) break;
                    $next_path = is_string($key) ? ($path !== '' ? $path . '.' : '') . $key : $path;
                    $walk($item, $next_path, $depth + 1);
                }
            }
        };
        $walk($error['validation_result'] ?? []);
        $extra = isset($decoded['extra']['totals']) && is_array($decoded['extra']['totals']) ? $decoded['extra']['totals']
            : (isset($error['extra']['totals']) && is_array($error['extra']['totals']) ? $error['extra']['totals'] : []);
        foreach (['amount_net', 'amount_vat', 'amount_gross', 'amount_due', 'payments_sum', 'amount_payments'] as $field) {
            if (isset($extra[$field]) && is_numeric($extra[$field]) && is_finite((float) $extra[$field])) {
                $parts[] = $field . ': ' . number_format((float) $extra[$field], 2, ',', '.');
            }
        }
        $message = implode(' · ', array_filter($parts, function ($value) { return $value !== ''; }));
        return function_exists('mb_substr') ? mb_substr($message, 0, 3000) : substr($message, 0, 3000);
    }

    private function existing_result(array $journal) {
        if (in_array($journal['status'] ?? '', ['created', 'created_mismatch'], true)) {
            $metadata = $this->sync_metadata($journal);
            return is_wp_error($metadata) ? $metadata : ['stage' => 'created', 'document' => $journal];
        }
        return new WP_Error('uncertain', 'Esiste una richiesta con esito non confermato. Usa “Verifica esito remoto” prima di qualsiasi nuovo tentativo.');
    }

    private function accounts_key(array $context): string { return 'pmpro_fic_accounts_' . $this->scope($context); }

    public function payment_accounts(array $context) {
        $response = $this->request('GET', '/c/' . $context['company_id'] . '/info/payment_accounts', $context);
        if (is_wp_error($response)) return $response;
        $accounts = [];
        foreach ($response['data'] as $account) {
            if (is_array($account) && isset($account['id'], $account['name']) && is_int($account['id']) && $account['id'] > 0) {
                $accounts[] = ['id' => $account['id'], 'name' => sanitize_text_field((string) $account['name'])];
            }
        }
        if (!$accounts) return new WP_Error('no_accounts', 'Configura un conto d’incasso nell’azienda Fatture in Cloud di prova e poi ricarica.');
        set_transient($this->accounts_key($context), $accounts, HOUR_IN_SECONDS);
        return $accounts;
    }

    /** Primi acquisti o rinnovi con autorizzazione e copia fiscale propria. */
    public function automation_order(int $id, bool $require_payment = true) {
        $order = $this->load_order((string) $id, $require_payment);
        if (is_wp_error($order)) return $order;
        if ((string) $order->gateway !== 'paypal' || !preg_match('/^I-[A-Z0-9]{5,32}$/D', (string) $order->subscription_transaction_id)) {
            return new WP_Error('auto_gateway', 'L’automazione richiede un primo acquisto con il nuovo gateway PayPal e un abbonamento valido.');
        }
        $snapshot = $this->billing_snapshot($order);
        if (is_wp_error($snapshot)) return $snapshot;
        if (!$snapshot) return new WP_Error('auto_snapshot', 'Manca la copia fiscale del checkout originale.');
        if (!method_exists('MemberOrder', 'get_orders')) return new WP_Error('pmpro_missing', 'Aggiorna PMPro per verificare gli ordini dell’abbonamento.');
        $orders = MemberOrder::get_orders(['subscription_transaction_id' => (string) $order->subscription_transaction_id,
            'gateway' => 'paypal', 'gateway_environment' => 'sandbox', 'limit' => 2]);
        if (($snapshot['origin'] ?? '') === 'renewal') {
            $allowed = PMPro_FIC_Renewals::guard($order, get_option(PMPro_FIC_Automation::CONFIG, []));
            if (is_wp_error($allowed)) return $allowed;
        } elseif (($snapshot['origin'] ?? '') === 'initial_pair') {
            $allowed = PMPro_FIC_Initial_Pair::guard($order);
            if (is_wp_error($allowed)) return $allowed;
        } elseif (!is_array($orders) || count($orders) !== 1 || (int) reset($orders)->id !== $id) {
            return new WP_Error('auto_first_order', 'L’abbonamento non ha un unico ordine iniziale. Verifica manualmente prima di creare il documento.');
        }
        if ($require_payment) {
            $payments = MemberOrder::get_orders(['payment_transaction_id' => (string) $order->payment_transaction_id,
                'gateway_environment' => 'sandbox', 'limit' => 2]);
            if (!is_array($payments) || count($payments) !== 1 || (int) reset($payments)->id !== $id) {
                return new WP_Error('auto_payment_duplicate', 'L’ID pagamento non è associato a un unico ordine Sandbox. Verifica manualmente.');
            }
        }
        return $order;
    }

    public function automate(int $order_id, array $context, int $account_id, callable $guard) {
        if (!(defined('DOING_CRON') && DOING_CRON) && !current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata alla coda o agli amministratori.');
        $allowed = $guard();
        if (is_wp_error($allowed)) return $allowed;
        $order = $this->automation_order($order_id);
        if (is_wp_error($order)) return $order;
        $journal = $this->journal($order_id);
        if ($journal && ($journal['status'] ?? '') !== 'rejected') return $this->existing_result($journal);
        $accounts = $this->payment_accounts($context);
        if (is_wp_error($accounts)) return $accounts;
        $preview = $this->preview(['account_id' => (string) $account_id, 'confirm_test' => '1'], $context, $order, $this->billing_snapshot($order), false);
        if (is_wp_error($preview)) return $preview;
        return $this->create_preview($preview, $context, $guard);
    }

    public function reconcile_payment(int $order_id, callable $guard) {
        $recovery = new PMPro_FIC_PayPal_Recovery(function ($id) { return $this->load_order($id, false); },
            function ($order) { return $this->order_fingerprint($order); });
        return $recovery->automatic($order_id, $guard);
    }

    /** Il metodo totals calcola soltanto: non crea documenti o movimenti d'incasso. */
    private function checked_totals(array $payload, array $context, float $expected_gross) {
        $calculation = ['data' => $payload['data']];
        unset($calculation['data']['payments_list']);
        $response = $this->request('POST', '/c/' . $context['company_id'] . '/issued_documents/totals', $context, $calculation);
        if (is_wp_error($response)) {
            return new WP_Error('totals_error', 'Calcolo remoto non riuscito. Nessun documento creato. ' . $response->get_error_message());
        }
        $totals = [];
        foreach (['amount_net', 'amount_vat', 'amount_gross', 'amount_due'] as $field) {
            if (!isset($response['data'][$field]) || !is_numeric($response['data'][$field]) || !is_finite((float) $response['data'][$field])
                || (float) $response['data'][$field] < 0 || (float) $response['data'][$field] > 9999999.99) {
                return new WP_Error('invalid_totals', 'Fatture in Cloud ha restituito totali incompleti o non validi. Nessun documento creato: ripeti l’anteprima.');
            }
            $totals[$field] = round((float) $response['data'][$field], 2);
        }
        $expected_cents = (int) round($expected_gross * 100);
        if ((int) round($totals['amount_gross'] * 100) !== $expected_cents || (int) round($totals['amount_due'] * 100) !== $expected_cents) {
            return new WP_Error('totals_mismatch', 'Creazione bloccata: totale ordine PMPro ' . number_format($expected_gross, 2, ',', '.')
                . ' €, totale Fatture in Cloud ' . number_format($totals['amount_gross'], 2, ',', '.')
                . ' €, da pagare ' . number_format($totals['amount_due'], 2, ',', '.') . ' €. Controlla aliquota e impostazioni fiscali dell’azienda di prova. Nessun documento creato.');
        }
        if ((int) round(($totals['amount_net'] + $totals['amount_vat']) * 100) !== $expected_cents) {
            return new WP_Error('totals_components', 'Imponibile e IVA remoti non corrispondono al totale dell’ordine. Il test a una sola voce non gestisce questi aggiustamenti. Nessun documento creato.');
        }
        return $totals;
    }

    public function dispatch(string $action, array $input) {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        if (!class_exists('MemberOrder') || !function_exists('get_pmpro_membership_order_meta') || !function_exists('update_pmpro_membership_order_meta')) return new WP_Error('pmpro_missing', 'Attiva una versione aggiornata di Paid Memberships Pro.');
        $this->last_order_id = $this->text($input, 'order_id');
        $context = call_user_func($this->context);
        if (is_wp_error($context)) return $context;
        if (in_array($action, PMPro_FIC_Initial_Pair::ACTIONS, true)) {
            $result = PMPro_FIC_Initial_Pair::dispatch($action, $input, $context);
            if (is_array($result) && isset($result['order_id'])) $this->last_order_id = (string) $result['order_id'];
            return $result;
        }
        if (in_array($action, ['inspect_paypal_payment', 'confirm_paypal_payment'], true)) {
            $recovery = new PMPro_FIC_PayPal_Recovery(function ($id) { return $this->load_order($id, false); },
                function ($order) { return $this->order_fingerprint($order); });
            $result = $action === 'inspect_paypal_payment' ? $recovery->prepare($this->text($input, 'order_id'))
                : $recovery->confirm($this->text($input, 'recovery_id'));
            if (is_array($result)) $this->last_order_id = (string) $result['order_id'];
            return $result;
        }
        if ($action === 'create_order') return $this->create($input, $context);
        if ($action === 'recover_order') return $this->recover($input, $context);
        $order = $this->load_order($this->text($input, 'order_id'));
        if (is_wp_error($order)) return $order;
        $snapshot = $this->billing_snapshot($order);
        if (is_wp_error($snapshot)) return $snapshot;
        $journal = $this->journal((int) $order->id);
        if ($journal && ($journal['status'] ?? '') !== 'rejected') return $this->existing_result($journal);
        if ($action === 'load_order') {
            $accounts = $this->payment_accounts($context);
            if (is_wp_error($accounts)) return $accounts;
            $billing = is_object($order->billing) ? $order->billing : (object) [];
            $user = get_userdata((int) $order->user_id);
            $customer = ['name' => (string) ($billing->name ?? ''), 'email' => $user ? $user->user_email : '',
                'street' => (string) ($billing->street ?? ''), 'zip' => (string) ($billing->zip ?? ''),
                'city' => (string) ($billing->city ?? ''), 'province' => (string) ($billing->state ?? ''),
                'tax_code' => '', 'vat_number' => '', 'recipient_code' => '', 'pec' => ''];
            if ($snapshot) foreach (array_keys($customer) as $key) $customer[$key] = $snapshot['data'][$key];
            return ['stage' => 'edit', 'order' => $order, 'accounts' => $accounts, 'company_name' => $context['company_name'], 'billing_locked' => (bool) $snapshot,
                'last_error' => ($journal['status'] ?? '') === 'rejected' ? (string) ($journal['last_error'] ?? '') : '',
                'customer' => $customer];
        }
        if ($action !== 'preview_order') return new WP_Error('invalid_action', 'Operazione non disponibile.');
        return $this->preview($input, $context, $order, $snapshot);
    }

    private function preview(array $input, array $context, $order, $snapshot, bool $persist = true) {
        if ($snapshot) $input = array_merge($input, $snapshot['data']); // La richiesta admin non può alterare la copia fiscale.
        $cached_accounts = get_transient($this->accounts_key($context));
        $this->edit = ['stage' => 'edit', 'order' => $order, 'company_name' => $context['company_name'],
            'accounts' => is_array($cached_accounts) ? $cached_accounts : [], 'customer' => [], 'billing_locked' => (bool) $snapshot, 'account_id' => $this->text($input, 'account_id')];
        foreach (['name', 'email', 'street', 'zip', 'city', 'province', 'tax_code', 'vat_number', 'recipient_code', 'pec'] as $field) $this->edit['customer'][$field] = sanitize_text_field($this->text($input, $field));
        if ($this->text($input, 'confirm_test') !== '1') return new WP_Error('confirm_test', 'Conferma che azienda e dati cliente sono destinati al collaudo.');
        $accounts = get_transient($this->accounts_key($context));
        $account_id = 0;
        foreach (is_array($accounts) ? $accounts : [] as $account) {
            if ((string) $account['id'] === $this->text($input, 'account_id')) $account_id = $account['id'];
        }
        if (!$account_id) return new WP_Error('account_required', 'Ricarica l’ordine e seleziona un conto d’incasso disponibile.');
        $payment = PMPro_FIC_Payments::verify($order);
        if (is_wp_error($payment)) return $payment;
        if (($snapshot['origin'] ?? '') === 'renewal') {
            $allowed = PMPro_FIC_Renewals::guard($order, get_option(PMPro_FIC_Automation::CONFIG, []), $payment);
            if (is_wp_error($allowed)) return $allowed;
        }
        if (($snapshot['origin'] ?? '') === 'initial_pair') {
            $allowed = PMPro_FIC_Initial_Pair::guard($order, $context, $payment);
            if (is_wp_error($allowed)) return $allowed;
        }
        $customer = [];
        foreach (['name', 'street', 'zip', 'city', 'province', 'tax_code', 'vat_number', 'recipient_code', 'pec'] as $field) $customer[$field] = sanitize_text_field($this->text($input, $field));
        $customer['email'] = sanitize_email($this->text($input, 'email'));
        if (!$customer['name'] || !$customer['street'] || !$customer['city'] || !preg_match('/^[0-9]{5}$/D', $customer['zip'])
            || !preg_match('/^[a-zA-Z]{2}$/D', $customer['province']) || !is_email($customer['email'])) {
            return new WP_Error('customer_required', 'Completa nome, email, indirizzo, CAP italiano di 5 cifre, città e provincia di 2 lettere, poi rigenera l’anteprima.');
        }
        if (($customer['vat_number'] !== '' && !preg_match('/^[0-9]{11}$/D', $customer['vat_number']))
            || ($customer['tax_code'] !== '' && !preg_match('/^(?:[A-Z0-9]{16}|[0-9]{11})$/iD', $customer['tax_code']))) {
            return new WP_Error('tax_data', 'Controlla il formato di partita IVA e codice fiscale. Per questo test non elettronico sono facoltativi.');
        }
        if (($customer['recipient_code'] !== '' && !preg_match('/^[A-Z0-9]{7}$/iD', $customer['recipient_code']))
            || ($customer['pec'] !== '' && !is_email($customer['pec']))) return new WP_Error('billing_delivery', 'Controlla codice destinatario e PEC.');
        $gross = round((float) $order->total, 2);
        $marker = 'innerplay-test-' . wp_generate_uuid4();
        $date = current_time('Y-m-d');
        $paid_date = $this->paid_date($order);
        if (is_wp_error($paid_date)) return $paid_date;
        if ($payment) $paid_date = wp_date('Y-m-d', PMPro_FIC_Payments::timestamp($payment['paid_at']));
        $description = $context['plan_descriptions'][(int) $order->membership_id] ?? ($context['description'] . ' — livello #' . (int) $order->membership_id);
        $payload = ['data' => [
            'type' => 'invoice', 'e_invoice' => false, 'date' => $date, 'numeration' => '/TEST',
            'currency' => ['id' => 'EUR'], 'use_gross_prices' => true,
            'rivalsa' => 0, 'cassa' => 0, 'cassa2' => 0, 'withholding_tax' => 0, 'other_withholding_tax' => 0,
            'stamp_duty' => 0, 'amount_due_discount' => 0, 'use_split_payment' => false,
            'entity' => array_filter(['name' => $customer['name'], 'email' => $customer['email'], 'country' => 'Italia',
                'address_street' => $customer['street'], 'address_postal_code' => $customer['zip'], 'address_city' => $customer['city'],
                'address_province' => strtoupper($customer['province']), 'tax_code' => strtoupper($customer['tax_code']), 'vat_number' => $customer['vat_number'],
                'ei_code' => strtoupper($customer['recipient_code']) ?: '0000000', 'certified_email' => $customer['pec']], function ($v) { return $v !== ''; }),
            'subject' => $description, 'visible_subject' => 'TEST — ordine PMPro #' . $order->id,
            'notes' => 'Documento di collaudo PayPal Sandbox. Ordine PMPro #' . $order->id . '. Riferimento: ' . $marker,
            'items_list' => [['name' => $description, 'qty' => 1,
                'gross_price' => $gross, 'discount' => 0, 'apply_withholding_taxes' => false, 'vat' => ['id' => $context['vat']['id']]]],
            'payments_list' => [['amount' => $gross, 'due_date' => $paid_date, 'paid_date' => $paid_date, 'status' => 'paid', 'payment_account' => ['id' => $account_id]]],
        ]];
        $totals = $this->checked_totals($payload, $context, $gross);
        if (is_wp_error($totals)) return $totals;
        if (!$persist) {
            $expected_net = (int) round(round($gross * 100) / (1 + $context['vat']['value'] / 100));
            if ((int) round($totals['amount_net'] * 100) !== $expected_net
                || (int) round($totals['amount_vat'] * 100) !== (int) round($gross * 100) - $expected_net) {
                return new WP_Error('auto_vat_mismatch', 'Imponibile o IVA remoti non corrispondono all’aliquota autorizzata. Verifica azienda e aliquota prima di riprovare.');
            }
        }
        $payload['data']['payments_list'][0]['amount'] = $totals['amount_due'];
        // Allinea solo dopo aver provato l'identità, al centesimo, con il totale pagato PMPro.
        $payload['options'] = ['fix_payments' => true];
        $preview_id = wp_generate_uuid4();
        $preview = ['stage' => 'preview', 'preview_id' => $preview_id, 'admin_id' => get_current_user_id(), 'order_id' => (int) $order->id,
            'order_code' => (string) $order->code, 'order_fingerprint' => $this->order_fingerprint($order), 'scope' => $this->scope($context),
            'company_id' => $context['company_id'], 'company_name' => $context['company_name'], 'gross' => $totals['amount_gross'], 'net' => $totals['amount_net'],
            'vat' => $totals['amount_vat'], 'totals_verified' => true, 'preview_version' => 5, 'payment_verified' => $payment, 'marker' => $marker, 'payload' => $payload];
        if ($persist) set_transient('pmpro_fic_preview_' . $preview_id, $preview, 15 * MINUTE_IN_SECONDS);
        return $preview;
    }

    private function create(array $input, array $context) {
        $id = $this->text($input, 'preview_id');
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) return new WP_Error('preview_missing', 'Rigenera l’anteprima prima della creazione.');
        $preview = get_transient('pmpro_fic_preview_' . $id);
        if (!is_array($preview) || $preview['admin_id'] !== get_current_user_id() || $preview['scope'] !== $this->scope($context)
            || empty($preview['totals_verified']) || ($preview['preview_version'] ?? 0) !== 5) {
            return new WP_Error('preview_expired', 'Anteprima scaduta o configurazione modificata. Ricarica l’ordine e rigenera l’anteprima.');
        }
        return $this->create_preview($preview, $context);
    }

    private function create_preview(array $preview, array $context, ?callable $guard = null) {
        $id = $preview['preview_id'];
        $this->last_order_id = (string) $preview['order_id'];
        $order = $this->load_order((string) $preview['order_id']);
        if (is_wp_error($order)) return $order;
        if ($this->order_fingerprint($order) !== $preview['order_fingerprint']) return new WP_Error('order_changed', 'L’ordine è cambiato dopo l’anteprima. Ricaricalo prima di procedere.');
        $journal = $this->journal($preview['order_id']);
        if ($journal && ($journal['status'] ?? '') !== 'rejected') return $this->existing_result($journal);
        $lock_key = 'pmpro_fic_lock_' . $preview['order_id'];
        if (!add_option($lock_key, $id, '', false)) return new WP_Error('locked', 'Una creazione è già in corso o non ha un esito confermato. Usa la verifica dell’esito remoto.');
        $journal = $this->journal($preview['order_id']); // Ricontrollo dopo il lock atomico.
        if ($journal && ($journal['status'] ?? '') !== 'rejected') {
            delete_option($lock_key);
            return $this->existing_result($journal);
        }
        $fresh = $this->load_order((string) $preview['order_id']);
        $allowed = $guard ? $guard() : true;
        if (is_wp_error($fresh) || is_wp_error($allowed) || $this->order_fingerprint($fresh) !== $preview['order_fingerprint']) {
            delete_option($lock_key);
            return is_wp_error($fresh) ? $fresh : (is_wp_error($allowed) ? $allowed : new WP_Error('order_changed', 'L’ordine è cambiato prima dell’invio. Nessun documento creato.'));
        }
        $payment = PMPro_FIC_Payments::verify($fresh);
        $snapshot = $this->billing_snapshot($fresh);
        if (is_wp_error($snapshot)) { delete_option($lock_key); return $snapshot; }
        $policy = !is_wp_error($payment) && ($snapshot['origin'] ?? '') === 'renewal'
            ? PMPro_FIC_Renewals::guard($fresh, get_option(PMPro_FIC_Automation::CONFIG, []), $payment) : true;
        if (!is_wp_error($payment) && ($snapshot['origin'] ?? '') === 'initial_pair') $policy = PMPro_FIC_Initial_Pair::guard($fresh, $context, $payment);
        if (is_wp_error($payment) || is_wp_error($policy) || $payment !== ($preview['payment_verified'] ?? null)) {
            delete_option($lock_key);
            return is_wp_error($payment) ? $payment : (is_wp_error($policy) ? $policy : new WP_Error('payment_changed', 'L’incasso è cambiato dopo l’anteprima. Ripeti la verifica.'));
        }
        $reservation = PMPro_FIC_Payments::reserve($fresh, $context['company_id']);
        if (is_wp_error($reservation)) { delete_option($lock_key); return $reservation; }
        $journal = ['status' => 'pending', 'company_id' => $context['company_id'], 'order_id' => $preview['order_id'],
            'marker' => $preview['marker'], 'gross' => $preview['gross'], 'requested_at' => time(), 'payload' => $preview['payload'],
            'payment_verified' => $payment, 'payment_reservation' => $reservation];
        if (!$this->save_journal($preview['order_id'], $journal)) {
            PMPro_FIC_Payments::release($reservation);
            delete_option($lock_key);
            return new WP_Error('storage', 'Impossibile registrare il tentativo in WordPress. Nessuna richiesta inviata.');
        }
        $fresh = $this->load_order((string) $preview['order_id']);
        $allowed = $guard ? $guard() : true;
        if (!is_wp_error($fresh) && !is_wp_error($allowed) && ($snapshot['origin'] ?? '') === 'initial_pair') $allowed = PMPro_FIC_Initial_Pair::guard($fresh, $context, $payment);
        if (is_wp_error($fresh) || is_wp_error($allowed) || $this->order_fingerprint($fresh) !== $preview['order_fingerprint']) {
            $error = is_wp_error($fresh) ? $fresh : (is_wp_error($allowed) ? $allowed : new WP_Error('order_changed', 'L’ordine è cambiato prima dell’invio. Nessun documento creato.'));
            $journal['status'] = 'rejected'; $journal['last_error'] = $error->get_error_message();
            if ($this->save_journal($preview['order_id'], $journal)) { delete_option($lock_key); PMPro_FIC_Payments::release($reservation); }
            return $error;
        }
        $response = $this->request('POST', '/c/' . $context['company_id'] . '/issued_documents', $context, $preview['payload']);
        if (is_wp_error($response)) {
            $journal['status'] = $response->get_error_code() === 'rejected' ? 'rejected' : 'uncertain';
            $journal['last_error'] = $response->get_error_message();
            if ($this->save_journal($preview['order_id'], $journal) && $journal['status'] === 'rejected') { delete_option($lock_key); PMPro_FIC_Payments::release($reservation); }
            return $response;
        }
        if (empty($response['data']['id']) || !is_int($response['data']['id']) || $response['data']['id'] <= 0) {
            $journal['status'] = 'uncertain';
            $this->save_journal($preview['order_id'], $journal);
            return new WP_Error('unknown_result', 'Il documento potrebbe essere stato creato, ma manca l’ID nella risposta. Usa la verifica dell’esito remoto.');
        }
        return $this->record_document($journal, $response['data'], $lock_key);
    }

    private function record_document(array $journal, array $document, string $lock_key) {
        $journal['document_id'] = (int) $document['id'];
        $journal['number'] = (string) ($document['number'] ?? '');
        $journal['numeration'] = (string) ($document['numeration'] ?? '/TEST');
        $journal['status'] = isset($document['amount_gross']) && is_numeric($document['amount_gross'])
            && abs(round((float) $document['amount_gross'], 2) - $journal['gross']) < 0.005 ? 'created' : 'created_mismatch';
        if (!$this->save_journal($journal['order_id'], $journal)) return new WP_Error('storage_uncertain', 'Documento remoto creato, ma salvataggio locale incompleto. Usa la verifica dell’esito remoto prima di procedere.');
        $metadata = $this->sync_metadata($journal);
        delete_option($lock_key);
        return is_wp_error($metadata) ? $metadata : ['stage' => 'created', 'document' => $journal];
    }

    private function sync_metadata(array $journal) {
        $meta = [
            'company_id' => $journal['company_id'], 'document_id' => $journal['document_id'], 'number' => $journal['number'],
            'numeration' => $journal['numeration'], 'status' => $journal['status'], 'marker' => $journal['marker']];
        update_pmpro_membership_order_meta($journal['order_id'], self::META, $meta);
        if (get_pmpro_membership_order_meta($journal['order_id'], self::META, true) !== $meta) return new WP_Error('metadata', 'Documento creato, ma associazione PMPro incompleta. Ricarica lo stesso ordine per ripararla; non creare un altro documento.');
        return true;
    }

    private function recover(array $input, array $context) {
        $order_id = $this->text($input, 'order_id');
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $order_id)) return new WP_Error('invalid_order', 'Inserisci l’ID numerico dell’ordine.');
        $journal = $this->journal((int) $order_id);
        if (!$journal || (int) $journal['company_id'] !== $context['company_id']) return new WP_Error('no_attempt', 'Non esiste un tentativo per questo ordine nell’azienda selezionata.');
        if (in_array($journal['status'], ['created', 'created_mismatch'], true)) return $this->existing_result($journal);
        if ($journal['status'] === 'rejected') return new WP_Error('rejected', 'La richiesta precedente è stata rifiutata. Correggi i dati e genera una nuova anteprima.');
        if (time() - $journal['requested_at'] < 120) return new WP_Error('wait', 'Attendi almeno due minuti dal tentativo prima di verificare l’esito remoto.');
        $marker = $journal['marker'] ?? null;
        if (!is_string($marker) || !preg_match('/^innerplay-test-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $marker)) {
            return new WP_Error('invalid_attempt', 'Riferimento del tentativo incompleto. Verifica il documento in Fatture in Cloud; il nuovo invio resta bloccato.');
        }
        $matches = [];
        $complete = false;
        $seen = [];
        // Una corrispondenza entro il limite non prova l'unicità oltre il limite.
        for ($page = 1; $page <= 5; $page++) {
            $path = '/c/' . $context['company_id'] . '/issued_documents?' . http_build_query([
                'type' => 'invoice', 'fields' => 'id,number,numeration,subject,notes,amount_gross', 'sort' => '-id', 'per_page' => 100, 'page' => $page]);
            $response = $this->request('GET', $path, $context);
            if (is_wp_error($response)) return $response;
            if (($response['data'] && array_keys($response['data']) !== range(0, count($response['data']) - 1))
                || count($response['data']) > 100
                || (isset($response['current_page']) && $response['current_page'] !== $page)
                || (isset($response['last_page']) && (!is_int($response['last_page']) || $response['last_page'] < $page))) {
                return new WP_Error('recovery_pagination', 'La risposta della ricerca non è una pagina verificabile. Il nuovo invio resta bloccato.');
            }
            foreach ($response['data'] as $document) {
                if (!is_array($document) || !isset($document['id']) || !is_int($document['id']) || $document['id'] <= 0 || isset($seen[$document['id']])) {
                    return new WP_Error('recovery_pagination', 'Elenco incompleto o modificato durante la ricerca. Ripeti solo la verifica; il nuovo invio resta bloccato.');
                }
                $seen[$document['id']] = true;
                // Legacy subjects were the exact UUID marker. New subjects are shared by a plan:
                // only the complete, bounded marker in notes may identify the attempted document.
                $legacy_match = ($document['subject'] ?? '') === $marker;
                $notes_match = is_string($document['notes'] ?? null)
                    && preg_match('/(?<![A-Za-z0-9-])' . preg_quote($marker, '/') . '(?![A-Za-z0-9-])/', $document['notes']) === 1;
                if ($legacy_match || $notes_match) $matches[$document['id']] = $document;
                if (count($matches) > 1) return new WP_Error('ambiguous_recovery', 'Più documenti contengono il riferimento di questo tentativo. Verifica Fatture in Cloud prima di associarli; il nuovo invio resta bloccato.');
            }
            $more = !empty($response['next_page_url']) || (isset($response['last_page']) && $response['last_page'] > $page);
            if (!$more && (count($response['data']) < 100 || ($response['last_page'] ?? null) === $page)) { $complete = true; break; }
        }
        if (!$complete) return new WP_Error('recovery_scan_limit', 'Scansione interrotta al limite di 500 documenti: non è possibile confermare l’assenza o l’unicità del riferimento. Nessuna associazione e nessun nuovo invio. Verifica il riferimento nelle note in Fatture in Cloud.');
        if (count($matches) === 1) return $this->record_document($journal, reset($matches), 'pmpro_fic_lock_' . $order_id);
        return new WP_Error('not_found_uncertain', 'Documento non trovato tra gli ultimi 500 documenti. Il nuovo invio resta bloccato: verifica l’account Fatture in Cloud prima di risolvere il tentativo.');
    }

    private function form_start(string $action): void {
        echo '<form method="post" action="' . esc_url(admin_url('options-general.php?page=' . PMPro_FattureInCloud_Sync::PAGE) . '#fic-order-result') . '">';
        wp_nonce_field(PMPro_FattureInCloud_Sync::NONCE);
        echo '<input type="hidden" name="fic_action" value="' . esc_attr($action) . '">';
    }

    public function render($result, string $action): void {
        echo '<hr><h2>3. Documento da ordine PayPal Sandbox</h2>';
        if (!class_exists('MemberOrder')) { echo '<p id="fic-order-result" tabindex="-1" style="scroll-margin-top:48px">Attiva Paid Memberships Pro per utilizzare questa sezione.</p>'; return; }
        echo '<p>Inserisci l’ID numerico visibile in Memberships / Abbonamenti → Ordini. Usa un ordine PayPal Sandbox pagato in euro. I dati cliente sono per un test italiano non elettronico.</p>';
        $this->form_start('load_order');
        echo '<label for="fic-order-id">ID ordine PMPro</label> <input id="fic-order-id" type="number" min="1" name="order_id" value="' . esc_attr($this->last_order_id) . '" required>';
        submit_button('Carica ordine e conti d’incasso', 'secondary'); echo '</form>';
        $this->form_start('recover_order');
        echo '<label for="fic-recover-id">ID ordine con esito incerto</label> <input id="fic-recover-id" type="number" min="1" name="order_id" value="' . esc_attr($this->last_order_id) . '" required>';
        submit_button('Verifica esito remoto', 'secondary'); echo '</form>';
        echo '<h3>Primo incasso presente su un altro ordine</h3><p>Usa questa verifica quando il checkout non ha ID pagamento e un secondo ordine dello stesso abbonamento contiene l’incasso.</p>';
        $this->form_start('inspect_initial_pair');
        echo '<label>ID checkout originale <input type="number" min="1" name="order_id" required></label>';
        submit_button('Analizza coppia di ordini (sola lettura)', 'secondary'); echo '</form>';
        echo '<div id="fic-order-result" tabindex="-1" style="scroll-margin-top:48px">';
        $order_action = in_array($action, self::ACTIONS, true);
        if ($order_action && is_wp_error($result)) {
            echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            if ($result->get_error_code() === 'transaction_missing' && $this->missing_payment_order && class_exists('PMPro_PayPal_API')) {
                echo '<p>Puoi verificare il primo incasso direttamente in PayPal Sandbox e recuperare il suo ID dopo aver controllato il risultato.</p>';
                $this->form_start('inspect_paypal_payment');
                echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $this->missing_payment_order->id) . '">';
                submit_button('Verifica pagamento in PayPal Sandbox', 'secondary'); echo '</form>';
            }
            if ($this->edit) $result = $this->edit;
        }
        if (!is_array($result) || !$order_action) { echo '</div>'; return; }
        if ($result['stage'] === 'pair_review') {
            $binding = $result['binding']; $payment = $binding['payment'];
            echo '<h3>Checkout #' . esc_html((string) $binding['source_order_id']) . ' → incasso sull’ordine #' . esc_html((string) $binding['target_order_id']) . '</h3>';
            echo '<p>Abbonamento: ' . esc_html($payment['subscription_id']) . '<br>ID incasso: ' . esc_html($payment['payment_id']) . '<br>Importo: ' . esc_html(number_format($payment['cents'] / 100, 2, ',', '.')) . ' EUR · Data UTC: ' . esc_html($payment['paid_at']) . '</p>';
            echo '<p>Conferma il collegamento dei dati fiscali del checkout all’ordine con incasso. Entrambi gli ordini e i loro ID pagamento restano invariati. Non viene creata alcuna fattura. Una verifica già confermata può riprendere una copia fiscale incompleta; i riferimenti vengono ricontrollati al salvataggio.</p>';
            $this->form_start('confirm_initial_pair');
            echo '<input type="hidden" name="review_id" value="' . esc_attr($result['review_id']) . '">';
            submit_button('Conferma collegamento fiscale', 'primary'); echo '</form>';
        } elseif ($result['stage'] === 'pair_linked') {
            echo '<div class="notice notice-success inline"><p>Checkout #' . esc_html((string) $result['source_order_id']) . ' collegato all’ordine con incasso #' . esc_html((string) $result['order_id']) . '. Usa questo ordine per il documento /TEST e, dopo averlo verificato, per autorizzare i rinnovi.</p></div>';
            $this->form_start('load_order');
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $result['order_id']) . '">';
            submit_button('Carica ordine e conti d’incasso', 'secondary'); echo '</form>';
        } elseif ($result['stage'] === 'payment_review') {
            $payment = $result['payment'];
            echo '<h3>Pagamento verificato per l’ordine #' . esc_html((string) $result['order_id']) . '</h3>';
            echo '<p>PayPal Sandbox conferma un unico primo incasso completato, corrispondente all’importo dell’ordine.</p>';
            echo '<p><strong>ID pagamento:</strong> ' . esc_html($payment['payment_id']) . '<br><strong>Abbonamento:</strong> ' . esc_html($payment['subscription_id'])
                . '<br><strong>Totale:</strong> ' . esc_html(number_format($payment['cents'] / 100, 2, ',', '.')) . ' EUR'
                . '<br><strong>Data pagamento (UTC):</strong> ' . esc_html($payment['paid_at']) . '</p>';
            echo '<p>Controlla i riferimenti. Confermando viene aggiornato soltanto l’ID pagamento vuoto dell’ordine PMPro esistente. Nessun nuovo addebito o documento viene creato. Verifica valida per 10 minuti.</p>';
            $this->form_start('confirm_paypal_payment');
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $result['order_id']) . '">';
            echo '<input type="hidden" name="recovery_id" value="' . esc_attr($result['recovery_id']) . '">';
            submit_button('Associa l’ID verificato all’ordine', 'primary'); echo '</form>';
        } elseif ($result['stage'] === 'payment_saved') {
            echo '<div class="notice notice-success inline"><p>ID pagamento recuperato per l’ordine #' . esc_html((string) $result['order_id'])
                . ': <strong>' . esc_html($result['payment']['payment_id']) . '</strong>. Puoi ora caricare i dati per il documento di prova.</p></div>';
            $this->form_start('load_order');
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $result['order_id']) . '">';
            submit_button('Carica ordine e conti d’incasso', 'secondary'); echo '</form>';
        } elseif ($result['stage'] === 'edit') {
            if (!empty($result['last_error'])) echo '<div class="notice notice-warning inline"><p>Ultimo rifiuto per questo ordine: ' . esc_html($result['last_error']) . '</p></div>';
            echo '<h3>Ordine #' . esc_html((string) $result['order']->id) . ' — totale ' . esc_html(number_format((float) $result['order']->total, 2, ',', '.')) . ' €</h3>';
            echo '<p>Azienda destinataria del test: <strong>' . esc_html($result['company_name']) . '</strong>.</p>';
            echo !empty($result['billing_locked']) ? '<p><strong>Dati acquisiti dal checkout.</strong> Questa è la copia salvata nell’ordine; non è modificabile da questo modulo.</p>'
                : '<p>Ordine precedente senza copia fiscale: completa i dati di prova eventualmente mancanti. Non saranno modificati nel profilo del cliente.</p>';
            $this->form_start('preview_order');
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $result['order']->id) . '"><table class="form-table"><tbody>';
            foreach (['name' => 'Nome / ragione sociale', 'email' => 'Email', 'street' => 'Indirizzo', 'zip' => 'CAP italiano', 'city' => 'Città', 'province' => 'Provincia (2 lettere)',
                'tax_code' => 'Codice fiscale', 'vat_number' => 'Partita IVA', 'recipient_code' => 'Codice destinatario', 'pec' => 'PEC'] as $key => $label) {
                echo '<tr><th><label for="fic-customer-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="fic-customer-' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($result['customer'][$key]) . '"' . (!empty($result['billing_locked']) ? ' readonly' : '') . (in_array($key, ['tax_code', 'vat_number', 'recipient_code', 'pec'], true) ? '' : ' required') . '></td></tr>';
            }
            echo '<tr><th><label for="fic-account">Conto d’incasso di prova</label></th><td><select id="fic-account" name="account_id" required><option value="">Seleziona</option>';
            foreach ($result['accounts'] as $account) echo '<option value="' . esc_attr((string) $account['id']) . '" ' . selected((string) ($result['account_id'] ?? ''), (string) $account['id'], false) . '>' . esc_html($account['name']) . '</option>';
            echo '</select></td></tr></tbody></table><p><label><input type="checkbox" name="confirm_test" value="1" required> Confermo che l’azienda selezionata e i dati cliente sono destinati al collaudo.</label></p>';
            echo '<p>L’anteprima verifica i totali con Fatture in Cloud, senza creare documenti.</p>';
            submit_button('Prepara anteprima', 'secondary'); echo '</form>';
        } elseif ($result['stage'] === 'preview') {
            echo '<h3>Anteprima ordine #' . esc_html((string) $result['order_id']) . '</h3><p>Azienda: <strong>' . esc_html($result['company_name']) . '</strong></p>';
            echo '<p><strong>Totali verificati con Fatture in Cloud:</strong> corrispondono al totale dell’ordine PMPro.</p>';
            echo '<p>Cliente: ' . esc_html($result['payload']['data']['entity']['name']) . ' · ' . esc_html($result['payload']['data']['entity']['email']) . '</p>';
            echo '<p><strong>Oggetto nell’elenco Fatture in Cloud:</strong> ' . esc_html($result['payload']['data']['subject']) . '</p>';
            echo '<p>Imponibile ' . esc_html(number_format($result['net'], 2, ',', '.')) . ' € · IVA ' . esc_html(number_format($result['vat'], 2, ',', '.')) . ' € · Totale ' . esc_html(number_format($result['gross'], 2, ',', '.')) . ' €</p>';
            echo '<p>Il pulsante seguente salva realmente un documento non elettronico nel sezionale /TEST, con incasso registrato. Non viene trasmesso allo SDI o inviato via email. Anteprima valida per 15 minuti.</p>';
            echo '<details><summary>Controlla tutti i dati del documento</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html(wp_json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
            $this->form_start('create_order');
            echo '<input type="hidden" name="preview_id" value="' . esc_attr($result['preview_id']) . '">';
            submit_button('Crea documento nell’azienda di prova', 'primary'); echo '</form>';
        } elseif ($result['stage'] === 'created') {
            $document = $result['document'];
            echo '<div class="notice notice-success inline"><p>Documento associato all’ordine #' . esc_html((string) $document['order_id']) . '. ID Fatture in Cloud: <strong>' . esc_html((string) $document['document_id']) . '</strong> · Numero ' . esc_html($document['number'] . $document['numeration']) . '.</p></div>';
            if ($document['status'] === 'created_mismatch') echo '<div class="notice notice-warning inline"><p>Il totale remoto non è confermato o non coincide. Controlla il documento in Fatture in Cloud; la creazione di duplicati resta bloccata.</p></div>';
            echo '<p>Apri Fatture in Cloud → Fatture e Documenti → Fatture e verifica il documento /TEST. Ricaricare lo stesso ordine mostrerà questa associazione senza creare un altro documento.</p>';
        }
        echo '</div>';
    }
}
