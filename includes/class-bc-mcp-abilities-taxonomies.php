<?php
/**
 * Permission-scoped WordPress categories, tags and custom taxonomy abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes safe taxonomy reads and explicitly enabled term assignment writes.
 */
final class BC_MCP_Abilities_Taxonomies {
	/**
	 * Register taxonomy abilities.
	 *
	 * @return void
	 */
	public static function register() {
		$str = array( 'type' => 'string' );
		$int = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		BC_MCP_Abilities::add(
			'list-taxonomies',
			__( 'List Editable Taxonomies', 'bc-mcp-abilities' ),
			__( 'List publicly visible taxonomies associated with permitted posts and custom content.', 'bc-mcp-abilities' ),
			self::schema(),
			array( __CLASS__, 'list_taxonomies' )
		);
		BC_MCP_Abilities::add(
			'list-taxonomy-terms',
			__( 'List Taxonomy Terms', 'bc-mcp-abilities' ),
			__( 'Read bounded category, tag and public custom-taxonomy terms.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'taxonomy' => $str,
					'search'   => $str,
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
					'page'     => $int,
				),
				array( 'taxonomy' )
			),
			array( __CLASS__, 'list_terms' )
		);
		BC_MCP_Abilities::add(
			'get-post-terms',
			__( 'Read Assigned Post Terms', 'bc-mcp-abilities' ),
			__( 'Read taxonomy assignments and a concurrency token for one editable post.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_id'  => $int,
					'taxonomy' => $str,
				),
				array( 'post_id', 'taxonomy' )
			),
			array( __CLASS__, 'get_post_terms' )
		);
		BC_MCP_Abilities::add(
			'create-taxonomy-term',
			__( 'Create Taxonomy Term', 'bc-mcp-abilities' ),
			__( 'Create a category, tag or eligible public custom term with taxonomy-level permissions.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'taxonomy'    => $str,
					'name'        => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'description' => array(
						'type'      => 'string',
						'maxLength' => 4000,
					),
					'slug'        => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'parent_id'   => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				array( 'taxonomy', 'name' )
			),
			array( __CLASS__, 'create_term' ),
			false
		);
		BC_MCP_Abilities::add(
			'assign-post-terms',
			__( 'Replace Assigned Taxonomy Terms', 'bc-mcp-abilities' ),
			__( 'Replace the terms for one permitted post after comparing its current assignment revision. An empty array clears the terms.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_id'           => $int,
					'taxonomy'          => $str,
					'term_ids'          => array(
						'type'        => 'array',
						'items'       => $int,
						'maxItems'    => 30,
						'uniqueItems' => true,
					),
					'expected_revision' => array(
						'type'    => 'string',
						'pattern' => '^[a-f0-9]{64}$',
					),
				),
				array( 'post_id', 'taxonomy', 'term_ids', 'expected_revision' )
			),
			array( __CLASS__, 'assign_post_terms' ),
			false
		);
	}

	/**
	 * Build WordPress abilities object schema.
	 *
	 * @param array $properties Property schema.
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
	 * Successful response.
	 *
	 * @param array $data Returned data.
	 * @return array
	 */
	private static function ok( $data ) {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Allow only public taxonomy terms belonging to editable content types.
	 *
	 * @param string $name Taxonomy slug.
	 * @return WP_Taxonomy|WP_Error
	 */
	private static function taxonomy( $name ) {
		$name     = sanitize_key( (string) $name );
		$taxonomy = get_taxonomy( $name );
		if ( ! $taxonomy || ! $taxonomy->public || ! $taxonomy->show_ui ) {
			return new WP_Error( 'bc_mcp_taxonomy_denied', __( 'Taxonomy is unavailable.', 'bc-mcp-abilities' ) );
		}
		$eligible = array_keys( BC_MCP_Abilities_Content::editable_types() );
		if ( ! array_intersect( (array) $taxonomy->object_type, $eligible ) ) {
			return new WP_Error( 'bc_mcp_taxonomy_type', __( 'Taxonomy is not attached to permitted public content.', 'bc-mcp-abilities' ) );
		}
		return $taxonomy;
	}

	/**
	 * Require object-specific edit access before viewing or changing assignments.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|WP_Error
	 */
	private static function post( $id ) {
		$post  = get_post( absint( $id ) );
		$types = BC_MCP_Abilities_Content::editable_types();
		if ( ! $post || ! isset( $types[ $post->post_type ] ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'bc_mcp_taxonomy_post_denied', __( 'Post is unavailable or editing is not permitted.', 'bc-mcp-abilities' ) );
		}
		return $post;
	}

	/**
	 * Term details without protected term metadata.
	 *
	 * @param WP_Term $term Term object.
	 * @return array
	 */
	private static function term_data( $term ) {
		return array(
			'term_id'     => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent_id'   => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}

	/**
	 * List taxonomies available for ordinary editable site content.
	 *
	 * @return array
	 */
	public static function list_taxonomies() {
		$items = array();
		foreach ( get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		) as $tax ) {
			if ( is_wp_error( self::taxonomy( $tax->name ) ) ) {
				continue;
			}
			$items[] = array(
				'slug'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
				'post_types'   => array_values( array_intersect( $tax->object_type, array_keys( BC_MCP_Abilities_Content::editable_types() ) ) ),
			);
		}
		return self::ok( array( 'taxonomies' => $items ) );
	}

	/**
	 * List a bounded page of public term records.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function list_terms( $input ) {
		$tax = self::taxonomy( $input['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$limit = min( 50, max( 1, absint( $input['per_page'] ?? 20 ) ) );
		$page  = min( 1000, max( 1, absint( $input['page'] ?? 1 ) ) );
		$terms = get_terms(
			array(
				'taxonomy'   => $tax->name,
				'hide_empty' => false,
				'number'     => $limit,
				'offset'     => ( $page - 1 ) * $limit,
				'search'     => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		return self::ok(
			array(
				'taxonomy' => $tax->name,
				'page'     => $page,
				'terms'    => array_map( array( __CLASS__, 'term_data' ), $terms ),
			)
		);
	}

	/**
	 * Retrieve IDs and calculate a revision of current taxonomy associations.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $taxonomy Taxonomy slug.
	 * @return array|WP_Error
	 */
	private static function state( $post, $taxonomy ) {
		$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$ids = array_map( 'intval', $ids );
		sort( $ids, SORT_NUMERIC );
		return array(
			'post_id'  => (int) $post->ID,
			'taxonomy' => $taxonomy,
			'term_ids' => $ids,
			'revision' => hash( 'sha256', $post->ID . '|' . $taxonomy . '|' . implode( ',', $ids ) ),
		);
	}

	/**
	 * Read a post's taxonomy term assignments.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_post_terms( $input ) {
		$post = self::post( $input['post_id'] );
		$tax  = self::taxonomy( $input['taxonomy'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		if ( ! is_object_in_taxonomy( $post->post_type, $tax->name ) ) {
			return new WP_Error( 'bc_mcp_taxonomy_not_associated', __( 'Taxonomy is not assigned to that post type.', 'bc-mcp-abilities' ) );
		}
		$state = self::state( $post, $tax->name );
		return is_wp_error( $state ) ? $state : self::ok( $state );
	}

	/**
	 * Create a public term with the taxonomy's own permissions.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function create_term( $input ) {
		$tax = self::taxonomy( $input['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		if ( ! current_user_can( $tax->cap->manage_terms ) || ! current_user_can( $tax->cap->edit_terms ) ) {
			return new WP_Error( 'bc_mcp_taxonomy_create_denied', __( 'Creating terms in this taxonomy is not permitted.', 'bc-mcp-abilities' ) );
		}
		$name = sanitize_text_field( $input['name'] );
		if ( '' === $name || strlen( $name ) > 200 ) {
			return new WP_Error( 'bc_mcp_taxonomy_term_name', __( 'Provide a valid term name.', 'bc-mcp-abilities' ) );
		}
		$args = array();
		if ( isset( $input['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $input['description'] );
		}
		if ( isset( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( $input['slug'] );
		}
		if ( ! empty( $input['parent_id'] ) ) {
			$parent = get_term( absint( $input['parent_id'] ), $tax->name );
			if ( ! $tax->hierarchical || ! $parent || is_wp_error( $parent ) ) {
				return new WP_Error( 'bc_mcp_taxonomy_parent', __( 'Parent term is invalid.', 'bc-mcp-abilities' ) );
			}
			$args['parent'] = (int) $parent->term_id;
		}
		$created = wp_insert_term( $name, $tax->name, $args );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$term = get_term( $created['term_id'], $tax->name );
		return self::ok(
			array(
				'taxonomy' => $tax->name,
				'term'     => self::term_data( $term ),
			)
		);
	}

	/**
	 * Replace assigned terms using a revision token and object-specific caps.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function assign_post_terms( $input ) {
		$post = self::post( $input['post_id'] );
		$tax  = self::taxonomy( $input['taxonomy'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		if ( ! is_object_in_taxonomy( $post->post_type, $tax->name ) || ! current_user_can( $tax->cap->assign_terms ) ) {
			return new WP_Error( 'bc_mcp_taxonomy_assign_denied', __( 'Term assignment is not permitted.', 'bc-mcp-abilities' ) );
		}
		$current = self::state( $post, $tax->name );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! hash_equals( $current['revision'], (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_taxonomy_conflict', __( 'Assigned terms changed; read the post terms again before updating.', 'bc-mcp-abilities' ) );
		}
		$ids = $input['term_ids'];
		if ( ! is_array( $ids ) || count( $ids ) > 30 || count( array_unique( $ids ) ) !== count( $ids ) ) {
			return new WP_Error( 'bc_mcp_taxonomy_assign_input', __( 'Supply up to 30 unique term IDs.', 'bc-mcp-abilities' ) );
		}
		$term_ids = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 || ! term_exists( $id, $tax->name ) ) {
				return new WP_Error( 'bc_mcp_taxonomy_invalid_term', __( 'One or more terms do not exist in the chosen taxonomy.', 'bc-mcp-abilities' ) );
			}
			$term_ids[] = $id;
		}
		$changed = wp_set_object_terms( $post->ID, $term_ids, $tax->name, false );
		if ( is_wp_error( $changed ) ) {
			return $changed;
		}
		$updated = self::state( $post, $tax->name );
		return is_wp_error( $updated ) ? $updated : self::ok( $updated );
	}
}
