<?php
/**
 * Permission-checked classic WordPress navigation menu abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * List and safely create classic navigation menus using WordPress core APIs.
 */
final class BC_MCP_Abilities_Menus {
	/**
	 * Register navigation abilities.
	 *
	 * @return void
	 */
	public static function register() {
		$int = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		BC_MCP_Abilities::add(
			'list-menus',
			__( 'List WordPress Navigation Menus', 'bc-mcp-abilities' ),
			__( 'List classic navigation menus and their assigned theme locations.', 'bc-mcp-abilities' ),
			self::schema(),
			array( __CLASS__, 'list_menus' )
		);
		BC_MCP_Abilities::add(
			'get-menu',
			__( 'Read Navigation Menu', 'bc-mcp-abilities' ),
			__( 'Read one classic navigation menu and its bounded item hierarchy.', 'bc-mcp-abilities' ),
			self::schema( array( 'menu_id' => $int ), array( 'menu_id' ) ),
			array( __CLASS__, 'get_menu' )
		);
		BC_MCP_Abilities::add(
			'create-menu',
			__( 'Create Navigation Menu', 'bc-mcp-abilities' ),
			__( 'Create an empty classic menu without changing theme locations.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'name' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 100,
					),
				),
				array( 'name' )
			),
			array( __CLASS__, 'create_menu' ),
			false
		);
		BC_MCP_Abilities::add(
			'add-menu-item',
			__( 'Add Menu Item', 'bc-mcp-abilities' ),
			__( 'Add one existing accessible post or public taxonomy term to a classic menu. Does not accept arbitrary URLs.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'menu_id'    => $int,
					'object_id'  => $int,
					'object'     => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 40,
					),
					'parent_id'  => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'position'   => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 500,
					),
				),
				array( 'menu_id', 'object_id', 'object' )
			),
			array( __CLASS__, 'add_menu_item' ),
			false
		);
	}

	/**
	 * Build a strict schema.
	 *
	 * @param array $properties Properties.
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
	 * Return an ordinary success response.
	 *
	 * @param array $data Result values.
	 * @return array
	 */
	private static function ok( $data ) {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Keep classic menu management restricted to theme editors.
	 *
	 * @return true|WP_Error
	 */
	private static function allowed() {
		return current_user_can( 'edit_theme_options' ) ? true : new WP_Error( 'bc_mcp_menu_forbidden', __( 'Navigation menu management is not permitted.', 'bc-mcp-abilities' ) );
	}

	/**
	 * Require a valid menu term before any reads or edits.
	 *
	 * @param int $id Menu ID.
	 * @return WP_Term|WP_Error
	 */
	private static function menu( $id ) {
		$menu = wp_get_nav_menu_object( absint( $id ) );
		return $menu && ! is_wp_error( $menu ) ? $menu : new WP_Error( 'bc_mcp_menu_missing', __( 'Navigation menu not found.', 'bc-mcp-abilities' ) );
	}

	/**
	 * List menus and their current theme-location bindings.
	 *
	 * @return array|WP_Error
	 */
	public static function list_menus() {
		$access = self::allowed();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$locations = get_nav_menu_locations();
		$items     = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$assigned = array();
			foreach ( $locations as $slug => $menu_id ) {
				if ( (int) $menu_id === (int) $menu->term_id ) {
					$assigned[] = $slug;
				}
			}
			$items[] = array(
				'menu_id'    => (int) $menu->term_id,
				'name'       => $menu->name,
				'slug'       => $menu->slug,
				'item_count' => (int) $menu->count,
				'locations'  => $assigned,
			);
		}
		return self::ok(
			array(
				'menus'      => $items,
				'locations'  => get_registered_nav_menus(),
				'classic_ui' => true,
			)
		);
	}

	/**
	 * Read one menu's item hierarchy.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function get_menu( $input ) {
		$access = self::allowed();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$menu = self::menu( $input['menu_id'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$entries = array();
		foreach ( (array) $items as $item ) {
			$entries[] = array(
				'item_id'    => (int) $item->ID,
				'title'      => $item->title,
				'url'        => esc_url_raw( $item->url ),
				'type'       => $item->type,
				'object'     => $item->object,
				'object_id'  => (int) $item->object_id,
				'parent_id'  => (int) $item->menu_item_parent,
				'position'   => (int) $item->menu_order,
			);
			if ( count( $entries ) >= 200 ) {
				break;
			}
		}
		return self::ok(
			array(
				'menu_id'   => (int) $menu->term_id,
				'name'      => $menu->name,
				'items'     => $entries,
				'truncated' => count( (array) $items ) > 200,
			)
		);
	}

	/**
	 * Create an empty menu without assigning a theme location.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function create_menu( $input ) {
		$access = self::allowed();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$name = sanitize_text_field( (string) $input['name'] );
		if ( '' === $name || strlen( $name ) > 100 ) {
			return new WP_Error( 'bc_mcp_menu_name', __( 'Menu name is invalid.', 'bc-mcp-abilities' ) );
		}
		$id = wp_create_nav_menu( $name );
		return is_wp_error( $id ) ? $id : self::ok( array( 'menu_id' => (int) $id, 'name' => $name ) );
	}

	/**
	 * Add an existing post or term as a published menu item.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function add_menu_item( $input ) {
		$access = self::allowed();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$menu = self::menu( $input['menu_id'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$object = sanitize_key( (string) $input['object'] );
		$id     = absint( $input['object_id'] );
		$types  = BC_MCP_Abilities_Content::editable_types();
		if ( isset( $types[ $object ] ) ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== $object || ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'bc_mcp_menu_item_denied', __( 'That post cannot be used as a menu item.', 'bc-mcp-abilities' ) );
			}
			$type = 'post_type';
		} else {
			$tax = get_taxonomy( $object );
			$term = $tax && $tax->public && $tax->show_ui ? get_term( $id, $object ) : false;
			if ( ! $tax || ! $tax->public || ! $tax->show_ui || ! $term || is_wp_error( $term ) ||
				! current_user_can( $tax->cap->assign_terms ) ) {
				return new WP_Error( 'bc_mcp_menu_term_denied', __( 'That taxonomy term cannot be added.', 'bc-mcp-abilities' ) );
			}
			$type = 'taxonomy';
		}
		$parent_id = absint( $input['parent_id'] ?? 0 );
		if ( $parent_id ) {
			$parents = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
			$matched = false;
			foreach ( (array) $parents as $parent ) {
				if ( (int) $parent->ID === $parent_id ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return new WP_Error( 'bc_mcp_menu_parent', __( 'Parent menu item does not belong to this menu.', 'bc-mcp-abilities' ) );
			}
		}
		$args = array(
			'menu-item-type'      => $type,
			'menu-item-object'    => $object,
			'menu-item-object-id' => $id,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_id,
		);
		if ( isset( $input['position'] ) ) {
			$args['menu-item-position'] = absint( $input['position'] );
		}
		$item_id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
		return is_wp_error( $item_id ) ? $item_id : self::ok( array( 'menu_id' => (int) $menu->term_id, 'item_id' => (int) $item_id ) );
	}
}
