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

	/**
	 * A credential: the wp-config.php constant when it is defined (so secrets stay out of the
	 * database), otherwise the saved gateway setting.
	 * Constants: WC_MPESA_CONSUMER_KEY, WC_MPESA_CONSUMER_SECRET, WC_MPESA_PASSKEY, WC_MPESA_SHORTCODE.
	 */
	private function credential(string $key): string {
		$constants = array(
			'consumer_key'       => 'WC_MPESA_CONSUMER_KEY',
			'consumer_secret'    => 'WC_MPESA_CONSUMER_SECRET',
			'passkey'            => 'WC_MPESA_PASSKEY',
			'business_shortcode' => 'WC_MPESA_SHORTCODE',
		);
		if (isset($constants[$key]) && defined($constants[$key]) && trim((string) constant($constants[$key])) !== '') {
			return trim((string) constant($constants[$key]));
		}
		return trim((string) $this->get_option($key));
	}

	/** Whether a credential comes from wp-config.php (the settings field is then ignored). */
	private function credential_from_constant(string $key): bool {
		$map = array('consumer_key' => 'WC_MPESA_CONSUMER_KEY', 'consumer_secret' => 'WC_MPESA_CONSUMER_SECRET', 'passkey' => 'WC_MPESA_PASSKEY', 'business_shortcode' => 'WC_MPESA_SHORTCODE');
		return isset($map[$key]) && defined($map[$key]) && trim((string) constant($map[$key])) !== '';
	}

	private function generate_access_token() {
		$cached = get_transient('wc_mpesa_token_' . md5($this->id . $this->credential('consumer_key') . $this->get_option('environment')));
		if (is_string($cached) && $cached !== '') return $cached;

		$consumer_key    = $this->credential('consumer_key');
		$consumer_secret = $this->credential('consumer_secret');

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

		// Daraja tokens last 3600 s; keep a margin.
		$ttl = max(60, (int) ($body['expires_in'] ?? 3599) - 120);
		set_transient('wc_mpesa_token_' . md5($this->id . $consumer_key . $this->get_option('environment')), $body['access_token'], $ttl);

		return $body['access_token'];
	}

	/** Base64(shortcode + passkey + timestamp), as the STK Push and STK Query APIs expect. */
	private function stk_password(string $timestamp): string {
		return base64_encode($this->credential('business_shortcode') . $this->credential('passkey') . $timestamp);
	}

	private function convert_to_kes(float $amount): float {
		if (get_woocommerce_currency() === 'KES') return $amount;

		$rate = (float) $this->get_option('exchange_rate', 1);
		if ($rate <= 0) $rate = 1;

		return $amount * $rate;
	}

	/** Any Kenyan mobile format (07…, 01…, 7…, 1…, +254…, 254…) to 2547XXXXXXXX / 2541XXXXXXXX. */
	private function normalize_msisdn(string $phone): string {
		$phone = preg_replace('/[^\d+]/', '', trim($phone));

		if (substr($phone, 0, 1) === '+') $phone = substr($phone, 1);

		if (preg_match('/^0([71]\d{8})$/', $phone, $m)) return '254' . $m[1];
		if (preg_match('/^([71]\d{8})$/', $phone, $m)) return '254' . $m[1];

		return $phone;
	}

	private function is_valid_msisdn(string $msisdn): bool {
		return (bool) preg_match('/^254[71]\d{8}$/', $msisdn);
	}

	/** Build a callback URL for a given woocommerce_api action, with the shared IPN secret appended. */
	private function secure_callback_url(string $wc_api_action): string {
		$url = WC()->api_request_url($wc_api_action);
		return add_query_arg('token', WC_Mpesa_Express_Plugin::get_ipn_secret(), $url);
	}

	/**
	 * Verify the incoming IPN request: the per-site secret token, and (unless turned off in
	 * the gateway settings) that it comes from Safaricom's callback addresses.
	 * Halts the request with a 403 JSON response on failure.
	 */
	private function verify_ipn_token(): void {
		$token  = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
		$secret = WC_Mpesa_Express_Plugin::get_ipn_secret();
		$ip     = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

		$reason = '';
		if ($token === '' || !hash_equals($secret, $token)) {
			$reason = 'invalid or missing token';
		} elseif ($this->get_option('restrict_callback_ips', 'yes') === 'yes' && !WC_Mpesa_Express_Plugin::is_safaricom_ip($ip)) {
			$reason = 'address ' . $ip . ' is not a Safaricom callback address';
		}

		if ($reason !== '') {
			// Logged whole, so a real payment from a new Safaricom address can still be reconciled.
			$this->log('Rejected IPN callback (' . $reason . '): ' . file_get_contents('php://input'), 'warning');
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
