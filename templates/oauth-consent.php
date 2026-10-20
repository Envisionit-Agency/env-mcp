<?php
/**
 * OAuth consent screen — shown when a client (e.g. claude.ai) is
 * authorizing itself against this site via the MCP connector.
 *
 * Expects in scope: $client, $client_id, $redirect_uri, $state,
 * $code_challenge, $abilities (from AMC_OAuth_Server::handle_authorize()).
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** @var array $client */
/** @var string $client_id */
/** @var string $redirect_uri */
/** @var string $state */
/** @var string $code_challenge */
/** @var array $abilities */

$user = wp_get_current_user();
$read_count  = count( array_filter( $abilities, static fn( $a ) => 'read' === $a['level'] ) );
$write_count = count( array_filter( $abilities, static fn( $a ) => 'write' === $a['level'] ) );
$destr_count = count( array_filter( $abilities, static fn( $a ) => 'destructive' === $a['level'] ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php esc_html_e( 'Authorize Connection', 'mcp-env-wp' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0f0f1; margin: 0; padding: 40px 20px; }
		.amc-consent-box { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
		.amc-consent-box h1 { font-size: 20px; margin: 0 0 8px; }
		.amc-consent-box p { color: #50575e; line-height: 1.5; }
		.amc-consent-summary { background: #f6f7f7; border-radius: 6px; padding: 16px; margin: 20px 0; font-size: 13px; }
		.amc-scope-choice { display: flex; flex-direction: column; gap: 10px; margin: 20px 0; }
		.amc-scope-choice label { display: flex; align-items: flex-start; gap: 10px; padding: 12px; border: 1px solid #dcdcde; border-radius: 6px; cursor: pointer; }
		.amc-scope-choice input { margin-top: 3px; }
		.amc-scope-choice label.is-recommended { border-color: #2271b1; }
		.amc-actions { display: flex; gap: 10px; margin-top: 24px; }
		.amc-btn { flex: 1; padding: 10px 16px; border-radius: 4px; border: 1px solid #2271b1; font-size: 14px; cursor: pointer; }
		.amc-btn-primary { background: #2271b1; color: #fff; }
		.amc-btn-secondary { background: #fff; color: #2271b1; }
	</style>
</head>
<body>
	<div class="amc-consent-box">
		<h1><?php echo esc_html( sprintf( /* translators: %s: OAuth client name */ __( '%s wants to connect', 'mcp-env-wp' ), $client['client_name'] ) ); ?></h1>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: user display name, 2: site name */
					__( 'Signed in as %1$s on %2$s.', 'mcp-env-wp' ),
					$user->display_name,
					get_bloginfo( 'name' )
				)
			);
			?>
		</p>

		<div class="amc-consent-summary">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: read count, 2: write count, 3: destructive count */
					__( 'This site currently has %1$d read, %2$d write, and %3$d destructive abilities available.', 'mcp-env-wp' ),
					$read_count,
					$write_count,
					$destr_count
				)
			);
			?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'amc_oauth_consent' ); ?>
			<input type="hidden" name="action" value="amc_oauth_consent" />
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>" />
			<input type="hidden" name="client_name" value="<?php echo esc_attr( $client['client_name'] ); ?>" />
			<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>" />
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" />
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>" />

			<p><strong><?php esc_html_e( 'Choose the access level to grant:', 'mcp-env-wp' ); ?></strong></p>
			<div class="amc-scope-choice">
				<label class="is-recommended">
					<input type="radio" name="amc_scope_mode" value="read" checked />
					<span>
						<strong><?php esc_html_e( 'Read-only', 'mcp-env-wp' ); ?></strong> (<?php esc_html_e( 'recommended', 'mcp-env-wp' ); ?>)<br />
						<?php esc_html_e( 'Can view content but cannot create, edit, or delete anything.', 'mcp-env-wp' ); ?>
					</span>
				</label>
				<label>
					<input type="radio" name="amc_scope_mode" value="read_write" />
					<span>
						<strong><?php esc_html_e( 'Read + Write', 'mcp-env-wp' ); ?></strong><br />
						<?php esc_html_e( 'Can also create and update content. No deletions.', 'mcp-env-wp' ); ?>
					</span>
				</label>
				<label>
					<input type="radio" name="amc_scope_mode" value="full" />
					<span>
						<strong><?php esc_html_e( 'Full access', 'mcp-env-wp' ); ?></strong><br />
						<?php esc_html_e( 'Everything above, plus destructive actions like deleting posts.', 'mcp-env-wp' ); ?>
					</span>
				</label>
			</div>

			<p class="description">
				<?php esc_html_e( 'You can review or revoke this connection anytime from Tools → MCP Connect.', 'mcp-env-wp' ); ?>
			</p>

			<div class="amc-actions">
				<button type="submit" name="amc_deny" value="1" class="amc-btn amc-btn-secondary"><?php esc_html_e( 'Deny', 'mcp-env-wp' ); ?></button>
				<button type="submit" class="amc-btn amc-btn-primary"><?php esc_html_e( 'Authorize', 'mcp-env-wp' ); ?></button>
			</div>
		</form>
	</div>
</body>
</html>
<?php
exit;
