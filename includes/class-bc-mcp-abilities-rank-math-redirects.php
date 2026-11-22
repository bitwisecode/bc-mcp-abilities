<?php
/**
 * Guarded Rank Math redirection management for native WordPress Abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Administrator-gated Rank Math redirection tools for WordPress Abilities.
 */
final class BC_MCP_Abilities_Rank_Math_Redirects {

	/**
	 * Build strict JSON object schema.
	 *
	 * @param array $properties Schema properties.
	 * @param array $required Required keys.
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
	 * Wrap successful ability output.
	 *
	 * @param mixed $data Function input.
	 * @return mixed
	 */
	private static function ok( $data ) {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Ensure the current administrator can use Rank Math redirections.
	 *
	 * @return mixed
	 */
	private static function available() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'bc_mcp_redirect_forbidden', 'Administrator permission is required.' );
		}
		if ( ! class_exists( '\RankMath\Redirections\DB' ) || ! class_exists( '\RankMath\Redirections\Redirection' ) ) {
			return new WP_Error( 'bc_mcp_redirect_unavailable', 'Activate the Rank Math Redirections module before using this ability.' );
		}
		return true;
	}

	/**
	 * Register six redirection abilities.
	 *
	 * @return void
	 */
	public static function register() {
		$str         = array(
			'type'      => 'string',
			'maxLength' => 2048,
		);
		$id          = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$rev         = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$rule        = array(
			'type'                 => 'object',
			'properties'           => array(
				'source'      => $str,
				'destination' => $str,
			),
			'required'             => array( 'source', 'destination' ),
			'additionalProperties' => false,
		);
		$definitions = array(
			array(
				'rankmath-list-redirects',
				__( 'List Rank Math Redirects', 'bc-mcp-abilities' ),
				__( 'Read the existing redirects, their sources, destinations, statuses and revision tokens.', 'bc-mcp-abilities' ),
				self::schema(
					array(
						'page'     => $id,
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					)
				),
				'list_redirects',
				true,
			),
			array( 'rankmath-check-redirect', __( 'Check Rank Math Redirect', 'bc-mcp-abilities' ), __( 'Find any existing redirect whose exact source matches the supplied legacy path.', 'bc-mcp-abilities' ), self::schema( array( 'source' => $str ), array( 'source' ) ), 'check_redirect', true ),
			array(
				'rankmath-create-redirect',
				__( 'Create Rank Math 301 Redirect', 'bc-mcp-abilities' ),
				__( 'Create a single exact 301 redirect without replacing an existing source.', 'bc-mcp-abilities' ),
				self::schema(
					array(
						'source'      => $str,
						'destination' => $str,
					),
					array( 'source', 'destination' )
				),
				'create_redirect',
				false,
			),
			array(
				'rankmath-update-redirect',
				__( 'Update Rank Math Redirect', 'bc-mcp-abilities' ),
				__( 'Update a redirect destination using its current revision token, preserving its sources.', 'bc-mcp-abilities' ),
				self::schema(
					array(
						'id'                => $id,
						'expected_revision' => $rev,
						'destination'       => $str,
					),
					array( 'id', 'expected_revision', 'destination' )
				),
				'update_redirect',
				false,
			),
			array(
				'rankmath-delete-redirect',
				__( 'Delete Rank Math Redirect', 'bc-mcp-abilities' ),
				__( 'Permanently delete one redirect by ID with revision protection.', 'bc-mcp-abilities' ),
				self::schema(
					array(
						'id'                => $id,
						'expected_revision' => $rev,
					),
					array( 'id', 'expected_revision' )
				),
				'delete_redirect',
				false,
			),
			array(
				'rankmath-bulk-import-redirects',
				__( 'Bulk Import Rank Math 301 Redirects', 'bc-mcp-abilities' ),
				__( 'Preview up to 50 exact redirects, detect duplicate/conflicting sources and optionally import only new rules.', 'bc-mcp-abilities' ),
				self::schema(
					array(
						'rules'                       => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 50,
							'items'    => $rule,
						),
						'apply'                       => array( 'type' => 'boolean' ),
						'expected_inventory_revision' => $rev,
					),
					array( 'rules' )
				),
				'bulk_import',
				false,
			),
		);
		foreach ( $definitions as $def ) {
			BC_MCP_Abilities::add( $def[0], $def[1], $def[2], $def[3], array( __CLASS__, $def[4] ), $def[5] );
		}
	}

	/**
	 * Validate and normalize a legacy source path.
	 *
	 * @param mixed $value Function input.
	 * @return mixed
	 */
	private static function path( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 || '' === $value || '/' !== $value[0] ) {
			return new WP_Error( 'bc_mcp_redirect_source', 'The source must be a local absolute path beginning with /.' );
		}
		if ( preg_match( '/[\\x00-\\x20\\x7f\\?#\\\\]/', $value ) || false !== strpos( $value, '//' ) || false !== strpos( $value, '..' ) ) {
			return new WP_Error( 'bc_mcp_redirect_source', 'Invalid source path (query strings, wildcards and unsafe characters are not supported).' );
		}
		$path = trim( $value, '/' );
		if ( '' === $path || preg_match( '~^(wp-admin|wp-json|wp-login\\.php)(/|$)~i', $path ) ) {
			return new WP_Error( 'bc_mcp_redirect_source', 'WordPress system paths and the homepage cannot be redirected.' );
		}
		return '/' . $path . '/';
	}

	/**
	 * Validate a same-site destination.
	 *
	 * @param mixed $value Function input.
	 * @param mixed $source Function input.
	 * @return mixed
	 */
	private static function destination( $value, $source = '' ) {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 ) {
			return new WP_Error( 'bc_mcp_redirect_destination', 'Invalid redirect destination.' );
		}
		// Accept a local path or an absolute URL on this exact WordPress host.
		if ( preg_match( '~^https?://~i', $value ) ) {
			$parts = wp_parse_url( $value );
			$home  = wp_parse_url( home_url() );
			if ( ! is_array( $parts ) || ! is_array( $home ) || ! isset( $parts['host'], $home['host'] )
				|| strtolower( $parts['host'] ) !== strtolower( $home['host'] )
				|| isset( $parts['user'] ) || isset( $parts['pass'] )
				|| isset( $parts['query'] ) || isset( $parts['fragment'] )
				|| ( isset( $parts['port'] ) ? (int) $parts['port'] : null ) !== ( isset( $home['port'] ) ? (int) $home['port'] : null ) ) {
				return new WP_Error( 'bc_mcp_redirect_destination', 'Only same-site, query-free destinations are supported.' );
			}
			$value = isset( $parts['path'] ) ? $parts['path'] : '/';
		}
		if ( '/' === $value ) {
			return '/' === $source ? new WP_Error( 'bc_mcp_redirect_loop', 'Source and destination must differ.' ) : '/';
		}
		$path = self::path( $value );
		if ( is_wp_error( $path ) ) {
			return new WP_Error( 'bc_mcp_redirect_destination', 'Destination must be a safe same-site path or URL.' );
		}
		if ( $path === $source ) {
			return new WP_Error( 'bc_mcp_redirect_loop', 'Source and destination must differ.' );
		}
		return $path;
	}

	/**
	 * Normalize a Rank Math record and calculate its revision.
	 *
	 * @param mixed $record Function input.
	 * @return mixed
	 */
	private static function normalized( $record ) {
		$sources            = maybe_unserialize( isset( $record['sources'] ) ? $record['sources'] : array() );
		$sources            = is_array( $sources ) ? $sources : array();
		$record['sources']  = $sources;
		$record['revision'] = hash(
			'sha256',
			wp_json_encode(
				array(
					(int) $record['id'],
					$sources,
					(string) $record['url_to'],
					(string) $record['header_code'],
					(string) $record['status'],
				)
			)
		);
		return $record;
	}

	/**
	 * Read the complete untrashed Rank Math redirect inventory.
	 *
	 * @return mixed
	 */
	private static function inventory() {
		$rows = array();
		for ( $page = 1; $page <= 100; ++$page ) {
			$batch = \RankMath\Redirections\DB::get_redirections(
				array(
					'limit' => 100,
					'paged' => $page,
				)
			);
			if ( ! isset( $batch['redirections'] ) || ! is_array( $batch['redirections'] ) ) {
				return new WP_Error( 'bc_mcp_redirect_read', 'Unable to read the Rank Math redirect inventory.' );
			}
			foreach ( $batch['redirections'] as $record ) {
				$rows[] = self::normalized( $record );
			}
			if ( count( $rows ) >= (int) $batch['count'] ) {
				break;
			}
			if ( 100 === $page ) {
				return new WP_Error( 'bc_mcp_redirect_limit', 'Too many redirections to safely check duplicates.' );
			}
		}
		return $rows;
	}

	/**
	 * Calculate a stable inventory revision for optimistic locking.
	 *
	 * @param mixed $rows Function input.
	 * @return mixed
	 */
	private static function inventory_revision( $rows ) {
		return hash(
			'sha256',
			wp_json_encode(
				array_map(
					static function ( $r ) {
						return array( (int) $r['id'], $r['revision'] );
					},
					$rows
				)
			)
		);
	}

	/**
	 * Find exact redirect sources that conflict with the requested path.
	 *
	 * @param mixed $rows Function input.
	 * @param mixed $source Function input.
	 * @return mixed
	 */
	private static function source_matches( $rows, $source ) {
		$matches = array();
		foreach ( $rows as $record ) {
			foreach ( $record['sources'] as $pattern ) {
				if ( ! is_array( $pattern ) || 'exact' !== ( isset( $pattern['comparison'] ) ? $pattern['comparison'] : 'exact' ) ) {
					continue;
				}
				$existing = '/' . trim( (string) $pattern['pattern'], '/' ) . '/';
				if ( strtolower( $existing ) === strtolower( $source ) ) {
					$matches[] = $record;
					break;
				}
			}
		}
		return $matches;
	}

	/**
	 * Return a paginated list of existing Rank Math redirects.
	 *
	 * @param mixed $input Function input.
	 * @return mixed
	 */
	public static function list_redirects( $input ) {
		$check = self::available();
		if ( is_wp_error( $check ) ) {
			return $check; }
		$rows = self::inventory();
		if ( is_wp_error( $rows ) ) {
			return $rows; }
		$page = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$size = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 50;
		return self::ok(
			array(
				'total'              => count( $rows ),
				'page'               => $page,
				'per_page'           => $size,
				'inventory_revision' => self::inventory_revision( $rows ),
				'items'              => array_slice( $rows, ( $page - 1 ) * $size, $size ),
			)
		);
	}

	/**
	 * Check a legacy source path for existing Rank Math redirects.
	 *
	 * @param mixed $input Function input.
	 * @return mixed
	 */
	public static function check_redirect( $input ) {
		$check = self::available();
		if ( is_wp_error( $check ) ) {
			return $check; }
		$source = self::path( $input['source'] );
		if ( is_wp_error( $source ) ) {
			return $source; }
		$rows = self::inventory();
		if ( is_wp_error( $rows ) ) {
			return $rows; }
		$matches = self::source_matches( $rows, $source );
		return self::ok(
			array(
				'source'             => $source,
				'exists'             => ! empty( $matches ),
				'items'              => $matches,
				'inventory_revision' => self::inventory_revision( $rows ),
			)
		);
	}

	/**
	 * Insert a new redirect through Rank Math's own model.
	 *
	 * @param mixed $source Function input.
	 * @param mixed $destination Function input.
	 * @return mixed
	 */
	private static function insert( $source, $destination ) {
		$item = \RankMath\Redirections\Redirection::from(
			array(
				'sources'     => array(
					array(
						'pattern'    => $source,
						'comparison' => 'exact',
						'ignore'     => '',
					),
				),
				'url_to'      => home_url( $destination ),
				'header_code' => '301',
				'status'      => 'active',
			)
		);
		if ( ! $item->has_sources() || $item->is_infinite_loop() ) {
			return new WP_Error( 'bc_mcp_redirect_invalid', 'Rank Math rejected the redirect source or detected a loop.' );
		}
		$id = $item->save();
		if ( ! $id ) {
			return new WP_Error( 'bc_mcp_redirect_save', 'Rank Math could not save the redirect.' );
		}
		return (int) $id;
	}

	/**
	 * Create one exact-match permanent redirection.
	 *
	 * @param mixed $input Function input.
	 * @return mixed
	 */
	public static function create_redirect( $input ) {
		$check = self::available();
		if ( is_wp_error( $check ) ) {
			return $check; }
		$source = self::path( $input['source'] );
		if ( is_wp_error( $source ) ) {
			return $source; }
		$dest = self::destination( $input['destination'], $source );
		if ( is_wp_error( $dest ) ) {
			return $dest; }
		$rows = self::inventory();
		if ( is_wp_error( $rows ) ) {
			return $rows; }
		if ( self::source_matches( $rows, $source ) ) {
			return new WP_Error( 'bc_mcp_redirect_duplicate', 'A Rank Math redirect already handles this exact source; read it before making changes.' );
		}
		$id = self::insert( $source, $dest );
		if ( is_wp_error( $id ) ) {
			return $id; }
		return self::ok(
			array(
				'id'          => $id,
				'source'      => $source,
				'destination' => $dest,
				'header_code' => 301,
			)
		);
	}

	/**
	 * Load and verify an existing redirect's revision.
	 *
	 * @param mixed $id Function input.
	 * @param mixed $revision Function input.
	 * @return mixed
	 */
	private static function current( $id, $revision ) {
		$record = \RankMath\Redirections\DB::get_redirection_by_id( $id );
		if ( ! $record || 'trashed' === $record['status'] ) {
			return new WP_Error( 'bc_mcp_redirect_missing', 'Redirect no longer exists.' );
		}
		$record = self::normalized( $record );
		if ( ! hash_equals( $record['revision'], $revision ) ) {
			return new WP_Error( 'bc_mcp_redirect_conflict', 'The redirect changed; read it again before editing.' );
		}
		return $record;
	}

	/**
	 * Update an existing Rank Math redirect destination.
	 *
	 * @param mixed $input Function input.
	 * @return mixed
	 */
	public static function update_redirect( $input ) {
		$check = self::available();
		if ( is_wp_error( $check ) ) {
			return $check; }
		$record = self::current( absint( $input['id'] ), $input['expected_revision'] );
		if ( is_wp_error( $record ) ) {
			return $record; }
		$dest = self::destination( $input['destination'] );
		if ( is_wp_error( $dest ) ) {
			return $dest; }
		foreach ( $record['sources'] as $source ) {
			if ( '/' . trim( (string) $source['pattern'], '/' ) . '/' === $dest ) {
				return new WP_Error( 'bc_mcp_redirect_loop', 'Destination matches an existing source.' );
			}
		}
		$item = \RankMath\Redirections\Redirection::from(
			array(
				'id'          => (int) $record['id'],
				'sources'     => $record['sources'],
				'url_to'      => home_url( $dest ),
				'header_code' => $record['header_code'],
				'status'      => $record['status'],
			)
		);
		if ( ! $item->save() ) {
			return new WP_Error( 'bc_mcp_redirect_save', 'Rank Math could not update this redirect.' );
		}
		return self::ok(
			array(
				'id'          => (int) $record['id'],
				'destination' => $dest,
			)
		);
	}

	/**
	 * Remove one revision-verified Rank Math redirect.
	 *
	 * @param mixed $input Function input.
	 * @return mixed
	 */
	public static function delete_redirect( $input ) {
		$check = self::available();
		if ( is_wp_error( $check ) ) {
			return $check; }
		$record = self::current( absint( $input['id'] ), $input['expected_revision'] );
		if ( is_wp_error( $record ) ) {
			return $record; }
		$deleted = \RankMath\Redirections\DB::delete( array( (int) $record['id'] ) );
		if ( ! $deleted ) {
			return new WP_Error( 'bc_mcp_redirect_delete', 'Could not delete the Rank Math redirect.' );
		}
		return self::ok( array( 'deleted_id' => (int) $record['id'] ) );
	}

	/**
	 * Preview or apply a guarded batch of exact-match redirects.
	 *
	 * @param mixed $input Function input.
	 * @return mixed
	 */
	public static function bulk_import( $input ) {
		$check = self::available();
		if ( is_wp_error( $check ) ) {
			return $check; }
		$rows = self::inventory();
		if ( is_wp_error( $rows ) ) {
			return $rows; }
		$rev   = self::inventory_revision( $rows );
		$apply = ! empty( $input['apply'] );
		if ( $apply && ( empty( $input['expected_inventory_revision'] ) || ! hash_equals( $rev, $input['expected_inventory_revision'] ) ) ) {
			return new WP_Error( 'bc_mcp_redirect_inventory_conflict', 'Preview the current inventory and supply its revision token before applying the import.' );
		}
		$prepared = array();
		$seen     = array();
		$issues   = array();
		foreach ( $input['rules'] as $index => $rule ) {
			$source = self::path( $rule['source'] );
			$dest   = is_wp_error( $source ) ? $source : self::destination( $rule['destination'], $source );
			if ( is_wp_error( $source ) || is_wp_error( $dest ) ) {
				$issues[] = array(
					'row'    => $index + 1,
					'reason' => 'invalid_source_or_destination',
				);
				continue;
			}
			$key = strtolower( $source );
			if ( isset( $seen[ $key ] ) ) {
				$issues[] = array(
					'row'    => $index + 1,
					'reason' => 'duplicate_in_batch',
				);
				continue;
			}
			$seen[ $key ] = true;
			$matches      = self::source_matches( $rows, $source );
			if ( $matches ) {
				$issues[] = array(
					'row'          => $index + 1,
					'reason'       => 'existing_redirect',
					'existing_ids' => array_map(
						static function ( $r ) {
							return (int) $r['id'];
						},
						$matches
					),
				);
				continue;
			}
			$prepared[] = array(
				'source'      => $source,
				'destination' => $dest,
			);
		}
		if ( ! $apply ) {
			return self::ok(
				array(
					'dry_run'            => true,
					'inventory_revision' => $rev,
					'ready'              => $prepared,
					'issues'             => $issues,
					'ready_count'        => count( $prepared ),
				)
			);
		}
		// Fail closed: do not partially apply a batch containing known conflicts.
		if ( $issues ) {
			return new WP_Error( 'bc_mcp_redirect_batch_conflicts', 'Resolve preview conflicts before importing. No rules were changed.', array( 'issues' => $issues ) );
		}
		$created = array();
		foreach ( $prepared as $rule ) {
			$id = self::insert( $rule['source'], $rule['destination'] );
			if ( is_wp_error( $id ) ) {
				return new WP_Error(
					'bc_mcp_redirect_partial_import',
					'Import stopped after a save failure; review the created list before retrying.',
					array(
						'created' => $created,
						'failed'  => $rule['source'],
					)
				);
			}
			$created[] = array(
				'id'          => $id,
				'source'      => $rule['source'],
				'destination' => $rule['destination'],
			);
		}
		return self::ok(
			array(
				'created_count' => count( $created ),
				'created'       => $created,
			)
		);
	}
}
