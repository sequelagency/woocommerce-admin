<?php
/**
 * Report table sync related functions and actions.
 *
 * @package WooCommerce Admin/Classes
 */

namespace Automattic\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Admin\Sync\BatchSync;
use Automattic\WooCommerce\Admin\Sync\CustomersSync;
use Automattic\WooCommerce\Admin\Sync\OrdersSync;

/**
 * ReportsSync Class.
 */
class ReportsSync {
	/**
	 * Hook in sync methods.
	 */
	public static function init() {
		// Initialize syncing hooks.
		$syncs = self::get_syncs();
		foreach ( $syncs as $sync ) {
			$sync::init();
		}
		add_action( 'woocommerce_update_product', array( __CLASS__, 'clear_stock_count_cache' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'clear_stock_count_cache' ) );
		add_action( 'update_option_woocommerce_notify_low_stock_amount', array( __CLASS__, 'clear_stock_count_cache' ) );
		add_action( 'update_option_woocommerce_notify_no_stock_amount', array( __CLASS__, 'clear_stock_count_cache' ) );
	}

	/**
	 * Get classes for syncing data.
	 *
	 * @return array
	 */
	public static function get_syncs() {
		return array(
			new BatchSync(),
			new CustomersSync(),
			new OrdersSync(),
		);
	}

	/**
	 * Returns true if an import is in progress.
	 *
	 * @return bool
	 */
	public static function is_importing() {
		return BatchSync::is_importing();
	}

	/**
	 * Regenerate data for reports.
	 *
	 * @param int|bool $days Number of days to import.
	 * @param bool     $skip_existing Skip exisiting records.
	 * @return string
	 */
	public static function regenerate_report_data( $days, $skip_existing ) {
		if ( self::is_importing() ) {
			return new \WP_Error( 'wc_admin_import_in_progress', __( 'An import is already in progress.  Please allow the previous import to complete before beginning a new one.', 'woocommerce-admin' ) );
		}

		self::reset_import_stats( $days, $skip_existing );
		$syncs = self::get_syncs();
		foreach ( $syncs as $sync ) {
			// @todo This needs to be updated; should we queue dependent actions directly inside the sync?
			if ( $sync::DEPENDENCY ) {
				$sync::queue_dependent_action( $sync::get_action( 'import_batch_init' ), array( $days, $skip_existing ), $sync::DEPENDENCY );
			} else {
				$sync::import_batch_init( $days, $skip_existing );
			}
		}

		return __( 'Report table data is being rebuilt.  Please allow some time for data to fully populate.', 'woocommerce-admin' );
	}

	/**
	 * Update the import stat totals and counts.
	 *
	 * @param int|bool $days Number of days to import.
	 * @param bool     $skip_existing Skip exisiting records.
	 */
	public static function reset_import_stats( $days, $skip_existing ) {
		$totals = self::get_import_totals( $days, $skip_existing );
		update_option( 'wc_admin_import_customers_count', 0 );
		update_option( 'wc_admin_import_orders_count', 0 );
		update_option( 'wc_admin_import_customers_total', $totals['customers'] );
		update_option( 'wc_admin_import_orders_total', $totals['orders'] );

		// Update imported from date if older than previous.
		$previous_import_date = get_option( 'wc_admin_imported_from_date' );
		$current_import_date  = $days ? date( 'Y-m-d 00:00:00', time() - ( DAY_IN_SECONDS * $days ) ) : -1;

		if ( ! $previous_import_date || -1 === $current_import_date || new \DateTime( $previous_import_date ) > new \DateTime( $current_import_date ) ) {
			update_option( 'wc_admin_imported_from_date', $current_import_date );
		}
	}

	/**
	 * Get the import totals for customers and orders.
	 *
	 * @param int|bool $days Number of days to import.
	 * @param bool     $skip_existing Skip exisiting records.
	 * @return array
	 */
	public static function get_import_totals( $days, $skip_existing ) {
		$totals = array();

		$syncs = self::get_syncs();
		foreach ( $syncs as $sync ) {
			$items                 = $sync::get_items( 1, 1, $days, $skip_existing );
			$totals[ $sync::NAME ] = $items->total;
		}

		return $totals;
	}

	/**
	 * Clears all queued actions.
	 */
	public static function clear_queued_actions() {
		$store = \ActionScheduler::store();

		if ( is_a( $store, 'Automattic\WooCommerce\Admin\orides\WPPostStore' ) ) {
			// If we're using our data store, call our bespoke deletion method.
			$action_types = array(
				self::QUEUE_BATCH_ACTION,
				self::QUEUE_DEPENDENT_ACTION,
				self::CUSTOMERS_IMPORT_BATCH_ACTION,
				self::CUSTOMERS_DELETE_BATCH_INIT,
				self::CUSTOMERS_DELETE_BATCH_ACTION,
				self::ORDERS_IMPORT_BATCH_ACTION,
				self::ORDERS_IMPORT_BATCH_INIT,
				self::ORDERS_DELETE_BATCH_INIT,
				self::ORDERS_DELETE_BATCH_ACTION,
				self::SINGLE_CUSTOMER_IMPORT_ACTION,
				self::SINGLE_ORDER_IMPORT_ACTION,
			);
			$store->clear_pending_wcadmin_actions( $action_types );
		} elseif ( version_compare( \ActionScheduler_Versions::instance()->latest_version(), '3.0', '>=' ) ) {
			$store->cancel_actions_by_group( self::QUEUE_GROUP );
		} else {
			self::queue()->cancel_all( null, array(), self::QUEUE_GROUP );
		}
	}

	/**
	 * Delete all data for reports.
	 *
	 * @return string
	 */
	public static function delete_report_data() {
		// Cancel all pending import jobs.
		self::clear_queued_actions();

		// Delete orders in batches.
		self::queue()->schedule_single( time() + 5, self::ORDERS_DELETE_BATCH_INIT, array(), self::QUEUE_GROUP );

		// Delete customers after order data is deleted.
		self::queue_dependent_action( self::CUSTOMERS_DELETE_BATCH_INIT, array(), self::ORDERS_DELETE_BATCH_INIT );

		// Delete import options.
		delete_option( 'wc_admin_import_customers_count' );
		delete_option( 'wc_admin_import_orders_count' );
		delete_option( 'wc_admin_import_customers_total' );
		delete_option( 'wc_admin_import_orders_total' );
		delete_option( 'wc_admin_imported_from_date' );

		return __( 'Report table data is being deleted.', 'woocommerce-admin' );
	}

	/**
	 * Clear the count cache when products are added or updated, or when
	 * the no/low stock options are changed.
	 *
	 * @param int $id Post/product ID.
	 */
	public static function clear_stock_count_cache( $id ) {
		delete_transient( 'wc_admin_stock_count_lowstock' );
		delete_transient( 'wc_admin_product_count' );
		$status_options = wc_get_product_stock_status_options();
		foreach ( $status_options as $status => $label ) {
			delete_transient( 'wc_admin_stock_count_' . $status );
		}
	}
}
