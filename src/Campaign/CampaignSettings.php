<?php
/**
 * A campaign's settings: format, goal, triggers, rules, frequency, suppression, priority.
 *
 * WordPress-free so the defaults and limits from the business rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Campaign;

use CampaignBandit\Notifications\LeadEmailRules;
use CampaignBandit\Support\Ulid;

/**
 * Normalises raw settings (from the editor or a stored version) into a known shape.
 * Unknown or out-of-range values fall back to the defaults below.
 */
final class CampaignSettings {

	public const FORMATS       = array( 'modal', 'slide_in', 'bar', 'inline' );
	public const GOALS         = array( 'form', 'button' );
	public const FREQUENCIES   = array( 'every_view', 'once_per_visit', 'every_n_days' );
	public const DEVICES       = array( 'mobile', 'tablet', 'desktop' );
	public const CORNERS       = array( 'bottom_right', 'bottom_left', 'top_right', 'top_left' );
	public const BAR_POSITIONS = array( 'top', 'bottom' );
	// Where a modal sits on larger screens (S4 Appearance); phones always get the sheet.
	public const MODAL_POSITIONS = array( 'top_left', 'top', 'top_right', 'left', 'center', 'right', 'bottom_left', 'bottom', 'bottom_right' );
	// How it appears; with reduced motion, it appears without any (05 §6).
	public const ANIMATIONS       = array( 'rise', 'fade', 'zoom', 'none' );
	public const MAX_BAR_OFFSET   = 300;
	public const MAX_SCHEDULES    = 10;
	public const MIN_WIDTH        = 240;
	public const MAX_WIDTH        = 1200;
	public const BACKDROP_DEFAULT = 55;
	public const MAX_DESTINATIONS = 10;

	/**
	 * Normalised settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Build from raw values.
	 *
	 * @param array<string, mixed> $raw Raw settings.
	 */
	public function __construct( array $raw = array() ) {
		$this->values = self::normalise( $raw );
	}

