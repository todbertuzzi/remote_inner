<?php
if (!defined('ABSPATH')) exit;

/** Dati fiscali del checkout: profilo riutilizzabile e copia immutabile per ordine. */
final class PMPro_FIC_Billing {
    const META = '_pmpro_fic_billing_snapshot';
    const PROFILE = '_pmpro_fic_billing_profile';
    const NONCE = 'pmpro_fic_billing_checkout';
    private $validated = null;
    private $pending = [];
    private $render_type = 'private';

    public function __construct() {
        add_action('pmpro_checkout_after_user_fields', [$this, 'render']);
        add_filter('pmpro_checkout_checks', [$this, 'checkout_checks'], 30);
        add_filter('pmpro_registration_checks', [$this, 'checkout_checks'], 30); // Compatibilità PMPro precedente.
        add_filter('pmpro_checkout_order', [$this, 'attach_order'], 30);
        add_action('pmpro_added_order', [$this, 'save_order'], 5);
        add_action('pmpro_updated_order', [$this, 'save_order'], 5);
        add_action('pmpro_before_commit_express_checkout', [$this, 'before_paypal'], 5);
        add_action('pmpro_after_checkout', [$this, 'save_profile'], 30, 2);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public static function required($level = null): bool {
        if ($level === null) { global $pmpro_level; $level = $pmpro_level; }
        if (!is_object($level)) return false;
        foreach (['initial_payment', 'billing_amount', 'trial_amount'] as $field) {
            if (isset($level->$field) && is_numeric($level->$field) && (float) $level->$field > 0) return true;
        }
        return false;
    }

    private static function field(array $data, string $key): string {
        return isset($data[$key]) && is_string($data[$key]) ? trim(sanitize_text_field($data[$key])) : '';
    }

    /** Controlli di formato/checksum, non verifica dell'esistenza presso l'Agenzia delle Entrate. */
    public static function valid_numeric_tax_id(string $value): bool {
        if (!preg_match('/^[0-9]{11}$/D', $value) || $value === '00000000000') return false;
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $value[$i];
            if ($i % 2 === 1) { $digit *= 2; if ($digit > 9) $digit -= 9; }
            $sum += $digit;
        }
        return (10 - $sum % 10) % 10 === (int) $value[10];
    }

