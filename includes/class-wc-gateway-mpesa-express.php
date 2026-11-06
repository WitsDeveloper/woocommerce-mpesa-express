<?php

if (!defined('ABSPATH')) exit;

/**
 * M-Pesa Express (STK Push).
 *
 * Flow: the customer enters an M-Pesa number in the payment box, places the order (On hold), and
 * gets a PIN prompt. The order-received page shows a "check your phone" screen that polls the
 * order; Safaricom's callback marks it paid. If the callback is late or lost, the status poll asks
 * Daraja (STK Query) at most every 20 s. The customer may resend the prompt up to 3 times, and the
 * PayBill details (account = prefix + order number) are shown as a fallback.
 */
class WC_Gateway_Mpesa_Express extends WC_Payment_Gateway {

	use WC_Mpesa_Helpers_Trait;

	const META_PHONE              = '_mpesa_phone';
	const META_CHECKOUT_REQUESTID = '_mpesa_checkout_request_id';
	const META_MERCHANT_REQUESTID = '_mpesa_merchant_request_id';
	const META_RECEIPT            = '_mpesa_receipt';
	const META_SENT_AT            = '_mpesa_sent_at';
	const META_RESENDS            = '_mpesa_resends';
	const META_LAST_QUERY         = '_mpesa_last_query';
	const META_LAST_RESULT        = '_mpesa_last_result';

	const MAX_RESENDS   = 3;
	const RESEND_WAIT   = 30;  // seconds between prompts
	const QUERY_AFTER   = 25;  // first STK Query this long after a prompt
	const QUERY_EVERY   = 20;  // then at most this often

	public function __construct() {
		$this->id                 = 'mpesa_express';
		$this->icon               = plugins_url('images/lipa-na-mpesa.png', WC_MPESA_EXPRESS_FILE);
		$this->has_fields         = true;
		$this->method_title       = __('M-Pesa Express (STK Push)', 'woocommerce-mpesa-express');
		$this->method_description = __('Accept payments via Safaricom M-Pesa Express (STK Push) using PayBill. The customer receives a prompt on their phone and confirms with their M-Pesa PIN.', 'woocommerce-mpesa-express');

		$this->supports = array('products');

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option('title');
		$this->description = $this->get_option('description');

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		// IPN callback: /wc-api/wc_mpesa_express
		add_action('woocommerce_api_wc_mpesa_express', array($this, 'handle_callback'));

		add_action('woocommerce_checkout_create_order', array($this, 'save_checkout_phone_to_order'), 10, 2);
		add_action('woocommerce_after_checkout_validation', array($this, 'validate_checkout_phone'), 10, 2);

		// "Check your phone" screen on the order-received page and in My Account > Orders.
		add_action('woocommerce_thankyou_' . $this->id, array($this, 'render_waiting_screen'), 1);
		add_action('woocommerce_view_order', array($this, 'render_waiting_screen_view_order'), 1);
	}

	public function is_available() {
		if (!parent::is_available()) return false;

		return $this->credential('business_shortcode') !== ''
			&& $this->credential('passkey') !== ''
			&& $this->credential('consumer_key') !== ''
			&& $this->credential('consumer_secret') !== '';
	}

	public function init_form_fields() {
		$from_config = function ($key) {
			return $this->credential_from_constant($key)
				? __('Set in wp-config.php; this field is ignored.', 'woocommerce-mpesa-express')
				: '';
		};
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __('Enable/Disable', 'woocommerce-mpesa-express'),
				'type'    => 'checkbox',
				'label'   => __('Enable M-Pesa Express', 'woocommerce-mpesa-express'),
				'default' => 'no',
			),
			'title' => array(
				'title'   => __('Title', 'woocommerce-mpesa-express'),
				'type'    => 'text',
				'default' => __('M-Pesa', 'woocommerce-mpesa-express'),
			),
			'description' => array(
				'title'   => __('Description', 'woocommerce-mpesa-express'),
				'type'    => 'textarea',
				'default' => __('You will get a prompt on your phone. Enter your M-Pesa PIN to pay.', 'woocommerce-mpesa-express'),
			),

