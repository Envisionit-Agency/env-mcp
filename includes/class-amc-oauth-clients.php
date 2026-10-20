<?php
/**
 * Storage for dynamically-registered OAuth clients (RFC 7591).
 *
 * Clients here are public clients (no client_secret) — PKCE (S256) is
 * mandatory for the authorization code flow instead, which is the standard
 * approach for the kind of app-to-server connectors this serves (Claude,
 * ChatGPT, etc. registering themselves against this site).
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_OAuth_Clients
 */
final class AMC_OAuth_Clients {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public static function install_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . AMC_OAUTH_CLIENTS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id VARCHAR(64) NOT NULL,
			client_name VARCHAR(191) NOT NULL,
			redirect_uris LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . AMC_OAUTH_CLIENTS_TABLE;
	}

	/**
	 * Registers a new public OAuth client.
	 *
	 * @param string   $client_name
	 * @param string[] $redirect_uris
	 *
	 * @return array{client_id:string}|WP_Error
	 */
	public function register( string $client_name, array $redirect_uris ) {
		global $wpdb;

		$redirect_uris = array_values( array_filter( array_map( 'esc_url_raw', $redirect_uris ) ) );
		if ( empty( $redirect_uris ) ) {
			return new WP_Error( 'invalid_redirect_uri', 'At least one valid redirect_uris entry is required.', array( 'status' => 400 ) );
		}

		$client_name = $client_name ? sanitize_text_field( $client_name ) : 'Unnamed MCP client';
		$client_id   = 'amc_client_' . wp_generate_password( 24, false, false );

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'client_id'     => $client_id,
				'client_name'   => $client_name,
				'redirect_uris' => wp_json_encode( $redirect_uris ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'registration_failed', 'Could not register the client.', array( 'status' => 500 ) );
		}

		return array( 'client_id' => $client_id );
	}

	/**
	 * @return array{client_id:string,client_name:string,redirect_uris:string[]}|null
	 */
	public function get( string $client_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE client_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$client_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['redirect_uris'] = json_decode( (string) $row['redirect_uris'], true ) ?: array();

		return $row;
	}

	public function redirect_uri_is_registered( array $client, string $redirect_uri ): bool {
		return in_array( $redirect_uri, $client['redirect_uris'], true );
	}
}
