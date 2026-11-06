<?php

if (!defined('ABSPATH')) exit;

class WC_Gateway_Mpesa_Express extends WC_Payment_Gateway {

	use WC_Mpesa_Helpers_Trait;

	const META_PHONE              = '_mpesa_phone';
	const META_CHECKOUT_REQUESTID = '_mpesa_checkout_request_id';
	const META_MERCHANT_REQUESTID = '_mpesa_merchant_request_id';
	const META_RECEIPT            = '_mpesa_receipt';

	public function __construct() {
		$this->id                 = 'mpesa_express';
		$this->icon               = plugins_url('images/lipa-na-mpesa.png', WC_MPESA_EXPRESS_FILE);
		$this->has_fields         = false;
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

		// Checkout phone field
		add_filter('woocommerce_checkout_fields', array($this, 'add_checkout_phone_field'));
		add_action('woocommerce_checkout_create_order', array($this, 'save_checkout_phone_to_order'), 10, 2);

		// Validate phone when this gateway is selected
		add_action('woocommerce_after_checkout_validation', array($this, 'validate_checkout_phone'), 10, 2);
	}

	public function is_available() {
		if (!parent::is_available()) return false;

		return trim((string) $this->get_option('business_shortcode')) !== ''
			&& trim((string) $this->get_option('passkey')) !== ''
			&& trim((string) $this->get_option('consumer_key')) !== ''
			&& trim((string) $this->get_option('consumer_secret')) !== '';
	}

	public function init_form_fields() {
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
				'default' => __('M-Pesa Express', 'woocommerce-mpesa-express'),
			),
			'description' => array(
				'title'   => __('Description', 'woocommerce-mpesa-express'),
				'type'    => 'textarea',
				'default' => __('Pay with M-Pesa STK Push (PayBill).', 'woocommerce-mpesa-express'),
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
				'description' => __('Your PayBill shortcode provisioned for STK Push.', 'woocommerce-mpesa-express'),
				'default'     => '',
			),

			'passkey' => array(
				'title'   => __('Passkey', 'woocommerce-mpesa-express'),
				'type'    => 'password',
				'default' => '',
			),

			'consumer_key' => array(
				'title'   => __('Consumer Key', 'woocommerce-mpesa-express'),
				'type'    => 'text',
				'default' => '',
			),

