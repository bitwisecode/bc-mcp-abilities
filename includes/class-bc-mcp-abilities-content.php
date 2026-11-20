<?php
/**
 * Native WordPress content abilities covering safe, visible custom post types.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scoped custom content operations using WordPress post capabilities.
 */
final class BC_MCP_Abilities_Content {
	/**
	 * Forbidden internal post types and separate integrations.
	 */
	const EXCLUDED = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'product',
		'product_variation',
		'shop_order',
		'shop_order_refund',
		'shop_coupon',
		'acf-field-group',
		'acf-field',
		'elementor_library',
		'e-landing-page',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
	);

	/**
	 * Register five standalone WordPress content abilities.
	 *
	 * @return void
	 */
	public static function register() {
		$int  = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$str  = array( 'type' => 'string' );
		$hash = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);

		BC_MCP_Abilities::add(
			'content-types',
			__( 'List Editable Content Types', 'bc-mcp-abilities' ),
			__( 'List ordinary WordPress and publicly visible custom post types that the current user is allowed to edit.', 'bc-mcp-abilities' ),
			self::schema(),
			array( __CLASS__, 'types' )
		);

		BC_MCP_Abilities::add(
			'search-custom-content',
			__( 'Search Visible WordPress Content', 'bc-mcp-abilities' ),
			__( 'Search posts, pages or eligible public custom post types with individual resource permission checks.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'search'    => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'post_type' => array(
						'type'      => 'string',
						'maxLength' => 40,
					),
					'status'    => array(
						'type' => 'string',
						'enum' => array( 'publish', 'draft', 'pending', 'future', 'private', 'any' ),
					),
					'per_page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
				)
			),
			array( __CLASS__, 'search' )
		);

		BC_MCP_Abilities::add(
			'get-custom-content',
			__( 'Read WordPress Custom Content', 'bc-mcp-abilities' ),
			__( 'Read an eligible post, page or custom post type item with a revision hash, without protected metadata.', 'bc-mcp-abilities' ),
			self::schema( array( 'post_id' => $int ), array( 'post_id' ) ),
			array( __CLASS__, 'get' )
		);

		BC_MCP_Abilities::add(
			'create-custom-draft',
			__( 'Create Custom Post Type Draft', 'bc-mcp-abilities' ),
			__( 'Create a draft in an eligible custom post type; never publish or grant capabilities.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_type' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 40,
					),
					'title'     => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 500,
					),
					'excerpt'   => array(
						'type'      => 'string',
						'maxLength' => 5000,
					),
					'content'   => array(
						'type'      => 'string',
						'maxLength' => 200000,
					),
				),
				array( 'post_type', 'title' )
			),
			array( __CLASS__, 'create_draft' ),
			false
		);

		BC_MCP_Abilities::add(
			'update-custom-content',
			__( 'Update Custom WordPress Content', 'bc-mcp-abilities' ),
			__( 'Edit an existing eligible custom post with an exact revision token; cannot change its status or type.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_id'  => $int,
					'revision' => $hash,
					'title'    => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
					'excerpt'  => array(
						'type'      => 'string',
						'maxLength' => 5000,
					),
					'content'  => array(
						'type'      => 'string',
						'maxLength' => 200000,
					),
				),
				array( 'post_id', 'revision' )
			),
			array( __CLASS__, 'update' ),
			false
		);
	}

	/**
	 * Define an object input schema with no unspecified keys.
	 *
	 * @param array $properties Property schemas.
	 * @param array $required   Required keys.
	 * @return array
	 */
	private static function schema( $properties = array(), $required = array() ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * Use the existing BC output envelope.
	 *
	 * @param array $data Result data.
	 * @return array
	 */
	private static function ok( $data ) {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * List public, UI-visible editable types, excluding sensitive system data.
	 *
	 * @return array<string,WP_Post_Type>
	 */
	public static function editable_types() {
		$types  = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		$result = array();
		foreach ( $types as $name => $type ) {
			if ( in_array( $name, self::EXCLUDED, true ) || ! isset( $type->cap->edit_posts ) ) {
				continue;
			}
			if ( ! current_user_can( $type->cap->edit_posts ) ) {
				continue;
			}
			$result[ $name ] = $type;
		}
		return $result;
	}

	/**
	 * Validate that this user's permissions cover an individual resource.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return WP_Post|WP_Error
	 */
	private static function permitted_post( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || ! isset( self::editable_types()[ $post->post_type ] ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'bc_mcp_content_denied', __( 'This content is unavailable or editing is not permitted.', 'bc-mcp-abilities' ) );
		}
		return $post;
	}

	/**
	 * Revision token for an editable post.
	 *
	 * @param WP_Post $post Current post object.
	 * @return string
	 */
	private static function revision( $post ) {
		return hash( 'sha256', $post->post_modified_gmt . '|' . $post->post_title . '|' . $post->post_content . '|' . $post->post_excerpt . '|' . $post->post_status );
	}

	/**
	 * Return allowed public content types.
	 *
	 * @return array
	 */
	public static function types() {
		$types = array();
		foreach ( self::editable_types() as $name => $type ) {
			$types[] = array(
				'post_type' => $name,
				'label'     => $type->label,
				'revisions' => post_type_supports( $name, 'revisions' ),
			);
		}
		return self::ok( array( 'types' => $types ) );
	}

	/**
	 * Search eligible content with object-by-object permissions.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function search( $input = array() ) {
		$types = self::editable_types();
		$type  = sanitize_key( (string) ( $input['post_type'] ?? 'any' ) );
		if ( 'any' !== $type && ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'bc_mcp_content_type_denied', __( 'That custom content type is not editable or available.', 'bc-mcp-abilities' ) );
		}
		if ( empty( $types ) ) {
			return self::ok( array( 'posts' => array() ) );
		}
		$limit = min( 50, max( 1, absint( $input['per_page'] ?? 20 ) ) );
		$query = new WP_Query(
			array(
				'post_type'           => 'any' === $type ? array_keys( $types ) : $type,
				'post_status'         => sanitize_key( (string) ( $input['status'] ?? 'any' ) ),
				's'                   => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				'posts_per_page'      => min( 150, $limit * 3 ),
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
			)
		);
		$items = array();
		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'post_id'   => (int) $post->ID,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'title'     => $post->post_title,
				'url'       => get_permalink( $post ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return self::ok( array( 'posts' => $items ) );
	}

	/**
	 * Retrieve an eligible post and a concurrency hash.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function get( $input ) {
		$post = self::permitted_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::ok(
			array(
				'post_id'  => (int) $post->ID,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'title'    => $post->post_title,
				'excerpt'  => $post->post_excerpt,
				'content'  => $post->post_content,
				'url'      => get_permalink( $post ),
				'revision' => self::revision( $post ),
			)
		);
	}

	/**
	 * Create a draft using only supported custom types and WordPress capabilities.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function create_draft( $input ) {
		$type  = sanitize_key( $input['post_type'] );
		$types = self::editable_types();
		if ( ! isset( $types[ $type ] ) || ! isset( $types[ $type ]->cap->create_posts ) || ! current_user_can( $types[ $type ]->cap->create_posts ) ) {
			return new WP_Error( 'bc_mcp_content_create_denied', __( 'You cannot create drafts in that post type.', 'bc-mcp-abilities' ) );
		}
		$values = array(
			'post_type'    => $type,
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'post_title'   => wp_slash( sanitize_text_field( $input['title'] ) ),
			'post_excerpt' => wp_slash( sanitize_textarea_field( $input['excerpt'] ?? '' ) ),
			'post_content' => wp_slash( wp_kses_post( $input['content'] ?? '' ) ),
		);
		$id     = wp_insert_post( $values, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		return self::ok(
			array(
				'post_id'  => (int) $id,
				'type'     => $type,
				'status'   => 'draft',
				'revision' => self::revision( $post ),
				'url'      => get_edit_post_link( $id, 'raw' ),
			)
		);
	}

	/**
	 * Apply a revision-guarded update without allowing status changes.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function update( $input ) {
		$post = self::permitted_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! hash_equals( self::revision( $post ), (string) $input['revision'] ) ) {
			return new WP_Error( 'bc_mcp_content_conflict', __( 'This post has changed. Read it again before editing.', 'bc-mcp-abilities' ) );
		}
		$values = array( 'ID' => $post->ID );
		foreach ( array( 'title', 'excerpt', 'content' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$property                    = array(
				'title'   => 'post_title',
				'excerpt' => 'post_excerpt',
				'content' => 'post_content',
			);
			$clean                       = 'content' === $key ? wp_kses_post( $input[ $key ] ) : ( 'excerpt' === $key ? sanitize_textarea_field( $input[ $key ] ) : sanitize_text_field( $input[ $key ] ) );
			$values[ $property[ $key ] ] = wp_slash( $clean );
		}
		if ( 1 === count( $values ) ) {
			return new WP_Error( 'bc_mcp_content_no_changes', __( 'No supported fields were supplied.', 'bc-mcp-abilities' ) );
		}
		$result = wp_update_post( $values, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$fresh = get_post( $post->ID );
		return self::ok(
			array(
				'post_id'  => (int) $fresh->ID,
				'status'   => $fresh->post_status,
				'revision' => self::revision( $fresh ),
			)
		);
	}
}
