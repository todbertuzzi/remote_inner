<?php
/**
 * Plugin Name: PMPro → Fatture in Cloud Sync
 * Description: Crea una fattura su Fatture in Cloud quando un ordine Paid Memberships Pro viene registrato come pagato.
 * Version: 0.1.0
 * Author: OpenAI
 */

if (!defined('ABSPATH')) {
	exit;
}

final class PMPro_FattureInCloud_Sync {
	const OPTION_GROUP = 'pmpro_fic_sync_options_group';
	const OPTION_NAME  = 'pmpro_fic_sync_options';

	const META_FIC_ID       = '_pmpro_fic_document_id';
	const META_FIC_NUMBER   = '_pmpro_fic_document_number';
	const META_FIC_STATUS   = '_pmpro_fic_sync_status';
	const META_FIC_RESPONSE = '_pmpro_fic_sync_response';

	public function __construct() {
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('pmpro_added_order', [$this, 'handle_pmpro_order'], 20, 1);
	}

	public function admin_menu(): void {
		add_options_page(
			'PMPro Fatture in Cloud',
			'PMPro Fatture in Cloud',
			'manage_options',
			'pmpro-fic-sync',
			[$this, 'settings_page']
		);
	}

	public function register_settings(): void {
		register_setting(self::OPTION_GROUP, self::OPTION_NAME, [$this, 'sanitize_options']);

		add_settings_section(
			'pmpro_fic_main_section',
			'Configurazione principale',
			function () {
				echo '<p>Inserisci i dati necessari per creare le fatture su Fatture in Cloud.</p>';
			},
			'pmpro-fic-sync'
		);

		$fields = [
			'company_id'      => 'Company ID Fatture in Cloud',
			'access_token'    => 'Access Token',
			'vat_id'          => 'ID IVA Fatture in Cloud',
			'document_prefix' => 'Prefisso descrizione',
			'enabled'         => 'Abilita sincronizzazione',
			'debug'           => 'Abilita log debug',
		];

		foreach ($fields as $key => $label) {
			add_settings_field(
				$key,
				$label,
				[$this, 'render_field'],
				'pmpro-fic-sync',
				'pmpro_fic_main_section',
				['key' => $key, 'label' => $label]
			);
		}
	}

	public function sanitize_options(array $input): array {
		return [
			'company_id'      => isset($input['company_id']) ? absint($input['company_id']) : 0,
			'access_token'    => isset($input['access_token']) ? sanitize_text_field($input['access_token']) : '',
			'vat_id'          => isset($input['vat_id']) ? absint($input['vat_id']) : 0,
			'document_prefix' => isset($input['document_prefix']) ? sanitize_text_field($input['document_prefix']) : 'Membership',
			'enabled'         => !empty($input['enabled']) ? 1 : 0,
			'debug'           => !empty($input['debug']) ? 1 : 0,
		];
	}

	public function render_field(array $args): void {
		$options = $this->get_options();
		$key     = $args['key'];
		$value   = $options[$key] ?? '';

		if (in_array($key, ['enabled', 'debug'], true)) {
			?>
			<label>
				<input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME . '[' . $key . ']'); ?>" value="1" <?php checked(!empty($value)); ?>>
				Attivo
			</label>
			<?php
			return;
		}

