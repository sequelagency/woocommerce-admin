<?php
/**
 * REST API Onboarding Plugins Controller
 *
 * Handles requests to install and activate depedent plugins.
 *
 * @package WooCommerce Admin/API
 */

namespace Automattic\WooCommerce\Admin\API;

use Automattic\WooCommerce\Admin\Features\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * Onboarding Plugins Controller.
 *
 * @package WooCommerce Admin/API
 * @extends WC_REST_Data_Controller
 */
class OnboardingPlugins extends \WC_REST_Data_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc-admin';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'onboarding/plugins';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/install',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'install_plugin' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/active',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'active_plugins' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/activate',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'activate_plugins' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/connect-jetpack',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'connect_jetpack' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_connect_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/request-wccom-connect',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'request_wccom_connect' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_connect_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/finish-wccom-connect',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'finish_wccom_connect' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_connect_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/connect-paypal',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'connect_paypal' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_connect_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/connect-square',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'connect_square' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_connect_schema' ),
			)
		);
	}

	/**
	 * Check if a given request has access to manage plugins.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new \WP_Error( 'woocommerce_rest_cannot_update', __( 'Sorry, you cannot manage plugins.', 'woocommerce-admin' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Installs the requested plugin.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|array Plugin Status
	 */
	public function install_plugin( $request ) {
		$allowed_plugins = Onboarding::get_allowed_plugins();
		$plugin          = sanitize_title_with_dashes( $request['plugin'] );
		if ( ! in_array( $plugin, array_keys( $allowed_plugins ), true ) ) {
			return new \WP_Error( 'woocommerce_rest_invalid_plugin', __( 'Invalid plugin.', 'woocommerce-admin' ), 404 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$slug              = $plugin;
		$path              = $allowed_plugins[ $slug ];
		$installed_plugins = get_plugins();

		if ( in_array( $path, array_keys( $installed_plugins ), true ) ) {
			return( array(
				'slug'   => $slug,
				'name'   => $installed_plugins[ $path ]['Name'],
				'status' => 'success',
			) );
		}

		include_once ABSPATH . '/wp-admin/includes/admin.php';
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		include_once ABSPATH . '/wp-admin/includes/plugin.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . '/wp-admin/includes/class-plugin-upgrader.php';

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => sanitize_key( $slug ),
				'fields' => array(
					'sections' => false,
				),
			)
		);

		// Log extra debug for plugin installation.
		$logger        = wc_get_logger();
		$logger_source = array(
			'source' => 'woocommerce-admin',
		);

		if ( is_wp_error( $api ) ) {
			$logger->info(
				sprintf( 'The requested plugin `%s` could not be installed. plugins_api call failed.', sanitize_key( $slug ) ),
				$logger_source
			);
			$logger->debug(
				'api: ' . wc_print_r( $api, true ),
				$logger_source
			);
			return new \WP_Error( 'woocommerce_rest_plugin_install', __( 'The requested plugin could not be installed.', 'woocommerce-admin' ), 500 );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) || is_null( $result ) ) {
			$logger->info(
				sprintf( 'The requested plugin `%s` could not be installed. install call failed.', sanitize_key( $slug ) ),
				$logger_source
			);
			$logger->debug(
				'upgrader: ' . wc_print_r( $upgrader, true ),
				$logger_source
			);
			$logger->debug(
				'api: ' . wc_print_r( $api, true ),
				$logger_source
			);
			$logger->debug(
				'result: ' . wc_print_r( $result, true ),
				$logger_source
			);
			return new \WP_Error( 'woocommerce_rest_plugin_install', __( 'The requested plugin could not be installed.', 'woocommerce-admin' ), 500 );
		}

		return array(
			'slug'   => $slug,
			'name'   => $api->name,
			'status' => 'success',
		);
	}

	/**
	 * Returns a list of active plugins.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return array Active plugins
	 */
	public function active_plugins( $request ) {
		$plugins = Onboarding::get_active_plugins();
		return( array(
			'plugins' => array_values( $plugins ),
		) );
	}

	/**
	 * Activate the requested plugin.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|array Plugin Status
	 */
	public function activate_plugins( $request ) {
		$allowed_plugins = Onboarding::get_allowed_plugins();
		$_plugins        = explode( ',', $request['plugins'] );
		$plugins         = array_intersect( array_keys( $allowed_plugins ), $_plugins );

		if ( empty( $plugins ) || ! is_array( $plugins ) ) {
			return new \WP_Error( 'woocommerce_rest_invalid_plugins', __( 'Invalid plugins.', 'woocommerce-admin' ), 404 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( $plugins as $plugin ) {
			$slug              = $plugin;
			$path              = $allowed_plugins[ $slug ];
			$installed_plugins = get_plugins();

			if ( ! in_array( $path, array_keys( $installed_plugins ), true ) ) {
				/* translators: %s: plugin slug (example: woocommerce-services) */
				return new \WP_Error( 'woocommerce_rest_invalid_plugin', sprintf( __( 'Invalid plugin %s.', 'woocommerce-admin' ), $slug ), 404 );
			}

			$result = activate_plugin( $path );
			if ( ! is_null( $result ) ) {
				return new \WP_Error( 'woocommerce_rest_invalid_plugin', sprintf( __( 'The requested plugins could not be activated.', 'woocommerce-admin' ), $slug ), 500 );
			}
		}

		return( array(
			'activatedPlugins' => array_values( $plugins ),
			'active'           => Onboarding::get_active_plugins(),
			'status'           => 'success',
		) );
	}

	/**
	 * Generates a Jetpack Connect URL.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|array Connection URL for Jetpack
	 */
	public function connect_jetpack( $request ) {
		if ( ! class_exists( '\Jetpack' ) ) {
			return new \WP_Error( 'woocommerce_rest_jetpack_not_active', __( 'Jetpack is not installed or active.', 'woocommerce-admin' ), 404 );
		}

		$redirect_url = apply_filters( 'woocommerce_admin_onboarding_jetpack_connect_redirect_url', esc_url_raw( $request['redirect_url'] ) );
		$connect_url  = \Jetpack::init()->build_connect_url( true, $redirect_url, 'woocommerce-onboarding' );

		// @todo When implementing user-facing split testing, this should be abled to a default of 'production'.
		$calypso_env = defined( 'WOOCOMMERCE_CALYPSO_ENVIRONMENT' ) && in_array( WOOCOMMERCE_CALYPSO_ENVIRONMENT, array( 'development', 'wpcalypso', 'horizon', 'stage' ) ) ? WOOCOMMERCE_CALYPSO_ENVIRONMENT : 'wpcalypso';
		$connect_url = add_query_arg( array( 'calypso_env' => $calypso_env ), $connect_url );

		return( array(
			'slug'          => 'jetpack',
			'name'          => __( 'Jetpack', 'woocommerce-admin' ),
			'connectAction' => $connect_url,
		) );
	}

	/**
	 *  Kicks off the WCCOM Connect process.
	 *
	 * @return WP_Error|array Connection URL for WooCommerce.com
	 */
	public function request_wccom_connect() {
		include_once WC_ABSPATH . 'includes/admin/helper/class-wc-helper-api.php';
		if ( ! class_exists( 'WC_Helper_API' ) ) {
			return new WP_Error( 'woocommerce_rest_helper_not_active', __( 'There was an error loading the WooCommerce.com Helper API.', 'woocommerce-admin' ), 404 );
		}

		$redirect_uri = wc_admin_url( '&task=connect&wccom-connected=1' );

		$request = \WC_Helper_API::post(
			'oauth/request_token',
			array(
				'body' => array(
					'home_url'     => home_url(),
					'redirect_uri' => $redirect_uri,
				),
			)
		);

		$code = wp_remote_retrieve_response_code( $request );
		if ( 200 !== $code ) {
			return new WP_Error( 'woocommerce_rest_helper_connect', __( 'There was an error connecting to WooCommerce.com. Please try again.', 'woocommerce-admin' ), 500 );
		}

		$secret = json_decode( wp_remote_retrieve_body( $request ) );
		if ( empty( $secret ) ) {
			return new WP_Error( 'woocommerce_rest_helper_connect', __( 'There was an error connecting to WooCommerce.com. Please try again.', 'woocommerce-admin' ), 500 );
		}

		do_action( 'woocommerce_helper_connect_start' );

		$connect_url = add_query_arg(
			array(
				'home_url'     => rawurlencode( home_url() ),
				'redirect_uri' => rawurlencode( $redirect_uri ),
				'secret'       => rawurlencode( $secret ),
				'wccom-from'   => 'onboarding',
			),
			\WC_Helper_API::url( 'oauth/authorize' )
		);

		if ( defined( 'WOOCOMMERCE_CALYPSO_ENVIRONMENT' ) && in_array( WOOCOMMERCE_CALYPSO_ENVIRONMENT, array( 'development', 'wpcalypso', 'horizon', 'stage' ) ) ) {
			$connect_url = add_query_arg(
				array(
					'calypso_env' => WOOCOMMERCE_CALYPSO_ENVIRONMENT,
				),
				$connect_url
			);
		}

		return( array(
			'connectAction' => $connect_url,
		) );
	}

	/**
	 * Finishes connecting to WooCommerce.com.
	 *
	 * @param  object $rest_request Request details.
	 * @return WP_Error|array Contains success status.
	 */
	public function finish_wccom_connect( $rest_request ) {
		include_once WC_ABSPATH . 'includes/admin/helper/class-wc-helper.php';
		include_once WC_ABSPATH . 'includes/admin/helper/class-wc-helper-api.php';
		include_once WC_ABSPATH . 'includes/admin/helper/class-wc-helper-updater.php';
		include_once WC_ABSPATH . 'includes/admin/helper/class-wc-helper-options.php';
		if ( ! class_exists( 'WC_Helper_API' ) ) {
			return new WP_Error( 'woocommerce_rest_helper_not_active', __( 'There was an error loading the WooCommerce.com Helper API.', 'woocommerce-admin' ), 404 );
		}

		// Obtain an access token.
		$request = \WC_Helper_API::post(
			'oauth/access_token',
			array(
				'body' => array(
					'request_token' => wp_unslash( $rest_request['request_token'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					'home_url'      => home_url(),
				),
			)
		);

		$code = wp_remote_retrieve_response_code( $request );
		if ( 200 !== $code ) {
			return new WP_Error( 'woocommerce_rest_helper_connect', __( 'There was an error connecting to WooCommerce.com. Please try again.', 'woocommerce-admin' ), 500 );
		}

		$access_token = json_decode( wp_remote_retrieve_body( $request ), true );
		if ( ! $access_token ) {
			return new WP_Error( 'woocommerce_rest_helper_connect', __( 'There was an error connecting to WooCommerce.com. Please try again.', 'woocommerce-admin' ), 500 );
		}

		\WC_Helper_Options::update(
			'auth',
			array(
				'access_token'        => $access_token['access_token'],
				'access_token_secret' => $access_token['access_token_secret'],
				'site_id'             => $access_token['site_id'],
				'user_id'             => get_current_user_id(),
				'updated'             => time(),
			)
		);

		if ( ! \WC_Helper::_flush_authentication_cache() ) {
			\WC_Helper_Options::update( 'auth', array() );
			return new WP_Error( 'woocommerce_rest_helper_connect', __( 'There was an error connecting to WooCommerce.com. Please try again.', 'woocommerce-admin' ), 500 );
		}

		delete_transient( '_woocommerce_helper_subscriptions' );
		\WC_Helper_Updater::flush_updates_cache();

		do_action( 'woocommerce_helper_connected' );

		return array(
			'success' => true,
		);
	}

	/**
	 * Returns a URL that can be used to connect to PayPal.
	 *
	 * @return WP_Error|array Connect URL.
	 */
	public function connect_paypal() {
		if ( ! function_exists( 'wc_gateway_ppec' ) ) {
			return new WP_Error( 'woocommerce_rest_helper_connect', __( 'There was an error connecting to PayPal.', 'woocommerce-admin' ), 500 );
		}

		$redirect_url = add_query_arg(
			array(
				'env'                     => 'live',
				'wc_ppec_ips_admin_nonce' => wp_create_nonce( 'wc_ppec_ips' ),
			),
			wc_admin_url( '&task=payments&paypal-connect-finish=1' )
		);

		// https://github.com/woocommerce/woocommerce-gateway-paypal-express-checkout/blob/b6df13ba035038aac5024d501e8099a37e13d6cf/includes/class-wc-gateway-ppec-ips-handler.php#L79-L93.
		$query_args  = array(
			'redirect'    => urlencode( $redirect_url ),
			'countryCode' => WC()->countries->get_base_country(),
			'merchantId'  => md5( site_url( '/' ) . time() ),
		);
		$connect_url = add_query_arg( $query_args, wc_gateway_ppec()->ips->get_middleware_login_url( 'live' ) );

		return( array(
			'connectUrl' => $connect_url,
		) );
	}

	/**
	 * Returns a URL that can be used to connect to Square.
	 *
	 * @return WP_Error|array Connect URL.
	 */
	public function connect_square() {
		if ( ! class_exists( '\WooCommerce\Square\Handlers\Connection' ) ) {
			return new WP_Error( 'woocommerce_rest_helper_connect', __( 'There was an error connecting to Square.', 'woocommerce-admin' ), 500 );
		}

		$url = \WooCommerce\Square\Handlers\Connection::CONNECT_URL_PRODUCTION;

		$redirect_url = wp_nonce_url( wc_admin_url( '&task=payments&square-connect-finish=1' ), 'wc_square_connected' );
		$args         = array(
			'redirect' => urlencode( urlencode( $redirect_url ) ),
			'scopes'   => implode(
				',',
				array(
					'MERCHANT_PROFILE_READ',
					'PAYMENTS_READ',
					'PAYMENTS_WRITE',
					'ORDERS_READ',
					'ORDERS_WRITE',
					'CUSTOMERS_READ',
					'CUSTOMERS_WRITE',
					'SETTLEMENTS_READ',
					'ITEMS_READ',
					'ITEMS_WRITE',
					'INVENTORY_READ',
					'INVENTORY_WRITE',
				)
			),
		);

		$connect_url = add_query_arg( $args, $url );

		return( array(
			'connectUrl' => $connect_url,
		) );
	}

	/**
	 * Get the schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'onboarding_plugin',
			'type'       => 'object',
			'properties' => array(
				'slug'   => array(
					'description' => __( 'Plugin slug.', 'woocommerce-admin' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'name'   => array(
					'description' => __( 'Plugin name.', 'woocommerce-admin' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'status' => array(
					'description' => __( 'Plugin status.', 'woocommerce-admin' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_connect_schema() {
		$schema = $this->get_item_schema();
		unset( $schema['properties']['status'] );
		$schema['properties']['connectAction'] = array(
			'description' => __( 'Action that should be completed to connect Jetpack.', 'woocommerce-admin' ),
			'type'        => 'string',
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
		);
		return $schema;
	}
}
