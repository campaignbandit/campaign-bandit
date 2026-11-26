<?php
/**
 * Lead records for people (FR-54): listing, detail, export and delete.
 *
 * Shared by the Leads screen, REST and, later, abilities (decision 0004). Each
 * export and deletion is recorded in the audit trail (BR-71).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Campaign\CampaignService;
use CampaignBandit\Forms\FormSchema;
use CampaignBandit\Publishing\VersionRepository;
use DateTimeZone;

/**
 * Lead listing, export and deletion.
 */
final class LeadManager {

	/**
	 * Campaign names by ULID, filled on first use.
	 *
	 * @var array<string, string>
	 */
	private array $names = array();

	/**
	 * Create the manager.
	 *
	 * @param LeadRepository    $leads         Leads.
	 * @param CampaignService   $campaigns     Campaigns, for names.
	 * @param VersionRepository $versions      Versions, for field labels.
	 * @param AuditLog          $audit         Audit trail.
	 * @param DateTimeZone      $site_timezone Site timezone.
	 */
	public function __construct(
		private readonly LeadRepository $leads,
		private readonly CampaignService $campaigns,
		private readonly VersionRepository $versions,
		private readonly AuditLog $audit,
		private readonly DateTimeZone $site_timezone,
	) {}

	/**
	 * One page of leads, the total, and counts per status for the status filter.
	 *
	 * @param LeadFilter $filter   Filter.
	 * @param int        $page     Page, from 1.
	 * @param int        $per_page Leads per page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, counts: array<string, int>}
	 */
	public function page( LeadFilter $filter, int $page, int $per_page ): array {
		$counts = array( '' => $this->leads->count( $filter->with_status( '' ), $this->site_timezone ) );
		foreach ( LeadFilter::STATUSES as $status ) {
			$counts[ $status ] = $this->leads->count( $filter->with_status( $status ), $this->site_timezone );
		}

		return array(
			'rows'   => $this->leads->search( $filter, $this->site_timezone, $per_page, ( max( 1, $page ) - 1 ) * $per_page ),
			'total'  => $counts[ $filter->status ],
			'counts' => $counts,
		);
	}

	/**
	 * One lead.
	 *
	 * @param string $uid Lead ULID.
	 * @return array<string, mixed>|null
	 */
	public function get( string $uid ): ?array {
		return $this->leads->find( $uid );
	}

	/**
	 * The field keys that are consent questions, for a lead's own version.
	 *
	 * The answer to a consent question is kept with the other fields, because that is
	 * what the visitor sent, but it is shown under Consent with its purpose and the
	 * exact text they agreed to (FR-23, BR-44). Listing it again among the fields as a
	 * bare "consent: yes" says less and reads like a second, different answer.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return string[]
	 */
	public function consent_keys( array $lead ): array {
		$keys    = array();
		$version = $this->versions->find_number( (string) $lead['campaign_uid'], (int) $lead['version_number'] );
		if ( null !== $version ) {
			foreach ( FormSchema::from_blocks( parse_blocks( $version->content ) )->fields as $field ) {
				if ( 'consent' === ( $field['type'] ?? '' ) && '' !== $field['key'] ) {
					$keys[] = (string) $field['key'];
				}
			}
		}
		return $keys;
	}

	/**
	 * Labels for a lead's fields, from the form in the version it was submitted to.
	 * Keys without a label (or from a version that no longer exists) map to themselves.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, string>
	 */
	public function field_labels( array $lead ): array {
		$labels  = array();
		$version = $this->versions->find_number( (string) $lead['campaign_uid'], (int) $lead['version_number'] );
		if ( null !== $version ) {
			foreach ( FormSchema::from_blocks( parse_blocks( $version->content ) )->fields as $field ) {
				if ( '' !== $field['key'] && '' !== $field['label'] ) {
					$labels[ $field['key'] ] = $field['label'];
				}
			}
		}
		return $labels;
	}

	/**
	 * A campaign's name, or '' when it no longer exists.
	 *
	 * @param string $uid Campaign ULID.
	 */
	public function campaign_name( string $uid ): string {
		if ( ! isset( $this->names[ $uid ] ) ) {
			$this->names[ $uid ] = $this->campaigns->get( $uid )->name ?? '';
		}
		return $this->names[ $uid ];
	}