			'environment' => array(
				'title'   => __('Environment', 'woocommerce-mpesa-express'),
				'type'    => 'select',
				'default' => 'sandbox',
				'options' => array(
					'sandbox' => __('Sandbox', 'woocommerce-mpesa-express'),
					'live'    => __('Live', 'woocommerce-mpesa-express'),
				),
				'description' => __('Choose which Safaricom Daraja API environment to use.', 'woocommerce-mpesa-express'),
			),

			'business_shortcode' => array(
				'title'       => __('PayBill Shortcode', 'woocommerce-mpesa-express'),
				'type'        => 'text',
				'description' => trim(__('Your PayBill shortcode provisioned for STK Push.', 'woocommerce-mpesa-express') . ' ' . $from_config('business_shortcode')),
				'default'     => '',
			),

			'account_prefix' => array(
				'title'       => __('Account reference prefix', 'woocommerce-mpesa-express'),
				'type'        => 'text',
				'default'     => 'RES',
				'description' => __('Shown on the customer\'s M-Pesa message and statement as prefix + order number (max 12 characters in total), e.g. RES1024. Use a prefix that no other system on this PayBill reads as its own reference.', 'woocommerce-mpesa-express'),
			),

			'passkey' => array(
				'title'       => __('Passkey', 'woocommerce-mpesa-express'),
				'type'        => 'password',
				'default'     => '',
				'description' => $from_config('passkey'),
			),

			'consumer_key' => array(
				'title'       => __('Consumer Key', 'woocommerce-mpesa-express'),
				'type'        => 'text',
				'default'     => '',
				'description' => $from_config('consumer_key'),
			),

			'consumer_secret' => array(
				'title'       => __('Consumer Secret', 'woocommerce-mpesa-express'),
				'type'        => 'password',
				'default'     => '',
				'description' => $from_config('consumer_secret'),
			),

			'force_complete_after_payment' => array(
				'title'       => __('Force order status to Completed after payment', 'woocommerce-mpesa-express'),
				'type'        => 'checkbox',
				'label'       => __('Yes (always mark order completed on successful callback)', 'woocommerce-mpesa-express'),
				'default'     => 'no',
				'description' => __('If disabled, WooCommerce will typically set paid orders to Processing (or Completed for virtual-only orders).', 'woocommerce-mpesa-express'),
			),

			'mark_failed_on_error' => array(
				'title'       => __('Mark order Failed on payment error', 'woocommerce-mpesa-express'),
				'type'        => 'checkbox',
				'label'       => __('Yes (move the order to Failed when the STK push is cancelled, times out, or is declined)', 'woocommerce-mpesa-express'),
				'default'     => 'yes',
				'description' => __('If disabled, the order stays On hold and must be reviewed manually.', 'woocommerce-mpesa-express'),
			),

			'restrict_callback_ips' => array(
				'title'   => __('Only accept callbacks from Safaricom', 'woocommerce-mpesa-express'),
				'type'    => 'checkbox',
				'label'   => __('Refuse callbacks that do not come from Safaricom\'s callback addresses (196.201.212-214.x)', 'woocommerce-mpesa-express'),
				'default' => 'yes',
			),

			'exchange_rate' => array(
				'title'       => __('Exchange Rate to KES', 'woocommerce-mpesa-express'),
				'type'        => 'text',
				'default'     => '1',
				'description' => __('If store currency is not KES, amount * exchange_rate is sent to M-Pesa.', 'woocommerce-mpesa-express'),
			),

