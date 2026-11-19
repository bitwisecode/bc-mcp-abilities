<?php
/**
 * Media upload and SEO abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register optional integration abilities.
 */
final class BC_MCP_Abilities_Integrations {
	/**
	 * Rank Math metadata keys supported by this integration.
	 *
	 * @return string[]
	 */
	private static function rank_math_keys() {
		return array(
			'rank_math_title',
			'rank_math_description',
			'rank_math_focus_keyword',
		);
	}

	/**
	 * Register abilities with the native WordPress registry.
	 *
	 * @return void
	 */
	public static function register() {
		$int = array( 'type' => 'integer', 'minimum' => 1 );
		$str = array( 'type' => 'string' );

		BC_MCP_Abilities::add(
			'rank-math-read',
			__( 'Read Rank Math SEO Fields', 'bc-mcp-abilities' ),
			__( 'Read existing Rank Math metadata and its revision token for a post or page.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'post_id' => $int ),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'rank_math_read'
		);

		BC_MCP_Abilities::add(
			'rank-math-update',
			__( 'Update Rank Math SEO Fields', 'bc-mcp-abilities' ),
			__( 'Update only explicitly supplied Rank Math fields using a metadata revision token.', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'             => $int,
					'expected_revision'   => $str,
					'title'               => $str,
					'description'         => $str,
					'focus_keyword'       => $str,
				),
				'required'             => array( 'post_id', 'expected_revision' ),
				'additionalProperties' => false,
			),
			'rank_math_update',
			false
		);

		BC_MCP_Abilities::add(
			'upload-image',
			__( 'Upload Image to Media Library', 'bc-mcp-abilities' ),
			__( 'Download a validated public HTTPS image and add it to WordPress Media Library (maximum 8 MB).', 'bc-mcp-abilities' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'source_url' => array(
						'type'      => 'string',
						'maxLength' => 2048,
						'format'    => 'uri',
					),
					'title'      => $str,
					'alt_text'   => $str,
					'filename'   => $str,
					'post_id'    => $int,
				),
				'required'             => array( 'source_url' ),
				'additionalProperties' => false,
			),
			'upload_image',
			false
		);
	}

	/**
	 * Return a standard success envelope.
	 *
	 * @param array $data Structured data.
	 * @return array
	 */
	private static function ok( $data ) {
		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Resolve a post that the current user may edit.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|WP_Error
	 */
	private static function allowed_post( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'bc_mcp_post_denied', __( 'Post is unavailable or editing is not permitted.', 'bc-mcp-abilities' ) );
		}
		return $post;
	}

	/**
	 * Read the three allowed Rank Math meta fields and their concurrency token.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function rank_math_state( $post_id ) {
		$fields = array();
		foreach ( self::rank_math_keys() as $key ) {
			$fields[ $key ] = (string) get_post_meta( $post_id, $key, true );
		}
		return array(
			'post_id'  => (int) $post_id,
			'fields'   => $fields,
			'revision' => hash( 'sha256', wp_json_encode( $fields ) ),
		);
	}

	/**
	 * Read Rank Math metadata.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function rank_math_read( $input ) {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return new WP_Error( 'bc_mcp_rank_math_missing', __( 'Rank Math is not active.', 'bc-mcp-abilities' ) );
		}
		$post = self::allowed_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::ok( self::rank_math_state( $post->ID ) );
	}

	/**
	 * Update only allowlisted Rank Math metadata after comparing revision.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function rank_math_update( $input ) {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return new WP_Error( 'bc_mcp_rank_math_missing', __( 'Rank Math is not active.', 'bc-mcp-abilities' ) );
		}
		$post = self::allowed_post( $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$existing = self::rank_math_state( $post->ID );
		if ( ! hash_equals( $existing['revision'], (string) $input['expected_revision'] ) ) {
			return new WP_Error( 'bc_mcp_rank_math_conflict', __( 'SEO fields changed. Read them again before saving.', 'bc-mcp-abilities' ) );
		}

		$fields = array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		);
		$updates = 0;
		foreach ( $fields as $field => $key ) {
			if ( array_key_exists( $field, $input ) ) {
				$value = sanitize_text_field( $input[ $field ] );
				if ( strlen( $value ) > 1000 ) {
					return new WP_Error( 'bc_mcp_rank_math_length', __( 'SEO field exceeds the 1000-byte limit.', 'bc-mcp-abilities' ) );
				}
				update_post_meta( $post->ID, $key, $value );
				++$updates;
			}
		}
		if ( 0 === $updates ) {
			return new WP_Error( 'bc_mcp_rank_math_empty', __( 'Supply at least one SEO field.', 'bc-mcp-abilities' ) );
		}
		return self::ok( self::rank_math_state( $post->ID ) );
	}

	/**
	 * Sideload an HTTPS image from a public host.
	 *
	 * @param array $input Validated input.
	 * @return array|WP_Error
	 */
	public static function upload_image( $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'bc_mcp_upload_denied', __( 'Media uploads are not permitted.', 'bc-mcp-abilities' ) );
		}
		$url = isset( $input['source_url'] ) ? esc_url_raw( (string) $input['source_url'] ) : '';
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'bc_mcp_upload_url', __( 'Provide a public HTTPS image URL.', 'bc-mcp-abilities' ) );
		}

		$parent_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( $parent_id ) {
			$parent = self::allowed_post( $parent_id );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( 'bc-mcp-image' );
		if ( ! $tmp ) {
			return new WP_Error( 'bc_mcp_upload_temp', __( 'Could not prepare a temporary file.', 'bc-mcp-abilities' ) );
		}
		$max_bytes = 8 * 1024 * 1024;
		$response  = wp_safe_remote_get(
			$url,
			array(
				'stream'              => true,
				'filename'            => $tmp,
				'timeout'             => 20,
				'redirection'         => 0,
				'limit_response_size' => $max_bytes + 1,
				'sslverify'           => true,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'bc_mcp_upload_fetch', __( 'Image download failed; redirects are not permitted.', 'bc-mcp-abilities' ) );
		}
		$size = filesize( $tmp );
		if ( ! $size || $size > $max_bytes ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'bc_mcp_upload_size', __( 'Downloaded image is empty or larger than 8 MB.', 'bc-mcp-abilities' ) );
		}
		$allowed = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		$mime = wp_get_image_mime( $tmp );
		if ( ! isset( $allowed[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'bc_mcp_upload_type', __( 'Only JPEG, PNG, WebP and GIF images are supported.', 'bc-mcp-abilities' ) );
		}
		$name = isset( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : 'ai-image';
		$base = sanitize_title( pathinfo( $name, PATHINFO_FILENAME ) );
		$file = array(
			'name'     => ( $base ? $base : 'ai-image' ) . '.' . $allowed[ $mime ],
			'tmp_name' => $tmp,
			'type'     => $mime,
			'size'     => $size,
			'error'    => 0,
		);
		$title     = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		$post_data = $title ? array( 'post_title' => $title ) : array();
		$id        = media_handle_sideload( $file, $parent_id, '', $post_data );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}
		return self::ok(
			array(
				'attachment_id' => (int) $id,
				'url'           => wp_get_attachment_url( $id ),
				'mime'          => $mime,
				'bytes'         => (int) $size,
			)
		);
	}
}