    public static function valid_tax_code(string $value): bool {
        if (preg_match('/^[0-9]{11}$/D', $value)) return self::valid_numeric_tax_id($value);
        if (!preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/D', $value)) return false;
        $odd = ['0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,
            'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,'K'=>2,'L'=>4,'M'=>18,
            'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23];
        $sum = 0;
        for ($i = 0; $i < 15; $i++) $sum += $i % 2 === 0 ? $odd[$value[$i]] : (ctype_digit($value[$i]) ? (int) $value[$i] : ord($value[$i]) - 65);
        return chr(65 + $sum % 26) === $value[15];
    }

    public static function validate(array $input) {
        $data = [];
        foreach (['customer_type','first_name','last_name','company','email','street','zip','city','province','country','tax_code','vat_number','recipient_code','pec'] as $field) {
            $data[$field] = self::field($input, $field);
            if (strlen($data[$field]) > 200) return new WP_Error('billing_length', 'Un campo di fatturazione supera la lunghezza consentita.');
        }
        foreach (['province','country','tax_code','recipient_code'] as $field) $data[$field] = strtoupper($data[$field]);
        foreach (['tax_code','vat_number','recipient_code'] as $field) $data[$field] = preg_replace('/\s+/', '', $data[$field]);
        if (!in_array($data['customer_type'], ['private','business'], true)) return new WP_Error('billing_type', 'Scegli Privato oppure Azienda / professionista.');
        if ($data['country'] !== 'IT') return new WP_Error('billing_country', 'Al momento gli acquisti sono disponibili con indirizzo di fatturazione in Italia.');
        if (!is_email($data['email'])) return new WP_Error('billing_email', 'Inserisci un indirizzo email di fatturazione valido.');
        if ($data['street'] === '' || $data['city'] === '' || !preg_match('/^[0-9]{5}$/D', $data['zip'])
            || !preg_match('/^[A-Z]{2}$/D', $data['province']) || $data['province'] === 'EE') return new WP_Error('billing_address', 'Completa indirizzo, CAP italiano di 5 cifre, città e sigla della provincia.');
        if ($data['customer_type'] === 'private') {
            if ($data['first_name'] === '' || $data['last_name'] === '') return new WP_Error('billing_name', 'Inserisci nome e cognome per la fatturazione.');
            if (!self::valid_tax_code($data['tax_code'])) return new WP_Error('billing_tax_code', 'Inserisci un codice fiscale valido.');
            $data['company'] = $data['vat_number'] = $data['pec'] = '';
            $data['recipient_code'] = '0000000';
            $data['name'] = trim($data['first_name'] . ' ' . $data['last_name']);
        } else {
            if ($data['company'] === '') return new WP_Error('billing_company', 'Inserisci la ragione sociale o il nome del professionista.');
            if (!self::valid_numeric_tax_id($data['vat_number'])) return new WP_Error('billing_vat', 'Inserisci una partita IVA italiana valida di 11 cifre.');
            if ($data['tax_code'] !== '' && !self::valid_tax_code($data['tax_code'])) return new WP_Error('billing_tax_code', 'Il codice fiscale indicato non è valido.');
            if ($data['recipient_code'] !== '' && !preg_match('/^[A-Z0-9]{7}$/D', $data['recipient_code'])) return new WP_Error('billing_recipient', 'Il codice destinatario deve contenere 7 caratteri alfanumerici.');
            if ($data['pec'] !== '' && !is_email($data['pec'])) return new WP_Error('billing_pec', 'Inserisci un indirizzo PEC valido oppure lascia il campo vuoto.');
            $data['recipient_code'] = $data['recipient_code'] ?: '0000000';
            $data['first_name'] = $data['last_name'] = '';
            $data['name'] = $data['company'];
        }
        return $data;
    }

    public static function snapshot(int $order_id) {
        if (!function_exists('get_pmpro_membership_order_meta')) return [];
        $saved = get_pmpro_membership_order_meta($order_id, self::META, true);
        if ($saved === '' || $saved === null || $saved === false || $saved === []) return [];
        if (!is_array($saved) || ($saved['schema_version'] ?? 0) !== 1 || (int) ($saved['order_id'] ?? 0) !== $order_id || !is_array($saved['data'] ?? null)
            || (int) ($saved['user_id'] ?? 0) <= 0 || (int) ($saved['level_id'] ?? 0) <= 0
            || !is_string($saved['currency'] ?? null) || !preg_match('/^[A-Z]{3}$/D', $saved['currency'])) {
            return new WP_Error('billing_snapshot', 'La copia dei dati fiscali dell’ordine non è valida. Verifica l’ordine prima di creare il documento.');
        }
        $valid = self::validate($saved['data']);
        if (is_wp_error($valid)) return new WP_Error('billing_snapshot', 'La copia dei dati fiscali dell’ordine è incompleta o non valida.');
        $saved['data'] = $valid;
        return $saved;
    }

    private function review_snapshot() {
        global $pmpro_review;
        if (!is_object($pmpro_review) || empty($pmpro_review->id)) return [];
        if ((int) $pmpro_review->user_id !== get_current_user_id() || get_current_user_id() <= 0) return new WP_Error('billing_owner', 'Accedi con l’account associato a questo checkout.');
        $saved = self::snapshot((int) $pmpro_review->id);
        if (is_wp_error($saved)) return $saved;
        if (!$saved || (int) $saved['user_id'] !== (int) $pmpro_review->user_id || (int) $saved['level_id'] !== (int) $pmpro_review->membership_id) {
            return new WP_Error('billing_missing', 'I dati di fatturazione di questo checkout non sono disponibili. Riparti dalla scelta del piano prima di pagare.');
        }
        return $saved;
    }

    public function checkout_checks($allowed) {
        $this->validated = null;
        if (!$allowed || !self::required()) return $allowed;
        if (!function_exists('add_pmpro_membership_order_meta') || !function_exists('get_pmpro_membership_order_meta')) {
            pmpro_setMessage('Aggiorna Paid Memberships Pro per salvare i dati di fatturazione.', 'pmpro_error');
            return false;
        }
        global $pmpro_review;
        if (is_object($pmpro_review) && !empty($pmpro_review->id)) {
            $saved = $this->review_snapshot();
            if (is_wp_error($saved)) { pmpro_setMessage($saved->get_error_message(), 'pmpro_error'); return false; }
            $this->validated = $saved['data'];
            return true;
        }
        $nonce = isset($_POST['fic_billing_nonce']) && is_string($_POST['fic_billing_nonce']) ? wp_unslash($_POST['fic_billing_nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE)) { pmpro_setMessage('Il modulo di fatturazione è scaduto. Ricarica la pagina e riprova.', 'pmpro_error'); return false; }
        $data = isset($_POST['fic_billing']) && is_array($_POST['fic_billing']) ? wp_unslash($_POST['fic_billing']) : [];
        $valid = self::validate($data);
        if (is_wp_error($valid)) { pmpro_setMessage($valid->get_error_message(), 'pmpro_error'); return false; }
        $this->validated = $valid;
        return true;
    }

    public function attach_order($order) {
        if (!is_object($order) || $this->validated === null || !self::required()) return $order;
        $this->pending[spl_object_hash($order)] = $this->validated;
        return $order;
    }

    public function save_order($order): void {
        if (!is_object($order) || empty($order->id) || empty($order->user_id)) return;
        $data = $this->pending[spl_object_hash($order)] ?? null;
        if ($data === null) return; // Nessuna modifica per ordini admin, IPN o rinnovi.
        $existing = self::snapshot((int) $order->id);
        if ($existing || is_wp_error($existing)) return; // La copia originale non viene sovrascritta.
        global $pmpro_currency;
        $snapshot = ['schema_version' => 1, 'order_id' => (int) $order->id, 'user_id' => (int) $order->user_id,
            'level_id' => (int) $order->membership_id, 'currency' => strtoupper((string) ($pmpro_currency ?: get_option('pmpro_currency', 'EUR'))),
            'captured_at' => current_time('mysql'), 'data' => $data];
        add_pmpro_membership_order_meta((int) $order->id, self::META, $snapshot, true);
        $stored = self::snapshot((int) $order->id);
        if ((!$stored || is_wp_error($stored)) && in_array((string) $order->status, ['token', 'review'], true)) {
            wp_die('Impossibile salvare i dati di fatturazione. Riparti dal checkout prima di completare il pagamento.', 'Dati di fatturazione non salvati', ['response' => 503]);
        }
    }

    public function before_paypal($order): void {
        if (!is_object($order) || !isset($this->pending[spl_object_hash($order)])) return;
        $this->save_order($order);
        $saved = self::snapshot((int) $order->id);
        if (!$saved || is_wp_error($saved) || (int) $saved['user_id'] !== (int) $order->user_id || (int) $saved['level_id'] !== (int) $order->membership_id) wp_die('Impossibile salvare i dati di fatturazione. Riparti dal checkout prima di completare il pagamento.', 'Dati di fatturazione non salvati', ['response' => 503]);
    }

    public function save_profile($user_id, $order): void {
        if (!is_object($order) || (int) $order->user_id !== (int) $user_id || (string) $order->status !== 'success') return;
        $saved = self::snapshot((int) $order->id);
        if ($saved && !is_wp_error($saved) && (int) $saved['user_id'] === (int) $user_id) update_user_meta((int) $user_id, self::PROFILE, $saved['data']);
    }

    public function assets(): void {
        if (!function_exists('pmpro_is_checkout') || !pmpro_is_checkout()) return;
        $root = dirname(__DIR__);
        wp_enqueue_style('pmpro-fic-billing', plugins_url('assets/billing-checkout.css', $root . '/fatture-in-cloud-sync.php'), [], (string) filemtime($root . '/assets/billing-checkout.css'));
        wp_enqueue_script('pmpro-fic-billing', plugins_url('assets/billing-checkout.js', $root . '/fatture-in-cloud-sync.php'), [], (string) filemtime($root . '/assets/billing-checkout.js'), true);
    }

    public function render(): void {
        if (!self::required()) return;
        global $pmpro_review;
        if (is_object($pmpro_review) && !empty($pmpro_review->id)) {
            $saved = $this->review_snapshot();
            if (is_wp_error($saved)) return;
            $data = $saved['data'];
            echo '<section class="fic-billing"><h2>Dati di fatturazione</h2><p>' . esc_html($data['name']) . '<br>' . esc_html($data['street'] . ', ' . $data['zip'] . ' ' . $data['city'] . ' (' . $data['province'] . ') — Italia') . '</p><p>Email: ' . esc_html($data['email']) . '</p></section>';
            return;
        }
        $profile = get_current_user_id() > 0 ? get_user_meta(get_current_user_id(), self::PROFILE, true) : [];
        $data = is_array($profile) ? $profile : [];
        if (isset($_POST['fic_billing']) && is_array($_POST['fic_billing'])) $data = wp_unslash($_POST['fic_billing']);
        $user = wp_get_current_user();
        if (!isset($data['email'])) $data['email'] = $user->ID ? $user->user_email : '';
        $type = self::field($data, 'customer_type') === 'business' ? 'business' : 'private';
        $this->render_type = $type;
        echo '<fieldset class="fic-billing pmpro_form_fieldset" data-fic-billing><legend>Dati di fatturazione</legend><p>Inserisci i dati dell’intestatario della fattura. Attualmente sono disponibili indirizzi di fatturazione in Italia.</p>';
        wp_nonce_field(self::NONCE, 'fic_billing_nonce');
        echo '<div class="fic-billing-field"><label for="fic-type">Acquisto come</label><select id="fic-type" name="fic_billing[customer_type]">';
        foreach (['private' => 'Privato', 'business' => 'Azienda / professionista'] as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($type, $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select></div><div data-fic-private class="fic-billing-grid"' . ($type === 'business' ? ' hidden' : '') . '>';
        $this->input('first_name', 'Nome', $data, true); $this->input('last_name', 'Cognome', $data, true);
        echo '</div><div data-fic-business class="fic-billing-grid"' . ($type === 'private' ? ' hidden' : '') . '>';
        $this->input('company', 'Ragione sociale / nome del professionista', $data, true); $this->input('vat_number', 'Partita IVA (11 cifre)', $data, true);
        echo '</div><div class="fic-billing-grid">';
        $this->input('tax_code', 'Codice fiscale', $data, $type === 'private'); $this->input('email', 'Email per la fatturazione', $data, true, 'email');
        $this->input('street', 'Indirizzo e numero civico', $data, true); $this->input('zip', 'CAP', $data, true);
        $this->input('city', 'Città', $data, true); $this->input('province', 'Provincia (sigla di 2 lettere)', $data, true);
        echo '<div class="fic-billing-field"><label for="fic-country">Paese di fatturazione</label><select id="fic-country" name="fic_billing[country]"><option value="IT">Italia</option></select></div></div><div data-fic-business class="fic-billing-grid"' . ($type === 'private' ? ' hidden' : '') . '>';
        $this->input('recipient_code', 'Codice destinatario (facoltativo)', $data, false); $this->input('pec', 'PEC (facoltativa)', $data, false, 'email');
        echo '<p class="fic-billing-help">Se non hai codice destinatario o PEC, puoi lasciare entrambi vuoti.</p></div></fieldset>';
    }

    private function input(string $key, string $label, array $data, bool $required, string $type = 'text'): void {
        $id = 'fic-' . $key;
        $maxlength = ['zip' => 5, 'province' => 2, 'vat_number' => 11, 'tax_code' => 16, 'recipient_code' => 7];
        $disabled = ($this->render_type === 'private' && in_array($key, ['company','vat_number','recipient_code','pec'], true))
            || ($this->render_type === 'business' && in_array($key, ['first_name','last_name'], true));
        echo '<div class="fic-billing-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input id="' . esc_attr($id) . '" name="fic_billing[' . esc_attr($key) . ']" type="' . esc_attr($type) . '" value="' . esc_attr(self::field($data, $key)) . '" maxlength="' . ($maxlength[$key] ?? 200) . '"' . ($required ? ' required' : '') . ($disabled ? ' disabled' : '') . '></div>';
    }
}