			'consumer_secret' => array(
				'title'   => __('Consumer Secret', 'woocommerce-mpesa-express'),
				'type'    => 'password',
				'default' => '',
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

	/** Add dedicated phone field (shown for every gateway; only required when this gateway is selected). */
	public function add_checkout_phone_field($fields) {
		$fields['billing']['mpesa_phone'] = array(
			'type'        => 'text',
			'label'       => __('M-Pesa Phone Number', 'woocommerce-mpesa-express'),
			'placeholder' => __('07XXXXXXXX or 2547XXXXXXXX', 'woocommerce-mpesa-express'),
			'required'    => false, // we validate conditionally only when gateway selected
			'class'       => array('form-row-wide'),
			'priority'    => 95,
			'clear'       => true,
		);
		return $fields;
	}

	public function validate_checkout_phone($data, $errors) {
		if (empty($data['payment_method']) || $data['payment_method'] !== $this->id) return;

		$raw = isset($_POST['mpesa_phone']) ? wc_clean(wp_unslash($_POST['mpesa_phone'])) : '';
		if ($raw === '') {
			$errors->add('mpesa_phone_missing', __('Please enter the M-Pesa phone number for STK Push.', 'woocommerce-mpesa-express'));
			return;
		}

		$msisdn = $this->normalize_msisdn($raw);
		if (!$this->is_valid_msisdn($msisdn)) {
			$errors->add('mpesa_phone_invalid', __('Invalid M-Pesa phone number. Use 07XXXXXXXX or 2547XXXXXXXX.', 'woocommerce-mpesa-express'));
		}
	}

	public function save_checkout_phone_to_order($order, $data) {
		if (!empty($_POST['mpesa_phone'])) {
			$raw    = wc_clean(wp_unslash($_POST['mpesa_phone']));
			$msisdn = $this->normalize_msisdn($raw);
			$order->update_meta_data(self::META_PHONE, $msisdn);
		}
	}

	public function process_payment($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			wc_add_notice(__('Payment error: invalid order.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		$access_token = $this->generate_access_token();
		if (!$access_token) {
			wc_add_notice(__('Payment error: unable to generate access token. Check credentials.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		$shortcode = trim((string) $this->get_option('business_shortcode'));
		$passkey   = trim((string) $this->get_option('passkey'));
		if ($shortcode === '' || $passkey === '') {
			wc_add_notice(__('Payment error: missing PayBill shortcode or passkey in settings.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		// Use the dedicated M-Pesa phone field (fallback to billing phone)
		$phone = (string) $order->get_meta(self::META_PHONE);
		if ($phone === '') {
			$phone = (string) $order->get_billing_phone();
		}
		$phone = $this->normalize_msisdn($phone);

		if (!$this->is_valid_msisdn($phone)) {
			wc_add_notice(__('Payment error: invalid M-Pesa phone number.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		$kes_amount = $this->convert_to_kes((float) $order->get_total());
		$amount_int = (int) round($kes_amount);
		if ($amount_int < 1) {
			wc_add_notice(__('Payment error: amount is invalid.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		$timestamp = gmdate('YmdHis');
		$password  = base64_encode($shortcode . $passkey . $timestamp);

		$callback_url = $this->secure_callback_url('wc_mpesa_express');

		$payload = array(
			'BusinessShortCode' => $shortcode,
			'Password'          => $password,
			'Timestamp'         => $timestamp,
			'TransactionType'   => 'CustomerPayBillOnline',
			'Amount'            => $amount_int,
			'PartyA'            => $phone,
			'PartyB'            => $shortcode,
			'PhoneNumber'       => $phone,
			'CallBackURL'       => $callback_url,
			'AccountReference'  => 'Order ' . $order_id,
			'TransactionDesc'   => 'Payment for order ' . $order_id,
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
			wc_add_notice(__('Payment error: unable to reach M-Pesa.', 'woocommerce-mpesa-express'), 'error');
			return array('result' => 'fail');
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (($body['ResponseCode'] ?? null) === '0') {
			if (!empty($body['CheckoutRequestID'])) {
				$order->update_meta_data(self::META_CHECKOUT_REQUESTID, sanitize_text_field($body['CheckoutRequestID']));
			}
			if (!empty($body['MerchantRequestID'])) {
				$order->update_meta_data(self::META_MERCHANT_REQUESTID, sanitize_text_field($body['MerchantRequestID']));
			}
			$order->save();

			// BEFORE callback confirmation: on-hold
			$order->update_status('on-hold', __('Awaiting M-Pesa payment confirmation (STK Push).', 'woocommerce-mpesa-express'));

			WC()->cart->empty_cart();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url($order),
			);
		}

		$this->log('STK push rejected: ' . wp_remote_retrieve_body($response));

		$msg = $body['errorMessage'] ?? $body['CustomerMessage'] ?? __('Unknown error from M-Pesa.', 'woocommerce-mpesa-express');
		wc_add_notice(__('Payment error: ', 'woocommerce-mpesa-express') . $msg, 'error');
		return array('result' => 'fail');
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

		$result_code = (int) ($callback['ResultCode'] ?? 1);
		$result_desc = (string) ($callback['ResultDesc'] ?? '');
		$checkout_id = (string) ($callback['CheckoutRequestID'] ?? '');

		// Find order by CheckoutRequestID
		$orders = wc_get_orders(array(
			'limit'      => 1,
			'type'       => 'shop_order',
			'status'     => array_keys(wc_get_order_statuses()),
			'meta_key'   => self::META_CHECKOUT_REQUESTID,
			'meta_value' => $checkout_id,
		));

		$order = $orders ? $orders[0] : null;

		if ($order) {
			// Callbacks can be redelivered by Safaricom; don't reprocess a settled order.
			if ($order->has_status(array('processing', 'completed', 'refunded'))) {
				echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Success'));
				exit;
			}

			if ($result_code === 0) {

				// Extract receipt, amount, etc (optional)
				$receipt = '';
				$items = $callback['CallbackMetadata']['Item'] ?? array();
				foreach ($items as $item) {
					if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
						$receipt = (string) ($item['Value'] ?? '');
						break;
					}
				}

				if ($receipt !== '') {
					$order->update_meta_data(self::META_RECEIPT, $receipt);
					$order->save();
				}

				// AFTER callback confirmation: mark paid (typically -> processing).
				// Note: needs_payment() excludes 'on-hold' by default, but payment_complete()
				// itself accepts it — the earlier has_status() guard above already prevents
				// reprocessing an order that was settled by a previous callback delivery.
				if (!$order->is_paid()) {
					$order->payment_complete($receipt ?: '');
				}

				$order->add_order_note(
					$receipt
						? sprintf(__('M-Pesa payment confirmed. Receipt: %s', 'woocommerce-mpesa-express'), $receipt)
						: __('M-Pesa payment confirmed.', 'woocommerce-mpesa-express')
				);

				// Optionally force completed
				if ($this->get_option('force_complete_after_payment') === 'yes') {
					$order->update_status('completed', __('Order auto-completed after M-Pesa confirmation.', 'woocommerce-mpesa-express'));
				}
			} else {
				$order->add_order_note(sprintf(__('M-Pesa payment not completed: %1$s (%2$s)', 'woocommerce-mpesa-express'), $result_desc, $result_code));

				if ($this->get_option('mark_failed_on_error') === 'yes') {
					$order->update_status('failed', $result_desc);
				}
			}
		} else {
			$this->log("STK callback: no order found for CheckoutRequestID '{$checkout_id}'.", 'warning');
		}

		echo wp_json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Success'));
		exit;
	}
}
