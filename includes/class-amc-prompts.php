<?php
/**
 * Registers this plugin's guided MCP prompts — multi-turn flows that walk
 * the user through a task (rather than a single tool call), driven by
 * interviewing the user and then calling this plugin's own abilities.
 *
 * @package AgencyMcpConnect
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class AMC_Prompts
 */
final class AMC_Prompts {

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
		add_action( 'wp_abilities_api_init', array( $this, 'register_all' ) );
	}

	public function register_all(): void {
		$this->register_case_study();
		$this->register_blog_post();
	}

	private function register_case_study(): void {
		wp_register_ability(
			'env-site/create-case-study',
			array(
				'label'               => __( 'Create a Case Study', 'mcp-env-wp' ),
				'description'         => __( 'Interviews you for the details of a client case study, then drafts and publishes it as a WordPress post — asking one question at a time instead of a giant form.', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'client' => array(
							'type'        => 'string',
							'description' => __( 'The client or project this case study is about.', 'mcp-env-wp' ),
						),
					),
					'required'   => array( 'client' ),
				),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => static function ( array $args ) {
					$client = $args['client'] ?? '';

					$text = <<<PROMPT
You are helping build a WordPress case study post for the client/project "{$client}" using the mcp-env-wp tools available on this connection (env-site-create-post, env-site-update-post, env-site-create-category, env-site-create-tag, env-site-upload-image, env-site-update-seo, env-site-get-categories, env-site-get-tags).

Interview the user for the following, asking ONE question at a time and waiting for their answer before moving to the next — do not dump the whole list at once:

1. Client/project name (already have: "{$client}" — confirm or correct it).
2. The challenge or problem the client came to the agency with.
3. The approach/solution the agency delivered (services, channels, key tactics).
4. The results — ask for concrete numbers/metrics if available (traffic, leads, revenue, rankings, etc.); if the user doesn't have numbers, that's fine, note it as qualitative.
5. A client quote/testimonial, if one exists (optional — skip if none).
6. Any images to include (ask for URLs, or note that images can be added later via env-site-upload-image).
7. Which category and/or tags this should be filed under — call env-site-get-categories and env-site-get-tags first to show existing options before asking, and only create a new one (env-site-create-category / env-site-create-tag) if the user confirms none of the existing ones fit.
8. Whether to publish immediately or save as a draft for review (default: draft).

Once you have enough to write a solid draft:
- Compose a well-structured post (a compelling title, an intro framing the challenge, a body covering approach and results, and a closing with the testimonial if provided).
- Call env-site-create-post with that content and the chosen category/tags.
- If the user gave SEO-relevant details (a target keyword, meta description angle), call env-site-update-seo on the new post.
- Tell the user the resulting post ID, edit link, and current status (draft/publish) at the end, and ask if they'd like any revisions.

If at any point the user's token doesn't have access to a needed tool (a "not authorized" error), tell them plainly which ability is missing and that they (or the connection owner) can add it from the MCP Connect page — don't just fail silently.
PROMPT;

					return array( 'text' => $text );
				},
				'meta'                => array(
					'mcp' => array( 'public' => true, 'type' => 'prompt' ),
				),
			)
		);
	}

	private function register_blog_post(): void {
		wp_register_ability(
			'env-site/create-blog-post',
			array(
				'label'               => __( 'Create a Blog Post', 'mcp-env-wp' ),
				'description'         => __( 'Interviews you for the details of a new blog post — content, category, tags, author, featured image — suggesting sensible defaults from the existing site content, then creates it as a draft (or publishes, if you say so).', 'mcp-env-wp' ),
				'category'            => 'agency-mcp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array(
							'type'        => 'string',
							'description' => __( 'The working title of the post, if already known.', 'mcp-env-wp' ),
						),
						'source' => array(
							'type'        => 'string',
							'description' => __( 'Optional: raw source content already provided (e.g. text pasted or extracted from an uploaded document) to build the post from, instead of writing from scratch.', 'mcp-env-wp' ),
						),
					),
				),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => static function ( array $args ) {
					$title  = $args['title'] ?? '';
					$source = $args['source'] ?? '';

					$title_line  = $title ? "Working title: \"{$title}\" (confirm or change it)." : 'No title given yet — ask for one, or propose one if source content is provided.';
					$source_line = $source
						? "Source content has already been provided below — use it as the basis for the post instead of asking the user to write it from scratch. Adapt/tighten it into a well-formatted post rather than pasting it verbatim; ask the user if they want it used as-is, summarized, or rewritten in the agency's voice.\n\n---\n{$source}\n---"
						: 'No source content given. If the user attaches or pastes a document (e.g. a Word doc, a set of notes), read it and use it as the source instead of asking them to retype everything. Otherwise, ask what the post should cover.';

					$text = <<<PROMPT
You are helping create a new WordPress blog post using the mcp-env-wp tools available on this connection (env-site-create-post, env-site-update-post, env-site-get-categories, env-site-get-tags, env-site-get-users, env-site-get-media, env-site-upload-image, env-site-create-category, env-site-create-tag, env-site-update-seo).

{$title_line}

{$source_line}

Before asking the user anything you can figure out yourself, gather context:
- Call env-site-get-categories and env-site-get-tags to see what already exists on the site.
- If useful, call env-site-get-posts to look at a few recent posts for tone/category patterns.

Then interview the user for whatever is still missing, one question at a time (don't dump a form):
1. Content — if not already provided as source content, ask what the post should say (or offer to draft it from a short brief they give you).
2. Category — based on the post's topic and the existing categories you fetched, SUGGEST the one or two that best fit (name them specifically) rather than asking "what category?" blindly. Only create a new category (env-site-create-category) if the user confirms none of the existing ones work.
3. Tags — same approach: suggest relevant existing tags for the user to confirm/adjust before creating new ones.
4. Author — ask who should be credited; call env-site-get-users if you need to look up a WordPress user ID by name, and default to the current connection's user if they don't specify.
5. Featured image — ask if there's one. If they give a URL, use env-site-upload-image (optionally with set_as_featured after the post exists, or pass the returned attachment id as featured_image_id on env-site-create-post). If they want to search the existing media library, use env-site-get-media.
6. Status — publish now or save as a draft (default: draft).

Once you have enough:
- Call env-site-create-post with title, content, categories, tags, author_id, and featured_image_id as applicable.
- If the user mentioned SEO details (target keyword, meta description), call env-site-update-seo on the new post.
- Report back the post ID, edit link, and status, and ask if they want any revisions.

If a needed tool isn't available on this connection (a "not authorized" error), say plainly which ability is missing rather than failing silently — the user can add it from the MCP Connect page.
PROMPT;

					return array( 'text' => $text );
				},
				'meta'                => array(
					'mcp' => array( 'public' => true, 'type' => 'prompt' ),
				),
			)
		);
	}
}
