<?php
/**
 * Read-only website performance diagnostics via Google PageSpeed Insights.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped, cache-bounded diagnostics that never fetch arbitrary user URLs.
 */
final class BC_MCP_Abilities_Performance {
	/**
	 * Register the site-scoped PageSpeed ability.
	 *
	 * @return void
	 */
	public static function register() {
		BC_MCP_Abilities::add(
			'pagespeed-audit',
			__( 'Run Google PageSpeed Insights', 'bc-mcp-abilities' ),
			__( 'Read cached Google Lighthouse scores and key metrics for a public path on this WordPress site. Supports mobile and desktop.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'path'       => array( 'type' => 'string', 'maxLength' => 1024 ),
					'strategy'   => array( 'type' => 'string', 'enum' => array( 'mobile', 'desktop' ) ),
					'categories' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string', 'enum' => array( 'performance', 'accessibility', 'best-practices', 'seo' ) ),
						'minItems'    => 1,
						'maxItems'    => 4,
						'uniqueItems' => true,
					),
				),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'audit' )
		);
	}

	/**
	 * Validate paths without allowing remote URLs, credentials, traversal or fragments.
	 *
	 * @param mixed $path Submitted path.
	 * @return string|WP_Error
	 */
	private static function clean_path( $path ) {
		if ( ! is_string( $path ) || strlen( $path ) > 1024 || '' === $path || '/' !== $path[0] ||
			0 === strpos( $path, '//' ) || false !== strpos( $path, '\\' ) ||
			false !== strpos( $path, '#' ) || false !== strpos( $path, '@' ) ||
			preg_match( '~(?:^|/)\\.\\.(?:/|$)|[\\x00-\\x1f]~', $path ) ) {
			return new WP_Error( 'bc_mcp_performance_path', __( 'Supply a safe root-relative same-site path.', 'bc-mcp-abilities' ) );
		}
		return $path;
	}

	/**
	 * Transform a Lighthouse audit into a bounded report.
	 *
	 * @param array $body Google API JSON response.
	 * @return array
	 */
	private static function report( $body ) {
		$lighthouse = isset( $body['lighthouseResult'] ) && is_array( $body['lighthouseResult'] ) ? $body['lighthouseResult'] : array();
		$scores     = array();
		foreach ( array( 'performance', 'accessibility', 'best-practices', 'seo' ) as $category ) {
			$score = isset( $lighthouse['categories'][ $category ]['score'] ) ? $lighthouse['categories'][ $category ]['score'] : null;
			if ( is_numeric( $score ) ) {
				$scores[ $category ] = round( 100 * (float) $score );
			}
		}
		$metrics = array();
		foreach ( array(
			'largest-contentful-paint' => 'lcp',
			'first-contentful-paint'   => 'fcp',
			'cumulative-layout-shift' => 'cls',
			'total-blocking-time'     => 'tbt',
			'speed-index'             => 'speed_index',
		) as $key => $label ) {
			$value = isset( $lighthouse['audits'][ $key ]['numericValue'] ) ? $lighthouse['audits'][ $key ]['numericValue'] : null;
			if ( is_numeric( $value ) ) {
				$metrics[ $label ] = (float) $value;
			}
		}
		$findings = array();
		if ( isset( $lighthouse['audits'] ) && is_array( $lighthouse['audits'] ) ) {
			foreach ( $lighthouse['audits'] as $audit ) {
				if ( ! is_array( $audit ) || ! isset( $audit['score'], $audit['title'] ) ||
					! is_numeric( $audit['score'] ) || (float) $audit['score'] >= 0.9 ||
					isset( $audit['errorMessage'] ) ) {
					continue;
				}
				if ( isset( $audit['details']['type'] ) && 'opportunity' !== $audit['details']['type'] ) {
					continue;
				}
				$findings[] = array(
					'title'   => sanitize_text_field( $audit['title'] ),
					'savings' => isset( $audit['details']['overallSavingsMs'] ) ? (int) $audit['details']['overallSavingsMs'] : 0,
				);
				if ( count( $findings ) >= 10 ) {
					break;
				}
			}
		}
		return array(
			'scores'            => $scores,
			'lab_metrics'       => $metrics,
			'findings'          => $findings,
			'fetch_time'        => sanitize_text_field( $lighthouse['fetchTime'] ?? '' ),
			'field_data_status' => sanitize_key( $body['loadingExperience']['overall_category'] ?? 'unavailable' ),
		);
	}

	/**
	 * Run a public Google API audit without fetching arbitrary URLs on WordPress.
	 *
	 * @param array $input Validated ability input.
	 * @return array|WP_Error
	 */
	public static function audit( $input = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'bc_mcp_performance_forbidden', __( 'Performance diagnostics require an administrator.', 'bc-mcp-abilities' ) );
		}
		$path = self::clean_path( isset( $input['path'] ) ? $input['path'] : '/' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$strategy   = isset( $input['strategy'] ) && 'desktop' === $input['strategy'] ? 'desktop' : 'mobile';
		$categories = isset( $input['categories'] ) && is_array( $input['categories'] ) ? $input['categories'] : array( 'performance', 'accessibility', 'best-practices', 'seo' );
		$allowed    = array( 'performance', 'accessibility', 'best-practices', 'seo' );
		if ( ! $categories || count( $categories ) > 4 || count( array_unique( $categories ) ) !== count( $categories ) || array_diff( $categories, $allowed ) ) {
			return new WP_Error( 'bc_mcp_performance_categories', __( 'Unsupported Lighthouse categories.', 'bc-mcp-abilities' ) );
		}
		$url = home_url( $path );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return new WP_Error( 'bc_mcp_performance_https', __( 'A public HTTPS WordPress site is required.', 'bc-mcp-abilities' ) );
		}
		$cache_key = 'bc_mcp_psi_' . md5( $url . '|' . $strategy . '|' . implode( ',', $categories ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$cached['cached'] = true;
			return array( 'success' => true, 'data' => $cached );
		}
		$args = array(
			'url'      => $url,
			'strategy' => $strategy,
		);
		$endpoint = add_query_arg( $args, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' );
		foreach ( $categories as $category ) {
			$endpoint .= '&category=' . rawurlencode( $category );
		}
		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'             => 35,
				'redirection'         => 0,
				'limit_response_size' => 3 * 1024 * 1024,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bc_mcp_performance_network', __( 'PageSpeed Insights request failed.', 'bc-mcp-abilities' ) );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'bc_mcp_performance_api', __( 'PageSpeed Insights is unavailable, rate-limited, or returned an error.', 'bc-mcp-abilities' ) );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['lighthouseResult'] ) ) {
			return new WP_Error( 'bc_mcp_performance_result', __( 'PageSpeed Insights returned no valid Lighthouse result.', 'bc-mcp-abilities' ) );
		}
		$result = self::report( $decoded );
		$result['url']      = $url;
		$result['strategy'] = $strategy;
		$result['cached']   = false;
		set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		return array( 'success' => true, 'data' => $result );
	}
}
