<?php
if (!defined('ABSPATH')) exit;

/** Explicit opt-in for future checkouts; canonical JSON, no changes to existing renewal policies. */
final class PMPro_FIC_Plan_Rules {
    const KEY = 'pmpro_fic_plan_rules';

    public static function read() {
        $rules = PMPro_FIC_Atomic_Store::read(self::KEY);
        if ($rules === null || is_wp_error($rules)) return $rules;
        if (($rules['schema_version'] ?? 0) !== 1 || !is_string($rules['revision'] ?? null) || !is_array($rules['plans'] ?? null)) return new WP_Error('plan_rules', 'Regole dei piani non leggibili. Verifica le impostazioni prima di acquisire nuovi acquisti.');
        foreach ($rules['plans'] as $id => $rule) {
            if (!preg_match('/^[1-9][0-9]*$/D', (string) $id) || !is_array($rule) || !is_bool($rule['first'] ?? null) || !is_bool($rule['renewals'] ?? null)
                || ($rule['renewals'] && !$rule['first']) || !is_int($rule['after_order_id'] ?? null) || $rule['after_order_id'] < 0
                || !is_int($rule['authorized_by'] ?? null) || $rule['authorized_by'] < 1 || !is_int($rule['saved_at'] ?? null)) return new WP_Error('plan_rules', 'Regole dei piani incomplete. Controlla e salva nuovamente le impostazioni.');
        }
        return $rules;
    }

    public static function levels(): array {
        $levels = function_exists('pmpro_getAllLevels') ? pmpro_getAllLevels(true) : [];
        $out = [];
        foreach (is_array($levels) ? $levels : [] as $level) if (is_object($level) && (int) ($level->id ?? 0) > 0) $out[(int) $level->id] = (string) $level->name;
        return $out;
    }

