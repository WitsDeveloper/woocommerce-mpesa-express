<?php

if (!defined('ABSPATH')) exit;

/**
 * "Pay manually via PayBill" gateway. The customer is shown the PayBill shortcode and an
 * account reference (their order number) and pays from the M-Pesa menu on their own phone.
 * The order is confirmed automatically by Safaricom's C2B Confirmation IPN.
 */
class WC_Gateway_Mpesa_C2B extends WC_Payment_Gateway {

	use WC_Mpesa_Helpers_Trait;

	const META_ACCOUNT_REF = '_mpesa_c2b_account_ref';
	const META_RECEIPT     = '_mpesa_c2b_receipt';
	const META_PAID_AMOUNT = '_mpesa_c2b_paid_amount';

	public function __construct() {
		$this->id                 = 'mpesa_c2b';
		$this->icon               = plugins_url('images/lipa-na-mpesa.png', WC_MPESA_EXPRESS_FILE);
		$this->has_fields         = false;
		$this->method_title       = __('M-Pesa PayBill (Manual / C2B)', 'woocommerce-mpesa-express');
		$this->method_description = __('Give customers your PayBill number and an account reference to pay manually from the M-Pesa menu. Orders are confirmed automatically via the Safaricom C2B Confirmation IPN.', 'woocommerce-mpesa-express');

		$this->supports = array('products');

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option('title');
		$this->description = $this->get_option('description');

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		// IPN callbacks: /wc-api/wc_mpesa_c2b_confirmation and /wc-api/wc_mpesa_c2b_validation
		add_action('woocommerce_api_wc_mpesa_c2b_confirmation', array($this, 'handle_confirmation'));
		add_action('woocommerce_api_wc_mpesa_c2b_validation', array($this, 'handle_validation'));

		// Payment instructions shown to the customer
		add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_instructions'));
		add_action('woocommerce_view_order', array($this, 'view_order_instructions'), 5);
		add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
	}

	public function is_available() {
		if (!parent::is_available()) return false;

		return trim((string) $this->get_option('business_shortcode')) !== '';
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __('Enable/Disable', 'woocommerce-mpesa-express'),
				'type'    => 'checkbox',
				'label'   => __('Enable M-Pesa PayBill (manual / C2B)', 'woocommerce-mpesa-express'),
				'default' => 'no',
			),
			'title' => array(
				'title'   => __('Title', 'woocommerce-mpesa-express'),
				'type'    => 'text',
				'default' => __('M-Pesa PayBill', 'woocommerce-mpesa-express'),
			),
			'description' => array(
				'title'   => __('Description', 'woocommerce-mpesa-express'),
				'type'    => 'textarea',
				'default' => __('Pay via M-Pesa PayBill. You will get the PayBill number and account details after placing the order.', 'woocommerce-mpesa-express'),
			),

			'environment' => array(
				'title'   => __('Environment', 'woocommerce-mpesa-express'),
				'type'    => 'select',
				'default' => 'sandbox',
				'options' => array(
					'sandbox' => __('Sandbox', 'woocommerce-mpesa-express'),
					'live'    => __('Live', 'woocommerce-mpesa-express'),
				),
				'description' => __('Only used to register the URLs below with Safaricom. Has no effect on how confirmations are received.', 'woocommerce-mpesa-express'),
			),

			'business_shortcode' => array(
				'title'       => __('PayBill Shortcode', 'woocommerce-mpesa-express'),
				'type'        => 'text',
				'description' => __('The PayBill (or Buy Goods till registered for C2B) number customers will dial to pay manually.', 'woocommerce-mpesa-express'),
				'default'     => '',
			),

			'account_reference_prefix' => array(
				'title'       => __('Account Reference Prefix', 'woocommerce-mpesa-express'),
				'type'        => 'text',
				'default'     => '',
				'description' => __('Optional prefix added before the order number, e.g. prefix "INV" turns order #1024 into account number INV1024. Leave blank to just use the order number.', 'woocommerce-mpesa-express'),
			),