	/**
	 * Campaigns that have leads, as ULID => name, for the campaign filter.
	 *
	 * @return array<string, string>
	 */
	public function campaigns_with_leads(): array {
		$out = array();
		foreach ( $this->leads->campaign_uids() as $uid ) {
			$name        = $this->campaign_name( $uid );
			$out[ $uid ] = '' !== $name ? $name : __( '(deleted campaign)', 'campaign-bandit' );
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/**
	 * Write matching leads as CSV and record the export.
	 *
	 * @param LeadFilter $filter  Filter (a lead ID exports one lead).
	 * @param resource   $out     Writable stream.
	 * @param string     $channel Audit channel.
	 * @return int Leads exported.
	 */
	public function export( LeadFilter $filter, $out, string $channel ): int {
		// Two passes: field keys first (only the union is needed), then the rows.
		$keys = LeadCsv::field_keys( $this->leads->each( $filter, $this->site_timezone ) );

		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so spreadsheet apps read accents correctly.
		fputcsv( $out, LeadCsv::header( $keys ), ',', '"', '' );
		$count = 0;
		foreach ( $this->leads->each( $filter, $this->site_timezone ) as $lead ) {
			fputcsv( $out, LeadCsv::row( $lead, $keys, $this->site_timezone, $this->campaign_name( (string) $lead['campaign_uid'] ) ), ',', '"', '' );
			++$count;
		}

		// The trail records what was exported, never the personal data itself.
		$this->audit->record(
			'lead.exported',
			'' !== $filter->lead ? 'lead' : 'leads',
			$filter->lead,
			$channel,
			array(
				'count'   => $count,
				'filters' => $filter->to_query_args(),
			)
		);
		return $count;
	}

	/**
	 * Delete leads past the retention period (BR-50), in batches, and record one
	 * audit event for the run.
	 *
	 * @param int $days        Retention period (0 keeps leads).
	 * @param int $now         Current Unix time.
	 * @param int $max_batches Batches per run; the rest waits for the next run.
	 * @return int Leads deleted.
	 */
	public function delete_expired( int $days, int $now, int $max_batches = 20 ): int {
		$cutoff = Retention::cutoff( $days, $now );
		if ( null === $cutoff ) {
			return 0;
		}

		$deleted = 0;
		for ( $i = 0; $i < $max_batches; $i++ ) {
			$uids     = $this->leads->delete_before( $cutoff, 500 );
			$deleted += count( $uids );
			if ( array() !== $uids ) {
				/**
				 * Fires after leads are deleted for retention. The add-on removes their
				 * delivery logs and copies elsewhere.
				 *
				 * @param string[] $uids Lead ULIDs.
				 */
				do_action( 'campaignbandit_leads_expired', $uids );
			}
			if ( count( $uids ) < 500 ) {
				break;
			}
		}

		if ( $deleted > 0 ) {
			$this->audit->record(
				'lead.retention_deleted',
				'leads',
				'',
				AuditLog::CHANNEL_SYSTEM,
				array(
					'count'  => $deleted,
					'days'   => $days,
					'before' => $cutoff,
				)
			);
		}
		return $deleted;
	}

	/**
	 * Delete a lead permanently and record it.
	 *
	 * @param string $uid     Lead ULID.
	 * @param string $channel Audit channel.
	 * @return bool Whether a lead was deleted.
	 */
	public function delete( string $uid, string $channel ): bool {
		$lead = $this->leads->find( $uid );
		if ( null === $lead || ! $this->leads->delete( $uid ) ) {
			return false;
		}
		$this->audit->record( 'lead.deleted', 'lead', $uid, $channel, array( 'campaign' => $lead['campaign_uid'] ) );

		/**
		 * Fires after a lead is deleted on this site. The add-on removes copies elsewhere.
		 *
		 * @param string $uid Lead ULID.
		 */
		do_action( 'campaignbandit_lead_deleted', $uid );
		return true;
	}
}
