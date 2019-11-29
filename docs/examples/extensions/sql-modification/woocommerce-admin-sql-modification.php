<?php
/**
 * Plugin Name: WooCommerce Admin SQL modification Example
 *
 * @package WC_Admin
 */

/**
 * Register the JS.
 */
function add_report_register_script() {

	if ( ! class_exists( 'Automattic\WooCommerce\Admin\Loader' ) || ! \Automattic\WooCommerce\Admin\Loader::is_admin_page() ) {
		return;
	}

	wp_register_script(
		'sql-modification',
		plugins_url( '/dist/index.js', __FILE__ ),
		array(
			'wp-hooks',
			'wp-element',
			'wp-i18n',
			'wp-plugins',
			'wc-components',
		),
		filemtime( dirname( __FILE__ ) . '/dist/index.js' ),
		true
	);

	wp_enqueue_script( 'sql-modification' );
}
add_action( 'admin_enqueue_scripts', 'add_report_register_script' );

function apply_currency_arg ( $args ) {

	if( isset( $_REQUEST['currency'] ) ) {
		$args['currency'] = $_REQUEST['currency'];
	}

	return $args;
}

add_filter( 'woocommerce_reports_revenue_query_args', 'apply_currency_arg' );