			'enable_validation' => array(
				'title'       => __('Use Validation URL', 'woocommerce-mpesa-express'),
				'type'        => 'checkbox',
				'label'       => __('Reject payments for an account number that does not match a valid, unpaid order', 'woocommerce-mpesa-express'),
				'default'     => 'no',
				'description' => __('Only turn this on if "Validation" has also been enabled for your shortcode by Safaricom — otherwise Safaricom never calls the Validation URL and this setting has no effect. Confirmation (which credits the order) works either way.', 'woocommerce-mpesa-express'),
			),

			'consumer_key' => array(
				'title'       => __('Consumer Key', 'woocommerce-mpesa-express'),
				'type'        => 'text',
				'default'     => '',
				'description' => __('Only needed to register the Confirmation/Validation URLs below via the button.', 'woocommerce-mpesa-express'),
			),

			'consumer_secret' => array(
				'title'   => __('Consumer Secret', 'woocommerce-mpesa-express'),
				'type'    => 'password',
				'default' => '',
			),

			'force_complete_after_payment' => array(
				'title'       => __('Force order status to Completed after payment', 'woocommerce-mpesa-express'),
				'type'        => 'checkbox',
				'label'       => __('Yes (always mark order completed on confirmed payment)', 'woocommerce-mpesa-express'),
				'default'     => 'no',
				'description' => __('If disabled, WooCommerce will typically set paid orders to Processing (or Completed for virtual-only orders).', 'woocommerce-mpesa-express'),
			),

			'instructions' => array(
				'title'       => __('Payment Instructions', 'woocommerce-mpesa-express'),
				'type'        => 'textarea',
				'css'         => 'width:100%;height:180px;',
				'description' => __('Shown on the order-received page, the account "My Account > Orders" view, and in the on-hold order email. Placeholders: {shortcode}, {account_ref}, {amount}', 'woocommerce-mpesa-express'),
				'default'     =>
					"To complete your payment, pay via M-Pesa PayBill:\n\n" .
					"1. Go to M-Pesa on your phone.\n" .
					"2. Select Lipa na M-Pesa, then Pay Bill.\n" .
					"3. Enter Business Number: {shortcode}\n" .
					"4. Enter Account Number: {account_ref}\n" .
					"5. Enter Amount: KES {amount}\n" .
					"6. Enter your M-Pesa PIN and confirm.\n\n" .
					"Your order will be confirmed automatically once payment is received.",
			),

