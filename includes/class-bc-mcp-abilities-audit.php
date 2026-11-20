<?php
/**
 * Privacy-preserving execution audit for first-party BC WordPress abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bounded, administrator-only activity history; never stores tool arguments.
 */
final class BC_MCP_Abilities_Audit {
	/**
	 * Persistent option containing bounded audit records.
	 */
	const OPTION = 'bc_mcp_abilities_activity';

	/**
	 * Maximum retained records.
	 */
	const MAX_EVENTS = 100;

	/**
	 * Maximum retention: seven days.
	 */
	const RETENTION = 7 * DAY_IN_SECONDS;

	/**
	 * Register authorized history actions.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_bc_mcp_clear_activity', array( __CLASS__, 'clear' ) );
		add_action( 'admin_post_bc_mcp_export_activity', array( __CLASS__, 'export' ) );
		add_action( 'bc_mcp_prune_activity', array( __CLASS__, 'prune' ) );
		if ( ! wp_next_scheduled( 'bc_mcp_prune_activity' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bc_mcp_prune_activity' );
		}
	}

	/**
	 * Filter stored records to current retention period.
	 *
	 * @return array
	 */
	public static function recent() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$now    = time();
		$events = array();
		foreach ( $stored as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['time'], $event['ability'], $event['user_id'], $event['outcome'], $event['kind'] ) ) {
				continue;
			}
			$age = $now - absint( $event['time'] );
			if ( $age < 0 || $age >= self::RETENTION ) {
				continue;
			}
			$events[] = array(
				'time'    => absint( $event['time'] ),
				'user_id' => absint( $event['user_id'] ),
				'ability' => sanitize_key( $event['ability'] ),
				'kind'    => 'write' === $event['kind'] ? 'write' : 'read',
				'outcome' => 'error' === $event['outcome'] ? 'error' : 'success',
			);
		}
		return array_slice( $events, -self::MAX_EVENTS );
	}

	/**
	 * Remove expired records from persistent storage on WordPress cron.
	 *
	 * @return void
	 */
	public static function prune() {
		$stored = get_option( self::OPTION, array() );
		$events = self::recent();
		if ( is_array( $stored ) && count( $events ) === count( $stored ) ) {
			return;
		}
		if ( $events ) {
			update_option( self::OPTION, $events, false );
		} else {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Record only the ability slug, account ID, action type and result.
	 * The arguments, result data, exception messages and raw HTTP data
	 * are deliberately never persisted.
	 *
	 * @param string $slug      First-party ability slug.
	 * @param bool   $read_only True for read-only abilities.
	 * @param bool   $success   Whether execution succeeded.
	 * @return void
	 */
	public static function record( $slug, $read_only, $success ) {
		$slug = sanitize_key( (string) $slug );
		if ( ! preg_match( '/^[a-z0-9-]{1,80}$/', $slug ) ) {
			return;
		}
		$events   = self::recent();
		$events[] = array(
			'time'    => time(),
			'user_id' => get_current_user_id(),
			'ability' => $slug,
			'kind'    => $read_only ? 'read' : 'write',
			'outcome' => $success ? 'success' : 'error',
		);
		update_option( self::OPTION, array_slice( $events, -self::MAX_EVENTS ), false );
	}

	/**
	 * Require a privileged WordPress session and a validated action nonce.
	 *
	 * @param string $action Nonce action key.
	 * @return void
	 */
	private static function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot access BC MCP activity history.', 'bc-mcp-abilities' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Delete audit records after explicit administrator confirmation.
	 *
	 * @return void
	 */
	public static function clear() {
		self::authorize( 'bc_mcp_clear_activity' );
		delete_option( self::OPTION );
		wp_safe_redirect( add_query_arg( 'page', 'bc-mcp-abilities', admin_url( 'options-general.php' ) ) . '#bc-panel-activity' );
		exit;
	}

	/**
	 * Export only the five safe record fields as CSV.
	 *
	 * @return void
	 */
	public static function export() {
		self::authorize( 'bc_mcp_export_activity' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bc-mcp-activity.csv"' );
		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streamed CSV download, no filesystem write.
		if ( false === $output ) {
			exit;
		}
		fputcsv( $output, array( 'time_utc', 'user_id', 'ability_slug', 'kind', 'outcome' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Streamed CSV download.
		foreach ( self::recent() as $event ) {
			fputcsv(
				$output,
				array(
					gmdate( 'Y-m-d H:i:s', $event['time'] ),
					$event['user_id'],
					$event['ability'],
					$event['kind'],
					$event['outcome'],
				)
			); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Streamed CSV download.
		}
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streamed CSV download.
		exit;
	}
}
