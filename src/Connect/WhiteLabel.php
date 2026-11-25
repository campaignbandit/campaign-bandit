<?php
/**
 * White label on a connected site (FR-72, decision 0031): the agency's name, logo and
 * support URL, sent by the hub and kept with the connection.
 *
 * This lives in the free plugin because the connected site runs the free plugin. It
 * does nothing at all until a hub sends the block.
 *
 * The agency's name replaces Campaign Bandit where the plugin names itself — the admin
 * menu and the Connection panel. It never replaces the words for what things are:
 * "Campaigns" and "Leads" are the glossary's words, not branding.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Connect;

/**
 * Reads and applies a hub's white label.
 */
final class WhiteLabel {

	/** Longest a name may be, so it fits an admin menu. */
	public const MAX_NAME = 40;

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'campaignbandit_menu_name', array( self::class, 'name' ) );
	}

	/**
	 * Whether this site is white labelled.
	 */
	public static function active(): bool {
		return '' !== self::name();
	}

	/**
	 * The agency's name, or '' when the site isn't white labelled.
	 */
	public static function name(): string {
		return (string) ( self::stored()['name'] ?? '' );
	}

	/**
	 * The logo as a URL on this site, or '' when there is none yet. The hub sends the
	 * file; the site copies it into its own uploads, so nothing on screen points at the
	 * hub (decision 0031).
	 */
	public static function logo(): string {
		return (string) ( self::stored()['logo'] ?? '' );
	}

	/**
	 * Where a client is sent for help, or ''.
	 */
	public static function support_url(): string {
		return (string) ( self::stored()['support_url'] ?? '' );
	}

	/**
	 * What the connection holds.
	 *
	 * @return array<string, string>
	 */
	private static function stored(): array {
		$connection = Connection::get();
		$label      = is_array( $connection['white_label'] ?? null ) ? $connection['white_label'] : array();
		return array_map( 'strval', $label );
	}

	/**
	 * Store what a sync sent, or clear it when the hub sends nothing — so turning white
	 * label off, or a plan that no longer includes it, puts a site back to its own
	 * branding within one sync.
	 *
	 * @param mixed  $payload     The payload's `white_label` block, or null.
	 * @param string $logo_url    The logo copied into this site's uploads, or ''.
	 */
	public static function apply( $payload, string $logo_url = '' ): void {
		Connection::update( array( 'white_label' => self::record( $payload, $logo_url ) ) );
	}

	/**
	 * What a site should keep from a `white_label` block, or null to go back to its own
	 * branding. Null covers every way a hub can say "not any more": the block missing,
	 * the switch off, the plan no longer including it, or the name emptied.
	 *
	 * WordPress-free, so the rule is unit-tested.
	 *
	 * @param mixed  $payload  The payload's `white_label` block, or null.
	 * @param string $logo_url The logo copied into this site's uploads, or ''.
	 * @return array{name: string, support_url: string, logo: string}|null
	 */
	public static function record( $payload, string $logo_url = '' ): ?array {
		$name = is_array( $payload ) && is_string( $payload['name'] ?? null ) ? self::clean_name( $payload['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}
		return array(
			'name'        => $name,
			'support_url' => self::clean_url( $payload['support_url'] ?? '' ),
			'logo'        => $logo_url,
		);
	}

	/**
	 * A name as a site keeps it: one line of text, no markup, short enough for a menu.
	 *
	 * @param mixed $raw Candidate.
	 */
	public static function clean_name( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		// Script and style take their contents with them, as wp_strip_all_tags() does;
		// the rest just loses its tags.
		$name = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $raw );
		$name = (string) preg_replace( '/<[^>]*>/', '', $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		return '' === $name ? '' : mb_substr( $name, 0, self::MAX_NAME );
	}

	/**
	 * The logo the hub sent, as a media item to copy, or null.
	 *
	 * @param mixed $payload The payload's `white_label` block.
	 * @return array<string, mixed>|null
	 */
	public static function logo_item( $payload ): ?array {
		if ( ! is_array( $payload ) || ! is_array( $payload['logo'] ?? null ) ) {
			return null;
		}
		return $payload['logo'];
	}

	/**
	 * A support URL, or ''. Only http and https; escaped where it is printed.
	 *
	 * @param mixed $raw Candidate.
	 */
	public static function clean_url( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$url    = trim( $raw );
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-free class; the URL is escaped where it is printed.
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}
}
