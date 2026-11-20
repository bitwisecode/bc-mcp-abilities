<?php
/**
 * Safe, reversible Elementor text editing abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strict Elementor element editing; no arbitrary layout/JSON writes.
 */
final class BC_MCP_Abilities_Elementor {
	/**
	 * One-level undo metadata.
	 */
	const BACKUP_KEY = '_bc_mcp_abilities_elementor_undo';

	/**
	 * Maximum serialized document size (two megabytes).
	 */
	const MAX_SIZE = 2097152;

	/**
	 * Register Elementor Abilities.
	 *
	 * @return void
	 */
	public static function register() {
		$int = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$str = array( 'type' => 'string' );

		BC_MCP_Abilities::add(
			'elementor-update-text',
			__( 'Update Elementor Text Widget', 'bc-mcp-abilities' ),
			__( 'Update only an existing Heading, Text Editor or Button text field, with revision and single-step undo.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'           => $int,
					'element_id'        => array(
						'type'      => 'string',
						'pattern'   => '^[a-zA-Z0-9_-]{1,64}$',
						'maxLength' => 64,
					),
					'expected_revision' => $str,
					'text'              => array(
						'type'      => 'string',
						'maxLength' => 20000,
					),
				),
				'required'             => array( 'post_id', 'element_id', 'expected_revision', 'text' ),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'update_text' ),
			false
		);

