<?php
/**
 * Scoped Elementor document operations inspired by the existing Jobayer Agent.
 *
 * No unrestricted layout JSON writes, code widgets or arbitrary settings.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Safe Elementor content editing, structure changes and diagnostics.
 */
final class BC_MCP_Abilities_Elementor_Agent {

	/**
	 * Maximum element count in an Elementor document.
	 */
	const MAX_ELEMENTS = 1500;

	/**
	 * Maximum nesting depth in Elementor data.
	 */
	const MAX_DEPTH = 30;

	/**
	 * Maximum bytes in a JSON template or response.
	 */
	const MAX_JSON = 1048576;

	/**
	 * Register eight new guarded Elementor abilities.
	 *
	 * @return void
	 */
	public static function register() {
		$int      = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$id       = array(
			'type'      => 'string',
			'pattern'   => '^[a-zA-Z0-9_-]{1,64}$',
			'maxLength' => 64,
		);
		$revision = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$object   = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		$position = array(
			'type'    => 'integer',
			'minimum' => 0,
			'maximum' => 1500,
		);

		BC_MCP_Abilities::add(
			'elementor-validate-json',
			__( 'Validate Elementor Template JSON', 'bc-mcp-abilities' ),
			__( 'Check the structure of an Elementor JSON template; never imports or executes its contents.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'template_json' => array(
						'type'      => 'string',
						'minLength' => 2,
						'maxLength' => self::MAX_JSON,
					),
				),
				array( 'template_json' )
			),
			array( __CLASS__, 'validate_json' )
		);

		BC_MCP_Abilities::add(
			'elementor-export-json',
			__( 'Export Elementor Template JSON', 'bc-mcp-abilities' ),
			__( 'Read and export an editable Elementor document in portable JSON format, bounded to 1 MB.', 'bc-mcp-abilities' ),
			self::schema( array( 'post_id' => $int ), array( 'post_id' ) ),
			array( __CLASS__, 'export_json' )
		);

		BC_MCP_Abilities::add(
			'elementor-create-document',
			__( 'Create Elementor Draft Document', 'bc-mcp-abilities' ),
			__( 'Create an empty draft post or page and initialize Elementor via its document API.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_type' => array(
						'type' => 'string',
						'enum' => array( 'post', 'page' ),
					),
					'title'     => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 500,
					),
				),
				array( 'post_type', 'title' )
			),
			array( __CLASS__, 'create_document' ),
			false
		);

		BC_MCP_Abilities::add(
			'elementor-update-settings',
			__( 'Update Safe Elementor Widget Settings', 'bc-mcp-abilities' ),
			__( 'Merge allowlisted content and alignment settings into one widget with revision check and undo.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_id'           => $int,
					'element_id'        => $id,
					'expected_revision' => $revision,
					'settings'          => $object,
				),
				array( 'post_id', 'element_id', 'expected_revision', 'settings' )
			),
			array( __CLASS__, 'update_settings' ),
			false
		);

		BC_MCP_Abilities::add(
			'elementor-add-element',
			__( 'Add Safe Elementor Element', 'bc-mcp-abilities' ),
			__( 'Insert a supported native widget or container, never code/HTML widgets, with revision protection and undo.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_id'           => $int,
					'expected_revision' => $revision,
					'parent_id'         => array(
						'type'      => 'string',
						'maxLength' => 64,
					),
					'position'          => $position,
					'kind'              => array(
						'type' => 'string',
						'enum' => array( 'container', 'heading', 'text-editor', 'button', 'divider', 'spacer' ),
					),
					'settings'          => $object,
				),
				array( 'post_id', 'expected_revision', 'kind' )
			),
			array( __CLASS__, 'add_element' ),
			false
		);

		BC_MCP_Abilities::add(
			'elementor-delete-element',
			__( 'Delete Elementor Element', 'bc-mcp-abilities' ),
			__( 'Remove one Elementor element after revision confirmation. Nested child deletion requires explicit permission.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_id'           => $int,
					'element_id'        => $id,
					'expected_revision' => $revision,
					'allow_nested'      => array( 'type' => 'boolean' ),
				),
				array( 'post_id', 'element_id', 'expected_revision' )
			),
			array( __CLASS__, 'delete_element' ),
			false
		);

		BC_MCP_Abilities::add(
			'elementor-move-element',
			__( 'Move Elementor Element', 'bc-mcp-abilities' ),
			__( 'Move a widget or container without altering settings; prevents loops, invalid parents and stale writes.', 'bc-mcp-abilities' ),
			self::schema(
				array(
					'post_id'           => $int,
					'element_id'        => $id,
					'expected_revision' => $revision,
					'new_parent_id'     => array(
						'type'      => 'string',
						'maxLength' => 64,
					),
					'position'          => $position,
				),
				array( 'post_id', 'element_id', 'expected_revision' )
			),
			array( __CLASS__, 'move_element' ),
			false
		);

		BC_MCP_Abilities::add(
			'elementor-regenerate-css',
			__( 'Regenerate Elementor Document CSS', 'bc-mcp-abilities' ),
			__( 'Regenerate Elementor-generated styles for an existing editable document using its CSS API.', 'bc-mcp-abilities' ),
			self::schema( array( 'post_id' => $int ), array( 'post_id' ) ),
			array( __CLASS__, 'regenerate_css' ),
			false
		);
	}

	/**
	 * Standard object schema.
	 *
	 * @param array $properties Allowed keys.
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
	 * Common output envelope.
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
	 * Validate an element list and reject duplicate IDs or abnormal depth.
	 *
	 * @param mixed $nodes Elements to examine.
	 * @param int   $depth Current depth.
	 * @param int   $count Running element count.
	 * @param array $ids   IDs already encountered.
	 * @return WP_Error|null
	 */
	private static function inspect_tree( $nodes, $depth, &$count, &$ids ) {
		if ( ! is_array( $nodes ) || array_values( $nodes ) !== $nodes || $depth > self::MAX_DEPTH ) {
			return new WP_Error( 'bc_mcp_elementor_tree_invalid', __( 'Expected a bounded, list-shaped Elementor element tree.', 'bc-mcp-abilities' ) );
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['id'] ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', (string) $node['id'] ) || ! in_array( $node['elType'] ?? '', array( 'widget', 'container', 'section', 'column' ), true ) ) {
				return new WP_Error( 'bc_mcp_elementor_invalid_node', __( 'The document contains an invalid Elementor element.', 'bc-mcp-abilities' ) );
			}
			if ( isset( $ids[ $node['id'] ] ) ) {
				return new WP_Error( 'bc_mcp_elementor_duplicate_id', __( 'The document contains repeated Elementor element IDs.', 'bc-mcp-abilities' ) );
			}
			$ids[ $node['id'] ] = true;
			++$count;
			if ( $count > self::MAX_ELEMENTS ) {
				return new WP_Error( 'bc_mcp_elementor_too_many', __( 'The Elementor element count is too large.', 'bc-mcp-abilities' ) );
			}
			if ( 'widget' === $node['elType'] && empty( $node['widgetType'] ) ) {
				return new WP_Error( 'bc_mcp_elementor_missing_widget', __( 'A widget has no widget type.', 'bc-mcp-abilities' ) );
			}
			if ( isset( $node['settings'] ) && ! is_array( $node['settings'] ) ) {
				return new WP_Error( 'bc_mcp_elementor_settings_invalid', __( 'A widget settings object is invalid.', 'bc-mcp-abilities' ) );
			}
			if ( isset( $node['elements'] ) ) {
				$error = self::inspect_tree( $node['elements'], $depth + 1, $count, $ids );
				if ( is_wp_error( $error ) ) {
					return $error;
				}
			}
		}
		return null;
	}

	/**
	 * Validate arbitrary Elementor element JSON without executing it.
	 *
	 * @param string $json JSON text.
	 * @return array|WP_Error
	 */
	private static function inspect_json( $json ) {
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_JSON ) {
			return new WP_Error( 'bc_mcp_template_large', __( 'Template JSON exceeds the 1 MB safety limit.', 'bc-mcp-abilities' ) );
		}
		$value = json_decode( $json, true, 40 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $value ) ) {
			return new WP_Error( 'bc_mcp_template_json', __( 'Template JSON could not be decoded as a valid object.', 'bc-mcp-abilities' ) );
		}
		$nodes = isset( $value['content'] ) && is_array( $value['content'] ) ? $value['content'] : ( isset( $value['elements'] ) && is_array( $value['elements'] ) ? $value['elements'] : $value );
		$count = 0;
		$ids   = array();
		$error = self::inspect_tree( $nodes, 0, $count, $ids );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		return array(
			'valid'         => true,
			'element_count' => $count,
			'depth_limit'   => self::MAX_DEPTH,
			'byte_length'   => strlen( $json ),
			'note'          => 'Structural checks only. Not approved for importing arbitrary HTML, PHP, JS or dynamic widget settings.',
		);
	}

	/**
	 * Report structural validity without making any WordPress changes.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function validate_json( $input ) {
		$report = self::inspect_json( $input['template_json'] );
		return is_wp_error( $report ) ? $report : self::ok( $report );
	}

	/**
	 * Read a current and valid Elementor snapshot with a required revision.
	 *
	 * @param array $input Input including post and revision.
	 * @return array|WP_Error
	 */
	private static function checked_snapshot( $input ) {
		$snapshot = BC_MCP_Abilities_Elementor::snapshot( absint( $input['post_id'] ) );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! hash_equals( $snapshot['revision'], (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_elementor_conflict', __( 'Elementor document changed. Fetch it again before editing.', 'bc-mcp-abilities' ) );
		}
		$ids   = array();
		$count = 0;
		$error = self::inspect_tree( $snapshot['tree'], 0, $count, $ids );
		return is_wp_error( $error ) ? $error : $snapshot;
	}

	/**
	 * Find a single element anywhere in a document tree.
	 *
	 * @param array  $tree Elementor element nodes.
	 * @param string $id   Element ID.
	 * @return array|null
	 */
	private static function find_element( $tree, $id ) {
		foreach ( $tree as $node ) {
			if ( (string) ( $node['id'] ?? '' ) === $id ) {
				return $node;
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$found = self::find_element( $node['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Allow only a few plain content or layout settings on supported elements.
	 *
	 * @param string $type     Widget kind.
	 * @param array  $settings Settings to change.
	 * @return array|WP_Error
	 */
	private static function safe_settings( $type, $settings ) {
		$allowed = array(
			'heading'     => array( 'title', 'header_size', 'align', 'size' ),
			'text-editor' => array( 'editor' ),
			'button'      => array( 'text', 'align', 'size' ),
			'container'   => array( 'flex_direction', 'content_width' ),
			'divider'     => array(),
			'spacer'      => array(),
		);
		if ( ! isset( $allowed[ $type ] ) || ! is_array( $settings ) ) {
			return new WP_Error( 'bc_mcp_elementor_settings_type', __( 'Unsupported widget type or settings format.', 'bc-mcp-abilities' ) );
		}
		$output = array();
		foreach ( $settings as $key => $value ) {
			if ( ! in_array( $key, $allowed[ $type ], true ) || ! is_string( $value ) || strlen( $value ) > 20000 ) {
				return new WP_Error( 'bc_mcp_elementor_settings_denied', __( 'Unsupported or unsafe Elementor setting.', 'bc-mcp-abilities' ) );
			}
			if ( 'editor' === $key ) {
				$output[ $key ] = wp_kses_post( $value );
			} elseif ( 'header_size' === $key ) {
				if ( ! in_array( $value, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p', 'span' ), true ) ) {
					return new WP_Error( 'bc_mcp_elementor_heading_size', __( 'Invalid heading tag.', 'bc-mcp-abilities' ) );
				}
				$output[ $key ] = $value;
			} elseif ( 'align' === $key ) {
				if ( ! in_array( $value, array( 'left', 'center', 'right', 'justify' ), true ) ) {
					return new WP_Error( 'bc_mcp_elementor_alignment', __( 'Invalid widget alignment.', 'bc-mcp-abilities' ) );
				}
				$output[ $key ] = $value;
			} elseif ( 'size' === $key ) {
				if ( ! in_array( $value, array( 'default', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl', 'small', 'medium', 'large' ), true ) ) {
					return new WP_Error( 'bc_mcp_elementor_size', __( 'Invalid widget size.', 'bc-mcp-abilities' ) );
				}
				$output[ $key ] = $value;
			} elseif ( 'flex_direction' === $key ) {
				if ( ! in_array( $value, array( 'row', 'column', 'row-reverse', 'column-reverse' ), true ) ) {
					return new WP_Error( 'bc_mcp_elementor_direction', __( 'Invalid container layout direction.', 'bc-mcp-abilities' ) );
				}
				$output[ $key ] = $value;
			} elseif ( 'content_width' === $key ) {
				if ( ! in_array( $value, array( 'boxed', 'full' ), true ) ) {
					return new WP_Error( 'bc_mcp_elementor_width', __( 'Invalid container width.', 'bc-mcp-abilities' ) );
				}
				$output[ $key ] = $value;
			} else {
				$output[ $key ] = sanitize_text_field( $value );
			}
		}
		return $output;
	}

	/**
	 * Update a unique widget's safe settings without replacing other fields.
	 *
	 * @param array  $nodes   Element tree.
	 * @param string $id     Requested element.
	 * @param array  $changes Validated settings.
	 * @param int    $matches Matched elements count.
	 * @return array
	 */
	private static function merge_element_settings( $nodes, $id, $changes, &$matches ) {
		foreach ( $nodes as $index => $node ) {
			if ( $node['id'] === $id ) {
				++$matches;
				$previous         = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
				$node['settings'] = array_merge( $previous, $changes );
				$nodes[ $index ]  = $node;
				continue;
			}
			if ( ! empty( $node['elements'] ) ) {
				$nodes[ $index ]['elements'] = self::merge_element_settings( $node['elements'], $id, $changes, $matches );
			}
		}
		return $nodes;
	}

	/**
	 * Edit one element's allowlisted values.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function update_settings( $input ) {
		$snapshot = self::checked_snapshot( $input );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$node = self::find_element( $snapshot['tree'], $input['element_id'] );
		if ( null === $node ) {
			return new WP_Error( 'bc_mcp_elementor_not_found', __( 'The Elementor element was not found.', 'bc-mcp-abilities' ) );
		}
		$type = 'container' === $node['elType'] ? 'container' : ( 'widget' === $node['elType'] ? (string) ( $node['widgetType'] ?? '' ) : '' );
		$safe = self::safe_settings( $type, $input['settings'] );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		if ( ! $safe ) {
			return new WP_Error( 'bc_mcp_elementor_no_changes', __( 'No supported settings were provided.', 'bc-mcp-abilities' ) );
		}
		$matches = 0;
		$updated = self::merge_element_settings( $snapshot['tree'], $input['element_id'], $safe, $matches );
		if ( 1 !== $matches ) {
			return new WP_Error( 'bc_mcp_elementor_not_unique', __( 'The Elementor element was not uniquely identified.', 'bc-mcp-abilities' ) );
		}
		return BC_MCP_Abilities_Elementor::save_structure( absint( $input['post_id'] ), $snapshot['revision'], $updated );
	}

	/**
	 * Insert a node at root or inside an existing non-widget parent.
	 *
	 * @param array  $nodes    Element list.
	 * @param string $parent_id Parent element ID, or empty root.
	 * @param array  $new_node  New element.
	 * @param int    $position Zero-based position.
	 * @param bool   $done     Whether insertion succeeded.
	 * @return array
	 */
	private static function insert( $nodes, $parent_id, $new_node, $position, &$done ) {
		if ( '' === $parent_id ) {
			array_splice( $nodes, min( $position, count( $nodes ) ), 0, array( $new_node ) );
			$done = true;
			return $nodes;
		}
		foreach ( $nodes as $index => $node ) {
			if ( $node['id'] === $parent_id && in_array( $node['elType'], array( 'container', 'section', 'column' ), true ) ) {
				$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : array();
				array_splice( $children, min( $position, count( $children ) ), 0, array( $new_node ) );
				$nodes[ $index ]['elements'] = $children;
				$done                        = true;
				return $nodes;
			}
			if ( ! empty( $node['elements'] ) ) {
				$nodes[ $index ]['elements'] = self::insert( $node['elements'], $parent_id, $new_node, $position, $done );
				if ( $done ) {
					return $nodes;
				}
			}
		}
		return $nodes;
	}

	/**
	 * Remove an element and its children by ID.
	 *
	 * @param array  $nodes   Element tree.
	 * @param string $id      Element ID.
	 * @param mixed  $removed Removed node reference.
	 * @param bool   $found   Whether a node was removed.
	 * @return array
	 */
	private static function remove( $nodes, $id, &$removed, &$found ) {
		foreach ( $nodes as $index => $node ) {
			if ( $node['id'] === $id ) {
				$removed = $node;
				array_splice( $nodes, $index, 1 );
				$found = true;
				return $nodes;
			}
			if ( ! empty( $node['elements'] ) ) {
				$nodes[ $index ]['elements'] = self::remove( $node['elements'], $id, $removed, $found );
				if ( $found ) {
					return $nodes;
				}
			}
		}
		return $nodes;
	}

	/**
	 * Generate an ID not already used in the current document.
	 *
	 * @param array $tree Current document.
	 * @return string
	 */
	private static function unique_id( $tree ) {
		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$id = bin2hex( random_bytes( 4 ) );
			if ( null === self::find_element( $tree, $id ) ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Create a supported widget or container.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function add_element( $input ) {
		$snapshot = self::checked_snapshot( $input );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$kind   = (string) $input['kind'];
		$parent = (string) ( $input['parent_id'] ?? '' );
		if ( '' === $parent && 'container' !== $kind ) {
			return new WP_Error( 'bc_mcp_elementor_root', __( 'Only a container can be inserted at the document root.', 'bc-mcp-abilities' ) );
		}
		$safe = self::safe_settings( $kind, $input['settings'] ?? array() );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		$id = self::unique_id( $snapshot['tree'] );
		if ( '' === $id ) {
			return new WP_Error( 'bc_mcp_elementor_id', __( 'Could not generate a unique element ID.', 'bc-mcp-abilities' ) );
		}
		$node = array(
			'id'       => $id,
			'elType'   => 'container' === $kind ? 'container' : 'widget',
			'settings' => $safe,
			'elements' => array(),
		);
		if ( 'container' !== $kind ) {
			$node['widgetType'] = $kind;
		}
		$done = false;
		$tree = self::insert( $snapshot['tree'], $parent, $node, absint( $input['position'] ?? 1500 ), $done );
		if ( ! $done ) {
			return new WP_Error( 'bc_mcp_elementor_parent', __( 'The destination Elementor parent is unavailable or does not accept children.', 'bc-mcp-abilities' ) );
		}
		$save = BC_MCP_Abilities_Elementor::save_structure( absint( $input['post_id'] ), $snapshot['revision'], $tree );
		if ( is_wp_error( $save ) ) {
			return $save;
		}
		$save['data']['element_id'] = $id;
		return $save;
	}

	/**
	 * Delete a node, requiring explicit nested deletion approval.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function delete_element( $input ) {
		$snapshot = self::checked_snapshot( $input );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$node = self::find_element( $snapshot['tree'], $input['element_id'] );
		if ( null === $node ) {
			return new WP_Error( 'bc_mcp_elementor_not_found', __( 'The requested element does not exist.', 'bc-mcp-abilities' ) );
		}
		if ( ! empty( $node['elements'] ) && true !== ( $input['allow_nested'] ?? false ) ) {
			return new WP_Error( 'bc_mcp_elementor_has_children', __( 'This element has children; explicit allow_nested=true is required.', 'bc-mcp-abilities' ) );
		}
		$removed = null;
		$found   = false;
		$tree    = self::remove( $snapshot['tree'], $input['element_id'], $removed, $found );
		if ( ! $found ) {
			return new WP_Error( 'bc_mcp_elementor_not_found', __( 'The Elementor element was not found.', 'bc-mcp-abilities' ) );
		}
		return BC_MCP_Abilities_Elementor::save_structure( absint( $input['post_id'] ), $snapshot['revision'], $tree );
	}

	/**
	 * Move an existing element, rejecting moves into its own descendants.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function move_element( $input ) {
		$snapshot = self::checked_snapshot( $input );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$target = (string) $input['element_id'];
		$parent = (string) ( $input['new_parent_id'] ?? '' );
		$node   = self::find_element( $snapshot['tree'], $target );
		if ( null === $node ) {
			return new WP_Error( 'bc_mcp_elementor_not_found', __( 'The requested element does not exist.', 'bc-mcp-abilities' ) );
		}
		if ( '' === $parent && 'container' !== $node['elType'] ) {
			return new WP_Error( 'bc_mcp_elementor_move_root', __( 'Only containers can be moved to the document root.', 'bc-mcp-abilities' ) );
		}
		if ( $target === $parent || ( '' !== $parent && null !== self::find_element( array( $node ), $parent ) ) ) {
			return new WP_Error( 'bc_mcp_elementor_cycle', __( 'An element cannot be moved into itself or its descendants.', 'bc-mcp-abilities' ) );
		}
		$removed = null;
		$found   = false;
		$tree    = self::remove( $snapshot['tree'], $target, $removed, $found );
		$done    = false;
		$tree    = self::insert( $tree, $parent, $removed, absint( $input['position'] ?? 1500 ), $done );
		if ( ! $done ) {
			return new WP_Error( 'bc_mcp_elementor_parent', __( 'The requested destination parent is unavailable.', 'bc-mcp-abilities' ) );
		}
		return BC_MCP_Abilities_Elementor::save_structure( absint( $input['post_id'] ), $snapshot['revision'], $tree );
	}

	/**
	 * Export Elementor data in standard JSON template shape.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function export_json( $input ) {
		$id       = absint( $input['post_id'] );
		$snapshot = BC_MCP_Abilities_Elementor::snapshot( $id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$doc  = get_post( $id );
		$data = array(
			'version'       => '0.4',
			'title'         => $doc->post_title,
			'type'          => ( (string) get_post_meta( $id, '_elementor_template_type', true ) ? (string) get_post_meta( $id, '_elementor_template_type', true ) : 'page' ),
			'content'       => $snapshot['tree'],
			'page_settings' => (array) get_post_meta( $id, '_elementor_page_settings', true ),
		);
		$json = wp_json_encode( $data );
		if ( false === $json || strlen( $json ) > self::MAX_JSON ) {
			return new WP_Error( 'bc_mcp_elementor_export_large', __( 'The Elementor template is too large to export through MCP.', 'bc-mcp-abilities' ) );
		}
		return self::ok(
			array(
				'post_id'  => $id,
				'revision' => $snapshot['revision'],
				'template' => $data,
			)
		);
	}

	/**
	 * Initialize a new empty Elementor draft using the native document API.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function create_document( $input ) {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! did_action( 'elementor/loaded' ) ) {
			return new WP_Error( 'bc_mcp_elementor_unavailable', __( 'Elementor is not active.', 'bc-mcp-abilities' ) );
		}
		$type   = (string) $input['post_type'];
		$object = get_post_type_object( $type );
		if ( ! $object || ! current_user_can( $object->cap->create_posts ) ) {
			return new WP_Error( 'bc_mcp_elementor_create_denied', __( 'You do not have permission to create this document.', 'bc-mcp-abilities' ) );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'draft',
				'post_title'  => wp_slash( sanitize_text_field( $input['title'] ) ),
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$document = \Elementor\Plugin::instance()->documents->get( $post_id );
		if ( ! $document ) {
			wp_trash_post( $post_id );
			return new WP_Error( 'bc_mcp_elementor_create_document', __( 'Elementor cannot initialize that post type.', 'bc-mcp-abilities' ) );
		}
		try {
			if ( method_exists( $document, 'set_is_built_with_elementor' ) ) {
				$document->set_is_built_with_elementor( true );
			}
			$save = $document->save( array( 'elements' => array() ) );
			if ( true !== $save ) {
				wp_trash_post( $post_id );
				return new WP_Error( 'bc_mcp_elementor_create_save', __( 'Elementor refused to initialize the draft. It was moved to Trash.', 'bc-mcp-abilities' ) );
			}
		} catch ( \Throwable $error ) {
			wp_trash_post( $post_id );
			return new WP_Error( 'bc_mcp_elementor_create_save', __( 'Elementor could not initialize the draft. It was moved to Trash.', 'bc-mcp-abilities' ) );
		}
		$snapshot = BC_MCP_Abilities_Elementor::snapshot( $post_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		return self::ok(
			array(
				'post_id'  => (int) $post_id,
				'status'   => 'draft',
				'revision' => $snapshot['revision'],
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Regenerate stylesheet for an Elementor document without editing content.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function regenerate_css( $input ) {
		$id       = absint( $input['post_id'] );
		$snapshot = BC_MCP_Abilities_Elementor::snapshot( $id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return new WP_Error( 'bc_mcp_elementor_css_missing', __( 'Elementor CSS regeneration API is unavailable.', 'bc-mcp-abilities' ) );
		}
		try {
			$css    = \Elementor\Core\Files\CSS\Post::create( $id );
			$result = $css->update();
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'bc_mcp_elementor_css_failed', __( 'Elementor rejected the CSS regeneration.', 'bc-mcp-abilities' ) );
			}
		} catch ( \Throwable $error ) {
			return new WP_Error( 'bc_mcp_elementor_css_failed', __( 'Elementor could not regenerate CSS for this document.', 'bc-mcp-abilities' ) );
		}
		return self::ok(
			array(
				'post_id'     => $id,
				'regenerated' => true,
			)
		);
	}
}
