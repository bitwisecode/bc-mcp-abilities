<?php
/**
 * Opt-in Yoast SEO and WooCommerce price management abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guarded SEO metadata and individual product pricing integrations.
 */
final class BC_MCP_Abilities_Release_Integrations {
	/**
	 * Register abilities with the native WordPress registry.
	 *
	 * @return void
	 */
	public static function register() {
		$id         = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$revision   = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$seo_fields = array(
			'title'         => array(
				'type'      => 'string',
				'maxLength' => 1000,
			),
			'description'   => array(
				'type'      => 'string',
				'maxLength' => 1000,
			),
			'focus_keyword' => array(
				'type'      => 'string',
				'maxLength' => 1000,
			),
		);
		BC_MCP_Abilities::add(
			'yoast-seo-read',
			__( 'Read Yoast SEO Fields', 'bc-mcp-abilities' ),
			__( 'Read Yoast SEO title, description and focus keyword for an editable post or page, with an SEO revision token.', 'bc-mcp-abilities' ),
			self::schema( array( 'post_id' => $id ), array( 'post_id' ) ),
			array( __CLASS__, 'yoast_read' )
		);
		BC_MCP_Abilities::add(
			'yoast-seo-update',
			__( 'Update Yoast SEO Fields', 'bc-mcp-abilities' ),
			__( 'Update only supplied Yoast SEO fields for an editable post or page using the current SEO revision token.', 'bc-mcp-abilities' ),
			self::schema(
				array_merge(
					array(
						'post_id'           => $id,
						'expected_revision' => $revision,
					),
					$seo_fields
				),
				array( 'post_id', 'expected_revision' )
			),
			array( __CLASS__, 'yoast_update' ),
			false
		);
		BC_MCP_Abilities::add(
			'wc-update-prices',
			__( 'Update WooCommerce Product Prices', 'bc-mcp-abilities' ),
			__( 'Update a simple product or individually managed variation regular/sale price using a pricing revision token; no bulk edits.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'product_id'        => $id,
					'expected_revision' => $revision,
					'regular_price'     => array(
						'type'      => 'string',
						'maxLength' => 24,
					),
					'sale_price'        => array(
						'type'      => 'string',
						'maxLength' => 24,
					),
				),
				array( 'product_id', 'expected_revision' )
			),
			array( __CLASS__, 'update_prices' ),
			false
		);
	}

	/**
	 * Strict object input schema.
	 *
	 * @param array $properties Properties.
	 * @param array $required Required property names.
	 * @return array
	 */
	private static function schema( $properties, $required ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * Standard result envelope.
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
	 * Validate Yoast is active and that the current account can edit this post.
	 *
	 * @param int $id Requested post.
	 * @return WP_Post|WP_Error
	 */
	private static function yoast_post( $id ) {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return new WP_Error( 'bc_mcp_yoast_missing', __( 'Yoast SEO is not active.', 'bc-mcp-abilities' ) );
		}
		$post = get_post( absint( $id ) );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ||
			! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'bc_mcp_yoast_forbidden', __( 'Post is unavailable or edit access is denied.', 'bc-mcp-abilities' ) );
		}
		return $post;
	}

	/**
	 * Read the three known Yoast post-meta keys (no arbitrary metadata).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function yoast_state( $post_id ) {
		$fields = array(
			'title'         => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'description'   => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
		);
		return array(
			'post_id'  => (int) $post_id,
			'fields'   => $fields,
			'revision' => hash( 'sha256', wp_json_encode( $fields ) ),
		);
	}

	/**
	 * Fetch bounded SEO fields and the revision token.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function yoast_read( $input ) {
		$post = self::yoast_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::ok( self::yoast_state( $post->ID ) );
	}

	/**
	 * Write only explicitly submitted Yoast fields after revision comparison.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function yoast_update( $input ) {
		$post = self::yoast_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$state = self::yoast_state( $post->ID );
		if ( ! hash_equals( $state['revision'], (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_yoast_conflict', __( 'Yoast SEO fields have changed; fetch the latest revision.', 'bc-mcp-abilities' ) );
		}
		// Use Yoast's own Abilities API for title and description so the
		// indexable presentation is refreshed by the owning plugin.
		// Focus keyword is intentionally separate because Yoast's official
		// update-post-seo-data ability does not permit writing keyphrases.
		$map = array(
			'title'       => 'seo_title',
			'description' => 'meta_description',
		);
		$native_input = array( 'post_id' => (int) $post->ID );
		foreach ( $map as $field => $native_field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			if ( ! is_string( $input[ $field ] ) || strlen( $input[ $field ] ) > 1000 ) {
				return new WP_Error( 'bc_mcp_yoast_field_invalid', __( 'SEO field must be text within 1000 bytes.', 'bc-mcp-abilities' ) );
			}
			$native_input[ $native_field ] = sanitize_text_field( $input[ $field ] );
		}
		$focus_supplied = array_key_exists( 'focus_keyword', $input );
		if ( $focus_supplied && ( ! is_string( $input['focus_keyword'] ) || strlen( $input['focus_keyword'] ) > 1000 ) ) {
			return new WP_Error( 'bc_mcp_yoast_field_invalid', __( 'Focus keyword must be text within 1000 bytes.', 'bc-mcp-abilities' ) );
		}
		if ( 1 === count( $native_input ) && ! $focus_supplied ) {
			return new WP_Error( 'bc_mcp_yoast_empty', __( 'Supply at least one Yoast SEO field.', 'bc-mcp-abilities' ) );
		}

		// Match Yoast's own advanced-metadata permission requirement before
		// running either the native ability or an existing focus-keyword edit.
		if ( class_exists( 'WPSEO_Capability_Utils' ) && ! WPSEO_Capability_Utils::current_user_can( 'wpseo_edit_advanced_metadata' ) ) {
			return new WP_Error( 'bc_mcp_yoast_capability', __( 'Advanced Yoast SEO metadata permission is required.', 'bc-mcp-abilities' ) );
		}

		if ( count( $native_input ) > 1 ) {
			$native = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'yoast-seo/update-post-seo-data' ) : null;
			if ( ! $native ) {
				return new WP_Error( 'bc_mcp_yoast_native_missing', __( 'Update Yoast SEO to a version supporting native SEO data abilities before editing titles or descriptions.', 'bc-mcp-abilities' ) );
			}
			$result = $native->execute( $native_input );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! is_array( $result ) || (int) ( $result['post_id'] ?? 0 ) !== (int) $post->ID ) {
				return new WP_Error( 'bc_mcp_yoast_native_failed', __( 'Yoast SEO did not confirm the updated post.', 'bc-mcp-abilities' ) );
			}
		}

		if ( $focus_supplied ) {
			update_post_meta( $post->ID, '_yoast_wpseo_focuskw', sanitize_text_field( $input['focus_keyword'] ) );
		}
		$updated = self::yoast_state( $post->ID );
		if ( count( $native_input ) > 1 ) {
			$rendered = wp_get_ability( 'yoast-seo/get-post-seo-data' );
			if ( $rendered ) {
				$data = $rendered->execute( array( 'post_id' => (int) $post->ID ) );
				if ( is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ) {
					$updated['rendered'] = array(
						'seo_title'        => (string) ( $data[0]['seo_title_rendered'] ?? '' ),
						'meta_description' => (string) ( $data[0]['meta_description_rendered'] ?? '' ),
					);
				}
			}
		}
		return self::ok( $updated );
	}

	/**
	 * Calculate a revision including sale scheduling and product currency.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return string
	 */
	private static function price_revision( $product ) {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					'id'            => $product->get_id(),
					'regular_price' => (string) $product->get_regular_price(),
					'sale_price'    => (string) $product->get_sale_price(),
					'sale_from'     => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->getTimestamp() : null,
					'sale_to'       => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->getTimestamp() : null,
					'currency'      => get_woocommerce_currency(),
				)
			)
		);
	}

	/**
	 * Read pricing revision for a guarded write.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function pricing_state( $product ) {
		return array(
			'product_id'       => (int) $product->get_id(),
			'regular_price'    => (string) $product->get_regular_price(),
			'sale_price'       => (string) $product->get_sale_price(),
			'currency'         => get_woocommerce_currency(),
			'pricing_revision' => self::price_revision( $product ),
		);
	}

	/**
	 * Parse a decimal amount using the shop currency's precision.
	 *
	 * @param mixed $raw Submitted value, or empty string to remove sale price.
	 * @param bool  $allow_empty Whether clearing is allowed.
	 * @return string|WP_Error
	 */
	private static function decimal_price( $raw, $allow_empty ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > 24 ) {
			return new WP_Error( 'bc_mcp_price_format', __( 'Provide a decimal price as a string.', 'bc-mcp-abilities' ) );
		}
		if ( $allow_empty && '' === $raw ) {
			return '';
		}
		$precision = min( 6, max( 0, (int) wc_get_price_decimals() ) );
		$pattern   = $precision > 0 ? '/^(?:0|[1-9][0-9]{0,7})(?:\\.[0-9]{1,' . $precision . '})?$/' : '/^(?:0|[1-9][0-9]{0,7})$/';
		if ( ! preg_match( $pattern, $raw ) ) {
			return new WP_Error( 'bc_mcp_price_format', __( 'Price must be nonnegative, without grouping, within shop decimal precision.', 'bc-mcp-abilities' ) );
		}
		return wc_format_decimal( $raw, $precision );
	}

	/**
	 * Save simple product or variation prices after verifying revision.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function update_prices( $input ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'bc_mcp_price_wc_missing', __( 'WooCommerce is not active.', 'bc-mcp-abilities' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) ) {
			return new WP_Error( 'bc_mcp_price_forbidden', __( 'WooCommerce product management permission is required.', 'bc-mcp-abilities' ) );
		}
		$id      = absint( $input['product_id'] );
		$product = wc_get_product( $id );
		if ( ! $product || ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
			return new WP_Error( 'bc_mcp_price_product', __( 'Only simple products or individual variations are supported.', 'bc-mcp-abilities' ) );
		}
		$target = $product->is_type( 'variation' ) ? $product->get_parent_id() : $id;
		if ( ! current_user_can( 'edit_post', $target ) ) {
			return new WP_Error( 'bc_mcp_price_post_forbidden', __( 'Permission to edit this product is required.', 'bc-mcp-abilities' ) );
		}
		if ( ! hash_equals( self::price_revision( $product ), (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_price_conflict', __( 'Product pricing changed. Read the product again before editing.', 'bc-mcp-abilities' ) );
		}
		$has_regular = array_key_exists( 'regular_price', $input );
		$has_sale    = array_key_exists( 'sale_price', $input );
		if ( ! $has_regular && ! $has_sale ) {
			return new WP_Error( 'bc_mcp_price_empty', __( 'Provide a regular price or sale price.', 'bc-mcp-abilities' ) );
		}
		$regular = $has_regular ? self::decimal_price( $input['regular_price'], false ) : (string) $product->get_regular_price();
		$sale    = $has_sale ? self::decimal_price( $input['sale_price'], true ) : (string) $product->get_sale_price();
		if ( is_wp_error( $regular ) ) {
			return $regular;
		}
		if ( is_wp_error( $sale ) ) {
			return $sale;
		}
		if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
			return new WP_Error( 'bc_mcp_price_sale_invalid', __( 'Sale price must be less than the regular price.', 'bc-mcp-abilities' ) );
		}
		if ( $has_regular ) {
			$product->set_regular_price( $regular );
		}
		if ( $has_sale ) {
			$product->set_sale_price( $sale );
		}
		try {
			$product->save();
		} catch ( Exception $error ) {
			return new WP_Error( 'bc_mcp_price_save_failed', __( 'WooCommerce could not save pricing.', 'bc-mcp-abilities' ) );
		}
		$fresh = wc_get_product( $id );
		return self::ok( self::pricing_state( $fresh ) );
	}
}
