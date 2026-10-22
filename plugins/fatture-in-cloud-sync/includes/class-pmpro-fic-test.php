<?php
if (!defined('ABSPATH')) exit;

final class PMPro_FattureInCloud_Sync {
    const OPTION_GROUP = 'pmpro_fic_sync_options_group';
    const OPTION_NAME = 'pmpro_fic_sync_options';
    const PAGE = 'pmpro-fic-sync';
    const NONCE = 'pmpro_fic_test';
    private $automation;
    private $diagnostics;

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        $this->automation = new PMPro_FIC_Automation($this);
        $this->diagnostics = new PMPro_FIC_Diagnostics();
        // Il vecchio enabled=1 non abilita la nuova coda.
    }

    public function admin_menu(): void {
        add_options_page('PMPro Fatture in Cloud', 'PMPro Fatture in Cloud', 'manage_options', self::PAGE, [$this, 'settings_page']);
    }

    public function register_settings(): void {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, ['sanitize_callback' => [$this, 'sanitize_options']]);
        add_settings_section('pmpro_fic_connection', 'Collegamento all’azienda di prova', function () {
            echo '<p>Salva i dati, verifica il collegamento e poi scegli l’aliquota per la simulazione.</p>';
        }, self::PAGE);
        foreach (['company_id' => 'Codice Cliente / Company ID', 'access_token' => 'Token personale',
            'vat_id' => 'Aliquota per i documenti di prova', 'document_prefix' => 'Descrizione generica / simulazione',
            'plan_descriptions' => 'Descrizioni in fattura per piano'] as $key => $label) {
            add_settings_field($key, $label, [$this, 'render_field'], self::PAGE, 'pmpro_fic_connection', ['key' => $key]);
        }
    }

    private function options(): array {
        $saved = get_option(self::OPTION_NAME, []);
        $options = array_merge(['company_id' => 0, 'access_token' => '', 'vat_id' => '', 'document_prefix' => 'Abbonamento', 'plan_descriptions' => []], is_array($saved) ? $saved : []);
        $options['plan_descriptions'] = $this->description_map($options['plan_descriptions']);
        return $options;
    }

    /** Stable numeric keys; empty descriptions retain the legacy fallback. */
    private function description_map($input): array {
        if (!is_array($input)) return [];
        $map = [];
        foreach (array_slice($input, 0, 100, true) as $id => $value) {
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) || !is_string($value)) continue;
            $text = trim((string) preg_replace('/[\r\n\t ]+/', ' ', sanitize_text_field($value)));
            if ($text !== '' && preg_match('/^.{1,1000}$/usD', $text)) $map[(int) $id] = $text;
        }
        ksort($map, SORT_NUMERIC);
        return $map;
    }

    private function token(array $options): string {
        return defined('PMPRO_FIC_ACCESS_TOKEN') ? trim((string) PMPRO_FIC_ACCESS_TOKEN) : (string) $options['access_token'];
    }

    private function connection_key(array $options): string {
        return 'pmpro_fic_check_' . hash('sha256', (string) $options['company_id'] . ':' . $this->token($options));
    }

    private function connection(array $options): array {
        $connection = get_transient($this->connection_key($options));
        return is_array($connection) ? $connection : [];
    }

    private function input(array $input, string $key): string {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
    }

    public function sanitize_options($input): array {
        $old = $this->options();
        if (!current_user_can('manage_options')) return $old;
        $input = is_array($input) ? $input : [];
        $company = $this->input($input, 'company_id');
        $next = ['company_id' => $old['company_id'], 'access_token' => defined('PMPRO_FIC_ACCESS_TOKEN') ? '' : $old['access_token'],
            'vat_id' => '', 'document_prefix' => sanitize_text_field($this->input($input, 'document_prefix')) ?: 'Abbonamento',
            'plan_descriptions' => $old['plan_descriptions'], 'enabled' => 0];
        if (array_key_exists('plan_descriptions', $input)) {
            if (is_array($input['plan_descriptions']) && count($input['plan_descriptions']) <= 100) {
                $map = $this->description_map($input['plan_descriptions']);
                $valid = true;
                foreach ($input['plan_descriptions'] as $id => $value) {
                    if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id) || !is_string($value)
                        || (trim(sanitize_text_field($value)) !== '' && !isset($map[(int) $id]))) $valid = false;
                }
                if ($valid) $next['plan_descriptions'] = $map;
                else add_settings_error(self::OPTION_NAME, 'plan_descriptions', 'Controlla le descrizioni dei piani: solo testo, massimo 1000 caratteri per piano. I testi precedenti sono stati conservati.');
            } else add_settings_error(self::OPTION_NAME, 'plan_descriptions', 'Descrizioni dei piani non valide. I testi precedenti sono stati conservati.');
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $company)) {
            $next['company_id'] = (int) $company;
        } else {
            add_settings_error(self::OPTION_NAME, 'company', 'Inserisci un Codice Cliente numerico maggiore di zero.');
        }
        if (!defined('PMPRO_FIC_ACCESS_TOKEN')) {
            $token = $this->input($input, 'access_token');
            if (!empty($input['forget_token'])) {
                $next['access_token'] = '';
            } elseif ($token !== '') {
                if (strlen($token) <= 8192 && !preg_match('/\s/', $token)) {
                    $next['access_token'] = $token;
                } else {
                    add_settings_error(self::OPTION_NAME, 'token', 'Token non valido: incolla solo il token, senza spazi o prefisso Bearer.');
                }
            }
        }
        if ($this->connection_key($old) !== $this->connection_key($next)) {
            delete_transient($this->connection_key($old));
        } else {
            $vat_id = $this->input($input, 'vat_id');
            $connection = $this->connection($next);
            foreach ($connection['vats'] ?? [] as $vat) {
                if ((string) $vat['id'] === $vat_id) {
                    $next['vat_id'] = $vat_id; // ID 0 valido: non usare empty().
                    break;
                }
            }
            if ($vat_id !== '' && $next['vat_id'] === '') {
                add_settings_error(self::OPTION_NAME, 'vat', 'Verifica il collegamento e seleziona un’aliquota disponibile.');
            }
        }
        if ([$old['company_id'], $this->token($old), (string) $old['vat_id'], $old['document_prefix'], $old['plan_descriptions']]
            !== [$next['company_id'], $this->token($next), (string) $next['vat_id'], $next['document_prefix'], $next['plan_descriptions']]) $this->automation->invalidate();
        return $next;
    }

    public function render_field(array $args): void {
        $options = $this->options();
        $key = $args['key'];
        $name = self::OPTION_NAME . '[' . $key . ']';
        if ($key === 'plan_descriptions') {
            $levels = function_exists('pmpro_getAllLevels') ? pmpro_getAllLevels(true) : [];
            $levels = is_array($levels) ? $levels : [];
            $available = [];
            foreach ($levels as $level) {
                if (is_object($level) && (int) ($level->id ?? 0) > 0) $available[(int) $level->id] = (string) $level->name;
            }
            // Retain descriptions for retired levels while they still have historic orders.
            foreach ($options['plan_descriptions'] as $id => $value) if (!isset($available[$id])) $available[$id] = 'Piano non più presente';
            if (!$available) echo '<p>Attiva PMPro e crea i piani per configurare le descrizioni.</p>';
            foreach ($available as $id => $label) {
                $field_id = 'fic-plan-description-' . $id;
                echo '<p><label for="' . esc_attr($field_id) . '"><strong>' . esc_html($label) . '</strong> (ID ' . (int) $id . ')</label><br>';
                echo '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($name . '[' . $id . ']') . '" rows="2" maxlength="1000" class="large-text" placeholder="Inserisci la descrizione completa per questo piano">' . esc_html($options['plan_descriptions'][$id] ?? '') . '</textarea></p>';
            }
            echo '<p class="description">Il testo compilato viene usato integralmente per quel piano, senza aggiungere il numero del livello. Se lasci vuoto, resta la descrizione generica seguita da “— livello #ID”. Gli ordini gratuiti non generano documenti.</p>';
            echo '<p class="description">Le modifiche riguardano i documenti ancora da creare. Se cambi un testo e salvi, riattiva l’automazione nella sezione 4 e rigenera eventuali anteprime.</p>';
        } elseif ($key === 'access_token') {
            if (defined('PMPRO_FIC_ACCESS_TOKEN')) {
                echo '<p>Token configurato sul server tramite PMPRO_FIC_ACCESS_TOKEN.</p>';
                return;
            }
            echo '<input aria-label="Nuovo token personale" type="password" autocomplete="new-password" name="' . esc_attr($name) . '" value="" class="regular-text">';
            echo '<p class="description">' . ($this->token($options) !== '' ? 'Token salvato. Lascia vuoto per conservarlo.' : 'Incolla il token personale dell’azienda di prova.') . '</p>';
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_NAME . '[forget_token]') . '" value="1"> Rimuovi il token salvato</label>';
        } elseif ($key === 'vat_id') {
            $connection = $this->connection($options);
            echo '<select aria-label="Aliquota per i documenti di prova" name="' . esc_attr($name) . '"><option value="">Seleziona dopo la verifica del collegamento</option>';
            foreach ($connection['vats'] ?? [] as $vat) {
                echo '<option value="' . esc_attr((string) $vat['id']) . '" ' . selected((string) $options['vat_id'], (string) $vat['id'], false) . '>';
                echo esc_html($vat['description'] . ' — ' . $vat['value'] . '% (ID ' . $vat['id'] . ')') . '</option>';
            }
            echo '</select><p class="description">Usata nella simulazione e nei documenti di prova della sezione 3. Non modifica le imposte del checkout.</p>';
        } else {
            echo '<input aria-label="' . esc_attr($key === 'company_id' ? 'Codice Cliente' : 'Descrizione del servizio') . '" type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $options[$key]) . '" class="regular-text">';
        }
    }

    /** GET su host e percorsi interni noti; non mostra né salva body o errori HTTP grezzi. */
    private function api_get(string $path, array $options) {
        $response = wp_remote_get('https://api-v2.fattureincloud.it' . $path, [
            'timeout' => 12, 'redirection' => 0, 'limit_response_size' => 1048576,
            'headers' => ['Authorization' => 'Bearer ' . $this->token($options), 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) return new WP_Error('connection', 'Connessione non riuscita. Controlla la connettività del server e riprova.');
        $status = (int) wp_remote_retrieve_response_code($response);
        $messages = [
            401 => 'Token non valido o revocato. Genera un nuovo token e salvalo.',
            403 => 'Accesso negato. Controlla azienda autorizzata, permessi del token ed email autorizzate nell’app privata.',
            404 => 'Risorsa non trovata. Controlla il Codice Cliente.',
            429 => 'Limite di richieste raggiunto. Attendi prima di riprovare.',
        ];
        if ($status < 200 || $status >= 300) return new WP_Error('api_' . $status, $messages[$status] ?? 'Fatture in Cloud non ha completato la richiesta (HTTP ' . $status . '). Riprova più tardi.');
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) return new WP_Error('invalid_response', 'Risposta inattesa da Fatture in Cloud. Riprova la verifica.');
        return $body['data'];
    }

    public function test_connection() {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        return $this->check_connection();
    }

    private function check_connection() {
        $options = $this->options();
        delete_transient($this->connection_key($options));
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string) $options['company_id']) || $this->token($options) === '') {
            return new WP_Error('missing_config', 'Salva prima il Codice Cliente e il token personale.');
        }
        $data = $this->api_get('/user/companies', $options);
        if (is_wp_error($data)) return $data;
        $company = null;
        foreach (isset($data['companies']) && is_array($data['companies']) ? $data['companies'] : [] as $candidate) {
            if (is_array($candidate) && isset($candidate['id']) && (string) $candidate['id'] === (string) $options['company_id']) {
                $company = $candidate;
                break;
            }
        }
        if (!$company) return new WP_Error('company_not_allowed', 'Il Codice Cliente non corrisponde a un’azienda accessibile con questo token.');
        $vats = $this->api_get('/c/' . (int) $options['company_id'] . '/info/vat_types', $options);
        if (is_wp_error($vats)) return $vats;
        $available = [];
        foreach ($vats as $vat) {
            if (!is_array($vat) || !isset($vat['id'], $vat['value']) || !is_int($vat['id']) || $vat['id'] < 0
                || !is_numeric($vat['value']) || !is_finite((float) $vat['value']) || $vat['value'] < 0 || $vat['value'] > 100 || !empty($vat['is_disabled'])) continue;
            $available[] = ['id' => $vat['id'], 'value' => (float) $vat['value'], 'description' => sanitize_text_field((string) ($vat['description'] ?? 'IVA'))];
        }
        if (!$available) return new WP_Error('no_vats', 'Nessuna aliquota utilizzabile ricevuta. Verifica le impostazioni dell’azienda.');
        $result = ['company_id' => (int) $options['company_id'], 'company_name' => sanitize_text_field((string) ($company['name'] ?? '')),
            'vats' => $available, 'checked_at' => current_time('mysql')];
        set_transient($this->connection_key($options), $result, HOUR_IN_SECONDS);
        return $result;
    }

    /** Una sola riga in EUR; nessuna chiamata API, nessun dato cliente reale. */
    public function simulate_invoice(string $amount) {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        $amount = str_replace(',', '.', trim($amount));
        if (!preg_match('/^([0-9]{1,7})(?:\.([0-9]{1,2}))?$/D', $amount, $parts)) {
            return new WP_Error('invalid_amount', 'Inserisci un totale positivo, senza separatori delle migliaia e con massimo due decimali.');
        }
        $cents = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($cents <= 0) return new WP_Error('zero_amount', 'Gli ordini gratuiti non richiedono questa simulazione. Inserisci un importo maggiore di zero.');
        $options = $this->options();
        $connection = $this->connection($options);
        $selected_vat = null;
        foreach ($connection['vats'] ?? [] as $vat) {
            if ((string) $vat['id'] === (string) $options['vat_id']) {
                $selected_vat = $vat;
                break;
            }
        }
        if ($selected_vat === null) return new WP_Error('vat_required', 'Verifica il collegamento, seleziona un’aliquota e salva le impostazioni. La verifica dura un’ora.');
        $net_cents = (int) round($cents / (1 + $selected_vat['value'] / 100));
        return ['gross' => $cents / 100, 'net' => $net_cents / 100, 'vat' => ($cents - $net_cents) / 100,
            'vat_description' => $selected_vat['description'],
            'payload' => ['data' => [
                'type' => 'invoice', 'date' => current_time('Y-m-d'), 'currency' => ['id' => 'EUR'],
                'entity' => ['name' => 'Cliente dimostrativo Innerplay', 'country' => 'Italia'], 'use_gross_prices' => true,
                'items_list' => [['name' => $options['document_prefix'], 'qty' => 1, 'gross_price' => $cents / 100, 'vat' => ['id' => $selected_vat['id']]]],
                'notes' => 'SIMULAZIONE LOCALE — nessun ordine o documento creato.',
            ]],
        ];
    }

    private function action_form(string $action, string $label): void {
        echo '<form method="post" action="' . esc_url(admin_url('options-general.php?page=' . self::PAGE)) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="fic_action" value="' . esc_attr($action) . '">';
        if ($action === 'simulate') echo '<label for="fic-amount">Totale IVA inclusa (€)</label> <input id="fic-amount" name="amount" type="text" inputmode="decimal" value="122,00" required>';
        submit_button($label, 'secondary');
        echo '</form>';
    }

    /** Contesto interno per la creazione manuale, legato alla verifica ancora valida. */
    public function order_context() {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        return $this->context(false);
    }

    public function background_context() {
        if (!(defined('DOING_CRON') && DOING_CRON) && !current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata alla coda o agli amministratori.');
        return $this->context(true);
    }

    public function automation_scope(): string {
        $options = $this->options();
        $parts = [$options['company_id'], $this->token($options), (string) $options['vat_id'], $options['document_prefix'],
            get_option('pmpro_gateway', ''), get_option('pmpro_gateway_environment', ''), get_option('pmpro_currency', ''),
            get_option('pmpro_paypal_client_id_sandbox', ''), get_option('pmpro_paypal_client_secret_sandbox', '')];
        if ($options['plan_descriptions']) $parts[] = $options['plan_descriptions'];
        return hash('sha256', wp_json_encode($parts));
    }

    private function context(bool $refresh) {
        $options = $this->options();
        $connection = $this->connection($options);
        if (!$connection && $refresh) {
            $connection = $this->check_connection();
            if (is_wp_error($connection)) return $connection;
        }
        foreach ($connection['vats'] ?? [] as $vat) {
            if ((string) $vat['id'] === (string) $options['vat_id']) {
                $context = ['company_id' => (int) $options['company_id'], 'company_name' => $connection['company_name'],
                    'token' => $this->token($options), 'vat' => $vat, 'description' => $options['document_prefix']];
                if ($options['plan_descriptions']) $context['plan_descriptions'] = $options['plan_descriptions'];
                return $context;
            }
        }
        return new WP_Error('vat_required', 'Verifica il collegamento, seleziona un’aliquota e salva le impostazioni.');
    }

    public function settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $result = null;
        $action = '';
        $orders = new PMPro_FIC_Test_Orders([$this, 'order_context']);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer(self::NONCE);
            $action = $this->input($_POST, 'fic_action');
            if ($action === 'connect') $result = $this->test_connection();
            elseif ($action === 'simulate') $result = $this->simulate_invoice($this->input($_POST, 'amount'));
            elseif (in_array($action, PMPro_FIC_Test_Orders::ACTIONS, true)) $result = $orders->dispatch($action, wp_unslash($_POST));
            elseif (in_array($action, PMPro_FIC_Automation::ACTIONS, true)) $result = $this->automation->dispatch($action, wp_unslash($_POST));
            elseif (in_array($action, PMPro_FIC_Diagnostics::ACTIONS, true)) $result = $this->diagnostics->dispatch($action, wp_unslash($_POST));
            else $result = new WP_Error('invalid_action', 'Operazione non disponibile.');
        }
        echo '<div class="wrap"><h1>PMPro → Fatture in Cloud</h1>';
        echo '<div class="notice notice-info inline"><p><strong>Modalità di collaudo.</strong> La sezione 3 crea documenti manualmente; la sezione 4 abilita la coda per gli acquisti PayPal Sandbox e autorizza i singoli abbonamenti di prova per i rinnovi. I documenti vengono creati nell’azienda di prova, nel sezionale /TEST, senza invio allo SDI o email.</p></div>';
        settings_errors(self::OPTION_NAME);
        $order_action = in_array($action, PMPro_FIC_Test_Orders::ACTIONS, true);
        $auto_action = in_array($action, PMPro_FIC_Automation::ACTIONS, true);
        $diagnostic_action = in_array($action, PMPro_FIC_Diagnostics::ACTIONS, true);
        if (is_wp_error($result) && !$order_action && !$auto_action && !$diagnostic_action) echo '<div class="notice notice-error inline"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        elseif ($result !== null && $action === 'connect') echo '<div class="notice notice-success inline"><p>Collegamento riuscito. Azienda e aliquote lette correttamente. Seleziona ora l’aliquota e salva.</p></div>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE);
        submit_button('Salva impostazioni');
        echo '</form><hr><h2>1. Verifica del collegamento</h2><p>Usa i dati già salvati. Questa verifica legge azienda e aliquote; non verifica i permessi di scrittura sulle fatture.</p>';
        $this->action_form('connect', 'Verifica collegamento e carica aliquote');
        $connection = $this->connection($this->options());
        if ($connection) {
            echo '<p><strong>Azienda:</strong> ' . esc_html($connection['company_name']) . ' · Codice Cliente ' . esc_html((string) $connection['company_id']) . '</p>';
            echo '<p>Ultima verifica: ' . esc_html($connection['checked_at']) . ' (valida per un’ora).</p>';
        }
        echo '<hr><h2>2. Simula una fattura campione</h2><p>Calcolo locale con una sola voce in euro e totale IVA inclusa. Non include bollo, ritenute, casse previdenziali o regole fiscali speciali. Non certifica la validità fiscale o l’accettazione del documento da parte delle API.</p>';
        $this->action_form('simulate', 'Simula senza creare documenti');
        if ($result !== null && !is_wp_error($result) && $action === 'simulate') {
            echo '<h3>Anteprima locale</h3><table class="widefat striped"><tbody>';
            foreach (['net' => 'Imponibile', 'vat' => 'IVA', 'gross' => 'Totale'] as $key => $label) {
                echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html(number_format($result[$key], 2, ',', '.')) . ' €</td></tr>';
            }
            echo '</tbody></table><p>Aliquota: ' . esc_html($result['vat_description']) . '</p>';
            echo '<details><summary>Dati tecnici della simulazione</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html(wp_json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
        }
        $orders->render($result, $action);
        $this->automation->render($result, $action);
        $this->diagnostics->render($result, $action);
        echo '</div>';
    }
}
