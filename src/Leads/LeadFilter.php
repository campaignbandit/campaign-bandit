<?php
/**
 * Filters for the Leads screen and CSV export (FR-54, S6).
 *
 * WordPress-free: it turns request values into a clean filter and SQL conditions,
 * so the rules are unit-tested.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

use CampaignBandit\Support\Ulid;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * A lead filter: status, search, campaign, date range, or one lead.
 */
final class LeadFilter {

	/**
	 * Status filters (S6). Delivered, Retrying and Failed appear in the list only
	 * where webhook delivery is available; Sending counts under Retrying.
	 */
	public const STATUSES = array( 'delivered', 'retrying', 'failed', 'stored_only', 'test' );

	/**
	 * Create a filter. Use from_request() for untrusted input.
	 *
	 * @param string      $status   '' for all, or one of STATUSES.
	 * @param string      $search   Name or email search.
	 * @param string      $campaign Campaign ULID, or ''.
	 * @param string|null $from     First day, "Y-m-d" in site time.
	 * @param string|null $to       Last day, "Y-m-d" in site time.
	 * @param string      $lead     One lead ULID, or ''.
	 * @param int|null    $site     One site (0 for this site), or null for all (hub, S6).
	 * @param int|null    $client   One client, or null for all. The hub turns it into
	 *                              that client's sites; this class only carries it.
	 * @param int[]       $sites    The sites a client filter narrows to, or none.
	 */
	public function __construct(
		public readonly string $status = '',
		public readonly string $search = '',
		public readonly string $campaign = '',
		public readonly ?string $from = null,
		public readonly ?string $to = null,
		public readonly string $lead = '',
		public readonly ?int $site = null,
		public readonly ?int $client = null,
		public readonly array $sites = array(),
	) {}

	/**
	 * Build a filter from request values, dropping anything invalid.
	 *
	 * @param array<string, mixed> $query Request values (already unslashed).
	 */
	public static function from_request( array $query ): self {
		$text = static fn ( string $key ): string => is_string( $query[ $key ] ?? null ) ? trim( $query[ $key ] ) : '';
		$uid  = static fn ( string $key ): string => Ulid::is_valid( strtoupper( $text( $key ) ) ) ? strtoupper( $text( $key ) ) : '';

		return new self(
			in_array( $text( 'status' ), self::STATUSES, true ) ? $text( 'status' ) : '',
			mb_substr( $text( 's' ), 0, 100 ),
			$uid( 'campaign' ),
			self::day( $text( 'from' ) ),
			self::day( $text( 'to' ) ),
			$uid( 'lead' ),
			ctype_digit( $text( 'site' ) ) ? (int) $text( 'site' ) : null,
			ctype_digit( $text( 'client' ) ) && (int) $text( 'client' ) > 0 ? (int) $text( 'client' ) : null,
		);
	}

	/**
	 * The same filter with another status ('' for all).
	 *
	 * @param string $status Status.
	 */
	public function with_status( string $status ): self {
		return new self( $status, $this->search, $this->campaign, $this->from, $this->to, $this->lead, $this->site, $this->client, $this->sites );
	}

	/**
	 * The same filter narrowed to the sites of the client it names (S6, hub). An empty
	 * list means the client has no sites, which matches no lead rather than every one.
	 *
	 * @param int[] $sites Site IDs.
	 */
	public function within_sites( array $sites ): self {
		return new self(
			$this->status,
			$this->search,
			$this->campaign,
			$this->from,
			$this->to,
			$this->lead,
			$this->site,
			$this->client,
			array_values( array_unique( array_map( 'intval', $sites ) ) )
		);
	}

	/**
	 * Whether anything narrows the list.
	 */
	public function is_filtered(): bool {
		return '' !== $this->status || '' !== $this->search || '' !== $this->campaign || null !== $this->from || null !== $this->to || '' !== $this->lead || null !== $this->site || null !== $this->client;
	}

	/**
	 * Values for links and forms, without empty ones.
	 *
	 * @return array<string, string>
	 */
	public function to_query_args(): array {
		return array_filter(
			array(
				'status'   => $this->status,
				's'        => $this->search,
				'campaign' => $this->campaign,
				'from'     => (string) $this->from,
				'to'       => (string) $this->to,
				'lead'     => $this->lead,
				'site'     => null === $this->site ? '' : (string) $this->site,
				'client'   => null === $this->client ? '' : (string) $this->client,
			),
			static fn ( string $value ): bool => '' !== $value
		);
	}

	/**
	 * SQL conditions for `$wpdb->prepare()`, joined with AND. Dates are site days,
	 * converted to the UTC range stored in `submitted_at`.
	 *
	 * @param DateTimeZone $site_timezone Site timezone.
	 * @return array{sql: string, args: array<int, string|int>}
	 */
	public function where( DateTimeZone $site_timezone ): array {
		$sql  = array( '1=1' );
		$args = array();

		if ( 'test' === $this->status ) {
			$sql[] = 'is_test = 1';
		} elseif ( 'retrying' === $this->status ) {
			$sql[] = "status IN ('retrying', 'sending') AND is_test = 0";
		} elseif ( '' !== $this->status ) {
			$sql[]  = 'status = %s AND is_test = 0';
			$args[] = $this->status;
		}
		if ( '' !== $this->search ) {
			$like   = '%' . addcslashes( $this->search, '_%\\' ) . '%';
			$sql[]  = '(email LIKE %s OR name LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}
		if ( '' !== $this->campaign ) {
			$sql[]  = 'campaign_uid = %s';
			$args[] = $this->campaign;
		}
		if ( null !== $this->from ) {
			$sql[]  = 'submitted_at >= %s';
			$args[] = self::utc_start( $this->from, $site_timezone, 0 );
		}
		if ( null !== $this->to ) {
			$sql[]  = 'submitted_at < %s';
			$args[] = self::utc_start( $this->to, $site_timezone, 1 );
		}
		if ( '' !== $this->lead ) {
			$sql[]  = 'uid = %s';
			$args[] = $this->lead;
		}
		if ( null !== $this->site ) {
			$sql[]  = 'site_id = %d';
			$args[] = $this->site;
		}

		// A client filter narrows to that client's sites (S6). No sites: nothing matches.
		if ( null !== $this->client ) {
			if ( array() === $this->sites ) {
				$sql[] = '1=0';
			} else {
				$sql[] = 'site_id IN (' . implode( ', ', array_fill( 0, count( $this->sites ), '%d' ) ) . ')';
				$args  = array_merge( $args, $this->sites );
			}
		}

		return array(
			'sql'  => implode( ' AND ', $sql ),
			'args' => $args,
		);
	}

	/**
	 * A valid "Y-m-d" day, or null.
	 *
	 * @param string $value Candidate.
	 */
	private static function day( string $value ): ?string {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return null;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : null;
	}

	/**
	 * Midnight at the start of a site day (plus some days), as UTC "Y-m-d H:i:s".
	 *
	 * @param string       $day           "Y-m-d".
	 * @param DateTimeZone $site_timezone Site timezone.
	 * @param int          $add_days      Days to add.
	 */
	private static function utc_start( string $day, DateTimeZone $site_timezone, int $add_days ): string {
		try {
			$start = ( new DateTimeImmutable( $day . ' 00:00:00', $site_timezone ) )->modify( '+' . $add_days . ' day' );
		} catch ( Exception $e ) {
			$start = new DateTimeImmutable( '@0' );
		}
		return $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
