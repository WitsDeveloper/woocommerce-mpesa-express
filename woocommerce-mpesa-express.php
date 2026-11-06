<?php

/**
 * Plugin Name: WooCommerce Mpesa Express
 * Description: WooCommerce payment gateway for Safaricom M-Pesa — M-Pesa Express (STK Push) and PayBill (manual / C2B), with IPN callback handling.
 * Version: 2.0.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Sammy Waweru
 * License: GPLv3 or later
 */

if (!defined('ABSPATH')) exit;

define('WC_MPESA_EXPRESS_FILE', __FILE__);
define('WC_MPESA_EXPRESS_DIR', plugin_dir_path(__FILE__));
define('WC_MPESA_EXPRESS_VERSION', '2.0.0');

/**
 * Plugin-wide helpers that don't depend on WooCommerce being loaded yet.
 */
class WC_Mpesa_Express_Plugin {

	const IPN_SECRET_OPTION = 'wc_mpesa_ipn_secret';

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
 * Admin-post handler for the "Register URLs with Safaricom" button on the
 * M-Pesa PayBill (C2B) settings screen.
 */
add_action('admin_post_wc_mpesa_c2b_register_urls', function () {
	if (!current_user_can('manage_woocommerce')) {
		wp_die(esc_html__('You do not have permission to do this.', 'woocommerce-mpesa-express'));
	}
	check_admin_referer('wc_mpesa_c2b_register_urls');

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
