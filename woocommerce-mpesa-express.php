<?php

/**
 * Plugin Name: WooCommerce Mpesa Express
 * Description: WooCommerce payment gateway for Safaricom M-Pesa — M-Pesa Express (STK Push) and PayBill (manual / C2B), with IPN callback handling.
 * Version: 2.1.1
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Sammy Waweru
 * License: GPLv3 or later
 */

if (!defined('ABSPATH')) exit;

define('WC_MPESA_EXPRESS_FILE', __FILE__);
define('WC_MPESA_EXPRESS_DIR', plugin_dir_path(__FILE__));
define('WC_MPESA_EXPRESS_VERSION', '2.1.1');

/**
 * Plugin-wide helpers that don't depend on WooCommerce being loaded yet.
 */
class WC_Mpesa_Express_Plugin {

	const IPN_SECRET_OPTION = 'wc_mpesa_ipn_secret';

	/** Safaricom's callback ranges (every live callback seen since Oct 2026 came from these). */
	const SAFARICOM_RANGES = array('196.201.212.0/24', '196.201.213.0/24', '196.201.214.0/24');

	public static function activate() {
		self::get_ipn_secret();
	}

	/** A per-install secret appended to every IPN callback URL so unauthenticated requests are rejected. */
	public static function get_ipn_secret(): string {
		$secret = get_option(self::IPN_SECRET_OPTION);
		if (!$secret) {
			$secret = wp_generate_password(32, false, false);
			update_option(self::IPN_SECRET_OPTION, $secret);
		}
		return $secret;
	}

	/**
	 * Whether an address may deliver M-Pesa callbacks: Safaricom's ranges, plus loopback on a
	 * local copy. Filter 'wc_mpesa_callback_ranges' to add a range Safaricom starts using.
	 */
	public static function is_safaricom_ip(string $ip): bool {
		if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local' && in_array($ip, array('127.0.0.1', '::1'), true)) {
			return true;
		}
		$long = ip2long($ip);
		if ($long === false) return false;
		foreach ((array) apply_filters('wc_mpesa_callback_ranges', self::SAFARICOM_RANGES) as $range) {
			[$net, $bits] = array_pad(explode('/', (string) $range), 2, '32');
			$mask = -1 << (32 - (int) $bits);
			if (($long & $mask) === (ip2long($net) & $mask)) return true;
		}
		return false;
	}

	/**
	 * "Register URLs with Safaricom" overwrites the shortcode's C2B confirmation URL. On a PayBill
	 * shared with another system (Finstock: the LMS owns the college PayBill's C2B URLs) that
	 * silently breaks the other system, so it is off unless wp-config.php defines
	 * WC_MPESA_ALLOW_REGISTER_URLS as true.
	 */
	public static function register_urls_allowed(): bool {
		return defined('WC_MPESA_ALLOW_REGISTER_URLS') && WC_MPESA_ALLOW_REGISTER_URLS === true;
	}
}

register_activation_hook(__FILE__, array('WC_Mpesa_Express_Plugin', 'activate'));

// Declare High-Performance Order Storage (HPOS) compatibility.
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WC_MPESA_EXPRESS_FILE, true);
	}
});

add_action('plugins_loaded', function () {

	if (!class_exists('WC_Payment_Gateway')) return;

	require_once WC_MPESA_EXPRESS_DIR . 'includes/trait-wc-mpesa-helpers.php';
	require_once WC_MPESA_EXPRESS_DIR . 'includes/class-wc-gateway-mpesa-express.php';
	require_once WC_MPESA_EXPRESS_DIR . 'includes/class-wc-gateway-mpesa-c2b.php';

	add_filter('woocommerce_payment_gateways', function ($methods) {
		$methods[] = 'WC_Gateway_Mpesa_Express';
		$methods[] = 'WC_Gateway_Mpesa_C2B';
		return $methods;
	});
}, 11);

/**
 * No "Order on hold" email to the customer for STK orders: On hold only means "waiting for the
 * phone prompt", the waiting screen says so, and the paid email follows seconds later. Staff
 * still get New order; a short payment stays On hold with an order note for review.
 */
add_filter('woocommerce_email_enabled_customer_on_hold_order', function ($enabled, $order) {
	return ($order instanceof WC_Order && $order->get_payment_method() === 'mpesa_express') ? false : $enabled;
}, 10, 2);

/**
 * The STK gateway instance (loaded by WooCommerce).
 */
function wc_mpesa_express_gateway(): ?WC_Gateway_Mpesa_Express {
	if (!function_exists('WC') || !class_exists('WC_Gateway_Mpesa_Express')) return null;
	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = $gateways['mpesa_express'] ?? null;
	return $gateway instanceof WC_Gateway_Mpesa_Express ? $gateway : null;
}

/**
 * Payment status routes used by the "check your phone" screen on the order-received page.
 * Each call must carry the order key, which only the customer (and staff) have.
 *   POST /wp-json/wc-mpesa/v1/orders/<id>/status   current status (and an STK status query when due)
 *   POST /wp-json/wc-mpesa/v1/orders/<id>/resend   send the STK push again (limited)
 */
