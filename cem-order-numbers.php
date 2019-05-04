<?php
/**
 * Plugin Name: CEM Sequential Order Numbers
 * Plugin URI: http://craigmart.in
 * Description: Provides sequential order numbers for WooCommerce orders starting at 2000
 * Author: Craig Martin
 * Author URI: http://craigmart.in
 * Version: 1.3
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Check if WooCommerce is active
if ( ! cem_seq_order_number::is_woocommerce_active() )
	return;

/**
 * The cem_seq_order_number global object
 * @name $cem_seq_order_number
 * @global cem_seq_order_number $GLOBALS['cem_seq_order_number']
 */
$GLOBALS['cem_seq_order_number'] = new cem_seq_order_number();

class cem_seq_order_number {

	/** version number */
	const VERSION = "1.4";

	/** version option name */
	const VERSION_OPTION_NAME = "cem_seq_order_number_db_version";

    /** order number start */
    const ORDER_START = "2000";

	public function __construct() {

		// set the custom order number on the new order.  we hook into wp_insert_post for orders which are created
		//  from the frontend, and we hook into woocommerce_process_shop_order_meta for admin-created orders
		add_action( 'wp_insert_post',                      array( $this, 'set_sequential_order_number' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'set_sequential_order_number' ), 10, 2 );

		// return our custom order number for display
		add_filter( 'woocommerce_order_number',            array( $this, 'get_order_number' ), 10, 2);

		// order tracking page search by order number
		add_filter( 'woocommerce_shortcode_order_tracking_order_id', array( $this, 'find_order_by_order_number' ) );

		// WC Subscriptions support: prevent unnecessary order meta from polluting parent renewal orders, and set order number for subscription orders
		add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'subscriptions_remove_renewal_order_meta' ), 10, 4 );
		add_action( 'woocommerce_subscriptions_renewal_order_created',    array( $this, 'subscriptions_set_sequential_order_number' ), 10, 4 );

		if ( is_admin() ) {
			add_filter( 'request',                              array( $this, 'woocommerce_custom_shop_order_orderby' ), 20 );
			add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'custom_search_fields' ) );

			// sort by underlying _order_number on the Pre-Orders table
			add_filter( 'wc_pre_orders_edit_pre_orders_request', array( $this, 'custom_orderby' ) );
			add_filter( 'wc_pre_orders_search_fields',           array( $this, 'custom_search_fields' ) );
		}

		// Installation
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) $this->install();
	}


	/**
	 * Search for an order with order_number $order_number
	 *
	 * @param string $order_number order number to search for
	 *
	 * @return int post_id for the order identified by $order_number, or 0
	 */
	public function find_order_by_order_number( $order_number ) {

		// search for the order by custom order number
		$query_args = array(
					'numberposts' => 1,
					'meta_key'    => '_order_number',
					'meta_value'  => $order_number,
					'post_type'   => 'shop_order',
					'post_status' => 'any',
					'fields'      => 'ids',
				);

		$posts            = get_posts( $query_args );
		list( $order_id ) = ! empty( $posts ) ? $posts : null;

		// order was found
		if ( $order_id !== null ) {
			return $order_id;
		}

		// if we didn't find the order, then it may be that this plugin was disabled and an order was placed in the interim
		$order = wc_get_order( $order_number );

		if ( ! $order ) {
			return 0;
		}

		if ( $this->get_order_meta( $order, '_order_number' ) ) {
			// _order_number was set, so this is not an old order, it's a new one that just happened to have post_id that matched the searched-for order_number
			return 0;
		}

		return $order->get_id();
	}


	/**
	 * Set the _order_number field for the newly created order
	 *
	 * @param int $post_id post identifier
	 * @param object $post post object
	 */
	public function set_sequential_order_number( $post_id, $post ) {
		global $wpdb;

		if ( 'shop_order' == $post->post_type && 'auto-draft' != $post->post_status ) {
			$order        = wc_get_order( $post_id );
			$order_number = $this->get_order_meta($order, '_order_number');
			if ( "" == $order_number ) {

                $start_order_number = TIRI_Seq_Order_Number::ORDER_START;
				// attempt the query up to 3 times for a much higher success rate if it fails (due to Deadlock)
				$success = false;
				for ( $i = 0; $i < 3 && ! $success; $i++ ) {
					// this seems to me like the safest way to avoid order number clashes
					$query = $wpdb->prepare( "
						INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
						SELECT %d, '_order_number', IF( MAX( CAST( meta_value as UNSIGNED ) ) IS NULL, {$start_order_number}, MAX( CAST( meta_value as UNSIGNED ) ) + 1 )
							FROM {$wpdb->postmeta}
							WHERE meta_key='_order_number'",
						$post_id );

					$success = $wpdb->query( $query );
				}
			}
		}
	}


	/**
	 * Filter to return our _order_number field rather than the post ID,
	 * for display.
	 *
	 * @param string $order_number the order id with a leading hash
	 * @param WC_Order $order the order object
	 *
	 * @return string custom order number, with leading hash
	 */
	public function get_order_number( $order_number, $order ) {
		if ( $this->get_order_meta($order, '_order_number') ) {
			$order_number = $this->get_order_meta($order, '_order_number');
		}
		return $order_number;
	}

	/** Admin filters ******************************************************/


	/**
	 * Admin order table orderby ID operates on our meta _order_number
	 *
	 * @param array $vars associative array of orderby parameteres
	 *
	 * @return array associative array of orderby parameteres
	 */
	public function woocommerce_custom_shop_order_orderby( $vars ) {
		global $typenow, $wp_query;
		if ( 'shop_order' != $typenow ) return $vars;

		// Sorting
		if ( isset( $vars['orderby'] ) ) :
			if ( 'ID' == $vars['orderby'] ) :
				$vars = array_merge( $vars, array(
					'meta_key' 	=> '_order_number',
					'orderby' 	=> 'meta_value_num'
				) );
			endif;
			
		endif;
		
		return $vars;
	}


	/**
	 * Mofifies the given $args argument to sort on our meta integral _order_number
	 *
	 * @since 1.3
	 * @param array $vars associative array of orderby parameteres
	 * @return array associative array of orderby parameteres
	 */
	public function custom_orderby( $args ) {
		// Sorting
		if ( isset( $args['orderby'] ) && 'ID' == $args['orderby'] ) {
			$args = array_merge( $args, array(
				'meta_key' => '_order_number',  // sort on numerical portion for better results
				'orderby'  => 'meta_value_num',
			) );
		}

		return $args;
	}


	/**
	 * Add our custom _order_number to the set of search fields so that
	 * the admin search functionality is maintained
	 *
	 * @param array $search_fields array of post meta fields to search by
	 *
	 * @return array of post meta fields to search by
	 */
	public function custom_search_fields( $search_fields ) {

		array_push( $search_fields, '_order_number' );

		return $search_fields;
	}


	/** 3rd Party Plugin Support ******************************************************/


	/**
	 * Sets an order number on a subscriptions-created order
	 *
	 * @since 1.3
	 *
	 * @param WC_Order $renewal_order the new renewal order object
	 * @param WC_Order $original_order the original order object
	 * @param int $product_id the product post identifier
	 * @param string $new_order_role the role the renewal order is taking, one of 'parent' or 'child'
	 */
	public function subscriptions_set_sequential_order_number( $renewal_order, $original_order, $product_id, $new_order_role ) {
		$order_post = get_post( $renewal_order->id );
		$this->set_sequential_order_number( $order_post->ID, $order_post );
	}


	/**
	 * Don't copy over order number meta when creating a parent or child renewal order
	 *
	 * @since 1.3
	 *
	 * @param array $order_meta_query query for pulling the metadata
	 * @param int $original_order_id Post ID of the order being used to purchased the subscription being renewed
	 * @param int $renewal_order_id Post ID of the order created for renewing the subscription
	 * @param string $new_order_role The role the renewal order is taking, one of 'parent' or 'child'
	 * @return string
	 */
	public function subscriptions_remove_renewal_order_meta( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {

		$order_meta_query .= " AND meta_key NOT IN ( '_order_number' )";

		return $order_meta_query;
	}


	/** Helper Methods ******************************************************/


	/**
	 * Checks if WooCommerce is active
	 *
	 * @since  1.3
	 * @return bool true if WooCommerce is active, false otherwise
	 */
	public static function is_woocommerce_active() {

		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() )
			$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

		return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
	}

	/**
	 * Helper method to get order meta pre and post WC 3.0.
	 *
	 * TODO: Remove this when WooCommerce 3.0+ is required and remove helpers {BR 2017-03-08}
	 *
	 * @param \WC_Order $order the order object
	 * @param string $key the meta key
	 * @param bool $single whether to get the meta as a single item. Defaults to `true`
	 * @param string $context if 'view' then the value will be filtered
	 * @return mixed the order property
	 */
	private function get_order_meta( $order, $key = '', $single = true, $context = 'edit' ) {

		$value = $order->get_meta( $key, $single, $context );

		return $value;
	}

	/**
	 * Compatibility function to get the version of the currently installed WooCommerce
	 *
	 * @since 1.3.1
	 * @return string woocommerce version number or null
	 */
	private function get_wc_version() {

		// WOOCOMMERCE_VERSION is now WC_VERSION, though WOOCOMMERCE_VERSION is still available for backwards compatibility, we'll disregard it on 2.1+
		if ( defined( 'WC_VERSION' )          && WC_VERSION )          return WC_VERSION;
		if ( defined( 'WOOCOMMERCE_VERSION' ) && WOOCOMMERCE_VERSION ) return WOOCOMMERCE_VERSION;

		return null;
	}

	/** Lifecycle methods ******************************************************/


	/**
	 * Run every time.  Used since the activation hook is not executed when updating a plugin
	 */
	private function install() {
		$installed_version = get_option( cem_seq_order_number::VERSION_OPTION_NAME );

		if ( ! $installed_version ) {
			// initial install, set the order number for all existing orders to the post id
			$orders = get_posts( array( 'numberposts' => '', 'post_type' => 'shop_order', 'nopaging' => true ) );
			if ( is_array( $orders ) ) {
				foreach( $orders as $order ) {
					if ( '' == get_post_meta( $order->get_id(), '_order_number', true ) ) {
                        add_post_meta( $order->get_id(), '_order_number', $order->get_id() );
					}
				}
			}
		}

		if ( $installed_version != cem_seq_order_number::VERSION ) {
			$this->upgrade( $installed_version );

			// new version number
			update_option( cem_seq_order_number::VERSION_OPTION_NAME, cem_seq_order_number::VERSION );
		}
	}


	/**
	 * Run when plugin version number changes
	 */
	private function upgrade( $installed_version ) {
		// upgrade code goes here
	}
}
