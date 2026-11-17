<?php
/**
 * Plugin Name: Krokedil demo payments
 * Description: Betalplugin demo payments.
 * Version: 1.0.0
 * Author: Thyra Nyström
 * Text Domain: krokedil-demo-payments
 * Requires at least: 7
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce // Fixat
 *
 * @package KrokedilDemoPayments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/classes/plugin.php';

KrokedilDemoPayments\Plugin::boot();
