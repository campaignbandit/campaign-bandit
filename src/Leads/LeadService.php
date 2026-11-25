<?php
/**
 * Lead capture (FR-23, FR-24, FR-50, BR-40): validate against the published form,
 * apply the spam limits, store on this site, then report success.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Leads;

use CampaignBandit\Forms\FormSchema;
use CampaignBandit\Forms\SubmissionValidator;
use CampaignBandit\Plugin;
use CampaignBandit\Publishing\DeploymentRepository;
use CampaignBandit\Publishing\VersionRepository;
use CampaignBandit\Support\Ulid;
use RuntimeException;
use WP_Error;

/**
 * Captures submissions from the visitor runtime.
 */
final class LeadService {

	public const STORED_ACTION   = 'campaignbandit_lead_stored';
	public const MAX_EXPLANATION = 20000;

	/**
	 * Create the service.
	 *
	 * @param LeadRepository       $leads       Leads.
	 * @param DeploymentRepository $deployments Deployments.
	 * @param VersionRepository    $versions    Versions.
	 */
	public function __construct(
		private readonly LeadRepository $leads,
		private readonly DeploymentRepository $deployments,
		private readonly VersionRepository $versions,
	) {}

	/**
	 * Capture one submission.
	 *
	 * @param array<string, mixed> $submission Campaign, version, fields, hp, elapsed, page_url, utm, explanation.
	 * @param string               $ip         Visitor IP, used only for the rate limit.
	 * @return array{lead: string|null}|WP_Error A lead ID, null when spam was silently dropped, or an error.
	 */
	public function capture( array $submission, string $ip ) {
		$campaign_uid = strtoupper( (string) ( $submission['campaign'] ?? '' ) );
		if ( ! Ulid::is_valid( $campaign_uid ) ) {
			return new WP_Error( 'campaignbandit_bad_request', __( 'This form could not be sent.', 'campaign-bandit' ), array( 'status' => 400 ) );
		}

		// Bots get a normal-looking answer and nothing is stored (BR-46).
		if ( SpamGuard::is_bot( (string) ( $submission['hp'] ?? '' ), (int) ( $submission['elapsed'] ?? 0 ) ) ) {
			SpamGuard::count();
			return array( 'lead' => null );
		}

		$deployment = $this->deployments->find( $campaign_uid, 0 );
		$version    = null === $deployment ? null : $this->versions->find( $deployment->version_id );
		if ( null === $deployment || null === $version || 'live' !== $deployment->state || (int) ( $submission['version'] ?? 0 ) !== $version->number ) {
			return new WP_Error( 'campaignbandit_form_gone', __( 'This form is no longer available. Please reload the page.', 'campaign-bandit' ), array( 'status' => 409 ) );
		}

		if ( ! SpamGuard::within_rate_limit( $ip, $campaign_uid ) ) {
			SpamGuard::count();
			return new WP_Error( 'campaignbandit_rate_limited', __( 'Too many submissions. Please try again in a few minutes.', 'campaign-bandit' ), array( 'status' => 429 ) );
		}

		$schema = FormSchema::from_blocks( parse_blocks( $version->content ) );
		// Date fields are checked against the site's today; Hidden fields take this
		// site's own values when the hub set them (BR-21).
		$result = SubmissionValidator::validate( $schema, is_array( $submission['fields'] ?? null ) ? $submission['fields'] : array(), wp_date( 'Y-m-d' ), (array) $version->settings->get( 'hidden' ) );
		if ( ! $schema->has_form ) {
			return new WP_Error( 'campaignbandit_bad_request', __( 'This form could not be sent.', 'campaign-bandit' ), array( 'status' => 400 ) );
		}
		if ( array() !== $result['errors'] ) {
			return new WP_Error(
				'campaignbandit_invalid',
				__( 'Please check the highlighted fields.', 'campaign-bandit' ),
				array(
					'status' => 400,
					'fields' => $result['errors'],
				)
			);
		}

		$uid = Ulid::generate();
		$now = gmdate( 'Y-m-d H:i:s' );
		try {
			$this->leads->insert(
				array(
					'uid'            => $uid,
					'site_id'        => 0,
					'campaign_uid'   => $campaign_uid,
					'version_number' => $version->number,
					'deployment_id'  => $deployment->id,
					'submitted_at'   => $now,
					'email'          => self::first_of( $schema, $result['fields'], 'email' ),
					'name'           => self::name( $schema, $result['fields'] ),
					'fields'         => (string) wp_json_encode( $result['fields'] ),
					'consent_text'   => $schema->consent_text(),
					// Consent by purpose, from the published version's blocks (BR-44).
					'consent'        => (string) wp_json_encode( (object) $schema->consents( $result['fields'] ) ),
					'page_url'       => self::page_url( $submission['page_url'] ?? '' ),
					'utm'            => (string) wp_json_encode( self::utm( $submission['utm'] ?? array() ) ),
					// Where the visitor came from, as their browser recorded it (BR-47).
					'source'         => (string) wp_json_encode( (object) LeadSource::clean( $submission['source'] ?? null ) ),
					'explanation'    => self::explanation( $submission['explanation'] ?? null ),
					'status'         => 'stored_only',
					'forward_status' => 'none',
					'is_test'        => 0,
					'created_at'     => $now,
				)
			);
		} catch ( RuntimeException $e ) {
			// Not stored: the visitor sees an error and keeps their entries (BR-40).
			return new WP_Error( 'campaignbandit_not_stored', __( 'Your details could not be sent. Please try again.', 'campaign-bandit' ), array( 'status' => 500 ) );
		}//end try

		if ( 'form' === $version->settings->get( 'goal' ) ) {
			Plugin::metrics()->record( $campaign_uid, $version->number, 'conversion', (string) ( $submission['device'] ?? '' ), (string) ( $submission['pageGroup'] ?? '/' ) );
		}

		/**
		 * Fires after a lead is stored on this site, before any forwarding or delivery.
		 *
		 * @param string $uid Lead ULID.
		 */
		do_action( 'campaignbandit_lead_stored', $uid );

		return array( 'lead' => $uid );
	}

