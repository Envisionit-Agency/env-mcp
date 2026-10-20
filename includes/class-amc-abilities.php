<?php
/**
 * Registers this plugin's own WordPress abilities (content read/write/delete
 * operations), so Agency MCP Connect does not depend on any other
 * abilities-providing plugin.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Abilities
 */
final class AMC_Abilities {

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
		// Registered directly at plugins_loaded time (not deferred into a
		// later hook) so we're guaranteed attached before anything can fire
		// wp_abilities_api_init first. See the mcp-adapter hook-ordering bug
		// fixed earlier in this project for why that matters.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_all' ) );
	}

	public function register_category(): void {
		wp_register_ability_category(
			'agency-mcp',
			array(
				'label'       => __( 'Agency MCP', 'mcp-env-wp' ),
				'description' => __( 'WordPress content and site abilities exposed by the Agency MCP Connect plugin.', 'mcp-env-wp' ),
			)
		);
	}

	public function register_all(): void {
		$this->register_posts();
		$this->register_pages();
		$this->register_taxonomies();
		$this->register_comments();
		$this->register_media();
		$this->register_users();
		$this->register_cpt();
		$this->register_meta();
		$this->register_site();
		$this->register_seo();
		$this->register_menus();
	}

	/** Shorthand for a public MCP tool ability. */
	private function public_tool_meta(): array {
		return array( 'mcp' => array( 'public' => true, 'type' => 'tool' ) );
	}

	/**
	 * Whether at least one public post type actually supports a given
	 * post-type feature (e.g. 'comments'). Used to skip registering
	 * abilities for site features that are globally disabled, rather than
	 * offering a connection permission for something that can't do anything.
	 */
	private function any_public_post_type_supports( string $feature ): bool {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( post_type_supports( $post_type, $feature ) ) {
				return true;
			}
		}
		return false;
	}

	// -----------------------------------------------------------------
	// Posts
	// -----------------------------------------------------------------

	private function register_posts(): void {
		wp_register_ability(
			'env-site/get-posts',
			array(
				'label'               => __( 'Get Posts', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves a list of blog posts with optional filters by status, category, search, and count.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'default' => 'publish' ),
						'search'   => array( 'type' => 'string' ),
						'category' => array( 'type' => 'integer', 'description' => 'Category ID to filter by.' ),
						'number'   => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => function ( array $args ) {
					$query = array(
						'post_type'      => 'post',
						'post_status'    => $args['status'] ?? 'publish',
						'posts_per_page' => min( 100, (int) ( $args['number'] ?? 20 ) ),
					);
					// Only set 's' when there's an actual term — WP_Query treats the mere
					// presence of the 's' key (even '') as a search query, which silently
					// resets 'post_type' to the default searchable types, discarding ours.
					if ( ! empty( $args['search'] ) ) {
						$query['s'] = $args['search'];
					}
					if ( ! empty( $args['category'] ) ) {
						$query['cat'] = (int) $args['category'];
					}
					return array( 'posts' => array_map( array( $this, 'shape_post' ), get_posts( $query ) ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-post',
			array(
				'label'               => __( 'Get Single Post', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves full details of a single post by ID.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'read_post', (int) ( $args['id'] ?? 0 ) ),
				'execute_callback'    => function ( array $args ) {
					$post = get_post( (int) $args['id'] );
					if ( ! $post || 'post' !== $post->post_type ) {
						return new WP_Error( 'amc_not_found', __( 'Post not found.', 'mcp-env-wp' ) );
					}
					return $this->shape_post( $post, true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/create-post',
			array(
				'label'               => __( 'Create Post', 'mcp-env-wp' ),
				'description'         => __( 'Creates a new blog post. Defaults to draft status.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'              => array( 'type' => 'string' ),
						'content'            => array( 'type' => 'string' ),
						'excerpt'            => array( 'type' => 'string' ),
						'status'             => array( 'type' => 'string', 'default' => 'draft' ),
						'categories'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category term IDs.' ),
						'tags'               => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Tag term IDs.' ),
						'author_id'          => array( 'type' => 'integer', 'description' => 'WordPress user ID to set as author. Defaults to the current user.' ),
						'featured_image_id'  => array( 'type' => 'integer', 'description' => 'Media attachment ID to set as the featured image (see env-site-get-media or env-site-upload-image).' ),
					),
					'required'   => array( 'title' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => function ( array $args ) {
					$insert = array(
						'post_type'     => 'post',
						'post_title'    => $args['title'],
						'post_content'  => $args['content'] ?? '',
						'post_excerpt'  => $args['excerpt'] ?? '',
						'post_status'   => $args['status'] ?? 'draft',
						'post_category' => $args['categories'] ?? array(),
					);
					if ( ! empty( $args['tags'] ) ) {
						$insert['tags_input'] = array_map( 'intval', $args['tags'] );
					}
					if ( ! empty( $args['author_id'] ) ) {
						$insert['post_author'] = (int) $args['author_id'];
					}

					$post_id = wp_insert_post( $insert, true );
					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}

					if ( ! empty( $args['featured_image_id'] ) ) {
						set_post_thumbnail( $post_id, (int) $args['featured_image_id'] );
					}

					return $this->shape_post( get_post( $post_id ), true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-post',
			array(
				'label'               => __( 'Update Post', 'mcp-env-wp' ),
				'description'         => __( 'Updates an existing post. Only provided fields are modified.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'                => array( 'type' => 'integer' ),
						'title'             => array( 'type' => 'string' ),
						'content'           => array( 'type' => 'string' ),
						'excerpt'           => array( 'type' => 'string' ),
						'status'            => array( 'type' => 'string' ),
						'categories'        => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'tags'              => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'author_id'         => array( 'type' => 'integer' ),
						'featured_image_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['id'] ?? 0 ) ),
				'execute_callback'    => function ( array $args ) {
					$update = array( 'ID' => (int) $args['id'] );
					foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ) as $in => $out ) {
						if ( isset( $args[ $in ] ) ) {
							$update[ $out ] = $args[ $in ];
						}
					}
					if ( isset( $args['categories'] ) ) {
						$update['post_category'] = array_map( 'intval', $args['categories'] );
					}
					if ( isset( $args['tags'] ) ) {
						$update['tags_input'] = array_map( 'intval', $args['tags'] );
					}
					if ( ! empty( $args['author_id'] ) ) {
						$update['post_author'] = (int) $args['author_id'];
					}

					$result = wp_update_post( $update, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					if ( ! empty( $args['featured_image_id'] ) ) {
						set_post_thumbnail( (int) $args['id'], (int) $args['featured_image_id'] );
					}

					return $this->shape_post( get_post( $result ), true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/delete-post',
			array(
				'label'               => __( 'Delete Post', 'mcp-env-wp' ),
				'description'         => __( 'Moves a post to trash (or permanently deletes it if force=true).', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'force' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'delete_post', (int) ( $args['id'] ?? 0 ) ),
				'execute_callback'    => static function ( array $args ) {
					$result = wp_delete_post( (int) $args['id'], (bool) ( $args['force'] ?? false ) );
					if ( ! $result ) {
						return new WP_Error( 'amc_delete_failed', __( 'Could not delete the post.', 'mcp-env-wp' ) );
					}
					return array( 'deleted' => true, 'id' => (int) $args['id'] );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Pages
	// -----------------------------------------------------------------

	private function register_pages(): void {
		wp_register_ability(
			'env-site/get-pages',
			array(
				'label'               => __( 'Get Pages', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves site pages with title, status, and hierarchy.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'default' => 'publish' ),
						'search' => array( 'type' => 'string' ),
						'number' => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_pages' ),
				'execute_callback'    => function ( array $args ) {
					$query = array(
						'post_type'      => 'page',
						'post_status'    => $args['status'] ?? 'publish',
						'posts_per_page' => min( 100, (int) ( $args['number'] ?? 20 ) ),
					);
					if ( ! empty( $args['search'] ) ) {
						$query['s'] = $args['search'];
					}
					$posts = get_posts( $query );
					return array( 'pages' => array_map( array( $this, 'shape_post' ), $posts ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-page',
			array(
				'label'               => __( 'Get Single Page', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves full details of a single page by ID.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'read_post', (int) ( $args['id'] ?? 0 ) ),
				'execute_callback'    => function ( array $args ) {
					$post = get_post( (int) $args['id'] );
					if ( ! $post || 'page' !== $post->post_type ) {
						return new WP_Error( 'amc_not_found', __( 'Page not found.', 'mcp-env-wp' ) );
					}
					return $this->shape_post( $post, true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/create-page',
			array(
				'label'               => __( 'Create Page', 'mcp-env-wp' ),
				'description'         => __( 'Creates a new WordPress page.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string', 'default' => 'draft' ),
						'parent_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'title' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_pages' ),
				'execute_callback'    => function ( array $args ) {
					$page_id = wp_insert_post(
						array(
							'post_type'    => 'page',
							'post_title'   => $args['title'],
							'post_content' => $args['content'] ?? '',
							'post_status'  => $args['status'] ?? 'draft',
							'post_parent'  => (int) ( $args['parent_id'] ?? 0 ),
						),
						true
					);
					if ( is_wp_error( $page_id ) ) {
						return $page_id;
					}
					return $this->shape_post( get_post( $page_id ), true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-page',
			array(
				'label'               => __( 'Update Page', 'mcp-env-wp' ),
				'description'         => __( 'Updates an existing page. Only provided fields are modified.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['id'] ?? 0 ) ),
				'execute_callback'    => function ( array $args ) {
					$update = array( 'ID' => (int) $args['id'] );
					foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status' ) as $in => $out ) {
						if ( isset( $args[ $in ] ) ) {
							$update[ $out ] = $args[ $in ];
						}
					}
					$result = wp_update_post( $update, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return $this->shape_post( get_post( $result ), true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Categories & tags
	// -----------------------------------------------------------------

	private function register_taxonomies(): void {
		wp_register_ability(
			'env-site/get-categories',
			array(
				'label'               => __( 'Get Categories', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves all blog categories.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => static function () {
					$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
					return array( 'categories' => array_map( array( __CLASS__, 'shape_term_static' ), $terms ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/create-category',
			array(
				'label'               => __( 'Create Category', 'mcp-env-wp' ),
				'description'         => __( 'Creates a new blog category.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
					'required'   => array( 'name' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'manage_categories' ),
				'execute_callback'    => static function ( array $args ) {
					$result = wp_insert_term(
						$args['name'],
						'category',
						array(
							'description' => $args['description'] ?? '',
							'parent'      => (int) ( $args['parent'] ?? 0 ),
						)
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return AMC_Abilities::shape_term_static( get_term( $result['term_id'] ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-tags',
			array(
				'label'               => __( 'Get Tags', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves all blog tags.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => static function () {
					$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
					return array( 'tags' => array_map( array( __CLASS__, 'shape_term_static' ), $terms ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/create-tag',
			array(
				'label'               => __( 'Create Tag', 'mcp-env-wp' ),
				'description'         => __( 'Creates a new blog tag.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'name' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'manage_categories' ),
				'execute_callback'    => static function ( array $args ) {
					$result = wp_insert_term( $args['name'], 'post_tag', array( 'description' => $args['description'] ?? '' ) );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return AMC_Abilities::shape_term_static( get_term( $result['term_id'] ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Comments
	// -----------------------------------------------------------------

	private function register_comments(): void {
		if ( ! $this->any_public_post_type_supports( 'comments' ) ) {
			return;
		}

		wp_register_ability(
			'env-site/get-comments',
			array(
				'label'               => __( 'Get Comments', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves comments with optional filters by status and post.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'  => array( 'type' => 'string', 'default' => 'approve' ),
						'post_id' => array( 'type' => 'integer' ),
						'number'  => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'moderate_comments' ) || current_user_can( 'edit_posts' ),
				'execute_callback'    => static function ( array $args ) {
					$query = array(
						'status' => $args['status'] ?? 'approve',
						'number' => min( 100, (int) ( $args['number'] ?? 20 ) ),
					);
					if ( ! empty( $args['post_id'] ) ) {
						$query['post_id'] = (int) $args['post_id'];
					}
					$comments = get_comments( $query );
					return array(
						'comments' => array_map(
							static fn( $c ) => array(
								'id'      => (int) $c->comment_ID,
								'post_id' => (int) $c->comment_post_ID,
								'author'  => $c->comment_author,
								'content' => $c->comment_content,
								'status'  => wp_get_comment_status( $c ),
								'date'    => $c->comment_date,
							),
							$comments
						),
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/reply-comment',
			array(
				'label'               => __( 'Reply to Comment', 'mcp-env-wp' ),
				'description'         => __( 'Replies to an existing comment, published as the current user.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array( 'type' => 'integer' ),
						'content'    => array( 'type' => 'string' ),
					),
					'required'   => array( 'comment_id', 'content' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'moderate_comments' ),
				'execute_callback'    => static function ( array $args ) {
					$parent = get_comment( (int) $args['comment_id'] );
					if ( ! $parent ) {
						return new WP_Error( 'amc_not_found', __( 'Comment not found.', 'mcp-env-wp' ) );
					}
					$user       = wp_get_current_user();
					$comment_id = wp_insert_comment(
						array(
							'comment_post_ID'      => $parent->comment_post_ID,
							'comment_parent'       => $parent->comment_ID,
							'comment_content'      => $args['content'],
							'comment_author'       => $user->display_name,
							'comment_author_email' => $user->user_email,
							'user_id'              => $user->ID,
							'comment_approved'     => 1,
						)
					);
					if ( ! $comment_id ) {
						return new WP_Error( 'amc_reply_failed', __( 'Could not post the reply.', 'mcp-env-wp' ) );
					}
					return array( 'id' => (int) $comment_id );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-comment',
			array(
				'label'               => __( 'Update Comment', 'mcp-env-wp' ),
				'description'         => __( 'Updates the content of an existing comment.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'content' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id', 'content' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'moderate_comments' ),
				'execute_callback'    => static function ( array $args ) {
					$result = wp_update_comment(
						array( 'comment_ID' => (int) $args['id'], 'comment_content' => $args['content'] ),
						true
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return array( 'updated' => true, 'id' => (int) $args['id'] );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/moderate-comment',
			array(
				'label'               => __( 'Moderate Comment', 'mcp-env-wp' ),
				'description'         => __( 'Changes a comment status: approve, hold, spam, or trash.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'status' => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash' ) ),
					),
					'required'   => array( 'id', 'status' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'moderate_comments' ),
				'execute_callback'    => static function ( array $args ) {
					$ok = wp_set_comment_status( (int) $args['id'], $args['status'], true );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
					return array( 'updated' => true, 'id' => (int) $args['id'], 'status' => $args['status'] );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Media
	// -----------------------------------------------------------------

	private function register_media(): void {
		wp_register_ability(
			'env-site/get-media',
			array(
				'label'               => __( 'Get Media', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves media library files with optional MIME type and search filters.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'mime_type' => array( 'type' => 'string' ),
						'search'    => array( 'type' => 'string' ),
						'number'    => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'upload_files' ),
				'execute_callback'    => static function ( array $args ) {
					$query = array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => min( 100, (int) ( $args['number'] ?? 20 ) ),
					);
					if ( ! empty( $args['search'] ) ) {
						$query['s'] = $args['search'];
					}
					if ( ! empty( $args['mime_type'] ) ) {
						$query['post_mime_type'] = $args['mime_type'];
					}
					$items = get_posts( $query );
					return array(
						'media' => array_map(
							static fn( $a ) => array(
								'id'        => (int) $a->ID,
								'title'     => $a->post_title,
								'url'       => wp_get_attachment_url( $a->ID ),
								'mime_type' => $a->post_mime_type,
								'date'      => $a->post_date,
							),
							$items
						),
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/upload-image',
			array(
				'label'               => __( 'Upload Image from URL', 'mcp-env-wp' ),
				'description'         => __( 'Downloads an image from an external URL and registers it in the media library.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'                => array( 'type' => 'string' ),
						'attach_to_post_id'  => array( 'type' => 'integer' ),
						'set_as_featured'    => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'url' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'upload_files' ),
				'execute_callback'    => static function ( array $args ) {
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';

					$post_id       = (int) ( $args['attach_to_post_id'] ?? 0 );
					$attachment_id = media_sideload_image( $args['url'], $post_id, null, 'id' );

					if ( is_wp_error( $attachment_id ) ) {
						return $attachment_id;
					}

					if ( $post_id && ! empty( $args['set_as_featured'] ) ) {
						set_post_thumbnail( $post_id, (int) $attachment_id );
					}

					return array(
						'id'  => (int) $attachment_id,
						'url' => wp_get_attachment_url( (int) $attachment_id ),
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Users
	// -----------------------------------------------------------------

	private function register_users(): void {
		wp_register_ability(
			'env-site/get-users',
			array(
				'label'               => __( 'Get Users', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves the list of site users with ID, name, email, and role.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'number' => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'list_users' ),
				'execute_callback'    => static function ( array $args ) {
					$users = get_users( array( 'number' => min( 100, (int) ( $args['number'] ?? 20 ) ) ) );
					return array(
						'users' => array_map(
							static fn( $u ) => array(
								'id'    => (int) $u->ID,
								'name'  => $u->display_name,
								'email' => $u->user_email,
								'roles' => $u->roles,
							),
							$users
						),
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Custom post types (generic)
	// -----------------------------------------------------------------

	private function register_cpt(): void {
		wp_register_ability(
			'env-site/list-post-types',
			array(
				'label'               => __( 'List Post Types', 'mcp-env-wp' ),
				'description'         => __( 'Lists all public custom post types registered on the site.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => static function () {
					$types = get_post_types( array( 'public' => true ), 'objects' );
					return array(
						'post_types' => array_map(
							static fn( $t ) => array( 'name' => $t->name, 'label' => $t->label ),
							array_values( $types )
						),
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-cpt-items',
			array(
				'label'               => __( 'Get CPT Items', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves items of a given public custom post type.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string', 'default' => 'publish' ),
						'number'    => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
					),
					'required'   => array( 'post_type' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'check_cpt_readable' ),
				'execute_callback'    => function ( array $args ) {
					$items = get_posts(
						array(
							'post_type'      => $args['post_type'],
							'post_status'    => $args['status'] ?? 'publish',
							'posts_per_page' => min( 100, (int) ( $args['number'] ?? 20 ) ),
						)
					);
					return array( 'items' => array_map( array( $this, 'shape_post' ), $items ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-cpt-item',
			array(
				'label'               => __( 'Get CPT Item', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves a single custom post type item by ID.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'read_post', (int) ( $args['id'] ?? 0 ) ),
				'execute_callback'    => function ( array $args ) {
					$post = get_post( (int) $args['id'] );
					if ( ! $post ) {
						return new WP_Error( 'amc_not_found', __( 'Item not found.', 'mcp-env-wp' ) );
					}
					return $this->shape_post( $post, true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/create-cpt-item',
			array(
				'label'               => __( 'Create CPT Item', 'mcp-env-wp' ),
				'description'         => __( 'Creates a new item in a public custom post type.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string', 'default' => 'draft' ),
					),
					'required'   => array( 'post_type', 'title' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => array( $this, 'check_cpt_writable' ),
				'execute_callback'    => function ( array $args ) {
					$post_id = wp_insert_post(
						array(
							'post_type'    => $args['post_type'],
							'post_title'   => $args['title'],
							'post_content' => $args['content'] ?? '',
							'post_status'  => $args['status'] ?? 'draft',
						),
						true
					);
					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}
					return $this->shape_post( get_post( $post_id ), true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-cpt-item',
			array(
				'label'               => __( 'Update CPT Item', 'mcp-env-wp' ),
				'description'         => __( 'Updates an existing custom post type item. Only provided fields are modified.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['id'] ?? 0 ) ),
				'execute_callback'    => function ( array $args ) {
					$update = array( 'ID' => (int) $args['id'] );
					foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status' ) as $in => $out ) {
						if ( isset( $args[ $in ] ) ) {
							$update[ $out ] = $args[ $in ];
						}
					}
					$result = wp_update_post( $update, true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return $this->shape_post( get_post( $result ), true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	public function check_cpt_readable( array $args ): bool {
		$type = get_post_type_object( $args['post_type'] ?? '' );
		return $type && $type->public && current_user_can( $type->cap->read_private_posts ?? 'read' );
	}

	public function check_cpt_writable( array $args ): bool {
		$type = get_post_type_object( $args['post_type'] ?? '' );
		return $type && $type->public && current_user_can( $type->cap->edit_posts ?? 'edit_posts' );
	}

	// -----------------------------------------------------------------
	// Post/term meta
	// -----------------------------------------------------------------

	private function register_meta(): void {
		wp_register_ability(
			'env-site/get-post-meta',
			array(
				'label'               => __( 'Get Post Meta', 'mcp-env-wp' ),
				'description'         => __( 'Reads a single post meta field by exact key.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id', 'key' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) ),
				'execute_callback'    => static function ( array $args ) {
					return array( 'value' => get_post_meta( (int) $args['post_id'], $args['key'], true ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-post-meta',
			array(
				'label'               => __( 'Update Post Meta', 'mcp-env-wp' ),
				'description'         => __( 'Writes a post meta field by exact key.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
						'value'   => array( 'description' => 'The value to store: text, number, boolean, array or object.' ),
					),
					'required'   => array( 'post_id', 'key', 'value' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) ),
				'execute_callback'    => static function ( array $args ) {
					update_post_meta( (int) $args['post_id'], $args['key'], $args['value'] );
					return array( 'updated' => true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-term-meta',
			array(
				'label'               => __( 'Get Term Meta', 'mcp-env-wp' ),
				'description'         => __( 'Reads a taxonomy term meta field by exact key.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
					),
					'required'   => array( 'term_id', 'key' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'manage_categories' ),
				'execute_callback'    => static function ( array $args ) {
					return array( 'value' => get_term_meta( (int) $args['term_id'], $args['key'], true ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-term-meta',
			array(
				'label'               => __( 'Update Term Meta', 'mcp-env-wp' ),
				'description'         => __( 'Writes a taxonomy term meta field by exact key.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
						'value'   => array( 'description' => 'The value to store: text, number, boolean, array or object.' ),
					),
					'required'   => array( 'term_id', 'key', 'value' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'manage_categories' ),
				'execute_callback'    => static function ( array $args ) {
					update_term_meta( (int) $args['term_id'], $args['key'], $args['value'] );
					return array( 'updated' => true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-term',
			array(
				'label'               => __( 'Update Term', 'mcp-env-wp' ),
				'description'         => __( 'Updates the core fields (name, slug, description, parent) of an existing taxonomy term.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'     => array( 'type' => 'integer' ),
						'taxonomy'    => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
					'required'   => array( 'term_id', 'taxonomy' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'manage_categories' ),
				'execute_callback'    => static function ( array $args ) {
					$update = array();
					foreach ( array( 'name', 'slug', 'description', 'parent' ) as $field ) {
						if ( isset( $args[ $field ] ) ) {
							$update[ $field ] = $args[ $field ];
						}
					}
					$result = wp_update_term( (int) $args['term_id'], $args['taxonomy'], $update );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return AMC_Abilities::shape_term_static( get_term( $result['term_id'] ) );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// SEO (auto-detects Yoast / Rank Math / SEOPress)
	// -----------------------------------------------------------------

	private function register_seo(): void {
		if ( ! AMC_SEO_Adapter::detect_active() ) {
			return;
		}

		wp_register_ability(
			'env-site/get-seo',
			array(
				'label'               => __( 'Get SEO Metadata', 'mcp-env-wp' ),
				'description'         => __( 'Retrieves normalized SEO metadata for a post or page (title, description, focus keyword, canonical, noindex, Open Graph title/description). Works with whichever SEO plugin is active (Yoast, Rank Math, or SEOPress).', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) ),
				'execute_callback'    => static fn( array $args ) => AMC_SEO_Adapter::get( (int) $args['post_id'] ),
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-seo',
			array(
				'label'               => __( 'Update SEO Metadata', 'mcp-env-wp' ),
				'description'         => __( 'Updates SEO metadata on a post or page. Only provided fields are modified. Works with whichever SEO plugin is active (Yoast, Rank Math, or SEOPress).', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'         => array( 'type' => 'integer' ),
						'title'           => array( 'type' => 'string' ),
						'description'     => array( 'type' => 'string' ),
						'focus_keyword'   => array( 'type' => 'string' ),
						'canonical'       => array( 'type' => 'string' ),
						'noindex'         => array( 'type' => 'boolean' ),
						'og_title'        => array( 'type' => 'string' ),
						'og_description'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn( array $args ) => current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) ),
				'execute_callback'    => static function ( array $args ) {
					$post_id = (int) $args['post_id'];
					unset( $args['post_id'] );
					AMC_SEO_Adapter::update( $post_id, $args );
					return AMC_SEO_Adapter::get( $post_id );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Menus
	// -----------------------------------------------------------------

	private function register_menus(): void {
		if ( empty( get_registered_nav_menus() ) ) {
			return;
		}

		wp_register_ability(
			'env-site/list-menus',
			array(
				'label'               => __( 'List Menus', 'mcp-env-wp' ),
				'description'         => __( 'Lists every navigation menu with its id, name, and item count, plus theme menu locations and which menu each one displays.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
				'execute_callback'    => static function () {
					$menus     = wp_get_nav_menus();
					$locations = get_nav_menu_locations();
					return array(
						'menus'     => array_map(
							static fn( $m ) => array(
								'id'    => (int) $m->term_id,
								'name'  => $m->name,
								'slug'  => $m->slug,
								'count' => (int) $m->count,
							),
							$menus
						),
						'locations' => $locations,
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-menu',
			array(
				'label'               => __( 'Get Menu', 'mcp-env-wp' ),
				'description'         => __( 'Returns every item of one menu with its hierarchy: id, title, url, and parent item.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'menu_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'menu_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
				'execute_callback'    => static function ( array $args ) {
					$items = wp_get_nav_menu_items( (int) $args['menu_id'] );
					if ( false === $items ) {
						return new WP_Error( 'amc_not_found', __( 'Menu not found.', 'mcp-env-wp' ) );
					}
					return array(
						'items' => array_map(
							static fn( $i ) => array(
								'id'        => (int) $i->ID,
								'title'     => $i->title,
								'url'       => $i->url,
								'parent_id' => (int) $i->menu_item_parent,
								'position'  => (int) $i->menu_order,
							),
							$items
						),
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/create-menu',
			array(
				'label'               => __( 'Create Menu', 'mcp-env-wp' ),
				'description'         => __( 'Creates a new, empty navigation menu.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'name' => array( 'type' => 'string' ) ),
					'required'   => array( 'name' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
				'execute_callback'    => static function ( array $args ) {
					$menu_id = wp_update_nav_menu_object( 0, array( 'menu-name' => $args['name'] ) );
					if ( is_wp_error( $menu_id ) ) {
						return $menu_id;
					}
					return array( 'id' => (int) $menu_id );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/add-menu-item',
			array(
				'label'               => __( 'Add Menu Item', 'mcp-env-wp' ),
				'description'         => __( 'Adds an item to a menu: a post/page (object_id) or a custom URL.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu_id'   => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'url'       => array( 'type' => 'string' ),
						'object_id' => array( 'type' => 'integer' ),
						'parent_id' => array( 'type' => 'integer' ),
						'position'  => array( 'type' => 'integer' ),
					),
					'required'   => array( 'menu_id', 'title' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
				'execute_callback'    => static function ( array $args ) {
					$item_args = array(
						'menu-item-title'     => $args['title'],
						'menu-item-status'    => 'publish',
						'menu-item-parent-id' => (int) ( $args['parent_id'] ?? 0 ),
						'menu-item-position'  => (int) ( $args['position'] ?? 0 ),
					);
					if ( ! empty( $args['object_id'] ) ) {
						$item_args['menu-item-object-id'] = (int) $args['object_id'];
						$item_args['menu-item-object']    = get_post_type( (int) $args['object_id'] ) ?: 'page';
						$item_args['menu-item-type']      = 'post_type';
					} else {
						$item_args['menu-item-url']  = $args['url'] ?? '#';
						$item_args['menu-item-type'] = 'custom';
					}
					$item_id = wp_update_nav_menu_item( (int) $args['menu_id'], 0, $item_args );
					if ( is_wp_error( $item_id ) ) {
						return $item_id;
					}
					return array( 'id' => (int) $item_id );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/update-menu-item',
			array(
				'label'               => __( 'Update Menu Item', 'mcp-env-wp' ),
				'description'         => __( 'Updates one menu item: title, custom URL, parent, or position.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu_id'   => array( 'type' => 'integer' ),
						'item_id'   => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'url'       => array( 'type' => 'string' ),
						'parent_id' => array( 'type' => 'integer' ),
						'position'  => array( 'type' => 'integer' ),
					),
					'required'   => array( 'menu_id', 'item_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
				'execute_callback'    => static function ( array $args ) {
					$existing = wp_setup_nav_menu_item( get_post( (int) $args['item_id'] ) );
					$item_args = array(
						'menu-item-title'     => $args['title'] ?? $existing->title,
						'menu-item-url'       => $args['url'] ?? $existing->url,
						'menu-item-parent-id' => $args['parent_id'] ?? $existing->menu_item_parent,
						'menu-item-position'  => $args['position'] ?? $existing->menu_order,
						'menu-item-status'    => 'publish',
						'menu-item-type'      => $existing->type ?? 'custom',
						'menu-item-object'    => $existing->object ?? '',
						'menu-item-object-id' => $existing->object_id ?? 0,
					);
					$result = wp_update_nav_menu_item( (int) $args['menu_id'], (int) $args['item_id'], $item_args );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return array( 'updated' => true, 'id' => (int) $args['item_id'] );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/remove-menu-item',
			array(
				'label'               => __( 'Remove Menu Item', 'mcp-env-wp' ),
				'description'         => __( 'Permanently removes one item from a menu. Children become top-level.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'item_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'item_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
				'execute_callback'    => static function ( array $args ) {
					$ok = wp_delete_post( (int) $args['item_id'], true );
					if ( ! $ok ) {
						return new WP_Error( 'amc_delete_failed', __( 'Could not remove the menu item.', 'mcp-env-wp' ) );
					}
					return array( 'deleted' => true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/assign-menu-location',
			array(
				'label'               => __( 'Assign Menu Location', 'mcp-env-wp' ),
				'description'         => __( 'Assigns a menu to a theme location (or clears it with menu_id 0). Changes site-wide navigation.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'location' => array( 'type' => 'string' ),
						'menu_id'  => array( 'type' => 'integer' ),
					),
					'required'   => array( 'location', 'menu_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
				'execute_callback'    => static function ( array $args ) {
					$locations                         = get_theme_mod( 'nav_menu_locations', array() );
					$locations[ $args['location'] ]    = (int) $args['menu_id'];
					set_theme_mod( 'nav_menu_locations', $locations );
					return array( 'updated' => true );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Site
	// -----------------------------------------------------------------

	private function register_site(): void {
		wp_register_ability(
			'env-site/site-stats',
			array(
				'label'               => __( 'Site Statistics', 'mcp-env-wp' ),
				'description'         => __( 'Returns a summary of total posts, pages, categories, tags, comments, and users.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => static function () {
					return array(
						'posts'      => (int) wp_count_posts( 'post' )->publish,
						'pages'      => (int) wp_count_posts( 'page' )->publish,
						'categories' => (int) wp_count_terms( array( 'taxonomy' => 'category' ) ),
						'tags'       => (int) wp_count_terms( array( 'taxonomy' => 'post_tag' ) ),
						'comments'   => (int) wp_count_comments()->approved,
						'users'      => (int) count_users()['total_users'],
					);
				},
				'meta'                => $this->public_tool_meta(),
			)
		);

		wp_register_ability(
			'env-site/get-active-plugins',
			array(
				'label'               => __( 'Get Active Plugins', 'mcp-env-wp' ),
				'description'         => __( 'Returns a list of currently active plugins with name and version.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn() => current_user_can( 'activate_plugins' ),
				'execute_callback'    => static function () {
					if ( ! function_exists( 'get_plugins' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$all    = get_plugins();
					$active = (array) get_option( 'active_plugins', array() );
					$out    = array();
					foreach ( $active as $file ) {
						if ( isset( $all[ $file ] ) ) {
							$out[] = array( 'name' => $all[ $file ]['Name'], 'version' => $all[ $file ]['Version'] );
						}
					}
					return array( 'plugins' => $out );
				},
				'meta'                => $this->public_tool_meta(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Shaping helpers
	// -----------------------------------------------------------------

	private function shape_post( WP_Post $post, bool $full = false ): array {
		$data = array(
			'id'     => (int) $post->ID,
			'title'  => $post->post_title,
			'status' => $post->post_status,
			'date'   => $post->post_date,
			'author' => (int) $post->post_author,
			'link'   => get_permalink( $post ),
		);

		if ( $full ) {
			$data['content'] = $post->post_content;
			$data['excerpt'] = $post->post_excerpt;
			$data['parent']  = (int) $post->post_parent;
		}

		return $data;
	}

	public static function shape_term_static( WP_Term $term ): array {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
		);
	}
}