	/**
	 * Build from the JSON stored in post meta or a version row.
	 *
	 * @param string $json Stored JSON.
	 */
	public static function from_json( string $json ): self {
		$raw = json_decode( $json, true );
		return new self( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Normalised values.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->values;
	}

	/**
	 * JSON for storage.
	 */
	public function to_json(): string {
		return (string) json_encode( $this->values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free class; values are already normalised.
	}

	/**
	 * Whether the campaign has a way to open. Inline campaigns need none: they show
	 * where they are placed. Every other format needs at least one trigger (FR-31).
	 */
	public function has_trigger(): bool {
		$t = $this->values['triggers'];
		return 'inline' === $this->values['format']
			|| $t['click'] || $t['exit_intent'] || $t['hover'] || '' !== $t['custom_event']
			|| null !== $t['delay_seconds'] || null !== $t['scroll_percent'];
	}

	/**
	 * Read one value.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Apply defaults and limits.
	 *
	 * @param array<string, mixed> $raw Raw settings.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $raw ): array {
		$triggers  = is_array( $raw['triggers'] ?? null ) ? $raw['triggers'] : array();
		$rules     = is_array( $raw['rules'] ?? null ) ? $raw['rules'] : array();
		$frequency = is_array( $raw['frequency'] ?? null ) ? $raw['frequency'] : array();

		$delay  = self::int_or_null( $triggers['delay_seconds'] ?? null, 0, 3600 );
		$scroll = self::int_or_null( $triggers['scroll_percent'] ?? null, 1, 100 );

		return array(
			'format'                    => self::one_of( $raw['format'] ?? null, self::FORMATS, 'modal' ),
			'corner'                    => self::one_of( $raw['corner'] ?? null, self::CORNERS, 'bottom_right' ),
			'bar_position'              => self::one_of( $raw['bar_position'] ?? null, self::BAR_POSITIONS, 'bottom' ),
			'goal'                      => self::one_of( $raw['goal'] ?? null, self::GOALS, 'form' ),
			'offer'                     => is_string( $raw['offer'] ?? null ) ? mb_substr( trim( $raw['offer'] ), 0, 60 ) : '',
			'priority'                  => self::clamp( $raw['priority'] ?? 0, -1000, 1000 ),
			'triggers'                  => array(
				// No default trigger: the editor chooses the triggers (FR-31).
				'click'          => true === ( $triggers['click'] ?? false ),
				'delay_seconds'  => $delay,
				'scroll_percent' => $scroll,
				'exit_intent'    => true === ( $triggers['exit_intent'] ?? false ),
				'hover'          => true === ( $triggers['hover'] ?? false ),
				'custom_event'   => self::event_name( $triggers['custom_event'] ?? null ),
			),
			'rules'                     => array(
				'default_excludes'      => ( $rules['default_excludes'] ?? true ) !== false,
				// Schedules become blackout periods (BR-08).
				'hide_during_schedules' => true === ( $rules['hide_during_schedules'] ?? false ),
				'schedules'             => self::schedules( $rules['schedules'] ?? ( isset( $rules['schedule'] ) ? array( $rules['schedule'] ) : null ) ),
			),
			// Display conditions (BR-06): groups of sentence-style rules.
			'condition'                 => Condition::normalise( $raw['condition'] ?? null ),
			'frequency'                 => array(
				'type' => self::one_of( $frequency['type'] ?? null, self::FREQUENCIES, 'every_n_days' ),
				'days' => self::clamp( $frequency['days'] ?? 7, 1, 365 ),
			),
			'dismiss_days'              => self::clamp( $raw['dismiss_days'] ?? 30, 0, 365 ),
			'suppress_after_conversion' => ( $raw['suppress_after_conversion'] ?? true ) !== false,
			'hide_after_same_offer'     => true === ( $raw['hide_after_same_offer'] ?? false ),
			// Where leads go (FR-52): destination ULIDs; none means stored only.
			'destinations'              => self::ulids( $raw['destinations'] ?? array(), self::MAX_DESTINATIONS ),
			// Whether a click on a modal's backdrop closes it (FR-22): yes on computers and
			// tablets, no on phones, where taps there are often accidental.
			'backdrop_close'            => array(
				'desktop' => ( ( is_array( $raw['backdrop_close'] ?? null ) ? $raw['backdrop_close'] : array() )['desktop'] ?? true ) !== false,
				'mobile'  => true === ( ( is_array( $raw['backdrop_close'] ?? null ) ? $raw['backdrop_close'] : array() )['mobile'] ?? false ),
			),
			// A site's own Hidden field values (BR-21), by field key.
			'hidden'                    => self::hidden_values( $raw['hidden'] ?? null ),
			// A site's own number for Call and Text us buttons (BR-21); '' uses the buttons' own.
			'phone'                     => ButtonActions::phone( $raw['phone'] ?? '' ),
			// Who gets the new-lead email (FR-56): the site's setting, these addresses, or nobody.
			'notify'                    => LeadEmailRules::campaign( $raw['notify'] ?? null ),
			'appearance'                => self::appearance( $raw['appearance'] ?? null ),
			// Copy variables (FR-26, decision 0028): `{{name}}` in text, with a default
			// the campaign sets and a value each site can override (BR-21).
			'variables'                 => CopyVariables::definitions( $raw['variables'] ?? null ),
			// Override-only (BR-21, decision 0029): one accent colour for a site's
			// buttons and links, and that site's own words for the campaign's text.
			'brand'                     => CampaignStyles::color( $raw['brand'] ?? '' ),
			'text'                      => LanguageCopy::normalise( $raw['text'] ?? null ),
			// Campaign styles (FR-27, decision 0025): off inherits each site's theme.
			'styles'                    => CampaignStyles::normalise( $raw['styles'] ?? null ),
			// The starter pattern the campaign began from, so its defaults apply once.
			'pattern'                   => is_string( $raw['pattern'] ?? null ) && 1 === preg_match( '/^[a-z0-9-]{1,64}$/', $raw['pattern'] ) ? $raw['pattern'] : '',
		);
	}

	/**
	 * Hidden field values by field key (BR-21): valid keys, values up to 200 characters.
	 *
	 * @param mixed $raw Candidate.
	 * @return array<string, string>
	 */
	public static function hidden_values( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $value ) {
			if ( is_string( $key ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key ) && is_scalar( $value ) && count( $out ) < 20 ) {
				$out[ $key ] = mb_substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $value ) ), 0, 200 );
			}
		}
		return $out;
	}

	/**
	 * The frame around the content: width, backdrop and padding. A width of null is the
	 * format's own; the backdrop is a flat colour at an opacity (no gradients).
	 *
	 * @param mixed $raw Raw appearance.
	 * @return array{width: ?int, backdrop: string, backdrop_opacity: int, edge: bool, position: string, animation: string, scroll_lock: bool, bar_offset: int}
	 */
	private static function appearance( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$color = is_string( $raw['backdrop'] ?? null ) ? strtolower( trim( $raw['backdrop'] ) ) : '';
		return array(
			'width'            => self::int_or_null( $raw['width'] ?? null, self::MIN_WIDTH, self::MAX_WIDTH ),
			'backdrop'         => 1 === preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : '',
			'backdrop_opacity' => self::clamp( $raw['backdrop_opacity'] ?? self::BACKDROP_DEFAULT, 0, 90 ),
			// Content runs to the frame's edges: for layouts with images that bleed.
			'edge'             => true === ( $raw['edge'] ?? false ),
			'position'         => self::one_of( $raw['position'] ?? null, self::MODAL_POSITIONS, 'center' ),
			'animation'        => self::one_of( $raw['animation'] ?? null, self::ANIMATIONS, 'rise' ),
			// A modal stops the page behind it scrolling while it is open.
			'scroll_lock'      => true === ( $raw['scroll_lock'] ?? false ),
			// A top bar moves this far down, below a sticky site header (FR-22).
			'bar_offset'       => self::clamp( $raw['bar_offset'] ?? 0, 0, self::MAX_BAR_OFFSET ),
		);
	}

	/**
	 * The backdrop as a CSS colour, for the runtime.
	 *
	 * @param array{backdrop: string, backdrop_opacity: int} $appearance Appearance.
	 */
	public static function backdrop_css( array $appearance ): string {
		$hex = '' !== $appearance['backdrop'] ? $appearance['backdrop'] : '#000000';
		return sprintf(
			'rgba(%d,%d,%d,%s)',
			hexdec( substr( $hex, 1, 2 ) ),
			hexdec( substr( $hex, 3, 2 ) ),
			hexdec( substr( $hex, 5, 2 ) ),
			rtrim( rtrim( number_format( $appearance['backdrop_opacity'] / 100, 2, '.', '' ), '0' ), '.' )
		);
	}

	/**
	 * Unique upper-case ULIDs from a list, up to a limit.
	 *
	 * @param mixed $value Candidate list.
	 * @param int   $limit Most kept.
	 * @return string[]
	 */
	private static function ulids( $value, int $limit ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			$uid = is_string( $item ) ? strtoupper( trim( $item ) ) : '';
			if ( Ulid::is_valid( $uid ) && ! in_array( $uid, $out, true ) ) {
				$out[] = $uid;
			}
		}
		return array_slice( $out, 0, $limit );
	}

	/**
	 * A value from an allowed list, or the default.
	 *
	 * @param mixed    $value   Candidate.
	 * @param string[] $allowed Allowed values.
	 * @param string   $fallback Default.
	 */
	private static function one_of( $value, array $allowed, string $fallback ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * An integer clamped to a range.
	 *
	 * @param mixed $value Candidate.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 */
	private static function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, is_numeric( $value ) ? (int) $value : $min ) );
	}

	/**
	 * An integer in range, or null when empty or out of range.
	 *
	 * @param mixed $value Candidate.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 */
	private static function int_or_null( $value, int $min, int $max ): ?int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int >= $min && $int <= $max ? $int : null;
	}

	/**
	 * A custom event name a trigger listens for (FR-31), or ''.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function event_name( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,80}$/', trim( $value ) ) ? trim( $value ) : '';
	}

	/**
	 * Schedules in the site's timezone, as local "Y-m-d H:i" strings (BR-08). The
	 * campaign can show while any one applies.
	 *
	 * @param mixed $value Candidate list.
	 * @return array<int, array{start: string|null, end: string|null, days: int[]}>
	 */
	private static function schedules( $value ): array {
		$out = array();
		foreach ( array_slice( is_array( $value ) ? array_values( $value ) : array(), 0, self::MAX_SCHEDULES ) as $schedule ) {
			$clean = is_array( $schedule ) ? self::schedule( $schedule ) : null;
			if ( null !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * One schedule (BR-08): optional start and end in site time, optional days of the
	 * week (0 = Sunday). A schedule that limits nothing is dropped.
	 *
	 * @param array<mixed> $value Candidate.
	 * @return array{start: string|null, end: string|null, days: int[]}|null
	 */
	private static function schedule( array $value ): ?array {
		$start = self::local_datetime( $value['start'] ?? null );
		$end   = self::local_datetime( $value['end'] ?? null );
		$days  = array();
		foreach ( (array) ( $value['days'] ?? array() ) as $day ) {
			if ( is_int( $day ) && $day >= 0 && $day <= 6 ) {
				$days[ $day ] = $day;
			}
		}
		ksort( $days );
		$days = array_values( $days );
		// All seven days is the same as every day.
		if ( 7 === count( $days ) ) {
			$days = array();
		}
		if ( null === $start && null === $end && array() === $days ) {
			return null;
		}
		return array(
			'start' => $start,
			'end'   => $end,
			'days'  => $days,
		);
	}

	/**
	 * A "Y-m-d H:i" local date and time, or null.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function local_datetime( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = str_replace( 'T', ' ', trim( $value ) );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', substr( $value, 0, 16 ) ) ? substr( $value, 0, 16 ) : null;
	}
}
