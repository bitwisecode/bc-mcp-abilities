<?php
/**
 * Safe, read-only WooCommerce management summaries.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce abilities using CRUD APIs (including HPOS-compatible orders).
 */
final class BC_MCP_Abilities_WooCommerce {
	/**
	 * Register optional WooCommerce abilities with WordPress.
	 *
	 * @return void
	 */
	public static function register() {
		$int = array( 'type' => 'integer', 'minimum' => 1 );

		BC_MCP_Abilities::add(
			'wc-products',
			__( 'Search WooCommerce Products', 'bc-mcp-abilities' ),
			__( 'Search a limited set of WooCommerce products, showing SKU, price and inventory information.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
					'page'     => $int,
				),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'products' )
		);

		BC_MCP_Abilities::add(
			'wc-product',
			__( 'Read WooCommerce Product', 'bc-mcp-abilities' ),
			__( 'Get safe product, variation and stock details for a WooCommerce product.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'product_id' => $int ),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'product' )
		);

		BC_MCP_Abilities::add(
			'wc-update-stock',
			__( 'Update WooCommerce Product Stock', 'bc-mcp-abilities' ),
			__( 'Set inventory for a stock-managed product or variation using an expected stock revision token.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'        => $int,
					'stock_quantity'    => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000000 ),
					'expected_revision' => array( 'type' => 'string' ),
				),
				'required'             => array( 'product_id', 'stock_quantity', 'expected_revision' ),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'update_stock' ),
			false
		);

		BC_MCP_Abilities::add(
			'wc-order-report',
			__( 'WooCommerce Order Report', 'bc-mcp-abilities' ),
			__( 'Read order statuses and totals without customers names, emails, addresses or payment metadata.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 25 ),
					'status'   => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'orders' )
		);
	}

	/**
	 * Create a standard success response.
	 *
	 * @param array $data Structured response data.
	 * @return array
	 */
	private static function ok( $data ) {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Validate WooCommerce availability and a product-management capability.
	 *
	 * @return WP_Error|null
	 */
	private static function check_products() {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'bc_mcp_woocommerce_missing', __( 'WooCommerce is not active.', 'bc-mcp-abilities' ) );
		}
		if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'bc_mcp_woocommerce_denied', __( 'Product management permission is required.', 'bc-mcp-abilities' ) );
		}
		return null;
	}

	/**
	 * Summarize product data without disclosing private plugin metadata.
	 *
	 * @param WC_Product $product WooCommerce product instance.
	 * @return array
	 */
	private static function product_summary( $product ) {
		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'sku'            => $product->get_sku(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
			'permalink'      => $product->get_permalink(),
			'inventory_revision' => self::inventory_revision( $product ),
		);
	}

	/**
	 * Read a stable concurrency token for stock-level management.
	 *
	 * @param WC_Product $product Product instance.
	 * @return string
	 */
	private static function inventory_revision( $product ) {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					'stock'         => $product->get_stock_quantity(),
					'status'        => $product->get_stock_status(),
					'manage_stock'  => $product->get_manage_stock(),
					'backorders'    => $product->get_backorders(),
					'product_id'    => $product->get_id(),
				)
			)
		);
	}

	/**
	 * Set a WooCommerce stock level after verifying ownership and revision.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function update_stock( $input ) {
		$denied = self::check_products();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$id      = absint( $input['product_id'] );
		$product = wc_get_product( $id );
		if ( ! $product || ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
			return new WP_Error( 'bc_mcp_stock_type', __( 'This ability supports simple products and individually managed variations only.', 'bc-mcp-abilities' ) );
		}

		$permission_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $id;
		if ( ! current_user_can( 'edit_post', $permission_id ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'bc_mcp_stock_denied', __( 'Permission to manage this inventory item is required.', 'bc-mcp-abilities' ) );
		}

		if ( true !== $product->get_manage_stock() || null === $product->get_stock_quantity() ) {
			return new WP_Error( 'bc_mcp_stock_unmanaged', __( 'Enable individual stock management for this item in WooCommerce first.', 'bc-mcp-abilities' ) );
		}
		if ( ! hash_equals( self::inventory_revision( $product ), (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_stock_conflict', __( 'Product stock has changed since it was read.', 'bc-mcp-abilities' ) );
		}

		$quantity = (int) $input['stock_quantity'];
		$updated  = wc_update_product_stock( $product, $quantity, 'set' );
		if ( null === $updated || false === $updated ) {
			return new WP_Error( 'bc_mcp_stock_failed', __( 'WooCommerce could not update the inventory level.', 'bc-mcp-abilities' ) );
		}
		$fresh = wc_get_product( $id );
		return self::ok( self::product_summary( $fresh ) );
	}

	/**
	 * Search an upper-bounded product collection.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function products( $input ) {
		$denied = self::check_products();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		$limit    = isset( $input['per_page'] ) ? min( 50, max( 1, absint( $input['per_page'] ) ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;
		$query    = array(
			'limit'    => $limit,
			'page'     => $page,
			'status'   => array( 'publish', 'private', 'draft', 'pending' ),
			'paginate' => false,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		if ( ! empty( $input['search'] ) ) {
			$query['s'] = sanitize_text_field( $input['search'] );
		}
		$products = wc_get_products( $query );
		$items    = array();
		foreach ( $products as $product ) {
			if ( current_user_can( 'edit_post', $product->get_id() ) ) {
				$items[] = self::product_summary( $product );
			}
		}
		return self::ok(
			array(
				'products' => $items,
				'count'    => count( $items ),
				'page'     => $page,
			)
		);
	}

	/**
	 * Read a single WooCommerce product.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function product( $input ) {
		$denied = self::check_products();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		$id = absint( $input['product_id'] );
		$product = wc_get_product( $id );
		if ( ! $product || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'bc_mcp_product_missing', __( 'Product unavailable or editing is not permitted.', 'bc-mcp-abilities' ) );
		}
		$details = self::product_summary( $product );
		if ( $product->is_type( 'variable' ) ) {
			$details['variation_ids'] = array_map( 'absint', array_slice( $product->get_children(), 0, 100 ) );
		}
		return self::ok( $details );
	}

	/**
	 * Read an HPOS-safe report with no identifiable customer fields.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function orders( $input ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'bc_mcp_woocommerce_missing', __( 'WooCommerce is not active.', 'bc-mcp-abilities' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'bc_mcp_order_denied', __( 'WooCommerce management permission is required.', 'bc-mcp-abilities' ) );
		}
		$allowed = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
		$status  = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : '';
		if ( $status && ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'bc_mcp_order_status', __( 'Unknown order status filter.', 'bc-mcp-abilities' ) );
		}
		$args = array(
			'limit' => isset( $input['per_page'] ) ? min( 25, max( 1, absint( $input['per_page'] ) ) ) : 10,
			'orderby' => 'date',
			'order' => 'DESC',
			'return' => 'objects',
		);
		if ( $status ) {
			$args['status'] = 'wc-' . $status;
		}
		$orders = wc_get_orders( $args );
		$items  = array();
		foreach ( $orders as $order ) {
			$date    = $order->get_date_created();
			$items[] = array(
				'id'       => $order->get_id(),
				'status'   => $order->get_status(),
				'total'    => $order->get_total(),
				'currency' => $order->get_currency(),
				'created'  => $date ? $date->date( 'c' ) : '',
			);
		}
		return self::ok( array( 'orders' => $items, 'count' => count( $items ) ) );
	}
}