add_action('rest_api_init', function () {
	$args = array(
		'id'  => array('type' => 'integer', 'required' => true),
		'key' => array('type' => 'string', 'required' => true),
	);
	$order_for = function (WP_REST_Request $request) {
		$order = wc_get_order((int) $request['id']);
		if (!$order || $order->get_payment_method() !== 'mpesa_express' || !hash_equals($order->get_order_key(), (string) $request['key'])) {
			return new WP_Error('wc_mpesa_not_found', __('Order not found.', 'woocommerce-mpesa-express'), array('status' => 404));
		}
		return $order;
	};
	register_rest_route('wc-mpesa/v1', '/orders/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'args'                => $args,
		'callback'            => function (WP_REST_Request $request) use ($order_for) {
			$order = $order_for($request);
			if (is_wp_error($order)) return $order;
			$gateway = wc_mpesa_express_gateway();
			if ($gateway) $gateway->maybe_query_status($order);
			$response = rest_ensure_response(wc_mpesa_express_status_payload(wc_get_order($order->get_id())));
			$response->header('Cache-Control', 'no-store');
			return $response;
		},
	));
	register_rest_route('wc-mpesa/v1', '/orders/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'args'                => $args,
		'callback'            => function (WP_REST_Request $request) use ($order_for) {
			$order = $order_for($request);
			if (is_wp_error($order)) return $order;
			$gateway = wc_mpesa_express_gateway();
			$result  = $gateway ? $gateway->resend_stk($order) : new WP_Error('wc_mpesa_off', __('M-Pesa is not available.', 'woocommerce-mpesa-express'));
			$payload = wc_mpesa_express_status_payload(wc_get_order($order->get_id()));
			$payload['message'] = is_wp_error($result) ? $result->get_error_message() : __('A new payment request has been sent to your phone.', 'woocommerce-mpesa-express');
			$payload['resent']  = !is_wp_error($result);
			$response = rest_ensure_response($payload);
			$response->header('Cache-Control', 'no-store');
			return $response;
		},
	));
});

/**
 * What the waiting screen needs to know about an order.
 */
function wc_mpesa_express_status_payload(WC_Order $order): array {
	$state = 'waiting';
	if ($order->is_paid()) {
		$state = 'paid';
	} elseif ($order->has_status(array('failed', 'cancelled'))) {
		$state = 'failed';
	}
	return array(
		'state'   => $state,
		'status'  => $order->get_status(),
		'note'    => (string) $order->get_meta('_mpesa_last_result'),
		'resends' => (int) $order->get_meta('_mpesa_resends'),
	);
}

/**
 * Admin-post handler for the "Register URLs with Safaricom" button on the
 * M-Pesa PayBill (C2B) settings screen. Off unless WC_MPESA_ALLOW_REGISTER_URLS is true.
 */
add_action('admin_post_wc_mpesa_c2b_register_urls', function () {
	if (!current_user_can('manage_woocommerce')) {
		wp_die(esc_html__('You do not have permission to do this.', 'woocommerce-mpesa-express'));
	}
	check_admin_referer('wc_mpesa_c2b_register_urls');

	if (!WC_Mpesa_Express_Plugin::register_urls_allowed()) {
		wp_die(esc_html__('Registering C2B URLs from this site is turned off: this PayBill\'s C2B URLs belong to another system. Set WC_MPESA_ALLOW_REGISTER_URLS to true in wp-config.php only if this site should receive the PayBill\'s confirmations.', 'woocommerce-mpesa-express'), 403);
	}

	$result = '0';

	if (class_exists('WC_Gateway_Mpesa_C2B') && function_exists('WC')) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways['mpesa_c2b'] ?? null;

		if ($gateway instanceof WC_Gateway_Mpesa_C2B) {
			$result = $gateway->register_c2b_urls() ? '1' : '0';
		}
	}

	wp_safe_redirect(add_query_arg(
		'mpesa_c2b_registered',
		$result,
		admin_url('admin.php?page=wc-settings&tab=checkout&section=mpesa_c2b')
	));
	exit;
});

add_action('admin_notices', function () {
	if (!isset($_GET['mpesa_c2b_registered'], $_GET['page']) || $_GET['page'] !== 'wc-settings') return;
	if (!current_user_can('manage_woocommerce')) return;

	$ok = sanitize_text_field(wp_unslash($_GET['mpesa_c2b_registered'])) === '1';

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr($ok ? 'success' : 'error'),
		$ok
			? esc_html__('M-Pesa C2B Confirmation/Validation URLs registered successfully with Safaricom.', 'woocommerce-mpesa-express')
			: esc_html__('Failed to register M-Pesa C2B URLs with Safaricom. Check the Consumer Key/Secret and PayBill shortcode, then try again (or add the URLs manually in the Daraja portal).', 'woocommerce-mpesa-express')
	);
});
