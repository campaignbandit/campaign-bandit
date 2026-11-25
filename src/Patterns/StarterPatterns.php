<?php
/**
 * Starter patterns (FR-29, decision 0018): ready-made campaigns to start from.
 *
 * Each starter is a file in a plugin's patterns folder, with its images beside it in
 * patterns/images. The free plugin ships four for its formats, modal and inline
 * (BR-63 tier table); the add-on adds the rest through the
 * `campaignbandit_pattern_sources` filter. A starter also carries the settings it was
 * designed with (format, appearance), which the editor applies once when it is
 * inserted.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Patterns;

use CampaignBandit\Campaign\CampaignPostType;

/**
 * Registers the starter patterns for the campaign editor only.
 */
final class StarterPatterns {

	public const CATEGORY = 'campaign-bandit-starters';

	/**
	 * Parsed starters, by slug.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $loaded = null;

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_patterns' ) );
	}

	/**
	 * Register the category and the patterns.
	 */
	public static function register_patterns(): void {
		register_block_pattern_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Campaign Bandit starters', 'campaign-bandit' ),
				'description' => __( 'Ready-made campaigns to start from.', 'campaign-bandit' ),
			)
		);

		foreach ( self::patterns() as $slug => $pattern ) {
			register_block_pattern(
				'campaign-bandit/' . $slug,
				array(
					'title'       => $pattern['title'],
					'description' => $pattern['description'],
					'content'     => $pattern['content'],
					'categories'  => array( self::CATEGORY ),
					'keywords'    => $pattern['keywords'],
					// Campaigns only, and offered when a new campaign starts (FR-29).
					'postTypes'   => array( CampaignPostType::POST_TYPE ),
					'blockTypes'  => array( 'core/post-content' ),
					'inserter'    => true,
				)
			);
		}
	}

	/**
	 * The settings each starter brings, for the editor (by slug).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		return array_map( static fn ( array $p ): array => $p['settings'], self::patterns() );
	}

	/**
	 * Every starter from every source, by slug.
	 *
	 * @return array<string, array{slug: string, title: string, description: string, keywords: string[], settings: array<string, mixed>, content: string}>
	 */
	public static function patterns(): array {
		if ( null !== self::$loaded ) {
			return self::$loaded;
		}
		$sources = array(
			array(
				'dir' => dirname( CAMPAIGNBANDIT_FILE ) . '/patterns',
				'url' => plugins_url( 'patterns/images', CAMPAIGNBANDIT_FILE ),
			),
		);

		/**
		 * Filters the folders starter patterns are read from. The add-on adds its own.
		 *
		 * @param array<int, array{dir: string, url: string}> $sources Folder path and its images' URL.
		 */
		$sources = (array) apply_filters( 'campaignbandit_pattern_sources', $sources );

		$patterns = array();
		foreach ( $sources as $source ) {
			$files = glob( rtrim( (string) ( $source['dir'] ?? '' ), '/\\' ) . '/*.html' );
			foreach ( false === $files ? array() : $files as $file ) {
				$text    = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the plugin.
				$pattern = false === $text ? null : PatternFile::parse( $text, untrailingslashit( (string) ( $source['url'] ?? '' ) ) );
				if ( null !== $pattern ) {
					$patterns[ $pattern['slug'] ] = $pattern;
				}
			}
		}
		ksort( $patterns );
		self::$loaded = $patterns;
		return $patterns;
	}
}
