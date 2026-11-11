<?php
/**
 * Plugin updates from GitHub releases.
 *
 * Shows new versions on the Plugins screen and installs them with the normal "Update now".
 * A release is a GitHub release on Envisionit-Agency/env-mcp tagged vX.Y.Z with an
 * env-mcp.zip asset (the release workflow builds it on every tag).
 *
 * The repo is public, so no setup is needed. Optional: define( 'ENV_MCP_GITHUB_TOKEN', '...' ) in wp-config.php
 * to lift GitHub's anonymous API rate limit (60 requests an hour per server IP).
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class AMC_Updater {

	private const REPO      = 'Envisionit-Agency/env-mcp';
	private const ASSET     = 'env-mcp.zip';
	private const CACHE_KEY = 'amc_update_release';

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );
	}

	private static function token(): string {
		return defined( 'ENV_MCP_GITHUB_TOKEN' ) ? (string) ENV_MCP_GITHUB_TOKEN : '';
	}

	private static function basename(): string {
		return plugin_basename( AMC_PLUGIN_FILE );
	}

	/**
	 * The latest release: version, zip API URL, notes, date. Cached 6 hours (1 hour after a failure).
	 *
	 * @return array{version:string,package:string,notes:string,date:string,url:string}|null
	 */
	private static function release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array_filter(
					array(
						'Accept'        => 'application/vnd.github+json',
						'Authorization' => self::token() ? 'Bearer ' . self::token() : '',
					)
				),
			)
		);
		$data = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( wp_remote_retrieve_body( $response ), true );

		$release = null;
		if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
			foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
				if ( self::ASSET === ( $asset['name'] ?? '' ) ) {
					$release = array(
						'version' => ltrim( (string) $data['tag_name'], 'vV' ),
						'package' => (string) $asset['browser_download_url'],
						'notes'   => (string) ( $data['body'] ?? '' ),
						'date'    => (string) ( $data['published_at'] ?? '' ),
						'url'     => (string) ( $data['html_url'] ?? '' ),
					);
					break;
				}
			}
		}

		set_site_transient(
			self::CACHE_KEY,
			$release ?? array( 'version' => '' ),
			$release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS
		);
		return $release;
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::release();
		if ( ! $release ) {
			return $transient;
		}
		$item = (object) array(
			'id'          => self::REPO,
			'slug'        => dirname( self::basename() ),
			'plugin'      => self::basename(),
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
		);
		if ( version_compare( $release['version'], AMC_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/** "View details" on the Plugins screen. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== dirname( self::basename() ) ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'MCP ENV WP',
			'slug'          => dirname( self::basename() ),
			'version'       => $release['version'],
			'author'        => 'Envisionit',
			'last_updated'  => $release['date'],
			'requires'      => '6.9',
			'requires_php'  => '8.1',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $release['notes'] ?: 'See the release on GitHub.' ) ),
			),
		);
	}

	public static function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}
}
