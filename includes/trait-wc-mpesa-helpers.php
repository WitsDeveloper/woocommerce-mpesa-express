<?php

if (!defined('ABSPATH')) exit;

/**
 * Shared helpers for the M-Pesa gateway classes (Daraja auth, formatting, IPN security, logging).
 */
trait WC_Mpesa_Helpers_Trait {

	private function api_base_url(): string {
		return ($this->get_option('environment') === 'live')
			? 'https://api.safaricom.co.ke'
			: 'https://sandbox.safaricom.co.ke';
	}

	private function generate_access_token() {
		$consumer_key    = trim((string) $this->get_option('consumer_key'));
		$consumer_secret = trim((string) $this->get_option('consumer_secret'));

		if ($consumer_key === '' || $consumer_secret === '') return false;

		$credentials = base64_encode($consumer_key . ':' . $consumer_secret);

		$response = wp_remote_get($this->api_base_url() . '/oauth/v1/generate?grant_type=client_credentials', array(
			'timeout' => 30,
			'headers' => array('Authorization' => 'Basic ' . $credentials),
		));

		if (is_wp_error($response)) {
			$this->log('Access token request failed: ' . $response->get_error_message());
			return false;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (empty($body['access_token'])) {
			$this->log('Access token response missing token: ' . wp_remote_retrieve_body($response));
			return false;
		}

		return $body['access_token'];
	}

	private function convert_to_kes(float $amount): float {
		if (get_woocommerce_currency() === 'KES') return $amount;

		$rate = (float) $this->get_option('exchange_rate', 1);
		if ($rate <= 0) $rate = 1;

		return $amount * $rate;
	}

	private function normalize_msisdn(string $phone): string {
		$phone = trim($phone);
		$phone = preg_replace('/\s+/', '', $phone);
		$phone = str_replace(array('-', '(', ')'), '', $phone);

		if (substr($phone, 0, 1) === '+') $phone = substr($phone, 1);

		// 07XXXXXXXX -> 2547XXXXXXXX
		if (preg_match('/^0(7\d{8})$/', $phone, $m)) return '254' . $m[1];

		// 7XXXXXXXX -> 2547XXXXXXXX
		if (preg_match('/^(7\d{8})$/', $phone, $m)) return '254' . $m[1];

		return $phone; // might already be 2547XXXXXXXX
	}

	private function is_valid_msisdn(string $msisdn): bool {
		return (bool) preg_match('/^2547\d{8}$/', $msisdn);
	}

	/** Build a callback URL for a given woocommerce_api action, with the shared IPN secret appended. */
	private function secure_callback_url(string $wc_api_action): string {
		$url = WC()->api_request_url($wc_api_action);
		return add_query_arg('token', WC_Mpesa_Express_Plugin::get_ipn_secret(), $url);
	}

	/**
	 * Verify the incoming IPN request carries the correct secret token.
	 * Halts the request with a 403 JSON response on failure.
	 */
	private function verify_ipn_token(): void {
		$token  = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
		$secret = WC_Mpesa_Express_Plugin::get_ipn_secret();

		if ($token === '' || !hash_equals($secret, $token)) {
			$this->log('Rejected IPN callback: invalid or missing token.', 'warning');
			status_header(403);
			header('Content-Type: application/json');
			echo wp_json_encode(array('ResultCode' => 1, 'ResultDesc' => 'Forbidden'));
			exit;
		}
	}

	private function log(string $message, string $level = 'info'): void {
		if (!function_exists('wc_get_logger')) return;
		wc_get_logger()->log($level, $message, array('source' => 'mpesa-express'));
	}
}