		BC_MCP_Abilities::add(
			'elementor-undo',
			__( 'Undo Previous Elementor Text Update', 'bc-mcp-abilities' ),
			__( 'Undo the most recent BC MCP Elementor edit only when the document still matches the saved revision.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'           => $int,
					'undo_token'        => $str,
					'expected_revision' => $str,
				),
				'required'             => array( 'post_id', 'undo_token', 'expected_revision' ),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'undo' ),
			false
		);
	}

	/**
	 * Convert Elementor metadata to a bounded element tree.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function snapshot( $post_id ) {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'bc_mcp_elementor_unavailable', __( 'Elementor is not active.', 'bc-mcp-abilities' ) );
		}
		$post = get_post( absint( $post_id ) );
		if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'bc_mcp_elementor_denied', __( 'Elementor post is unavailable or editing is not permitted.', 'bc-mcp-abilities' ) );
		}
		$document = \Elementor\Plugin::instance()->documents->get( $post->ID );
		if ( ! $document ) {
			return new WP_Error( 'bc_mcp_elementor_document', __( 'Elementor could not open the document.', 'bc-mcp-abilities' ) );
		}
		$raw  = get_post_meta( $post->ID, '_elementor_data', true );
		$tree = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $tree ) ) {
			return new WP_Error( 'bc_mcp_elementor_invalid', __( 'Elementor document has no valid JSON element tree.', 'bc-mcp-abilities' ) );
		}
		$json = wp_json_encode( $tree );
		if ( ! $json || strlen( $json ) > self::MAX_SIZE ) {
			return new WP_Error( 'bc_mcp_elementor_large', __( 'Elementor document is too large to edit safely.', 'bc-mcp-abilities' ) );
		}
		return array(
			'document' => $document,
			'tree'     => $tree,
			'revision' => hash( 'sha256', $json ),
		);
	}

	/**
	 * Validate the structure without changing any arbitrary element properties.
	 *
	 * @param array $tree  Elementor elements array.
	 * @param int   $depth Tree depth.
	 * @param int   $count Number of visited nodes.
	 * @return bool
	 */
	public static function valid_tree( $tree, $depth, &$count ) {
		if ( ! is_array( $tree ) || $depth > 30 ) {
			return false;
		}
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) || empty( $node['id'] ) || empty( $node['elType'] ) ) {
				return false;
			}
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', (string) $node['id'] ) ) {
				return false;
			}
			++$count;
			if ( $count > 1500 ) {
				return false;
			}
			if ( isset( $node['elements'] ) && ! self::valid_tree( $node['elements'], $depth + 1, $count ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Replace exactly one supported text setting in the Elementor tree.
	 *
	 * @param array  $tree    Element tree passed by reference.
	 * @param string $id      Existing widget ID.
	 * @param string $text    Sanitized new text.
	 * @param int    $matches Number of matched elements, passed by reference.
	 * @param int    $depth   Current depth.
	 * @return WP_Error|null
	 */
	private static function replace_text( &$tree, $id, $text, &$matches, $depth = 0 ) {
		if ( $depth > 30 ) {
			return new WP_Error( 'bc_mcp_elementor_depth', __( 'Element tree is too deeply nested.', 'bc-mcp-abilities' ) );
		}
		foreach ( $tree as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && (string) $node['id'] === $id ) {
				++$matches;
				$type   = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';
				$fields = array(
					'heading'     => 'title',
					'text-editor' => 'editor',
					'button'      => 'text',
				);
				if ( 'widget' !== ( $node['elType'] ?? '' ) || ! isset( $fields[ $type ] ) ) {
					return new WP_Error( 'bc_mcp_elementor_widget', __( 'This ability supports Heading, Text Editor and Button widgets only.', 'bc-mcp-abilities' ) );
				}
				if ( ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
					$node['settings'] = array();
				}
				$node['settings'][ $fields[ $type ] ] = 'text-editor' === $type ? wp_kses_post( $text ) : sanitize_text_field( $text );
			}
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$result = self::replace_text( $node['elements'], $id, $text, $matches, $depth + 1 );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		unset( $node );
		return null;
	}

	/**
	 * Prepare a compact ability response.
	 *
	 * @param array $data Operation result.
	 * @return array
	 */
	private static function ok( $data ) {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Write a narrow, reversible Elementor text change.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function update_text( $input ) {
		$post_id  = absint( $input['post_id'] );
		$snapshot = self::snapshot( $post_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! hash_equals( $snapshot['revision'], (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_elementor_conflict', __( 'Elementor layout changed. Reload the document first.', 'bc-mcp-abilities' ) );
		}
		$count = 0;
		if ( ! self::valid_tree( $snapshot['tree'], 0, $count ) ) {
			return new WP_Error( 'bc_mcp_elementor_structure', __( 'Elementor document failed structural validation.', 'bc-mcp-abilities' ) );
		}

		$updated = $snapshot['tree'];
		$matches = 0;
		$error   = self::replace_text( $updated, (string) $input['element_id'], (string) $input['text'], $matches );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		if ( 1 !== $matches ) {
			return new WP_Error( 'bc_mcp_elementor_match', __( 'A unique Elementor element with that ID was not found.', 'bc-mcp-abilities' ) );
		}
		if ( strlen( wp_json_encode( $updated ) ) > self::MAX_SIZE ) {
			return new WP_Error( 'bc_mcp_elementor_large', __( 'Updated Elementor document exceeds the safe size limit.', 'bc-mcp-abilities' ) );
		}
		$token  = wp_generate_uuid4();
		$backup = array(
			'token'         => $token,
			'before'        => $snapshot['tree'],
			'before_hash'   => $snapshot['revision'],
			'after_hash'    => '',
			'created_at'    => time(),
			'owner_user_id' => get_current_user_id(),
		);
		// Store the recovery snapshot before asking Elementor to modify anything.
		if ( ! update_post_meta( $post_id, self::BACKUP_KEY, $backup ) ) {
			return new WP_Error( 'bc_mcp_elementor_backup', __( 'Could not persist a recovery snapshot. No changes were saved.', 'bc-mcp-abilities' ) );
		}
		$saved = $snapshot['document']->save( array( 'elements' => $updated ) );
		if ( true !== $saved ) {
			return new WP_Error( 'bc_mcp_elementor_save', __( 'Elementor rejected the update. The pre-save snapshot was preserved for recovery.', 'bc-mcp-abilities' ) );
		}
		$fresh = self::snapshot( $post_id );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		$backup['after_hash'] = $fresh['revision'];
		update_post_meta( $post_id, self::BACKUP_KEY, $backup );
		return self::ok(
			array(
				'post_id'    => $post_id,
				'element_id' => (string) $input['element_id'],
				'revision'   => $fresh['revision'],
				'undo_token' => $token,
			)
		);
	}

	/**
	 * Undo only the latest successfully recorded update.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	/**
	 * Save a controlled Elementor structure change with one-level undo.
	 *
	 * @param int    $post_id          Elementor document ID.
	 * @param string $expected_revision Current tree revision.
	 * @param array  $updated          Validated Elementor element tree.
	 * @return array|WP_Error
	 */
	public static function save_structure( $post_id, $expected_revision, $updated ) {
		$snapshot = self::snapshot( $post_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! hash_equals( $snapshot['revision'], (string) $expected_revision ) ) {
			return new WP_Error( 'bc_mcp_elementor_conflict', __( 'Elementor layout changed. Reload the document before updating.', 'bc-mcp-abilities' ) );
		}
		$count = 0;
		if ( ! self::valid_tree( $updated, 0, $count ) ) {
			return new WP_Error( 'bc_mcp_elementor_structure', __( 'Elementor document has an invalid structure.', 'bc-mcp-abilities' ) );
		}
		$json = wp_json_encode( $updated );
		if ( false === $json || strlen( $json ) > self::MAX_SIZE ) {
			return new WP_Error( 'bc_mcp_elementor_large', __( 'Elementor document exceeds the safe size limit.', 'bc-mcp-abilities' ) );
		}
		// Recheck immediately before saving; concurrent third-party changes must
		// never be intentionally overwritten by stale AI requests.
		$fresh_before = self::snapshot( $post_id );
		if ( is_wp_error( $fresh_before ) ) {
			return $fresh_before;
		}
		if ( ! hash_equals( $snapshot['revision'], $fresh_before['revision'] ) ) {
			return new WP_Error( 'bc_mcp_elementor_conflict', __( 'Elementor changed before the update was saved.', 'bc-mcp-abilities' ) );
		}

		$token  = wp_generate_uuid4();
		$backup = array(
			'token'         => $token,
			'before'        => $snapshot['tree'],
			'before_hash'   => $snapshot['revision'],
			'after_hash'    => '',
			'created_at'    => time(),
			'owner_user_id' => get_current_user_id(),
		);
		if ( ! update_post_meta( $post_id, self::BACKUP_KEY, $backup ) ) {
			return new WP_Error( 'bc_mcp_elementor_backup', __( 'Could not save the recovery snapshot. No changes were applied.', 'bc-mcp-abilities' ) );
		}
		$saved = $snapshot['document']->save( array( 'elements' => $updated ) );
		if ( true !== $saved ) {
			return new WP_Error( 'bc_mcp_elementor_save', __( 'Elementor rejected the change; a recovery snapshot was preserved.', 'bc-mcp-abilities' ) );
		}
		$fresh = self::snapshot( $post_id );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		$backup['after_hash'] = $fresh['revision'];
		update_post_meta( $post_id, self::BACKUP_KEY, $backup );

		return self::ok(
			array(
				'post_id'    => (int) $post_id,
				'revision'   => $fresh['revision'],
				'undo_token' => $token,
				'elements'   => $count,
			)
		);
	}

	/**
	 * Restore the most recent Elementor update when its revision and token match.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function undo( $input ) {
		$post_id = absint( $input['post_id'] );
		$backup  = get_post_meta( $post_id, self::BACKUP_KEY, true );
		if ( ! is_array( $backup ) || empty( $backup['token'] ) || empty( $backup['after_hash'] ) || empty( $backup['before'] ) ) {
			return new WP_Error( 'bc_mcp_elementor_undo_missing', __( 'No completed BC MCP Elementor change is available for undo.', 'bc-mcp-abilities' ) );
		}
		if ( ! hash_equals( (string) $backup['token'], (string) $input['undo_token'] ) ) {
			return new WP_Error( 'bc_mcp_elementor_undo_token', __( 'The undo token does not match the most recent change.', 'bc-mcp-abilities' ) );
		}
		$snapshot = self::snapshot( $post_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! hash_equals( $backup['after_hash'], $snapshot['revision'] ) || ! hash_equals( $snapshot['revision'], (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_elementor_conflict', __( 'The document has changed since the saved operation and cannot be automatically restored.', 'bc-mcp-abilities' ) );
		}
		$saved = $snapshot['document']->save( array( 'elements' => $backup['before'] ) );
		if ( true !== $saved ) {
			return new WP_Error( 'bc_mcp_elementor_undo_failed', __( 'Elementor rejected the undo operation.', 'bc-mcp-abilities' ) );
		}
		delete_post_meta( $post_id, self::BACKUP_KEY );
		$fresh = self::snapshot( $post_id );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		return self::ok(
			array(
				'post_id'  => $post_id,
				'restored' => true,
				'revision' => $fresh['revision'],
			)
		);
	}
}