			'urls' => array(
				'title'       => __('Callback URLs', 'woocommerce-mpesa-express'),
				'type'        => 'title',
				'description' => sprintf(
					'<p>%1$s<br><code>%2$s</code></p><p>%3$s<br><code>%4$s</code></p><p>%5$s</p>',
					esc_html__('Confirmation URL (register this with Safaricom — this is what credits orders):', 'woocommerce-mpesa-express'),
					esc_html($this->secure_callback_url('wc_mpesa_c2b_confirmation')),
					esc_html__('Validation URL (only called if Validation is enabled for your shortcode):', 'woocommerce-mpesa-express'),
					esc_html($this->secure_callback_url('wc_mpesa_c2b_validation')),
					'<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=wc_mpesa_c2b_register_urls'), 'wc_mpesa_c2b_register_urls')) . '" class="button button-secondary">' . esc_html__('Register URLs with Safaricom', 'woocommerce-mpesa-express') . '</a> ' .
						esc_html__('(requires Consumer Key/Secret and shortcode above to be saved first). If registration fails, add the URLs manually in the Daraja portal.', 'woocommerce-mpesa-express')
				),
			),
		);
	}

	private function account_reference_for_order(WC_Order $order): string {
		$prefix = trim((string) $this->get_option('account_reference_prefix'));
		return $prefix . $order->get_id();
	}

	private function parse_order_id_from_bill_ref(string $bill_ref): int {
		$prefix   = trim((string) $this->get_option('account_reference_prefix'));
		$bill_ref = trim($bill_ref);

		if ($prefix !== '') {
			if (stripos($bill_ref, $prefix) !== 0) return 0;
			$bill_ref = substr($bill_ref, strlen($prefix));
		}

		$digits = preg_replace('/\D/', '', $bill_ref);
		return $digits === '' ? 0 : (int) $digits;
	}

	private function get_instructions_html(WC_Order $order): string {
		$template    = (string) $this->get_option('instructions');
		$shortcode   = trim((string) $this->get_option('business_shortcode'));
		$account_ref = (string) $order->get_meta(self::META_ACCOUNT_REF);
		if ($account_ref === '') $account_ref = $this->account_reference_for_order($order);
		$amount = number_format($this->convert_to_kes((float) $order->get_total()), 2);

		$text = str_replace(
			array('{shortcode}', '{account_ref}', '{amount}'),
			array($shortcode, $account_ref, $amount),
			$template
		);

		return wpautop(wptexturize($text));
	}

	public function process_payment($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			wc_add_notice(__('Payment error: invalid order.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		if (trim((string) $this->get_option('business_shortcode')) === '') {
			wc_add_notice(__('Payment error: PayBill shortcode is not configured.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		$order->update_meta_data(self::META_ACCOUNT_REF, $this->account_reference_for_order($order));
		$order->save();

		$order->update_status('on-hold', __('Awaiting manual M-Pesa PayBill payment.', 'woocommerce-mpesa-express'));

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url($order),
		);
	}

	public function thankyou_instructions($order_id) {
		$order = wc_get_order($order_id);
		if (!$order || $order->get_payment_method() !== $this->id || !$order->needs_payment()) return;

		echo '<section class="woocommerce-order-mpesa-instructions">';
		echo wp_kses_post($this->get_instructions_html($order));
		echo '</section>';
	}

	public function view_order_instructions($order_id) {
		$order = wc_get_order($order_id);
		if (!$order || $order->get_payment_method() !== $this->id || !$order->needs_payment()) return;

		echo '<section class="woocommerce-order-mpesa-instructions">';
		echo wp_kses_post($this->get_instructions_html($order));
		echo '</section>';
	}

	public function email_instructions($order, $sent_to_admin, $plain_text = false) {
		if (!$order instanceof WC_Order) return;
		if ($sent_to_admin || $order->get_payment_method() !== $this->id) return;
		if (!$order->has_status('on-hold') || !$order->needs_payment()) return;

		if ($plain_text) {
			echo wp_strip_all_tags($this->get_instructions_html($order)) . "\n\n";
		} else {
			echo wp_kses_post($this->get_instructions_html($order));
		}
	}

	/**
	 * Register the Confirmation/Validation URLs with Safaricom via the C2B RegisterURL API.
	 * Called from the admin-post handler when the merchant clicks "Register URLs with Safaricom".
	 */
	public function register_c2b_urls(): bool {
		$access_token = $this->generate_access_token();
		if (!$access_token) {
			$this->log('C2B URL registration failed: could not obtain access token.', 'error');
			return false;
		}

		$shortcode = trim((string) $this->get_option('business_shortcode'));
		if ($shortcode === '') {
			$this->log('C2B URL registration failed: no shortcode configured.', 'error');
			return false;
		}

		$response = wp_remote_post($this->api_base_url() . '/mpesa/c2b/v1/registerurl', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode(array(
				'ShortCode'       => $shortcode,
				'ResponseType'    => 'Completed',
				'ConfirmationURL' => $this->secure_callback_url('wc_mpesa_c2b_confirmation'),
				'ValidationURL'   => $this->secure_callback_url('wc_mpesa_c2b_validation'),
			)),
		));

		if (is_wp_error($response)) {
			$this->log('C2B URL registration failed: ' . $response->get_error_message(), 'error');
			return false;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		$this->log('C2B URL registration response: ' . wp_remote_retrieve_body($response));

		return isset($body['ResponseCode']) && (string) $body['ResponseCode'] === '0';
	}

	/**
	 * Validation IPN — called before the transaction completes (only if Validation is enabled
	 * for the shortcode). Accept/reject based on whether the account number matches an unpaid order.
	 */
	public function handle_validation() {
		$this->verify_ipn_token();

		$raw  = file_get_contents('php://input');
		$data = json_decode($raw, true);
		$this->log('C2B validation received: ' . $raw);

		status_header(200);
		header('Content-Type: application/json');

		if ($this->get_option('enable_validation') !== 'yes') {
			echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Accepted'));
			exit;
		}

		$bill_ref = isset($data['BillRefNumber']) ? sanitize_text_field($data['BillRefNumber']) : '';
		$order_id = $this->parse_order_id_from_bill_ref($bill_ref);
		$order    = $order_id ? wc_get_order($order_id) : false;

		if ($order && $order->get_payment_method() === $this->id && $order->needs_payment()) {
			echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Accepted'));
		} else {
			echo wp_json_encode(array('ResultCode' => 1, 'ResultDesc' => 'Rejected - invalid account number'));
		}
		exit;
	}

	/**
	 * Confirmation IPN — called after the transaction has already completed at Safaricom.
	 * Money has moved regardless of our response, so we always acknowledge with ResultCode 0
	 * and reconcile what we can; unmatched or short payments are logged/noted for manual review.
	 */
	public function handle_confirmation() {
		$this->verify_ipn_token();

		$raw  = file_get_contents('php://input');
		$data = json_decode($raw, true);
		$this->log('C2B confirmation received: ' . $raw);

		status_header(200);
		header('Content-Type: application/json');

		$bill_ref  = isset($data['BillRefNumber']) ? sanitize_text_field($data['BillRefNumber']) : '';
		$trans_id  = isset($data['TransID']) ? sanitize_text_field($data['TransID']) : '';
		$trans_amt = isset($data['TransAmount']) ? (float) $data['TransAmount'] : 0.0;
		$msisdn    = isset($data['MSISDN']) ? sanitize_text_field($data['MSISDN']) : '';

		$order_id = $this->parse_order_id_from_bill_ref($bill_ref);
		$order    = $order_id ? wc_get_order($order_id) : false;

		if (!$order || $order->get_payment_method() !== $this->id) {
			$this->log("C2B confirmation: no matching order for BillRefNumber '{$bill_ref}' (receipt {$trans_id}). Needs manual reconciliation.", 'warning');
			echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Success'));
			exit;
		}

		if ($order->has_status(array('processing', 'completed', 'refunded'))) {
			// Already settled; Safaricom redelivered the confirmation.
			echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Success'));
			exit;
		}

		$order->update_meta_data(self::META_RECEIPT, $trans_id);
		$order->update_meta_data(self::META_PAID_AMOUNT, $trans_amt);
		$order->save();

		$expected = $this->convert_to_kes((float) $order->get_total());

		if ($trans_amt + 0.01 < $expected) {
			$order->add_order_note(sprintf(
				/* translators: 1: amount paid 2: receipt 3: order total */
				__('M-Pesa PayBill payment of KES %1$s received (receipt %2$s) but order total is KES %3$s. Left On hold for manual review.', 'woocommerce-mpesa-express'),
				number_format($trans_amt, 2),
				$trans_id,
				number_format($expected, 2)
			));
			$this->log("C2B confirmation: underpayment on order {$order_id} (received {$trans_amt}, expected {$expected}).", 'warning');
			echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Success'));
			exit;
		}

		// Note: needs_payment() excludes 'on-hold' by default, but payment_complete() itself
		// accepts it — the has_status() guard above already prevents reprocessing a settled order.
		if (!$order->is_paid()) {
			$order->payment_complete($trans_id);
		}

		$order->add_order_note(sprintf(
			/* translators: 1: receipt 2: amount 3: phone number */
			__('M-Pesa PayBill payment confirmed. Receipt: %1$s, Amount: KES %2$s, Phone: %3$s', 'woocommerce-mpesa-express'),
			$trans_id,
			number_format($trans_amt, 2),
			$msisdn
		));

		if ($this->get_option('force_complete_after_payment') === 'yes') {
			$order->update_status('completed', __('Order auto-completed after M-Pesa PayBill confirmation.', 'woocommerce-mpesa-express'));
		}

		echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Success'));
		exit;
	}
}