    public static function save(array $input) {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Operazione riservata agli amministratori.');
        if (($input['confirm_plan_rules'] ?? '') !== '1') return new WP_Error('confirm_test', 'Conferma le regole Sandbox per i nuovi acquisti e i relativi dati fiscali.');
        if (get_option('pmpro_gateway', '') !== 'paypal' || get_option('pmpro_gateway_environment', '') !== 'sandbox' || get_option('pmpro_currency', '') !== 'EUR') return new WP_Error('auto_sandbox', 'Le regole richiedono PayPal Sandbox e valuta EUR.');
        $old = self::read();
        if (is_wp_error($old)) return $old;
        if (($input['rules_revision'] ?? null) !== ($old['revision'] ?? '')) return new WP_Error('plan_stale', 'Le regole sono cambiate. Ricarica la pagina prima di salvare.');
        $levels = self::levels();
        if (!$levels) return new WP_Error('plan_levels', 'Nessun piano PMPro disponibile.');
        foreach ($old['plans'] ?? [] as $id => $rule) if (!isset($levels[$id])) $levels[$id] = 'Piano non più presente';
        if (!is_array($input['plans'] ?? null) || count($input['plans']) !== count($levels)) return new WP_Error('plan_input', 'Elenco piani incompleto o cambiato. Ricarica la pagina.');
        foreach ($input['plans'] as $id => $rule) {
            if (!isset($levels[$id]) || !is_array($rule) || ($rule['present'] ?? '') !== '1'
                || !in_array($rule['first'] ?? '0', ['0','1'], true) || !in_array($rule['renewals'] ?? '0', ['0','1'], true)
                || (($rule['renewals'] ?? '0') === '1' && ($rule['first'] ?? '0') !== '1')) return new WP_Error('plan_input', 'Per fatturare i rinnovi abilita anche il primo acquisto. Usa solo i piani presenti nella tabella.');
        }
        // A database high-water mark excludes old orders even when their hooks are replayed later.
        global $wpdb;
        $table = $wpdb->pmpro_membership_orders ?? '';
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table)) return new WP_Error('plan_storage', 'Tabella ordini PMPro non disponibile.');
        $last = $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$table}");
        if (!empty($wpdb->last_error) || !is_scalar($last) || !preg_match('/^[0-9]+$/D', (string) $last)) return new WP_Error('plan_storage', 'Impossibile stabilire la decorrenza delle regole. Nessuna modifica salvata.');
        $by = (int) get_current_user_id();
        if ($by < 1) return new WP_Error('forbidden', 'Amministratore non identificabile.');
        $next = ['schema_version'=>1, 'revision'=>wp_generate_uuid4(), 'plans'=>[]];
        foreach ($levels as $id => $label) {
            $first = ($input['plans'][$id]['first'] ?? '0') === '1';
            $renewals = ($input['plans'][$id]['renewals'] ?? '0') === '1';
            $previous = $old['plans'][$id] ?? null;
            $next['plans'][$id] = $previous && $previous['first'] === $first && $previous['renewals'] === $renewals ? $previous
                : ['first'=>$first, 'renewals'=>$renewals, 'after_order_id'=>(int) $last, 'authorized_by'=>$by, 'saved_at'=>time()];
        }
        if ($old !== null && $old['plans'] === $next['plans']) return ['message'=>'Regole già salvate. Nessuna autorizzazione o decorrenza modificata.'];
        $saved = $old === null ? PMPro_FIC_Atomic_Store::insert(self::KEY, $next)
            : $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", wp_json_encode($next), self::KEY, wp_json_encode($old))) === 1;
        if (!$saved || self::read() !== $next) return new WP_Error('plan_storage', 'Salvataggio non confermato o regole modificate da un altro amministratore. Ricarica e verifica.');
        return ['message'=>'Regole salvate per i nuovi acquisti. I lavori già acquisiti e gli abbonamenti già autorizzati restano invariati; per sospenderne uno usa Rinnovi.'];
    }

    /** null = legacy (first purchases only); false = excluded; array = captured rule. */
    public static function for_order($order) {
        $rules = self::read();
        if ($rules === null || is_wp_error($rules)) return $rules;
        $rule = $rules['plans'][(int) ($order->membership_id ?? 0)] ?? null;
        if (!$rule || !$rule['first'] || (int) $order->id <= $rule['after_order_id']) return false;
        return $rule + ['level_id'=>(int) $order->membership_id, 'revision'=>$rules['revision']];
    }

    public static function render(): void {
        $rules = self::read();
        echo '<h3>Fatturazione automatica per piano</h3><p>Le regole si applicano ai nuovi acquisti acquisiti dopo il salvataggio. Un piano escluso non genera nuovi documenti automatici. I lavori già acquisiti e gli abbonamenti già autorizzati mantengono le regole precedenti: per sospendere un abbonamento usa Rinnovi.</p>';
        if (is_wp_error($rules)) { echo '<p class="notice notice-error inline">' . esc_html($rules->get_error_message()) . '</p>'; return; }
        if ($rules === null) echo '<p class="notice notice-info inline">Regole per piano non ancora attivate: prosegue il comportamento precedente (primi acquisti automatici, rinnovi autorizzati manualmente). Salva questa tabella per passare alle regole per piano.</p>';
        $levels = self::levels();
        foreach ($rules['plans'] ?? [] as $id => $rule) if (!isset($levels[$id])) $levels[$id] = 'Piano non più presente';
        if (!$levels) { echo '<p>Attiva PMPro e configura almeno un piano.</p>'; return; }
        echo '<form method="post" action="' . esc_url(PMPro_FIC_Admin::url('impostazioni') . '#fic-auto-result') . '">';
        wp_nonce_field(PMPro_FattureInCloud_Sync::NONCE);
        echo '<input type="hidden" name="fic_action" value="plan_rules_save"><input type="hidden" name="rules_revision" value="' . esc_attr($rules['revision'] ?? '') . '"><div class="fic-table-scroll"><table class="widefat"><thead><tr><th>Piano PMPro</th><th>Primo acquisto</th><th>Rinnovi automatici</th></tr></thead><tbody>';
        foreach ($levels as $id => $label) {
            echo '<tr><td>' . esc_html($label) . ' <small>(#' . (int) $id . ')</small><input type="hidden" name="plans[' . (int) $id . '][present]" value="1"></td>';
            foreach (['first'=>'Primo acquisto', 'renewals'=>'Rinnovi automatici'] as $key=>$text) {
                $checked = $rules === null ? $key === 'first' : !empty($rules['plans'][$id][$key]);
                echo '<td><label><input type="checkbox" name="plans[' . (int) $id . '][' . esc_attr($key) . ']" value="1"' . ($checked ? ' checked' : '') . '> <span class="screen-reader-text">' . esc_html($text . ' — ' . $label) . '</span></label></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div><p>I rinnovi richiedono anche il primo acquisto. L’abilitazione avviene solo dopo documento iniziale corretto, verifica dell’incasso e dei dati fiscali; non modifica gli addebiti PayPal. I piani aggiunti in seguito restano esclusi finché non vengono abilitati.</p><p><label><input type="checkbox" name="confirm_plan_rules" value="1" required> Confermo le regole per i nuovi acquisti Sandbox e l’uso dei dati fiscali del checkout anche nei rinnovi.</label></p>';
        submit_button('Salva regole dei piani', 'primary'); echo '</form>';
    }
}