		$type = ($key === 'access_token') ? 'password' : 'text';
		?>
		<input
			type="<?php echo esc_attr($type); ?>"
			name="<?php echo esc_attr(self::OPTION_NAME . '[' . $key . ']'); ?>"
			value="<?php echo esc_attr((string) $value); ?>"
			class="regular-text"
		/>
		<?php
	}

	public function settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		?>
		<div class="wrap">
			<h1>PMPro → Fatture in Cloud</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields(self::OPTION_GROUP);
				do_settings_sections('pmpro-fic-sync');
				submit_button();
				?>
			</form>
			<hr>
			<p><strong>Note:</strong></p>
			<ul>
				<li><strong>Company ID</strong>: ID azienda di Fatture in Cloud.</li>
				<li><strong>Access Token</strong>: per questo MVP incolla un token già disponibile.</li>
				<li><strong>ID IVA</strong>: ID dell’aliquota IVA presente nel tuo account Fatture in Cloud.</li>
			</ul>
		</div>
		<?php
	}

	private function get_options(): array {
		$defaults = [
			'company_id'      => 0,
			'access_token'    => '',
			'vat_id'          => 0,
			'document_prefix' => 'Membership',
			'enabled'         => 0,
			'debug'           => 0,
		];

		$options = get_option(self::OPTION_NAME, []);
		return wp_parse_args(is_array($options) ? $options : [], $defaults);
	}

	public function handle_pmpro_order($order): void {
		$options = $this->get_options();

		if (empty($options['enabled'])) {
			return;
		}

		if (empty($order) || empty($order->id)) {
			$this->log('Ordine PMPro non valido.');
			return;
		}

		// Evita duplicati.
		$existing = $this->get_order_meta($order->id, self::META_FIC_ID);
		if (!empty($existing)) {
			$this->log("Ordine #{$order->id}: documento già creato, skip.");
			return;
		}

		// Adatta gli stati in base al tuo gateway reale.
		$status = strtolower((string) ($order->status ?? ''));
		$valid_statuses = ['success', 'paid'];

		if (!in_array($status, $valid_statuses, true)) {
			$this->log("Ordine #{$order->id}: stato '{$status}' non sincronizzato.");
			$this->update_order_meta($order->id, self::META_FIC_STATUS, 'skipped_status_' . $status);
			return;
		}

		$total = isset($order->total) ? (float) $order->total : 0.0;
		if ($total <= 0) {
			$this->log("Ordine #{$order->id}: totale <= 0, skip.");
			$this->update_order_meta($order->id, self::META_FIC_STATUS, 'skipped_zero_total');
			return;
		}

		$user_id = !empty($order->user_id) ? (int) $order->user_id : 0;
		$user    = $user_id ? get_userdata($user_id) : false;

		if (!$user) {
			$this->log("Ordine #{$order->id}: utente non trovato.");
			$this->update_order_meta($order->id, self::META_FIC_STATUS, 'error_user_not_found');
			return;
		}

		if (empty($options['company_id']) || empty($options['access_token']) || empty($options['vat_id'])) {
			$this->log('Configurazione Fatture in Cloud incompleta.');
			$this->update_order_meta($order->id, self::META_FIC_STATUS, 'error_missing_config');
			return;
		}

		$customer = $this->build_customer_data($user_id, $user);
		$payload  = $this->build_invoice_payload($order, $customer, $options);

		$response = $this->fic_create_invoice(
			(int) $options['company_id'],
			(string) $options['access_token'],
			$payload
		);

		if (is_wp_error($response)) {
			$this->log("Ordine #{$order->id}: errore HTTP - " . $response->get_error_message());
			$this->update_order_meta($order->id, self::META_FIC_STATUS, 'error_http');
			$this->update_order_meta($order->id, self::META_FIC_RESPONSE, $response->get_error_message());
			return;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body_raw    = wp_remote_retrieve_body($response);
		$body        = json_decode($body_raw, true);

		if ($status_code >= 200 && $status_code < 300 && !empty($body['data']['id'])) {
			$this->update_order_meta($order->id, self::META_FIC_ID, (string) $body['data']['id']);
			$this->update_order_meta($order->id, self::META_FIC_STATUS, 'success');
			$this->update_order_meta($order->id, self::META_FIC_RESPONSE, wp_json_encode($body));

			if (!empty($body['data']['number'])) {
				$this->update_order_meta($order->id, self::META_FIC_NUMBER, (string) $body['data']['number']);
			}

			$this->log("Ordine #{$order->id}: fattura creata con successo.");
			return;
		}

		$this->log("Ordine #{$order->id}: errore API FIC - HTTP {$status_code} - {$body_raw}");
		$this->update_order_meta($order->id, self::META_FIC_STATUS, 'error_api_' . $status_code);
		$this->update_order_meta($order->id, self::META_FIC_RESPONSE, $body_raw);
	}

	private function build_customer_data(int $user_id, WP_User $user): array {
		$first_name = get_user_meta($user_id, 'first_name', true);
		$last_name  = get_user_meta($user_id, 'last_name', true);

		// Adatta questi meta ai campi che hai davvero nel checkout.
		$company    = get_user_meta($user_id, 'billing_company', true);
		$vat_number = get_user_meta($user_id, 'billing_vat_number', true);
		$tax_code   = get_user_meta($user_id, 'billing_tax_code', true);
		$sdi_code   = get_user_meta($user_id, 'billing_sdi_code', true);
		$pec        = get_user_meta($user_id, 'billing_pec', true);

		$address_1  = get_user_meta($user_id, 'billing_address_1', true);
		$postcode   = get_user_meta($user_id, 'billing_zip', true);
		$city       = get_user_meta($user_id, 'billing_city', true);
		$province   = get_user_meta($user_id, 'billing_state', true);
		$country    = get_user_meta($user_id, 'billing_country', true);

		$name = trim((string) $company);
		if ($name === '') {
			$name = trim($first_name . ' ' . $last_name);
		}
		if ($name === '') {
			$name = (string) $user->user_email;
		}

		$entity = [
			'name'                => $name,
			'email'               => (string) $user->user_email,
			'vat_number'          => (string) $vat_number,
			'tax_code'            => (string) $tax_code,
			'address_street'      => (string) $address_1,
			'address_postal_code' => (string) $postcode,
			'address_city'        => (string) $city,
			'address_province'    => (string) $province,
			'country'             => $country ? (string) $country : 'Italia',
			'certified_email'     => (string) $pec,
			'ei_code'             => (string) $sdi_code,
		];

		return array_filter(
			$entity,
			static function ($value) {
				return $value !== null && $value !== '';
			}
		);
	}

	private function build_invoice_payload($order, array $customer, array $options): array {
		$total         = round((float) $order->total, 2);
		$membership_id = !empty($order->membership_id) ? (int) $order->membership_id : 0;
		$prefix        = !empty($options['document_prefix']) ? $options['document_prefix'] : 'Membership';

		$item_name = $prefix;
		if ($membership_id > 0) {
			$item_name .= ' - Livello #' . $membership_id;
		}

		$payment_method = '';
		if (!empty($order->gateway)) {
			$payment_method = (string) $order->gateway;
		}

		$notes = 'Ordine PMPro #' . $order->id;
		if ($payment_method !== '') {
			$notes .= ' | Gateway: ' . $payment_method;
		}

		return [
			'data' => [
				'type'     => 'invoice',
				'date'     => current_time('Y-m-d'),
				'currency' => [
					'id' => 'EUR',
				],
				'entity'   => $customer,
				'items_list' => [
					[
						'name'      => $item_name,
						'qty'       => 1,
						'net_price' => $total,
						'vat'       => [
							'id' => (int) $options['vat_id'],
						],
					],
				],
				'notes' => $notes,
			],
		];
	}

	private function fic_create_invoice(int $company_id, string $access_token, array $payload) {
		$url = sprintf('https://api-v2.fattureincloud.it/c/%d/issued_documents', $company_id);

		return wp_remote_post($url, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body' => wp_json_encode($payload),
		]);
	}

	private function get_order_meta(int $order_id, string $key) {
		if (function_exists('pmpro_getMemberOrderMeta')) {
			return pmpro_getMemberOrderMeta($order_id, $key, true);
		}
		return get_post_meta($order_id, $key, true);
	}

	private function update_order_meta(int $order_id, string $key, string $value): void {
		if (function_exists('pmpro_updateOrderMeta')) {
			pmpro_updateOrderMeta($order_id, $key, $value);
			return;
		}
		update_post_meta($order_id, $key, $value);
	}

	private function log(string $message): void {
		$options = $this->get_options();
		if (!empty($options['debug']) && defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[PMPro-FIC] ' . $message);
		}
	}
}

new PMPro_FattureInCloud_Sync();