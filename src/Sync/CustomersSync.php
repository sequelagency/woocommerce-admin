<?php
/**
 * Customer syncing related functions and actions.
 *
 * @package WooCommerce Admin/Classes
 */

namespace Automattic\WooCommerce\Admin\Sync;

defined( 'ABSPATH' ) || exit;

use \Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore as CustomersDataStore;

/**
 * CustomersSync Class.
 */
class CustomersSync extends BaseSync {
	/**
	 * Slug to identify the import data type.
	 */
	const NAME = 'customers';

	/**
	 * Attach customer lookup update hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_new_customer', array( __CLASS__, 'schedule_import' ) );
		add_action( 'woocommerce_update_customer', array( __CLASS__, 'schedule_import' ) );

		CustomersDataStore::init();
		parent::init();
	}

	/**
	 * Get the customer IDs and total count that need to be synced.
	 *
	 * @param int      $limit Number of records to retrieve.
	 * @param int      $page  Page number.
	 * @param int|bool $days Number of days prior to current date to limit search results.
	 * @param bool     $skip_existing Skip already imported customers.
	 */
	public static function get_items( $limit = 10, $page = 1, $days = false, $skip_existing = false ) {
		if ( is_int( $days ) ) {
			$query_args['date_query'] = array(
				'after' => date( 'Y-m-d 00:00:00', time() - ( DAY_IN_SECONDS * $days ) ),
			);
		}

		if ( $skip_existing ) {
			add_action( 'pre_user_query', array( __CLASS__, 'exclude_existing_customers_from_query' ) );
		}

		$customer_roles = apply_filters( 'woocommerce_admin_import_customer_roles', array( 'customer' ) );
		$customer_query = new \WP_User_Query(
			array(
				'fields'   => 'ID',
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'number'   => $limit,
				'paged'    => $page,
				'role__in' => $customer_roles,
			)
		);

		remove_action( 'pre_user_query', array( __CLASS__, 'exclude_existing_customers_from_query' ) );

		return (object) array(
			'total' => $customer_query->get_total(),
			'ids'   => $customer_query->get_results(),
		);
	}

	/**
	 * Exclude users that already exist in our customer lookup table.
	 *
	 * Meant to be hooked into 'pre_user_query' action.
	 *
	 * @param WP_User_Query $wp_user_query WP_User_Query to modify.
	 */
	public static function exclude_existing_customers_from_query( $wp_user_query ) {
		global $wpdb;

		$wp_user_query->query_where .= " AND NOT EXISTS (
			SELECT ID FROM {$wpdb->prefix}wc_customer_lookup
			WHERE {$wpdb->prefix}wc_customer_lookup.user_id = {$wpdb->users}.ID
		)";
	}

	/**
	 * Get total number of rows imported.
	 */
	public static function get_total_imported() {
		global $wpdb;
		return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_customer_lookup" );
	}

	/**
	 * Imports a single customer.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function import( $user_id ) {
		CustomersDataStore::update_registered_customer( $user_id );
	}

	/**
	 * Delete a batch of customers.
	 *
	 * @param int $batch_size Number of items to delete.
	 * @return void
	 */
	public static function delete( $batch_size ) {
		global $wpdb;

		$customer_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup ORDER BY customer_id ASC LIMIT %d",
				$batch_size
			)
		);

		foreach ( $customer_ids as $customer_id ) {
			CustomersDataStore::delete_customer( $customer_id );
		}
	}
}
