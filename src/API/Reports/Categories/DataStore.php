<?php
/**
 * API\Reports\Categories\DataStore class file.
 *
 * @package WooCommerce Admin/Classes
 */

namespace Automattic\WooCommerce\Admin\API\Reports\Categories;

defined( 'ABSPATH' ) || exit;

use \Automattic\WooCommerce\Admin\API\Reports\DataStore as ReportsDataStore;
use \Automattic\WooCommerce\Admin\API\Reports\DataStoreInterface;
use \Automattic\WooCommerce\Admin\API\Reports\TimeInterval;
use \Automattic\WooCommerce\Admin\API\Reports\SqlQuery;

/**
 * API\Reports\Categories\DataStore.
 */
class DataStore extends ReportsDataStore implements DataStoreInterface {

	/**
	 * Table used to get the data.
	 *
	 * @var string
	 */
	protected static $table_name = 'wc_order_product_lookup';

	/**
	 * Cache identifier.
	 *
	 * @var string
	 */
	protected $cache_key = 'categories';

	/**
	 * Order by setting used for sorting categories data.
	 *
	 * @var string
	 */
	private $order_by = '';

	/**
	 * Order setting used for sorting categories data.
	 *
	 * @var string
	 */
	private $order = '';

	/**
	 * Mapping columns to data type to return correct response types.
	 *
	 * @var array
	 */
	protected $column_types = array(
		'category_id'    => 'intval',
		'items_sold'     => 'intval',
		'net_revenue'    => 'floatval',
		'orders_count'   => 'intval',
		'products_count' => 'intval',
	);

	/**
	 * Data store context used to pass to filters.
	 *
	 * @var string
	 */
	protected $context = 'categories';

	/**
	 * Assign report columns once full table name has been assigned.
	 */
	protected function assign_report_columns() {
		$table_name           = self::get_db_table_name();
		$this->report_columns = array(
			'items_sold'     => 'SUM(product_qty) as items_sold',
			'net_revenue'    => 'SUM(product_net_revenue) AS net_revenue',
			'orders_count'   => "COUNT(DISTINCT {$table_name}.order_id) as orders_count",
			'products_count' => "COUNT(DISTINCT {$table_name}.product_id) as products_count",
		);
	}

	/**
	 * Return the database query with parameters used for Categories report: time span and order status.
	 *
	 * @param array $query_args Query arguments supplied by the user.
	 */
	protected function add_sql_query_params( $query_args ) {
		global $wpdb;
		$order_product_lookup_table = self::get_db_table_name();

		$this->add_time_period_sql_params( $query_args, $order_product_lookup_table );

		// join wp_order_product_lookup_table with relationships and taxonomies
		// @todo How to handle custom product tables?
		$this->subquery->add_sql_clause( 'left_join', "LEFT JOIN {$wpdb->term_relationships} ON {$order_product_lookup_table}.product_id = {$wpdb->term_relationships}.object_id" );
		$this->subquery->add_sql_clause( 'left_join', "LEFT JOIN {$wpdb->wc_category_lookup} ON {$wpdb->term_relationships}.term_taxonomy_id = {$wpdb->wc_category_lookup}.category_id" );

		$included_categories = $this->get_included_categories( $query_args );
		if ( $included_categories ) {
			$this->subquery->add_sql_clause( 'where', "AND {$wpdb->wc_category_lookup}.category_tree_id IN ({$included_categories})" );

			// Limit is left out here so that the grouping in code by PHP can be applied correctly.
			// This also needs to be put after the term_taxonomy JOIN so that we can match the correct term name.
			$this->add_order_by_params( $query_args, 'outer', 'default_results.category_id' );
		} else {
			$this->add_order_by_params( $query_args, 'inner', "{$wpdb->wc_category_lookup}.category_tree_id" );
		}

		// @todo Only products in the category C or orders with products from category C (and, possibly others?).
		$included_products = $this->get_included_products( $query_args );
		if ( $included_products ) {
			$this->subquery->add_sql_clause( 'where', "AND {$order_product_lookup_table}.product_id IN ({$included_products})" );
		}

		$this->add_order_status_clause( $query_args, $order_product_lookup_table, $this->subquery );
		$this->subquery->add_sql_clause( 'where', "AND {$wpdb->wc_category_lookup}.category_tree_id IS NOT NULL" );
	}

	/**
	 * Fills ORDER BY clause of SQL request based on user supplied parameters.
	 *
	 * @param array  $query_args Parameters supplied by the user.
	 * @param string $from_arg   Target of the JOIN sql param.
	 * @param string $id_cell    ID cell identifier, like `table_name.id_column_name`.
	 */
	protected function add_order_by_params( $query_args, $from_arg, $id_cell ) {
		global $wpdb;
		$lookup_table    = self::get_db_table_name();
		$order_by_clause = $this->add_order_by_clause( $query_args, $this );
		$this->add_orderby_order_clause( $query_args, $this );

		if ( false !== strpos( $order_by_clause, '_terms' ) ) {
			$join = "JOIN {$wpdb->terms} AS _terms ON {$id_cell} = _terms.term_id";
			if ( 'inner' === $from_arg ) {
				$this->subquery->add_sql_clause( 'join', $join );
			} else {
				$this->add_sql_clause( 'join', $join );
			}
		}
	}

	/**
	 * Maps ordering specified by the user to columns in the database/fields in the data.
	 *
	 * @param string $order_by Sorting criterion.
	 * @return string
	 */
	protected function normalize_order_by( $order_by ) {
		if ( 'date' === $order_by ) {
			return 'time_interval';
		}
		if ( 'category' === $order_by ) {
			return '_terms.name';
		}
		return $order_by;
	}