	/**
	 * The first value of a field type, as a string.
	 *
	 * @param FormSchema                                    $schema Schema.
	 * @param array<string, string|array<int, string>|bool> $fields Clean values.
	 * @param string                                        $type   Field type.
	 */
	private static function first_of( FormSchema $schema, array $fields, string $type ): ?string {
		foreach ( $schema->fields as $field ) {
			if ( $type === $field['type'] && is_string( $fields[ $field['key'] ] ?? null ) && '' !== $fields[ $field['key'] ] ) {
				return mb_substr( $fields[ $field['key'] ], 0, 191 );
			}
		}
		return null;
	}

	/**
	 * A display name: a text field whose key mentions "name", or the first text field.
	 *
	 * @param FormSchema                                    $schema Schema.
	 * @param array<string, string|array<int, string>|bool> $fields Clean values.
	 */
	private static function name( FormSchema $schema, array $fields ): ?string {
		foreach ( $schema->fields as $field ) {
			if ( 'text' === $field['type'] && str_contains( strtolower( $field['key'] ), 'name' ) && is_string( $fields[ $field['key'] ] ?? null ) && '' !== $fields[ $field['key'] ] ) {
				return mb_substr( $fields[ $field['key'] ], 0, 191 );
			}
		}
		return self::first_of( $schema, $fields, 'text' );
	}

	/**
	 * The page the lead came from: an http(s) URL on this site, without a fragment.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function page_url( $value ): string {
		$url  = is_string( $value ) ? esc_url_raw( strtok( $value, '#' ) ) : '';
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === $host ? substr( $url, 0, 2000 ) : '';
	}

	/**
	 * UTM values from the visit (BR-44).
	 *
	 * @param mixed $value Candidate parameters.
	 * @return array<string, string>
	 */
	private static function utm( $value ): array {
		$out = array();
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $part ) {
			$raw          = is_array( $value ) ? ( $value[ 'utm_' . $part ] ?? $value[ $part ] ?? '' ) : '';
			$out[ $part ] = is_scalar( $raw ) ? sanitize_text_field( mb_substr( (string) $raw, 0, 200 ) ) : '';
		}
		return $out;
	}

	/**
	 * The display explanation from the runtime (BR-14), size-limited.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function explanation( $value ): ?string {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$json = (string) wp_json_encode( $value );
		return strlen( $json ) <= self::MAX_EXPLANATION ? $json : null;
	}
}