			'callback_url' => array(
				'title'       => __('IPN Callback URL', 'woocommerce-mpesa-express'),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: callback URL */
					__('This URL is generated automatically and sent with every STK Push request — you do not need to register it in the Daraja portal: <code>%s</code>', 'woocommerce-mpesa-express'),
					esc_html($this->secure_callback_url('wc_mpesa_express'))
				),
			),
		);
	}

	/* ---------- Checkout ---------- */

	/** The account reference for an order: prefix + order number, max 12 characters (Daraja's limit). */
	public function account_reference(WC_Order $order): string {
		$prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) $this->get_option('account_prefix', 'RES'));
		return substr($prefix . $order->get_order_number(), 0, 12);
	}

	/** Payment box: the description plus the M-Pesa number, pre-filled from the billing phone. */
	public function payment_fields() {
		if ($this->description) {
			echo wpautop(wp_kses_post($this->description));
		}
		$value = '';
		if (isset($_POST['mpesa_phone'])) {
			$value = wc_clean(wp_unslash($_POST['mpesa_phone']));
		} elseif (function_exists('WC') && WC()->customer) {
			$value = (string) WC()->customer->get_billing_phone();
		}
		$msisdn = $this->normalize_msisdn($value);
		if ($this->is_valid_msisdn($msisdn)) {
			$value = '0' . substr($msisdn, 3);
		}
		?>
		<p class="form-row form-row-wide wc-mpesa-phone">
			<label for="mpesa_phone"><?php esc_html_e('M-Pesa phone number', 'woocommerce-mpesa-express'); ?> <span class="required" aria-hidden="true">*</span></label>
			<input type="tel" class="input-text" id="mpesa_phone" name="mpesa_phone" value="<?php echo esc_attr($value); ?>" placeholder="07XX XXX XXX" autocomplete="tel" inputmode="tel" maxlength="16">
			<small class="wc-mpesa-hint"><?php esc_html_e('Safaricom number that will receive the payment prompt.', 'woocommerce-mpesa-express'); ?></small>
		</p>
		<?php
	}

	public function validate_checkout_phone($data, $errors) {
		if (empty($data['payment_method']) || $data['payment_method'] !== $this->id) return;

		$raw = isset($_POST['mpesa_phone']) ? wc_clean(wp_unslash($_POST['mpesa_phone'])) : '';
		if ($raw === '') {
			$raw = (string) ($data['billing_phone'] ?? '');
		}
		if ($raw === '') {
			$errors->add('mpesa_phone_missing', __('Please enter the M-Pesa phone number that should get the payment prompt.', 'woocommerce-mpesa-express'));
			return;
		}
		if (!$this->is_valid_msisdn($this->normalize_msisdn($raw))) {
			$errors->add('mpesa_phone_invalid', __('Enter a Safaricom number, e.g. 0712 345 678 or 0110 123 456.', 'woocommerce-mpesa-express'));
		}
	}

	public function save_checkout_phone_to_order($order, $data) {
		if (($data['payment_method'] ?? '') !== $this->id) return;
		$raw = !empty($_POST['mpesa_phone']) ? wc_clean(wp_unslash($_POST['mpesa_phone'])) : (string) ($data['billing_phone'] ?? '');
		$msisdn = $this->normalize_msisdn($raw);
		if ($this->is_valid_msisdn($msisdn)) {
			$order->update_meta_data(self::META_PHONE, $msisdn);
		}
	}

	public function process_payment($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			wc_add_notice(__('Payment error: invalid order.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		$sent = $this->send_stk($order);
		if (is_wp_error($sent)) {
			wc_add_notice($sent->get_error_message(), 'error');
			return array('result' => 'fail');
		}

		$order->update_status('on-hold', __('Awaiting M-Pesa payment confirmation (STK Push).', 'woocommerce-mpesa-express'));
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url($order),
		);
	}

	/**
	 * Sends an STK push for the order and records the CheckoutRequestID.
	 *
	 * @return true|WP_Error
	 */
	public function send_stk(WC_Order $order) {
		$access_token = $this->generate_access_token();
		if (!$access_token) {
			return new WP_Error('wc_mpesa_token', __('M-Pesa is not reachable right now. Please try again in a moment.', 'woocommerce-mpesa-express'));
		}

		$shortcode = $this->credential('business_shortcode');
		if ($shortcode === '' || $this->credential('passkey') === '') {
			return new WP_Error('wc_mpesa_config', __('M-Pesa payments are not set up yet. Please choose another payment method.', 'woocommerce-mpesa-express'));
		}

		$phone = (string) $order->get_meta(self::META_PHONE);
		if ($phone === '') {
			$phone = (string) $order->get_billing_phone();
		}
		$phone = $this->normalize_msisdn($phone);
		if (!$this->is_valid_msisdn($phone)) {
			return new WP_Error('wc_mpesa_phone', __('Enter a Safaricom number, e.g. 0712 345 678 or 0110 123 456.', 'woocommerce-mpesa-express'));
		}

		$amount_int = (int) ceil($this->convert_to_kes((float) $order->get_total()));
		if ($amount_int < 1) {
			return new WP_Error('wc_mpesa_amount', __('Payment error: amount is invalid.', 'woocommerce-mpesa-express'));
		}

		$timestamp = wp_date('YmdHis', null, new DateTimeZone('Africa/Nairobi'));
		$reference = $this->account_reference($order);

		$payload = array(
			'BusinessShortCode' => $shortcode,
			'Password'          => $this->stk_password($timestamp),
			'Timestamp'         => $timestamp,
			'TransactionType'   => 'CustomerPayBillOnline',
			'Amount'            => $amount_int,
			'PartyA'            => $phone,
			'PartyB'            => $shortcode,
			'PhoneNumber'       => $phone,
			'CallBackURL'       => $this->secure_callback_url('wc_mpesa_express'),
			'AccountReference'  => $reference,
			'TransactionDesc'   => substr('Order ' . $order->get_order_number(), 0, 13),
		);

		$response = wp_remote_post($this->api_base_url() . '/mpesa/stkpush/v1/processrequest', array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode($payload),
		));

		if (is_wp_error($response)) {
			$this->log('STK push request failed: ' . $response->get_error_message());
			return new WP_Error('wc_mpesa_unreachable', __('M-Pesa is not reachable right now. Please try again in a moment.', 'woocommerce-mpesa-express'));
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ((string) ($body['ResponseCode'] ?? '') === '0' && !empty($body['CheckoutRequestID'])) {
			$order->update_meta_data(self::META_CHECKOUT_REQUESTID, sanitize_text_field($body['CheckoutRequestID']));
			if (!empty($body['MerchantRequestID'])) {
				$order->update_meta_data(self::META_MERCHANT_REQUESTID, sanitize_text_field($body['MerchantRequestID']));
			}
			$order->update_meta_data(self::META_PHONE, $phone);
			$order->update_meta_data(self::META_SENT_AT, time());
			$order->delete_meta_data(self::META_LAST_QUERY);
			$order->delete_meta_data(self::META_LAST_RESULT);
			$order->add_order_note(sprintf(
				/* translators: 1: amount 2: masked phone 3: account reference */
				__('M-Pesa payment request sent: KES %1$d to %2$s, account %3$s.', 'woocommerce-mpesa-express'),
				$amount_int,
				substr($phone, 0, 6) . '***' . substr($phone, -3),
				$reference
			));
			$order->save();
			return true;
		}

		$this->log('STK push rejected: ' . wp_remote_retrieve_body($response));
		$msg = $body['errorMessage'] ?? $body['CustomerMessage'] ?? __('Unknown error from M-Pesa.', 'woocommerce-mpesa-express');
		return new WP_Error('wc_mpesa_rejected', __('M-Pesa could not send the payment prompt: ', 'woocommerce-mpesa-express') . $msg);
	}

	/**
	 * Sends the prompt again from the waiting screen: unpaid orders only, at most MAX_RESENDS times,
	 * not within RESEND_WAIT seconds of the last prompt.
	 *
	 * @return true|WP_Error
	 */
	public function resend_stk(WC_Order $order) {
		if ($order->is_paid()) {
			return new WP_Error('wc_mpesa_paid', __('This order is already paid.', 'woocommerce-mpesa-express'));
		}
		if (!$order->has_status(array('on-hold', 'pending', 'failed'))) {
			return new WP_Error('wc_mpesa_status', __('This order can no longer be paid here.', 'woocommerce-mpesa-express'));
		}
		$resends = (int) $order->get_meta(self::META_RESENDS);
		if ($resends >= self::MAX_RESENDS) {
			return new WP_Error('wc_mpesa_limit', __('The prompt has been resent several times. Please pay using the PayBill details below.', 'woocommerce-mpesa-express'));
		}
		$wait = (int) $order->get_meta(self::META_SENT_AT) + self::RESEND_WAIT - time();
		if ($wait > 0) {
			/* translators: %d: seconds */
			return new WP_Error('wc_mpesa_wait', sprintf(__('Please wait %d seconds before asking for another prompt.', 'woocommerce-mpesa-express'), $wait));
		}
		$sent = $this->send_stk($order);
		if (is_wp_error($sent)) return $sent;

		$order = wc_get_order($order->get_id());
		$order->update_meta_data(self::META_RESENDS, $resends + 1);
		if ($order->has_status('failed')) {
			$order->update_status('on-hold', __('Customer asked for a new M-Pesa prompt.', 'woocommerce-mpesa-express'));
		}
		$order->save();
		return true;
	}

	/* ---------- Status ---------- */

	/**
	 * When the callback is late, asks Daraja for the prompt's result (STK Query), rate-limited per order.
	 * Settles the order the same way the callback would.
	 */
	public function maybe_query_status(WC_Order $order): void {
		if ($order->is_paid() || !$order->has_status(array('on-hold', 'pending'))) return;
		$checkout_id = (string) $order->get_meta(self::META_CHECKOUT_REQUESTID);
		$sent_at     = (int) $order->get_meta(self::META_SENT_AT);
		$last        = (int) $order->get_meta(self::META_LAST_QUERY);
		if ($checkout_id === '' || time() - $sent_at < self::QUERY_AFTER || time() - $last < self::QUERY_EVERY) return;

		$order->update_meta_data(self::META_LAST_QUERY, time());
		$order->save();

		$token = $this->generate_access_token();
		if (!$token) return;
		$timestamp = wp_date('YmdHis', null, new DateTimeZone('Africa/Nairobi'));
		$response  = wp_remote_post($this->api_base_url() . '/mpesa/stkpushquery/v1/query', array(
			'timeout' => 20,
			'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
			'body'    => wp_json_encode(array(
				'BusinessShortCode' => $this->credential('business_shortcode'),
				'Password'          => $this->stk_password($timestamp),
				'Timestamp'         => $timestamp,
				'CheckoutRequestID' => $checkout_id,
			)),
		));
		if (is_wp_error($response)) return;
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($body) || !isset($body['ResultCode']) || (int) $body['ResultCode'] === 4999) {
			return; // Still processing (errorCode 500.001.1001 or ResultCode 4999) or an error: try again later.
		}
		$this->settle($order, (int) $body['ResultCode'], (string) ($body['ResultDesc'] ?? ''), '', null, 'query');
	}

	/**
	 * Applies a prompt's result to the order (from the callback or an STK Query).
	 *
	 * @param float|null $amount Amount Safaricom reports as paid (callback only).
	 */
	private function settle(WC_Order $order, int $result_code, string $result_desc, string $receipt, ?float $amount, string $source): void {
		if ($order->has_status(array('processing', 'completed', 'refunded')) || $order->is_paid()) return;

		if ($result_code === 0) {
			$due = (int) ceil($this->convert_to_kes((float) $order->get_total()));
			if ($amount !== null && $amount + 0.001 < $due) {
				$order->update_meta_data(self::META_LAST_RESULT, __('Paid less than the order total.', 'woocommerce-mpesa-express'));
				$order->save();
				$order->update_status('on-hold', sprintf(
					/* translators: 1: paid 2: due 3: receipt */
					__('M-Pesa paid KES %1$s but the order total is KES %2$s (receipt %3$s). Left On hold for review.', 'woocommerce-mpesa-express'),
					wc_format_decimal($amount, 2),
					$due,
					$receipt !== '' ? $receipt : '-'
				));
				return;
			}
			if ($receipt !== '') {
				$order->update_meta_data(self::META_RECEIPT, $receipt);
			}
			$order->delete_meta_data(self::META_LAST_RESULT);
			$order->save();
			$order->payment_complete($receipt);
			$order->add_order_note($receipt !== ''
				? sprintf(__('M-Pesa payment confirmed. Receipt: %s', 'woocommerce-mpesa-express'), $receipt)
				: ($source === 'query'
					? __('M-Pesa payment confirmed by STK status query (the callback had not arrived). The receipt number will be on the PayBill statement.', 'woocommerce-mpesa-express')
					: __('M-Pesa payment confirmed.', 'woocommerce-mpesa-express')));
			if ($this->get_option('force_complete_after_payment') === 'yes') {
				$order->update_status('completed', __('Order auto-completed after M-Pesa confirmation.', 'woocommerce-mpesa-express'));
			}
			return;
		}

		$friendly = array(
			1032 => __('The payment request was cancelled on the phone.', 'woocommerce-mpesa-express'),
			1037 => __('The phone did not respond in time.', 'woocommerce-mpesa-express'),
			1    => __('The M-Pesa balance was not enough.', 'woocommerce-mpesa-express'),
			2001 => __('The M-Pesa PIN was wrong.', 'woocommerce-mpesa-express'),
		);
		$order->update_meta_data(self::META_LAST_RESULT, $friendly[$result_code] ?? __('The payment was not completed.', 'woocommerce-mpesa-express'));
		$order->save();
		$order->add_order_note(sprintf(__('M-Pesa payment not completed: %1$s (%2$s)', 'woocommerce-mpesa-express'), $result_desc, $result_code));
		if ($this->get_option('mark_failed_on_error') === 'yes') {
			$order->update_status('failed', $result_desc);
		}
	}

	public function handle_callback() {
		$this->verify_ipn_token();

		$payload = file_get_contents('php://input');
		$data    = json_decode($payload, true);
		$this->log('STK callback received: ' . $payload);

		status_header(200);
		header('Content-Type: application/json');

		$callback = $data['Body']['stkCallback'] ?? null;
		if (!$callback) {
			echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'OK'));
			exit;
		}

		$checkout_id = (string) ($callback['CheckoutRequestID'] ?? '');
		$orders = $checkout_id === '' ? array() : wc_get_orders(array(
			'limit'      => 1,
			'type'       => 'shop_order',
			'status'     => array_keys(wc_get_order_statuses()),
			'meta_key'   => self::META_CHECKOUT_REQUESTID,
			'meta_value' => $checkout_id,
		));
		$order = $orders ? $orders[0] : null;

		if ($order) {
			$receipt = '';
			$amount  = null;
			foreach ((array) ($callback['CallbackMetadata']['Item'] ?? array()) as $item) {
				$name = (string) ($item['Name'] ?? '');
				if ($name === 'MpesaReceiptNumber') {
					$receipt = sanitize_text_field((string) ($item['Value'] ?? ''));
				} elseif ($name === 'Amount') {
					$amount = (float) ($item['Value'] ?? 0);
				}
			}
			$this->settle($order, (int) ($callback['ResultCode'] ?? 1), (string) ($callback['ResultDesc'] ?? ''), $receipt, $amount, 'callback');
		} else {
			$this->log("STK callback: no order found for CheckoutRequestID '{$checkout_id}'.", 'warning');
		}

		echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Success'));
		exit;
	}

	/* ---------- Waiting screen ---------- */

	public function render_waiting_screen_view_order($order_id) {
		$order = wc_get_order($order_id);
		if ($order && $order->get_payment_method() === $this->id) {
			$this->render_waiting_screen($order_id);
		}
	}

	/**
	 * "Check your phone" box on the order-received page: polls the order, offers a resend, and shows
	 * the PayBill details as a fallback. Paid orders show nothing extra (WooCommerce lists the downloads).
	 */
	public function render_waiting_screen($order_id) {
		static $done = false;
		$order = wc_get_order($order_id);
		if ($done || !$order || $order->is_paid() || !$order->has_status(array('on-hold', 'pending', 'failed'))) return;
		$done = true;

		$phone = (string) $order->get_meta(self::META_PHONE);
		$shown = $phone !== '' ? '0' . substr($phone, 3, 3) . ' *** ' . substr($phone, -3) : '';
		$due   = (int) ceil($this->convert_to_kes((float) $order->get_total()));
		$state = $order->has_status('failed') ? 'failed' : 'waiting';

		wp_enqueue_script('wc-mpesa-express-wait', plugins_url('assets/js/wait.js', WC_MPESA_EXPRESS_FILE), array(), WC_MPESA_EXPRESS_VERSION, array('in_footer' => true, 'strategy' => 'defer'));
		wp_enqueue_style('wc-mpesa-express-wait', plugins_url('assets/css/wait.css', WC_MPESA_EXPRESS_FILE), array(), WC_MPESA_EXPRESS_VERSION);
		wp_localize_script('wc-mpesa-express-wait', 'wcMpesaWait', array(
			'statusUrl' => rest_url('wc-mpesa/v1/orders/' . $order->get_id() . '/status'),
			'resendUrl' => rest_url('wc-mpesa/v1/orders/' . $order->get_id() . '/resend'),
			'key'       => $order->get_order_key(),
			'reloadUrl' => $order->get_checkout_order_received_url(),
			'interval'  => 5000,
			'timeout'   => 180000,
			'text'      => array(
				'paid'    => __('Payment received. Loading your order…', 'woocommerce-mpesa-express'),
				'failed'  => __('The payment was not completed.', 'woocommerce-mpesa-express'),
				'timeout' => __('We have not received a confirmation yet. If you entered your PIN, wait a moment; otherwise ask for a new prompt.', 'woocommerce-mpesa-express'),
				'sending' => __('Sending…', 'woocommerce-mpesa-express'),
				'error'   => __('Could not check the payment. Check your connection.', 'woocommerce-mpesa-express'),
			),
		));
		?>
		<section class="wc-mpesa-wait" data-state="<?php echo esc_attr($state); ?>" aria-live="polite">
			<h2 class="wc-mpesa-wait__title"><?php esc_html_e('Complete your M-Pesa payment', 'woocommerce-mpesa-express'); ?></h2>
			<div class="wc-mpesa-wait__waiting">
				<span class="wc-mpesa-wait__spinner" aria-hidden="true"></span>
				<p><?php
				echo $shown !== ''
					? sprintf(
						/* translators: 1: masked phone 2: amount */
						esc_html__('We sent a payment request of KES %2$s to %1$s. Unlock your phone and enter your M-Pesa PIN.', 'woocommerce-mpesa-express'),
						'<strong>' . esc_html($shown) . '</strong>',
						esc_html(number_format_i18n($due))
					)
					: esc_html__('We sent a payment request to your phone. Enter your M-Pesa PIN to pay.', 'woocommerce-mpesa-express');
				?></p>
				<p class="wc-mpesa-wait__hint"><?php esc_html_e('This page updates by itself once the payment is confirmed.', 'woocommerce-mpesa-express'); ?></p>
			</div>
			<p class="wc-mpesa-wait__message" role="status"><?php echo esc_html((string) $order->get_meta(self::META_LAST_RESULT)); ?></p>
			<p><button type="button" class="button wc-mpesa-wait__resend"><?php esc_html_e('Send the prompt again', 'woocommerce-mpesa-express'); ?></button></p>
			<details class="wc-mpesa-wait__paybill">
				<summary><?php esc_html_e('No prompt? Pay with PayBill instead', 'woocommerce-mpesa-express'); ?></summary>
				<ol>
					<li><?php esc_html_e('Go to M-Pesa, then Lipa na M-Pesa, then Pay Bill.', 'woocommerce-mpesa-express'); ?></li>
					<li><?php printf(esc_html__('Business number: %s', 'woocommerce-mpesa-express'), '<strong>' . esc_html($this->credential('business_shortcode')) . '</strong>'); ?></li>
					<li><?php printf(esc_html__('Account number: %s', 'woocommerce-mpesa-express'), '<strong>' . esc_html($this->account_reference($order)) . '</strong>'); ?></li>
					<li><?php printf(esc_html__('Amount: KES %s', 'woocommerce-mpesa-express'), '<strong>' . esc_html(number_format_i18n($due)) . '</strong>'); ?></li>
				</ol>
				<p><?php esc_html_e('PayBill payments are confirmed by our team during working hours; you will get an email when your order is ready.', 'woocommerce-mpesa-express'); ?></p>
			</details>
		</section>
		<?php
	}
}
