<?php
/**
 * Normalizes SEO metadata reads/writes across whichever SEO plugin is active
 * (Yoast SEO, Rank Math, or SEOPress) behind one consistent field set.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_SEO_Adapter
 */
final class AMC_SEO_Adapter {

	/**
	 * Detects which supported SEO plugin is active, if any.
	 */
	public static function detect_active(): ?string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get( int $post_id ): array {
		return match ( self::detect_active() ) {
			'yoast'    => self::get_yoast( $post_id ),
			'rankmath' => self::get_rankmath( $post_id ),
			'seopress' => self::get_seopress( $post_id ),
			default    => array(),
		};
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public static function update( int $post_id, array $fields ): void {
		match ( self::detect_active() ) {
			'yoast'    => self::update_yoast( $post_id, $fields ),
			'rankmath' => self::update_rankmath( $post_id, $fields ),
			'seopress' => self::update_seopress( $post_id, $fields ),
			default    => null,
		};
	}

	// -----------------------------------------------------------------
	// Yoast SEO
	// -----------------------------------------------------------------

	private static function get_yoast( int $id ): array {
		return array(
			'title'            => get_post_meta( $id, '_yoast_wpseo_title', true ),
			'description'      => get_post_meta( $id, '_yoast_wpseo_metadesc', true ),
			'focus_keyword'    => get_post_meta( $id, '_yoast_wpseo_focuskw', true ),
			'canonical'        => get_post_meta( $id, '_yoast_wpseo_canonical', true ),
			'noindex'          => '1' === get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ),
			'og_title'         => get_post_meta( $id, '_yoast_wpseo_opengraph-title', true ),
			'og_description'   => get_post_meta( $id, '_yoast_wpseo_opengraph-description', true ),
		);
	}

	private static function update_yoast( int $id, array $f ): void {
		$map = array(
			'title'          => '_yoast_wpseo_title',
			'description'    => '_yoast_wpseo_metadesc',
			'focus_keyword'  => '_yoast_wpseo_focuskw',
			'canonical'      => '_yoast_wpseo_canonical',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
		);
		foreach ( $map as $field => $meta_key ) {
			if ( isset( $f[ $field ] ) ) {
				update_post_meta( $id, $meta_key, $f[ $field ] );
			}
		}
		if ( isset( $f['noindex'] ) ) {
			update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', $f['noindex'] ? '1' : '2' );
		}
	}

	// -----------------------------------------------------------------
	// Rank Math
	// -----------------------------------------------------------------

	private static function get_rankmath( int $id ): array {
		$robots = get_post_meta( $id, 'rank_math_robots', true );
		return array(
			'title'          => get_post_meta( $id, 'rank_math_title', true ),
			'description'    => get_post_meta( $id, 'rank_math_description', true ),
			'focus_keyword'  => get_post_meta( $id, 'rank_math_focus_keyword', true ),
			'canonical'      => get_post_meta( $id, 'rank_math_canonical_url', true ),
			'noindex'        => is_array( $robots ) && in_array( 'noindex', $robots, true ),
			'og_title'       => get_post_meta( $id, 'rank_math_facebook_title', true ),
			'og_description' => get_post_meta( $id, 'rank_math_facebook_description', true ),
		);
	}

	private static function update_rankmath( int $id, array $f ): void {
		$map = array(
			'title'          => 'rank_math_title',
			'description'    => 'rank_math_description',
			'focus_keyword'  => 'rank_math_focus_keyword',
			'canonical'      => 'rank_math_canonical_url',
			'og_title'       => 'rank_math_facebook_title',
			'og_description' => 'rank_math_facebook_description',
		);
		foreach ( $map as $field => $meta_key ) {
			if ( isset( $f[ $field ] ) ) {
				update_post_meta( $id, $meta_key, $f[ $field ] );
			}
		}
		if ( isset( $f['noindex'] ) ) {
			update_post_meta( $id, 'rank_math_robots', $f['noindex'] ? array( 'noindex' ) : array() );
		}
	}

	// -----------------------------------------------------------------
	// SEOPress
	// -----------------------------------------------------------------

	private static function get_seopress( int $id ): array {
		return array(
			'title'          => get_post_meta( $id, '_seopress_titles_title', true ),
			'description'    => get_post_meta( $id, '_seopress_titles_desc', true ),
			'focus_keyword'  => get_post_meta( $id, '_seopress_analysis_target_kw', true ),
			'canonical'      => get_post_meta( $id, '_seopress_robots_canonical', true ),
			'noindex'        => 'yes' === get_post_meta( $id, '_seopress_robots_index', true ),
			'og_title'       => get_post_meta( $id, '_seopress_social_fb_title', true ),
			'og_description' => get_post_meta( $id, '_seopress_social_fb_desc', true ),
		);
	}

	private static function update_seopress( int $id, array $f ): void {
		$map = array(
			'title'          => '_seopress_titles_title',
			'description'    => '_seopress_titles_desc',
			'focus_keyword'  => '_seopress_analysis_target_kw',
			'canonical'      => '_seopress_robots_canonical',
			'og_title'       => '_seopress_social_fb_title',
			'og_description' => '_seopress_social_fb_desc',
		);
		foreach ( $map as $field => $meta_key ) {
			if ( isset( $f[ $field ] ) ) {
				update_post_meta( $id, $meta_key, $f[ $field ] );
			}
		}
		if ( isset( $f['noindex'] ) ) {
			update_post_meta( $id, '_seopress_robots_index', $f['noindex'] ? 'yes' : 'no' );
		}
	}
}
