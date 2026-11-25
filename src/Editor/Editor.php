<?php
/**
 * The campaign editor (FR-21, FR-25, S4): the launch block set, the settings sidebar
 * and Campaign Bandit's own Publish flow in place of the core publish button.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Editor;

use CampaignBandit\Campaign\BlockSet;
use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Frontend\Frontend;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Patterns\StarterPatterns;
use CampaignBandit\Rest\RestController;
use WP_Block_Editor_Context;
use WP_Block_Type_Registry;

/**
 * Editor integration.
 */
final class Editor {

	public const HANDLE = 'campaign-bandit-editor';

	/**
	 * Settings that need a paid plan, by control. The free plugin shows them locked with
	 * the plan that unlocks them (FR-25); the add-on unlocks them for licensed plans.
	 */
	public const PAID_CONTROLS = array(
		'format_slide_in'      => 'pro',
		'format_bar'           => 'pro',
		'trigger_scroll'       => 'pro',
		'trigger_exit_intent'  => 'pro',
		'trigger_hover'        => 'pro',
		'trigger_custom_event' => 'pro',
		'condition_logic'      => 'pro',
		'saved_conditions'     => 'pro',
		'url_parameters'       => 'pro',
		'preview'              => 'pro',
		'schedule'             => 'pro',
		'country'              => 'pro',
		'frequency'            => 'pro',
		'priority'             => 'pro',
		'suppression'          => 'pro',
		'campaign_styles'      => 'pro',
		'destinations'         => 'pro',
		'close_behaviour'      => 'pro',
		'copy_variables'       => 'agency',
	);

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_filter( 'allowed_block_types_all', array( self::class, 'allowed_blocks' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_filter( 'block_categories_all', array( self::class, 'block_category' ) );
	}

	/**
	 * A "Campaign Bandit lead capture" group in the inserter (S4).
	 *
	 * @param array<int, array<string, mixed>> $categories Block categories.
	 * @return array<int, array<string, mixed>>
	 */
	public static function block_category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'campaign-bandit',
				'title' => __( 'Campaign Bandit lead capture', 'campaign-bandit' ),
			)
		);
		return $categories;
	}

	/**
	 * Register the Inline block (FR-22). It renders the same slot as the shortcode.
	 */
	public static function register_blocks(): void {
		$dir = CAMPAIGNBANDIT_DIR . '/build/blocks/inline';
		if ( file_exists( $dir . '/block.json' ) ) {
			register_block_type(
				$dir,
				array(
					'render_callback' => static fn ( array $attributes ): string => Frontend::shortcode( array( 'id' => (string) ( $attributes['campaign'] ?? '' ) ) ),
				)
			);
		}
	}

	/**
	 * Campaigns get the launch block set only. Other editors get every block except
	 * Campaign Bandit's form blocks (FR-21).
	 *
	 * @param bool|string[]           $allowed Allowed block names, or true for all.
	 * @param WP_Block_Editor_Context $context Editor context.
	 * @return bool|string[]
	 */
	public static function allowed_blocks( $allowed, WP_Block_Editor_Context $context ) {
		if ( null !== $context->post && CampaignPostType::POST_TYPE === $context->post->post_type ) {
			return BlockSet::allowed();
		}
		$all = true === $allowed ? array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) : $allowed;
		return is_array( $all ) ? array_values( array_diff( $all, BlockSet::own() ) ) : $allowed;
	}

	/**
	 * Load the campaign sidebar in the campaign editor only.
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$asset  = CAMPAIGNBANDIT_DIR . '/build/editor/index.asset.php';
		if ( null === $screen || CampaignPostType::POST_TYPE !== $screen->post_type || ! file_exists( $asset ) ) {
			return;
		}
		$deps = require $asset;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/editor/index.js', CAMPAIGNBANDIT_FILE ),
			$deps['dependencies'],
			$deps['version'],
			true
		);
		wp_set_script_translations( self::HANDLE, 'campaign-bandit' );
		wp_add_inline_script(
			self::HANDLE,
			'window.campaignBanditEditor=' . wp_json_encode( self::config() ) . ';',
			'before'
		);

		// Sidebar styles; they also hide the core publish controls (the post is always the draft, BR-20).
		wp_enqueue_style( self::HANDLE, plugins_url( 'build/editor/index.css', CAMPAIGNBANDIT_FILE ), array( 'wp-components' ), $deps['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * The editor's settings, also used by the Conditions screen's rule builder (S11).
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		/**
		 * Filters the editor's settings. The hub adds `publishScreen`, so Publish
		 * opens its Publish screen to choose sites (FR-41).
		 *
		 * @param array<string, mixed> $config Settings.
		 */
		return (array) apply_filters(
			'campaignbandit_editor_config',
			array(
				'detailScreen'    => admin_url( 'edit.php?post_type=' . CampaignPostType::POST_TYPE . '&page=campaign-bandit-campaign&campaign=' ),
				'plan'            => Plan::current()->value,
				// On a hub, rules that depend on each site's content are marked (decision 0021).
				'hub'             => Plan::current()->is_hub_plan(),
				// Page paths are relative to the site's home, so they carry across sites.
				'homePath'        => rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ),
				'locked'          => (object) self::locked_controls(),
				'namespace'       => RestController::NAMESPACE,
				'postTypes'       => self::choices( get_post_types( array( 'public' => true ), 'objects' ) ),
				// Campaign styles (FR-27): the theme's fonts by name and its colours.
				'themeFonts'      => self::theme_fonts(),
				'palette'         => array_map(
					static fn ( string $slug, string $color ): array => array(
						'name'  => $slug,
						'color' => $color,
					),
					array_keys( \CampaignBandit\Publishing\PublishingService::palette() ),
					array_values( \CampaignBandit\Publishing\PublishingService::palette() )
				),
				'taxonomies'      => self::choices( get_taxonomies( array( 'public' => true ), 'objects' ) ),
				// Days of the week in schedules start where the site's week starts (BR-08).
				'startOfWeek'     => (int) get_option( 'start_of_week', 0 ),
				'destinationsUrl' => admin_url( 'edit.php?post_type=' . CampaignPostType::POST_TYPE . '&page=campaign-bandit-destinations' ),
				// What each starter pattern brings, applied once when it is inserted (decision 0018).
				'patterns'        => (object) StarterPatterns::settings(),
			)
		);
	}

	/**
	 * The theme's own fonts (theme.json), by name and CSS font family, for campaign
	 * styles (FR-27). Only the family is used: no font file is loaded for campaigns.
	 *
	 * @return array<int, array{name: string, fontFamily: string}>
	 */
	private static function theme_fonts(): array {
		$families = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'typography', 'fontFamilies' ) ) : array();
		$out      = array();
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $families[ $origin ] ?? array() ) as $font ) {
				$family = \CampaignBandit\Campaign\CampaignStyles::font( (string) ( $font['fontFamily'] ?? '' ) );
				if ( '' !== $family && ! in_array( $family, array_column( $out, 'fontFamily' ), true ) ) {
					$out[] = array(
						'name'       => (string) ( $font['name'] ?? $family ),
						'fontFamily' => $family,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Whether this install is a hub.
	 */
	public static function is_hub(): bool {
		return Plan::current()->is_hub_plan();
	}

	/**
	 * Slug and label pairs for the page rule pickers (BR-01).
	 *
	 * @param array<string, \WP_Post_Type|\WP_Taxonomy> $objects Post types or taxonomies.
	 * @return array<int, array{slug: string, label: string}>
	 */
	private static function choices( array $objects ): array {
		$out = array();
		foreach ( $objects as $slug => $object ) {
			if ( 'attachment' !== $slug && CampaignPostType::POST_TYPE !== $slug && 'post_format' !== $slug ) {
				$out[] = array(
					'slug'  => (string) $slug,
					'label' => (string) $object->labels->singular_name,
				);
			}
		}
		return $out;
	}

	/**
	 * Locked controls and the plan that unlocks each.
	 *
	 * @return array<string, string>
	 */
	public static function locked_controls(): array {
		/**
		 * Filters which campaign settings are locked for this install's plan.
		 *
		 * @param array<string, string> $locked Control => plan that unlocks it.
		 */
		$locked = apply_filters( 'campaignbandit_locked_controls', self::PAID_CONTROLS );
		return is_array( $locked ) ? $locked : self::PAID_CONTROLS;
	}
}
