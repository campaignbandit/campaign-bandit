<?php
/**
 * Which cookie consent tool this site has (BR-48): the WP Consent API, Complianz,
 * CookieYes or Cookiebot, or none. The runtime reads consent from it in the visitor's
 * browser; the hub shows it for each site.
 *
 * The mapping is WordPress-free and unit-tested; detect() reads this site's plugins.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Privacy;

/**
 * Consent tool detection.
 */
final class ConsentTools {

	/**
	 * Tools by key, with the plugin folders that provide them.
	 */
	public const PLUGINS = array(
		'complianz'      => array( 'complianz-gdpr', 'complianz-gdpr-premium' ),
		'cookieyes'      => array( 'cookie-law-info' ),
		'cookiebot'      => array( 'cookiebot' ),
		'wp_consent_api' => array( 'wp-consent-api' ),
	);

	/**
	 * The tool from the active plugins: a named tool first, then the WP Consent API on
	 * its own, or '' for none.
	 *
	 * @param string[] $active      Active plugin files, such as `cookiebot/cookiebot.php`.
	 * @param bool     $consent_api Whether the WP Consent API is loaded.
	 */
	public static function from_plugins( array $active, bool $consent_api = false ): string {
		$folders = array_map( static fn ( string $file ): string => explode( '/', $file )[0], array_filter( $active, 'is_string' ) );
		foreach ( self::PLUGINS as $tool => $slugs ) {
			if ( array() !== array_intersect( $slugs, $folders ) ) {
				return $tool;
			}
		}
		return $consent_api ? 'wp_consent_api' : '';
	}

	/**
	 * The tool on this site.
	 */
	public static function detect(): string {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return self::from_plugins( $active, function_exists( 'wp_has_consent' ) );
	}

	/**
	 * A tool's name for screens.
	 *
	 * @param string $tool Key.
	 */
	public static function name( string $tool ): string {
		return array(
			'complianz'      => 'Complianz',
			'cookieyes'      => 'CookieYes',
			'cookiebot'      => 'Cookiebot',
			'wp_consent_api' => 'WP Consent API',
		)[ $tool ] ?? '';
	}
}
