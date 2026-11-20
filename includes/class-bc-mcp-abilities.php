<?php
/**
 * Core Abilities registry, independent from any MCP transport.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core WordPress ability registration and secure operations.
 */
final class BC_MCP_Abilities {
	/**
	 * Registered read-only ability catalog, including hidden entries.
	 *
	 * @var array<string,array>
	 */
	private static $read_catalog = array();

	/**
	 * Return all first-party read-only entries for the exposure settings.
	 *
	 * @return array
	 */
	public static function read_catalog() {
		return self::$read_catalog;
	}

	/**
	 * Init using the native WordPress Abilities API.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register categories using the native WordPress Abilities API.
	 *
	 * @return void
	 */
	public static function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'bc-mcp-abilities',
			array(
				'label'       => __( 'BC MCP Abilities', 'bc-mcp-abilities' ),
				'description' => __( 'Secure WordPress management abilities by BitwiseCode.', 'bc-mcp-abilities' ),
			)
		);
	}

	/**
	 * Schema using the native WordPress Abilities API.
	 *
	 * @param mixed $properties Callback input.
	 * @param mixed $required Callback input.
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
	 * Output schema using the native WordPress Abilities API.
	 *
	 * @return array
	 */
	private static function output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'data'    => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'required'   => array( 'success', 'data' ),
		);
	}

	/**
	 * Add using the native WordPress Abilities API.
	 *
	 * @param mixed $slug Callback input.
	 * @param mixed $label Callback input.
	 * @param mixed $description Callback input.
	 * @param mixed $inputs Callback input.
	 * @param mixed $callback Callback input.
	 * @param mixed $read_only Callback input.
	 * @return void
	 */
	public static function add( $slug, $label, $description, $inputs, $callback, $read_only = true ) {
		if ( $read_only ) {
			self::$read_catalog[ $slug ] = array(
				'label'       => $label,
				'description' => $description,
			);
			// Do not register a hidden read ability. This also removes discovery
			// and REST/MCP client exposure, not merely execution permissions.
			if ( BC_MCP_Abilities_Admin::is_read_disabled( $slug ) ) {
				return;
			}
		}

		wp_register_ability(
			'bc-mcp-abilities/' . $slug,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'bc-mcp-abilities',
				'input_schema'        => $inputs,
				'output_schema'       => self::output_schema(),
				'execute_callback'    => static function ( $input = array() ) use ( $callback, $slug, $read_only ) {
					$handler = is_array( $callback ) ? $callback : array( __CLASS__, $callback );
					try {
						$result = call_user_func( $handler, $input );
						$success = ! is_wp_error( $result ) && ( ! is_array( $result ) || ! isset( $result['success'] ) || true === $result['success'] );
						BC_MCP_Abilities_Audit::record( $slug, $read_only, $success );
						return $result;
					} catch ( Throwable $error ) {
						BC_MCP_Abilities_Audit::record( $slug, $read_only, false );
						throw $error;
					}
				},
				'permission_callback' => static function () use ( $read_only, $slug ) {
					return $read_only ? self::can_read( $slug ) : self::can_write( $slug );
				},
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => $read_only,
						'destructive' => ! $read_only,
						'idempotent'  => $read_only,
					),
				),
			)
		);
	}

	/**
	 * Can read using the native WordPress Abilities API.
	 *
	 * @param mixed $slug Callback input.
	 * @return bool
	 */
	public static function can_read( $slug ) {
		if ( BC_MCP_Abilities_Admin::is_read_disabled( $slug ) ) {
			return false;
		}
		// Infrastructure and installed-plugin details are administrator-only.
		if ( 'site-info' === $slug ) {
			return is_user_logged_in() && current_user_can( 'manage_options' );
		}
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * Can write using the native WordPress Abilities API.
	 *
	 * @param mixed $slug Callback input.
	 * @return bool
	 */
	public static function can_write( $slug ) {
		return is_user_logged_in()
			&& current_user_can( 'manage_options' )
			&& BC_MCP_Abilities_Admin::is_enabled( $slug );
	}

	/**
	 * Register abilities using the native WordPress Abilities API.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$integer = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$string  = array( 'type' => 'string' );
		self::add( 'site-info', 'Get Site Information', 'Read site, version, theme, and enabled plugin metadata; requires edit_posts.', self::schema(), 'site_info' );
		self::add(
			'list-content',
			'List Posts and Pages',
			'Search existing posts or pages with optional status and type filters.',
			self::schema(
				array(
					'search'    => $string,
					'post_type' => array(
						'type' => 'string',
						'enum' => array( 'post', 'page' ),
					),
					'status'    => array(
						'type' => 'string',
						'enum' => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ),
					),
					'per_page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
				)
			),
			'list_content'
		);
		self::add( 'get-content', 'Get Post or Page', 'Read a post or page including revision token and editable fields.', self::schema( array( 'post_id' => $integer ), array( 'post_id' ) ), 'get_content' );
		self::add(
			'create-draft',
			'Create Draft',
			'Create a new draft post or page. This never publishes content.',
			self::schema(
				array(
					'post_type' => array(
						'type' => 'string',
						'enum' => array( 'post', 'page' ),
					),
					'title'     => $string,
					'content'   => $string,
					'excerpt'   => $string,
				),
				array( 'post_type', 'title' )
			),
			'create_draft',
			false
		);
		self::add(
			'update-content',
			'Update Post or Page',
			'Edit title, content or excerpt using an exact revision token to prevent overwriting intervening edits.',
			self::schema(
				array(
					'post_id'  => $integer,
					'revision' => $string,
					'title'    => $string,
					'content'  => $string,
					'excerpt'  => $string,
				),
				array( 'post_id', 'revision' )
			),
			'update_content',
			false
		);
		self::add( 'list-revisions', 'List Post Revisions', 'List recent content revisions for a post or page.', self::schema( array( 'post_id' => $integer ), array( 'post_id' ) ), 'list_revisions' );
		self::add(
			'restore-revision',
			'Restore Post Revision',
			'Restore an existing revision only when the current content matches the supplied revision token.',
			self::schema(
				array(
					'post_id'           => $integer,
					'revision_id'       => $integer,
					'expected_revision' => $string,
				),
				array( 'post_id', 'revision_id', 'expected_revision' )
			),
			'restore_revision',
			false
		);
		self::add(
			'list-media',
			'List Media',
			'List recent WordPress Media Library files without downloading bytes.',
			self::schema(
				array(
					'search'   => $string,
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
				)
			),
			'list_media'
		);
		self::add( 'get-media', 'Get Media', 'Read Media Library attachment metadata and alt text.', self::schema( array( 'attachment_id' => $integer ), array( 'attachment_id' ) ), 'get_media' );
		self::add(
			'update-media-alt',
			'Update Image Alt Text',
			'Update the alt text of one existing image attachment.',
			self::schema(
				array(
					'attachment_id' => $integer,
					'alt_text'      => $string,
				),
				array( 'attachment_id', 'alt_text' )
			),
			'update_media_alt',
			false
		);
		self::add( 'elementor-document', 'Read Elementor Document', 'Read an Elementor page structure and revision token without modifying its data.', self::schema( array( 'post_id' => $integer ), array( 'post_id' ) ), 'elementor_document' );
		self::add(
			'elementor-get-element',
			'Read Elementor Element',
			'Find one Elementor element by its element ID.',
			self::schema(
				array(
					'post_id'    => $integer,
					'element_id' => $string,
				),
				array( 'post_id', 'element_id' )
			),
			'elementor_get_element'
		);
		self::add( 'check-url', 'Check Same-site URL', 'Perform a bounded HEAD request to a relative path on the current WordPress site.', self::schema( array( 'path' => $string ) ), 'check_url' );
	}

	/**
	 * Ok using the native WordPress Abilities API.
	 *
	 * @param mixed $data Callback input.
	 * @return array
	 */
	private static function ok( $data ) {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Permitted post using the native WordPress Abilities API.
	 *
	 * @param mixed $id Callback input.
	 * @return WP_Post|WP_Error
	 */
	private static function permitted_post( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'bc_mcp_post_forbidden', __( 'Post unavailable or editing not permitted.', 'bc-mcp-abilities' ) );
		}
		return $post;
	}

	/**
	 * Revision using the native WordPress Abilities API.
	 *
	 * @param mixed $post Callback input.
	 * @return string
	 */
	private static function revision( $post ) {
		return hash( 'sha256', $post->post_modified_gmt . '|' . $post->post_title . '|' . $post->post_content . '|' . $post->post_excerpt . '|' . $post->post_status );
	}

	/**
	 * Post data using the native WordPress Abilities API.
	 *
	 * @param mixed $post Callback input.
	 * @return array
	 */
	private static function post_data( $post ) {
		return array(
			'id'        => (int) $post->ID,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'title'     => $post->post_title,
			'content'   => $post->post_content,
			'excerpt'   => $post->post_excerpt,
			'revision'  => self::revision( $post ),
			'url'       => get_permalink( $post ),
		);
	}

	/**
	 * Site info using the native WordPress Abilities API.
	 *
	 * @return array
	 */
	public static function site_info() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$theme = wp_get_theme();
		return self::ok(
			array(
				'site_name'         => get_bloginfo( 'name' ),
				'home_url'          => home_url( '/' ),
				'wordpress_version' => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'theme'             => $theme->get( 'Name' ),
				'active_plugins'    => array_values( (array) get_option( 'active_plugins', array() ) ),
				'elementor_active'  => did_action( 'elementor/loaded' ) > 0,
			)
		);
	}

	/**
	 * List content using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array
	 */
	public static function list_content( $input ) {
		$input = (array) $input;
		$args  = array(
			'post_type'      => isset( $input['post_type'] ) ? $input['post_type'] : array( 'post', 'page' ),
			'post_status'    => isset( $input['status'] ) ? $input['status'] : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => isset( $input['per_page'] ) ? min( 50, max( 1, absint( $input['per_page'] ) ) ) : 20,
			's'              => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
		);
		$posts = get_posts( $args );
		$items = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'type'     => $post->post_type,
				'revision' => self::revision( $post ),
			);
		}
		return self::ok(
			array(
				'items' => $items,
				'count' => count( $items ),
			)
		);
	}

	/**
	 * Get content using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array|WP_Error
	 */
	public static function get_content( $input ) {
		$post = self::permitted_post( $input['post_id'] );
		return is_wp_error( $post ) ? $post : self::ok( self::post_data( $post ) );
	}

	/**
	 * Create draft using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array|WP_Error
	 */
	public static function create_draft( $input ) {
		$type = isset( $input['post_type'] ) ? $input['post_type'] : '';
		if ( ! in_array( $type, array( 'post', 'page' ), true ) || ! current_user_can( get_post_type_object( $type )->cap->create_posts ) ) {
			return new WP_Error( 'bc_mcp_create_forbidden', 'Not allowed to create this post type.' );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'draft',
				'post_title'   => sanitize_text_field( $input['title'] ),
				'post_content' => isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '',
				'post_excerpt' => isset( $input['excerpt'] ) ? sanitize_textarea_field( $input['excerpt'] ) : '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::ok( self::post_data( get_post( $id ) ) );
	}

	/**
	 * Update content using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array|WP_Error
	 */
	public static function update_content( $input ) {
		$post = self::permitted_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! hash_equals( self::revision( $post ), (string) $input['revision'] ) ) {
			return new WP_Error( 'bc_mcp_revision_conflict', 'Content changed since it was read. Fetch a fresh revision before retrying.' );
		}
		$updates = array( 'ID' => $post->ID );
		foreach ( array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		) as $input_key => $field ) {
			if ( array_key_exists( $input_key, $input ) ) {
				$updates[ $field ] = 'content' === $input_key ? wp_kses_post( $input[ $input_key ] ) : ( 'title' === $input_key ? sanitize_text_field( $input[ $input_key ] ) : sanitize_textarea_field( $input[ $input_key ] ) );
			}
		}
		if ( 1 === count( $updates ) ) {
			return new WP_Error( 'bc_mcp_empty_update', 'Supply at least one content field to update.' );
		}
		$id = wp_update_post( wp_slash( $updates ), true );
		return is_wp_error( $id ) ? $id : self::ok( self::post_data( get_post( $id ) ) );
	}

	/**
	 * List previous revisions of a permitted WordPress post or page.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function list_revisions( $input ) {
		$post = self::permitted_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$revisions = wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 20 ) );
		$items     = array();
		foreach ( $revisions as $revision ) {
			$items[] = array(
				'id'        => (int) $revision->ID,
				'date_gmt'  => $revision->post_date_gmt,
				'author_id' => (int) $revision->post_author,
				'title'     => $revision->post_title,
			);
		}
		return self::ok(
			array(
				'post_id'          => $post->ID,
				'items'            => $items,
				'current_revision' => self::revision( $post ),
			)
		);
	}

	/**
	 * Restore a prior post revision only if no intervening update occurred.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function restore_revision( $input ) {
		$post = self::permitted_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'bc_mcp_restore_forbidden', __( 'Not allowed to restore this post.', 'bc-mcp-abilities' ) );
		}
		if ( ! hash_equals( self::revision( $post ), (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_revision_conflict', __( 'Post changed since it was read. Read the post again.', 'bc-mcp-abilities' ) );
		}
		$revision_id = absint( $input['revision_id'] );
		$revision    = wp_get_post_revision( $revision_id );
		if ( ! $revision || (int) $revision->post_parent !== (int) $post->ID ) {
			return new WP_Error( 'bc_mcp_revision_missing', __( 'Revision does not belong to this post.', 'bc-mcp-abilities' ) );
		}
		$restored_id = wp_restore_post_revision( $revision_id );
		if ( ! $restored_id || is_wp_error( $restored_id ) ) {
			return new WP_Error( 'bc_mcp_restore_failed', __( 'WordPress could not restore the revision.', 'bc-mcp-abilities' ) );
		}
		return self::ok( self::post_data( get_post( $post->ID ) ) );
	}

	/**
	 * List media using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array
	 */
	public static function list_media( $input ) {
		$input = (array) $input;
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => isset( $input['per_page'] ) ? min( 50, max( 1, absint( $input['per_page'] ) ) ) : 20,
				's'              => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$items[] = array(
					'id'    => $post->ID,
					'title' => $post->post_title,
					'mime'  => $post->post_mime_type,
					'url'   => wp_get_attachment_url( $post->ID ),
				);
			}
		}
		return self::ok(
			array(
				'items' => $items,
				'count' => count( $items ),
			)
		);
	}

	/**
	 * Get media using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array|WP_Error
	 */
	public static function get_media( $input ) {
		$id   = absint( $input['attachment_id'] );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'bc_mcp_media_forbidden', 'Attachment unavailable or editing not permitted.' );
		}
		return self::ok(
			array(
				'id'          => $id,
				'title'       => $post->post_title,
				'caption'     => $post->post_excerpt,
				'description' => $post->post_content,
				'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'mime'        => $post->post_mime_type,
				'url'         => wp_get_attachment_url( $id ),
			)
		);
	}

	/**
	 * Update media alt using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array|WP_Error
	 */
	public static function update_media_alt( $input ) {
		$id   = absint( $input['attachment_id'] );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'bc_mcp_media_forbidden', 'Image unavailable or editing not permitted.' );
		}
		$alt = sanitize_text_field( $input['alt_text'] );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return self::ok(
			array(
				'attachment_id' => $id,
				'alt_text'      => $alt,
			)
		);
	}

	/**
	 * Elementor document using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array|WP_Error
	 */
	public static function elementor_document( $input ) {
		$post = self::permitted_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! did_action( 'elementor/loaded' ) ) {
			return new WP_Error( 'bc_mcp_elementor_missing', 'Elementor must be active.' );
		}
		$raw  = get_post_meta( $post->ID, '_elementor_data', true );
		$tree = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $tree ) ) {
			return new WP_Error( 'bc_mcp_elementor_invalid', 'Elementor document has no valid element tree.' );
		}
		return self::ok(
			array(
				'post_id'  => $post->ID,
				'elements' => $tree,
				'revision' => hash( 'sha256', wp_json_encode( $tree ) ),
			)
		);
	}


	/**
	 * Find a nested Elementor node without modifying its document.
	 *
	 * @param array  $nodes Elementor element tree.
	 * @param string $id    Exact element ID.
	 * @param int    $depth Recursion depth.
	 * @return array|null
	 */
	private static function find_element( $nodes, $id, $depth = 0 ) {
		if ( ! is_array( $nodes ) || $depth > 30 ) {
			return null;
		}
		foreach ( $nodes as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === $id ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) ) {
				$found = self::find_element( $element['elements'], $id, $depth + 1 );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Read a particular Elementor element.
	 *
	 * @param array $input Schema-validated input.
	 * @return array|WP_Error
	 */
	public static function elementor_get_element( $input ) {
		$document = self::elementor_document( array( 'post_id' => $input['post_id'] ) );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$id = (string) $input['element_id'];
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $id ) ) {
			return new WP_Error( 'bc_mcp_elementor_bad_id', 'Invalid element ID.' );
		}
		$element = self::find_element( $document['data']['elements'], $id );
		if ( null === $element ) {
			return new WP_Error( 'bc_mcp_elementor_not_found', 'Element not found.' );
		}
		return self::ok(
			array(
				'post_id'  => absint( $input['post_id'] ),
				'revision' => $document['data']['revision'],
				'element'  => $element,
			)
		);
	}

	/**
	 * Check url using the native WordPress Abilities API.
	 *
	 * @param mixed $input Callback input.
	 * @return array|WP_Error
	 */
	public static function check_url( $input ) {
		$path = isset( $input['path'] ) ? (string) $input['path'] : '/';
		if ( '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) || false !== strpos( $path, '\\' ) || false !== strpos( $path, '#' ) ) {
			return new WP_Error( 'bc_mcp_invalid_path', 'Use a root-relative same-site path starting with a single slash.' );
		}
		$url      = home_url( $path );
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 2048,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return self::ok(
			array(
				'url'         => $url,
				'http_status' => (int) wp_remote_retrieve_response_code( $response ),
			)
		);
	}
}
