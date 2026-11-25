<?php
/**
 * Builds the campaign manifest the visitor runtime reads (decision 0009).
 *
 * WordPress-free: given deployments, their versions and the site's timezone, it
 * produces the JSON-ready array. Only rules go in; never content, destinations or secrets.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Package;

use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Campaign\CampaignStyles;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\Version;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Manifest shape is shared with `js/runtime`: keep both in step.
 */
final class ManifestBuilder {

	public const FORMAT_VERSION = 1;

	/**
	 * Create the builder.
	 *
	 * @param DateTimeZone $site_timezone  Site timezone, for schedules (FR-30).
	 * @param string[]     $default_exclude Site default exclusions (BR-01).
	 */
	public function __construct(
		private readonly DateTimeZone $site_timezone,
		private readonly array $default_exclude,
	) {}

	/**
	 * Build the manifest.
	 *
	 * @param array<int, array{deployment: Deployment, version: Version, name: string}> $entries Live deployments with their versions.
	 * @param int                                                                       $now_ms  Generation time (ms).
	 * @return array<string, mixed>
	 */
	public function build( array $entries, int $now_ms ): array {
		$deployments = array();
		foreach ( $entries as $entry ) {
			if ( Deployment::LIVE !== $entry['deployment']->state ) {
				continue;
			}
			$deployments[] = $this->entry( $entry['deployment'], $entry['version'], $entry['name'] );
		}

		return array(
			'format'      => self::FORMAT_VERSION,
			'generatedAt' => $now_ms,
			'site'        => array(
				'defaultExclude' => array_values( $this->default_exclude ),
				// Days of the week are judged in the site's timezone (BR-08): an IANA name
				// when the site has one, and the current offset as a fallback.
				'timezone'       => $this->site_timezone->getName(),
				'utcOffset'      => intdiv( $this->site_timezone->getOffset( new DateTimeImmutable( '@' . intdiv( $now_ms, 1000 ) ) ), 60 ),
			),
			'deployments' => $deployments,
		);
	}

	/**
	 * Path of a version's content file, relative to the package folder.
	 *
	 * @param Version $version Version.
	 */
	public static function content_path( Version $version ): string {
		return 'c/' . strtolower( $version->campaign_uid ) . '-v' . $version->number . '.json';
	}

	/**
	 * One manifest entry.
	 *
	 * @param Deployment $deployment Deployment.
	 * @param Version    $version    Its version.
	 * @param string     $name       Campaign name (for explanations: "Outranked by <name>").
	 * @return array<string, mixed>
	 */
	private function entry( Deployment $deployment, Version $version, string $name ): array {
		$s     = $version->settings->to_array();
		$rules = $s['rules'];

		return array(
			'campaign'                => $version->campaign_uid,
			'name'                    => $name,
			'version'                 => $version->number,
			'deployment'              => $deployment->id,
			'state'                   => $deployment->state,
			'format'                  => $s['format'],
			'corner'                  => $s['corner'],
			'barPosition'             => $s['bar_position'],
			'width'                   => $s['appearance']['width'],
			'backdrop'                => CampaignSettings::backdrop_css( $s['appearance'] ),
			'edge'                    => $s['appearance']['edge'],
			'position'                => $s['appearance']['position'],
			'animation'               => $s['appearance']['animation'],
			'scrollLock'              => $s['appearance']['scroll_lock'],
			'barOffset'               => $s['appearance']['bar_offset'],
			// Campaign styles (FR-27): CSS variables, or null to inherit the theme.
			'styles'                  => CampaignStyles::variables( $s['styles'] ),
			// A site's brand colour (BR-21, decision 0029): buttons and links, with or
			// without campaign styles. '' when the site sets none.
			'brand'                   => CampaignStyles::color( $s['brand'] ?? '' ),
			'backdropClose'           => $s['backdrop_close'],
			'goal'                    => $s['goal'],
			'offer'                   => $s['offer'],
			// A site's own number for Call and Text us buttons (BR-21); '' keeps the buttons'.
			'phone'                   => $s['phone'],
			'priority'                => $s['priority'],
			'publishedAt'             => self::utc_ms( $deployment->published_at ),
			'triggers'                => array(
				'click'         => $s['triggers']['click'],
				'delaySeconds'  => $s['triggers']['delay_seconds'],
				'scrollPercent' => $s['triggers']['scroll_percent'],
				'exitIntent'    => $s['triggers']['exit_intent'],
				'hover'         => $s['triggers']['hover'],
				'customEvent'   => $s['triggers']['custom_event'],
			),
			'rules'                   => array(
				'defaultExcludes'     => $rules['default_excludes'],
				'hideDuringSchedules' => $rules['hide_during_schedules'],
				'schedules'           => array_map(
					fn ( array $schedule ): array => array(
						'start' => $this->local_ms( $schedule['start'] ),
						'end'   => $this->local_ms( $schedule['end'] ),
						'days'  => $schedule['days'],
					),
					$rules['schedules']
				),
			),
			'condition'               => $s['condition'],
			'frequency'               => $s['frequency'],
			'dismissDays'             => $s['dismiss_days'],
			'suppressAfterConversion' => $s['suppress_after_conversion'],
			'hideAfterSameOffer'      => $s['hide_after_same_offer'],
			'showsResetAt'            => self::utc_ms( $deployment->shows_reset_at ),
			'content'                 => self::content_path( $version ),
		);
	}

	/**
	 * A site-local "Y-m-d H:i" as UTC milliseconds.
	 *
	 * @param string|null $local Local date and time.
	 */
	private function local_ms( ?string $local ): ?int {
		if ( null === $local ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( $local, $this->site_timezone ) )->getTimestamp() * 1000;
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * A UTC "Y-m-d H:i:s" as milliseconds.
	 *
	 * @param string|null $utc UTC date and time.
	 */
	private static function utc_ms( ?string $utc ): ?int {
		if ( null === $utc || '' === $utc ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->getTimestamp() * 1000;
		} catch ( Exception $e ) {
			return null;
		}
	}
}