	/**
	 * Returns an array of ids of included categories, based on query arguments from the user.
	 *
	 * @param array $query_args Parameters supplied by the user.
	 * @return string
	 */
	protected function get_included_categories_array( $query_args ) {
		if ( isset( $query_args['categories'] ) && is_array( $query_args['categories'] ) && count( $query_args['categories'] ) > 0 ) {
			return $query_args['categories'];
		}
		return array();
	}

	/**
	 * Returns the page of data according to page number and items per page.
	 *
	 * @param array   $data           Data to paginate.
	 * @param integer $page_no        Page number.
	 * @param integer $items_per_page Number of items per page.
	 * @return array
	 */
	protected function page_records( $data, $page_no, $items_per_page ) {
		$offset = ( $page_no - 1 ) * $items_per_page;
		return array_slice( $data, $offset, $items_per_page );
	}

	/**
	 * Enriches the category data.
	 *
	 * @param array $categories_data Categories data.
	 * @param array $query_args  Query parameters.
	 */
	protected function include_extended_info( &$categories_data, $query_args ) {
		foreach ( $categories_data as $key => $category_data ) {
			$extended_info = new \ArrayObject();
			if ( $query_args['extended_info'] ) {
				$extended_info['name'] = get_the_category_by_ID( $category_data['category_id'] );
			}
			$categories_data[ $key ]['extended_info'] = $extended_info;
		}
	}

	/**
	 * Returns the report data based on parameters supplied by the user.
	 *
	 * @param array $query_args  Query parameters.
	 * @return stdClass|WP_Error Data.
	 */
	public function get_data( $query_args ) {
		global $wpdb;

		$table_name = self::get_db_table_name();

		// These defaults are only partially applied when used via REST API, as that has its own defaults.
		$defaults   = array(
			'per_page'      => get_option( 'posts_per_page' ),
			'page'          => 1,
			'order'         => 'DESC',
			'orderby'       => 'date',
			'before'        => TimeInterval::default_before(),
			'after'         => TimeInterval::default_after(),
			'fields'        => '*',
			'categories'    => array(),
			'extended_info' => false,
		);
		$query_args = wp_parse_args( $query_args, $defaults );
		$this->normalize_timezones( $query_args, $defaults );

		/*
		 * We need to get the cache key here because
		 * parent::update_intervals_sql_params() modifies $query_args.
		 */
		$cache_key = $this->get_cache_key( $query_args );
		$data      = $this->get_cached_data( $cache_key );

		if ( false === $data ) {
			$this->initialize_queries();

			$data = (object) array(
				'data'    => array(),
				'total'   => 0,
				'pages'   => 0,
				'page_no' => 0,
			);

			$this->subquery->add_sql_clause( 'select', $this->selected_columns( $query_args ) );
			$included_categories = $this->get_included_categories_array( $query_args );
			$this->add_sql_query_params( $query_args );

			if ( count( $included_categories ) > 0 ) {
				$fields    = $this->get_fields( $query_args );
				$ids_table = $this->get_ids_table( $included_categories, 'category_id' );

				$this->add_sql_clause( 'select', $this->format_join_selections( array_merge( array( 'category_id' ), $fields ), array( 'category_id' ) ) );
				$this->add_sql_clause( 'from', '(' );
				$this->add_sql_clause( 'from', $this->subquery->get_query_statement() );
				$this->add_sql_clause( 'from', ") AS {$table_name}" );
				$this->add_sql_clause(
					'right_join',
					"RIGHT JOIN ( {$ids_table} ) AS default_results
					ON default_results.category_id = {$table_name}.category_id"
				);

				$categories_query = $this->get_query_statement();
			} else {
				$this->subquery->add_sql_clause( 'order_by', $this->get_sql_clause( 'order_by' ) );
				$categories_query = $this->subquery->get_query_statement();
			}
			$categories_data = $wpdb->get_results(
				$categories_query,
				ARRAY_A
			); // WPCS: cache ok, DB call ok, unprepared SQL ok.

			if ( null === $categories_data ) {
				return new \WP_Error( 'woocommerce_reports_categories_result_failed', __( 'Sorry, fetching revenue data failed.', 'woocommerce-admin' ), array( 'status' => 500 ) );
			}

			$record_count = count( $categories_data );
			$total_pages  = (int) ceil( $record_count / $query_args['per_page'] );
			if ( $query_args['page'] < 1 || $query_args['page'] > $total_pages ) {
				return $data;
			}

			$categories_data = $this->page_records( $categories_data, $query_args['page'], $query_args['per_page'] );
			$this->include_extended_info( $categories_data, $query_args );
			$categories_data = array_map( array( $this, 'cast_numbers' ), $categories_data );
			$data            = (object) array(
				'data'    => $categories_data,
				'total'   => $record_count,
				'pages'   => $total_pages,
				'page_no' => (int) $query_args['page'],
			);

			$this->set_cached_data( $cache_key, $data );
		}

		return $data;
	}

	/**
	 * Initialize query objects.
	 */
	protected function initialize_queries() {
		global $wpdb;
		$this->subquery = new SqlQuery( $this->context . '_subquery' );
		$this->subquery->add_sql_clause( 'select', "{$wpdb->wc_category_lookup}.category_tree_id as category_id," );
		$this->subquery->add_sql_clause( 'from', self::get_db_table_name() );
		$this->subquery->add_sql_clause( 'group_by', "{$wpdb->wc_category_lookup}.category_tree_id" );
	}
}
