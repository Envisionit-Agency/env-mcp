<?php
/**
 * Finds media library attachments that don't appear to be referenced
 * anywhere on the site, so a human can review them before deleting.
 *
 * This is deliberately a "candidates for review" tool, not an auto-delete
 * tool — usage detection here is best-effort (it can't see external CSS/JS,
 * third-party plugin storage it doesn't know about, etc.), so nothing here
 * ever deletes anything; env-site-delete-post can remove an attachment once a
 * human has confirmed it's safe to.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Media_Audit
 */
final class AMC_Media_Audit {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'env-site/find-unused-media',
			array(
				'label'               => __( 'Find Unused Media', 'mcp-env-wp' ),
				'description'         => __( 'Scans media library files for ones that don\'t appear to be used anywhere on the site — as a featured image, inside post/page content, in the site logo/icon, or in any ACF field (including flexible-content page builder sections) — so you can review and decide whether to delete them. This is a best-effort heuristic (it can\'t see references from outside WordPress, like hardcoded theme CSS/JS), so always review the results yourself; nothing is deleted automatically.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'mime_type' => array( 'type' => 'string', 'default' => 'image', 'description' => __( 'MIME type (or prefix, e.g. "image") to scan. Defaults to images.', 'mcp-env-wp' ) ),
						'number'    => array( 'type' => 'integer', 'default' => 50, 'maximum' => 200, 'description' => __( 'How many attachments to check in this call — this does real per-file lookups, so keep it modest and page through with "offset" for large libraries.', 'mcp-env-wp' ) ),
						'offset'    => array( 'type' => 'integer', 'default' => 0 ),
						'only_unused' => array( 'type' => 'boolean', 'default' => true, 'description' => __( 'If true (default), only returns attachments that appear unused. Set false to see the full scanned list with their usage status.', 'mcp-env-wp' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'upload_files' ),
				'execute_callback'    => array( $this, 'execute' ),
				'meta'                => array( 'mcp' => array( 'public' => true, 'type' => 'tool' ) ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function execute( array $args ): array {
		$mime_type   = $args['mime_type'] ?? 'image';
		$number      = min( 200, max( 1, (int) ( $args['number'] ?? 50 ) ) );
		$offset      = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$only_unused = array_key_exists( 'only_unused', $args ) ? (bool) $args['only_unused'] : true;

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime_type,
				'posts_per_page' => $number,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		if ( empty( $attachments ) ) {
			return array( 'checked' => 0, 'results' => array() );
		}

		$featured_ids     = $this->get_featured_image_ids();
		$content_ref_ids  = $this->get_ids_referenced_in_content_by_class();
		$logo_icon_ids    = $this->get_logo_and_icon_ids();

		$results = array();
		foreach ( $attachments as $attachment ) {
			$id       = $attachment->ID;
			$used_via = array();

			if ( in_array( $id, $featured_ids, true ) ) {
				$used_via[] = 'featured_image';
			}
			if ( in_array( $id, $content_ref_ids, true ) ) {
				$used_via[] = 'post_content';
			}
			if ( in_array( $id, $logo_icon_ids, true ) ) {
				$used_via[] = 'site_logo_or_icon';
			}
			if ( $this->basename_appears_in_content( $id ) ) {
				$used_via[] = 'post_content_url';
			}
			if ( $this->id_appears_in_postmeta( $id ) ) {
				$used_via[] = 'custom_field'; // Covers ACF image fields and flexible-content sections.
			}

			$is_used = ! empty( $used_via );

			if ( $only_unused && $is_used ) {
				continue;
			}

			$results[] = array(
				'id'        => $id,
				'title'     => $attachment->post_title,
				'url'       => wp_get_attachment_url( $id ),
				'mime_type' => $attachment->post_mime_type,
				'date'      => $attachment->post_date,
				'used'      => $is_used,
				'used_via'  => $used_via,
			);
		}

		return array(
			'checked'         => count( $attachments ),
			'unused_count'    => count( array_filter( $results, static fn( $r ) => ! $r['used'] ) ),
			'next_offset'     => $offset + count( $attachments ),
			'results'         => $results,
		);
	}

	/** @return int[] */
	private function get_featured_image_ids(): array {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" );
		return array_map( 'intval', $ids );
	}

	/**
	 * Attachment IDs mentioned via the standard `wp-image-{ID}` CSS class
	 * that the block/classic editor adds when you insert an image — a
	 * single scan across all content, not one query per attachment.
	 *
	 * @return int[]
	 */
	private function get_ids_referenced_in_content_by_class(): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_status NOT IN ('trash','auto-draft')
			 AND post_content LIKE '%wp-image-%'"
		);

		$ids = array();
		foreach ( $rows as $content ) {
			if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
			}
		}

		return array_unique( $ids );
	}

	/** @return int[] */
	private function get_logo_and_icon_ids(): array {
		$ids = array();

		$site_icon = (int) get_option( 'site_icon' );
		if ( $site_icon ) {
			$ids[] = $site_icon;
		}

		$custom_logo = (int) get_theme_mod( 'custom_logo' );
		if ( $custom_logo ) {
			$ids[] = $custom_logo;
		}

		$header_image_data = get_theme_mod( 'header_image_data' );
		if ( is_object( $header_image_data ) && ! empty( $header_image_data->attachment_id ) ) {
			$ids[] = (int) $header_image_data->attachment_id;
		}

		return $ids;
	}

	/**
	 * Whether the attachment's filename appears literally in any post's
	 * content — catches hardcoded <img src="..."> usage that doesn't carry
	 * the wp-image-{ID} class (e.g. pasted HTML, some page builders).
	 */
	private function basename_appears_in_content( int $attachment_id ): bool {
		global $wpdb;

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return false;
		}

		// Strip any -WxH size suffix WP may have appended, and use the
		// stem so we still match if content links to a specific size.
		$basename = wp_basename( $file );
		$stem     = preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $basename );

		$like = '%' . $wpdb->esc_like( $stem ) . '%';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Whether the attachment ID appears as a value anywhere in postmeta —
	 * covers ACF image fields (stored as a plain int) and ACF flexible
	 * content / repeater rows (stored serialized, containing the ID as one
	 * of the array's values). A cheap LIKE pre-filter avoids unserializing
	 * every row on the site; only rows that could plausibly contain the ID
	 * get unserialized and checked precisely.
	 */
	private function id_appears_in_postmeta( int $attachment_id ): bool {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key NOT LIKE '\_%'
				 AND meta_value LIKE %s
				 LIMIT 200",
				'%' . $wpdb->esc_like( (string) $attachment_id ) . '%'
			)
		);

		foreach ( $rows as $raw ) {
			if ( (string) $attachment_id === trim( $raw ) ) {
				return true; // Plain scalar ACF image field.
			}

			$value = maybe_unserialize( $raw );
			if ( is_array( $value ) && $this->array_contains_int( $value, $attachment_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively searches a (possibly nested) array for an integer value
	 * or a numeric string equal to $needle — ACF flexible content rows are
	 * arrays of arrays, so a shallow check isn't enough.
	 *
	 * @param array<mixed> $haystack
	 */
	private function array_contains_int( array $haystack, int $needle ): bool {
		foreach ( $haystack as $value ) {
			if ( is_array( $value ) ) {
				if ( $this->array_contains_int( $value, $needle ) ) {
					return true;
				}
				continue;
			}
			if ( is_numeric( $value ) && (int) $value === $needle ) {
				return true;
			}
		}
		return false;
	}
}
