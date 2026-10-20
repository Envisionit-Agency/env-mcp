<?php
/**
 * Template: MCP Connect self-serve page.
 *
 * Expects in scope: $user, $tokens, $abilities, $grouped, $is_admin,
 * $this->error, $this->just_created (via AMC_Admin).
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** @var WP_User $user */
/** @var array $tokens */
/** @var array $abilities */
/** @var array $grouped */
/** @var bool $is_admin */

$level_labels = array(
	'read'        => __( 'Read', 'mcp-env-wp' ),
	'write'       => __( 'Write', 'mcp-env-wp' ),
	'destructive' => __( 'Destructive', 'mcp-env-wp' ),
);

$scope_mode_labels = array(
	'read'       => __( 'Read-only', 'mcp-env-wp' ),
	'read_write' => __( 'Read + Write', 'mcp-env-wp' ),
	'full'       => __( 'Full access', 'mcp-env-wp' ),
);

/**
 * Renders the "abilities allowed" cell: the live preset name (auto-updating)
 * or a plain count for a hand-picked explicit list.
 */
$amc_render_scope_summary = static function ( array $token ) use ( $scope_mode_labels ): string {
	$mode = $token['scope_mode'] ?? 'explicit';
	if ( isset( $scope_mode_labels[ $mode ] ) ) {
		return $scope_mode_labels[ $mode ] . ' ' . __( '(auto-updating)', 'mcp-env-wp' );
	}
	return sprintf(
		/* translators: %d: number of abilities */
		_n( '%d ability', '%d abilities', count( $token['scope'] ), 'mcp-env-wp' ),
		count( $token['scope'] )
	);
};
?>
<div class="wrap amc-wrap">
	<h1 class="amc-title">
		<span class="dashicons dashicons-superhero-alt"></span>
		<?php esc_html_e( 'MCP Connect', 'mcp-env-wp' ); ?>
	</h1>
	<p class="amc-subtitle">
		<?php esc_html_e( 'Connect Claude, ChatGPT, or Gemini to this site. Each connection gets its own token and only the WordPress abilities you choose to allow it — nothing more.', 'mcp-env-wp' ); ?>
	</p>

	<?php if ( isset( $_GET['amc_revoked'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connection revoked.', 'mcp-env-wp' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $this->error instanceof WP_Error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $this->error->get_error_message() ); ?></p></div>
	<?php endif; ?>

	<?php if ( $this->just_created ) : ?>
		<?php
		$plain = $this->just_created['plain_token'];
		?>
		<div class="amc-card amc-card--success">
			<h2><?php echo empty( $this->just_created['existing'] ) ? esc_html__( '✅ Connection created', 'mcp-env-wp' ) : esc_html__( '✅ Connection setup', 'mcp-env-wp' ); ?></h2>
			<?php if ( empty( $this->just_created['existing'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Copy this token now and keep it somewhere safe: it is stored securely and can\'t be shown again. With it, Show setup on the connection rebuilds these instructions any time.', 'mcp-env-wp' ); ?></strong></p>
			<?php endif; ?>

			<div class="amc-copy-row">
				<code id="amc-plain-token"><?php echo esc_html( $plain ); ?></code>
				<button type="button" class="button amc-copy-btn" data-copy-target="amc-plain-token"><?php esc_html_e( 'Copy token', 'mcp-env-wp' ); ?></button>
			</div>

			<h3><?php esc_html_e( 'Add it to your AI client', 'mcp-env-wp' ); ?></h3>

			<div class="amc-tabs" data-amc-tabs>
				<div class="amc-tabs__nav">
					<button type="button" class="amc-tabs__btn is-active" data-tab="claude"><?php esc_html_e( 'Claude', 'mcp-env-wp' ); ?></button>
					<button type="button" class="amc-tabs__btn" data-tab="chatgpt"><?php esc_html_e( 'ChatGPT', 'mcp-env-wp' ); ?></button>
					<button type="button" class="amc-tabs__btn" data-tab="gemini"><?php esc_html_e( 'Gemini', 'mcp-env-wp' ); ?></button>
					<button type="button" class="amc-tabs__btn" data-tab="curl"><?php esc_html_e( 'Test with cURL', 'mcp-env-wp' ); ?></button>
				</div>

				<div class="amc-tabs__panel is-active" data-panel="claude">
					<p><?php esc_html_e( 'Claude Desktop / Claude Code — add this to your MCP config:', 'mcp-env-wp' ); ?></p>
					<div class="amc-code-block">
						<pre id="amc-claude-json"><?php echo esc_html( AMC_Connect_Config::claude_json( 'agency-wordpress', $plain ) ); ?></pre>
						<button type="button" class="button amc-copy-btn" data-copy-target="amc-claude-json"><?php esc_html_e( 'Copy', 'mcp-env-wp' ); ?></button>
					</div>
					<p><?php esc_html_e( 'Or, in a terminal:', 'mcp-env-wp' ); ?></p>
					<div class="amc-code-block">
						<pre id="amc-claude-cli"><?php echo esc_html( AMC_Connect_Config::claude_cli_command( 'agency-wordpress', $plain ) ); ?></pre>
						<button type="button" class="button amc-copy-btn" data-copy-target="amc-claude-cli"><?php esc_html_e( 'Copy', 'mcp-env-wp' ); ?></button>
					</div>
				</div>

				<div class="amc-tabs__panel" data-panel="chatgpt">
					<?php $openai = AMC_Connect_Config::openai_fields( $plain ); ?>
					<p><?php esc_html_e( 'In ChatGPT → Settings → Connectors → Add custom connector, use:', 'mcp-env-wp' ); ?></p>
					<table class="amc-kv">
						<tr>
							<th><?php esc_html_e( 'Server URL', 'mcp-env-wp' ); ?></th>
							<td><code id="amc-openai-url"><?php echo esc_html( $openai['url'] ); ?></code>
								<button type="button" class="button button-small amc-copy-btn" data-copy-target="amc-openai-url"><?php esc_html_e( 'Copy', 'mcp-env-wp' ); ?></button>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Header name', 'mcp-env-wp' ); ?></th>
							<td><code><?php echo esc_html( $openai['header'] ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Header value', 'mcp-env-wp' ); ?></th>
							<td><code id="amc-openai-value"><?php echo esc_html( $openai['value'] ); ?></code>
								<button type="button" class="button button-small amc-copy-btn" data-copy-target="amc-openai-value"><?php esc_html_e( 'Copy', 'mcp-env-wp' ); ?></button>
							</td>
						</tr>
					</table>
				</div>

				<div class="amc-tabs__panel" data-panel="gemini">
					<p><?php esc_html_e( 'Gemini CLI / extensions config:', 'mcp-env-wp' ); ?></p>
					<div class="amc-code-block">
						<pre id="amc-gemini-json"><?php echo esc_html( AMC_Connect_Config::gemini_json( 'agency-wordpress', $plain ) ); ?></pre>
						<button type="button" class="button amc-copy-btn" data-copy-target="amc-gemini-json"><?php esc_html_e( 'Copy', 'mcp-env-wp' ); ?></button>
					</div>
				</div>

				<div class="amc-tabs__panel" data-panel="curl">
					<p><?php esc_html_e( 'Sanity-check the token from a terminal:', 'mcp-env-wp' ); ?></p>
					<div class="amc-code-block">
						<pre id="amc-curl"><?php echo esc_html( AMC_Connect_Config::curl_test_command( $plain ) ); ?></pre>
						<button type="button" class="button amc-copy-btn" data-copy-target="amc-curl"><?php esc_html_e( 'Copy', 'mcp-env-wp' ); ?></button>
					</div>
				</div>
			</div>

			<p>
				<button type="button" class="button button-primary" id="amc-test-connection" data-token="<?php echo esc_attr( $plain ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
					<?php esc_html_e( 'Test connection now', 'mcp-env-wp' ); ?>
				</button>
				<span id="amc-test-result"></span>
			</p>
		</div>
	<?php endif; ?>

	<div class="amc-card">
		<h2><?php esc_html_e( 'New connection', 'mcp-env-wp' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'amc_create_token' ); ?>
			<input type="hidden" name="amc_action" value="create_token" />

			<p>
				<label for="amc_name"><strong><?php esc_html_e( 'Name this connection', 'mcp-env-wp' ); ?></strong></label><br />
				<input type="text" id="amc_name" name="amc_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. My Claude Desktop', 'mcp-env-wp' ); ?>" required />
			</p>

			<p>
				<label for="amc_expires_days"><strong><?php esc_html_e( 'Expires', 'mcp-env-wp' ); ?></strong></label><br />
				<select id="amc_expires_days" name="amc_expires_days">
					<option value="0"><?php esc_html_e( 'Never', 'mcp-env-wp' ); ?></option>
					<option value="7"><?php esc_html_e( 'In 7 days', 'mcp-env-wp' ); ?></option>
					<option value="30" selected><?php esc_html_e( 'In 30 days', 'mcp-env-wp' ); ?></option>
					<option value="90"><?php esc_html_e( 'In 90 days', 'mcp-env-wp' ); ?></option>
				</select>
			</p>

			<p><strong><?php esc_html_e( 'Access level', 'mcp-env-wp' ); ?></strong></p>
			<input type="hidden" id="amc_scope_mode" name="amc_scope_mode" value="explicit" />
			<div class="amc-presets">
				<button type="button" class="button" data-preset="read"><?php esc_html_e( 'Read-only', 'mcp-env-wp' ); ?></button>
				<button type="button" class="button" data-preset="read_write"><?php esc_html_e( 'Read + Write', 'mcp-env-wp' ); ?></button>
				<button type="button" class="button" data-preset="full"><?php esc_html_e( 'Full access', 'mcp-env-wp' ); ?></button>
				<button type="button" class="button" data-preset="none"><?php esc_html_e( 'Clear all', 'mcp-env-wp' ); ?></button>
			</div>
			<p class="description" id="amc-scope-mode-hint" hidden>
				<?php esc_html_e( '✓ This preset will stay in sync automatically — if new abilities are added to the site later, this connection gets them too, without recreating the token.', 'mcp-env-wp' ); ?>
			</p>

			<div class="amc-scope-groups">
				<?php foreach ( $grouped as $category => $items ) : ?>
					<div class="amc-scope-group">
						<h4><?php echo esc_html( $category ); ?></h4>
						<?php foreach ( $items as $ability ) : ?>
							<label class="amc-scope-item amc-scope-item--<?php echo esc_attr( $ability['level'] ); ?>">
								<input type="checkbox" name="amc_scope[]" value="<?php echo esc_attr( $ability['name'] ); ?>" data-level="<?php echo esc_attr( $ability['level'] ); ?>" />
								<span class="amc-scope-label"><?php echo esc_html( $ability['label'] ); ?></span>
								<span class="amc-badge amc-badge--<?php echo esc_attr( $ability['level'] ); ?>"><?php echo esc_html( $level_labels[ $ability['level'] ] ?? $ability['level'] ); ?></span>
								<?php if ( $ability['description'] ) : ?>
									<span class="amc-scope-desc"><?php echo esc_html( $ability['description'] ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<?php if ( empty( $abilities ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'No abilities are currently enabled for MCP on this site. Ask an administrator to enable some in "Enable Abilities for MCP" settings first.', 'mcp-env-wp' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<p>
				<button type="submit" class="button button-primary" <?php disabled( empty( $abilities ) ); ?>><?php esc_html_e( 'Create connection', 'mcp-env-wp' ); ?></button>
			</p>
		</form>
	</div>

	<div class="amc-card">
		<h2><?php esc_html_e( 'Your connections', 'mcp-env-wp' ); ?></h2>
		<?php if ( empty( $tokens ) ) : ?>
			<p class="description"><?php esc_html_e( 'You have not created any connections yet.', 'mcp-env-wp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'mcp-env-wp' ); ?></th>
						<th><?php esc_html_e( 'Token', 'mcp-env-wp' ); ?></th>
						<th><?php esc_html_e( 'Abilities allowed', 'mcp-env-wp' ); ?></th>
						<th><?php esc_html_e( 'Created', 'mcp-env-wp' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'mcp-env-wp' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'mcp-env-wp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tokens as $token ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $token['name'] ); ?></strong></td>
							<td><code><?php echo esc_html( $token['token_prefix'] ); ?></code></td>
							<td><?php echo esc_html( $amc_render_scope_summary( $token ) ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $token['created_at'] ) ); ?></td>
							<td><?php echo $token['last_used_at'] ? esc_html( human_time_diff( strtotime( $token['last_used_at'] . ' UTC' ) ) . ' ago' ) : esc_html__( 'Never', 'mcp-env-wp' ); ?></td>
							<td><?php echo $token['expires_at'] ? esc_html( mysql2date( get_option( 'date_format' ), $token['expires_at'] ) ) : esc_html__( 'Never', 'mcp-env-wp' ); ?></td>
							<td class="amc-row-actions">
								<details class="amc-show-setup">
									<summary class="button button-small"><?php esc_html_e( 'Show setup', 'mcp-env-wp' ); ?></summary>
									<form method="post" class="amc-show-setup__form">
										<?php wp_nonce_field( 'amc_show_setup' ); ?>
										<input type="hidden" name="amc_action" value="show_setup" />
										<input type="hidden" name="amc_token_id" value="<?php echo esc_attr( $token['id'] ); ?>" />
										<label class="screen-reader-text" for="amc-token-<?php echo esc_attr( $token['id'] ); ?>"><?php esc_html_e( 'Token', 'mcp-env-wp' ); ?></label>
										<input type="password" id="amc-token-<?php echo esc_attr( $token['id'] ); ?>" name="amc_plain_token" class="regular-text" placeholder="<?php echo esc_attr( $token['token_prefix'] ); ?>" autocomplete="off" required />
										<button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Show', 'mcp-env-wp' ); ?></button>
										<p class="description"><?php esc_html_e( 'Paste this connection\'s token to get its setup again. The token is not changed.', 'mcp-env-wp' ); ?></p>
									</form>
								</details>
								<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this connection? Anything using this token will stop working immediately.', 'mcp-env-wp' ) ); ?>');">
									<?php wp_nonce_field( 'amc_revoke_token' ); ?>
									<input type="hidden" name="amc_action" value="revoke_token" />
									<input type="hidden" name="amc_token_id" value="<?php echo esc_attr( $token['id'] ); ?>" />
									<button type="submit" class="button-link-delete"><?php esc_html_e( 'Revoke', 'mcp-env-wp' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="amc-card">
		<h2><?php esc_html_e( 'Recent activity', 'mcp-env-wp' ); ?></h2>
		<?php if ( empty( $my_activity ) ) : ?>
			<p class="description"><?php esc_html_e( 'No tool calls yet from your connections.', 'mcp-env-wp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Connection', 'mcp-env-wp' ); ?></th>
						<th><?php esc_html_e( 'Tool', 'mcp-env-wp' ); ?></th>
						<th><?php esc_html_e( 'Result', 'mcp-env-wp' ); ?></th>
						<th><?php esc_html_e( 'When', 'mcp-env-wp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $my_activity as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['token_name'] ); ?></td>
							<td><code><?php echo esc_html( $entry['tool_name'] ); ?></code></td>
							<td><?php echo $entry['success'] ? '✅' : '❌'; ?></td>
							<td><?php echo esc_html( human_time_diff( strtotime( $entry['created_at'] . ' UTC' ) ) . ' ago' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php if ( $is_admin ) : ?>
		<div class="amc-card">
			<h2><?php esc_html_e( 'All agency connections (admin view)', 'mcp-env-wp' ); ?></h2>
			<?php $all_tokens = AMC_Token_Manager::instance()->list_all_tokens(); ?>
			<?php if ( empty( $all_tokens ) ) : ?>
				<p class="description"><?php esc_html_e( 'No one has created a connection yet.', 'mcp-env-wp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'Name', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'Abilities allowed', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'Created', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'Last used', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'mcp-env-wp' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_tokens as $token ) : ?>
							<tr>
								<td><?php echo esc_html( $token['display_name'] ?: $token['user_email'] ); ?></td>
								<td><strong><?php echo esc_html( $token['name'] ); ?></strong> <code><?php echo esc_html( $token['token_prefix'] ); ?></code></td>
								<td><?php echo esc_html( $amc_render_scope_summary( $token ) ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $token['created_at'] ) ); ?></td>
								<td><?php echo $token['last_used_at'] ? esc_html( human_time_diff( strtotime( $token['last_used_at'] . ' UTC' ) ) . ' ago' ) : esc_html__( 'Never', 'mcp-env-wp' ); ?></td>
								<td><?php echo $token['expires_at'] ? esc_html( mysql2date( get_option( 'date_format' ), $token['expires_at'] ) ) : esc_html__( 'Never', 'mcp-env-wp' ); ?></td>
								<td>
									<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this user\'s connection?', 'mcp-env-wp' ) ); ?>');">
										<?php wp_nonce_field( 'amc_revoke_token' ); ?>
										<input type="hidden" name="amc_action" value="revoke_token" />
										<input type="hidden" name="amc_token_id" value="<?php echo esc_attr( $token['id'] ); ?>" />
										<input type="hidden" name="amc_force" value="1" />
										<button type="submit" class="button-link-delete"><?php esc_html_e( 'Revoke', 'mcp-env-wp' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="amc-card">
			<h2><?php esc_html_e( 'Site-wide recent activity', 'mcp-env-wp' ); ?></h2>
			<?php if ( empty( $all_activity ) ) : ?>
				<p class="description"><?php esc_html_e( 'No tool calls recorded yet.', 'mcp-env-wp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'Connection', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'Result', 'mcp-env-wp' ); ?></th>
							<th><?php esc_html_e( 'When', 'mcp-env-wp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_activity as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['display_name'] ); ?></td>
								<td><?php echo esc_html( $entry['token_name'] ); ?></td>
								<td><code><?php echo esc_html( $entry['tool_name'] ); ?></code></td>
								<td><?php echo $entry['success'] ? '✅' : '❌'; ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $entry['created_at'] . ' UTC' ) ) . ' ago' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
