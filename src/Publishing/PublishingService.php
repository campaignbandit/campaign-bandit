<?php
/**
 * Publishing on this install: turn the draft into the next version and make it live,
 * and switch a live campaign off and on (BR-20, FR-40, FR-43).
 *
 * Shared by the editor's REST endpoints and abilities (decision 0004).
 * Publishing to connected sites arrives with the hub (M3).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Publishing;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignService;
use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Conditions\SavedConditions;
use CampaignBandit\Conditions\SavedConditionStore;
use CampaignBandit\Campaign\PrePublishChecks;
use CampaignBandit\Forms\FormSchema;
use CampaignBandit\Security\Capabilities;
use RuntimeException;
use WP_Error;

/**
 * Publishes, pauses and resumes campaigns on this install (site 0).
 */
final class PublishingService {

	/**
	 * Fired after any deployment on this install changes, so the campaign package is rewritten.
	 */
	public const CHANGED_ACTION = 'campaignbandit_deployments_changed';

	/**
	 * Create the service.
	 *
	 * @param CampaignService      $campaigns   Campaigns.
	 * @param VersionRepository    $versions    Versions.
	 * @param DeploymentRepository $deployments Deployments.
	 * @param AuditLog             $audit       Audit trail.
	 */
	public function __construct(
		private readonly CampaignService $campaigns,
		private readonly VersionRepository $versions,
		private readonly DeploymentRepository $deployments,
		private readonly AuditLog $audit,
	) {}

	/**
	 * Publish the campaign's draft as its next version, live on this install.
	 *
	 * @param string $uid            Campaign ULID.
	 * @param bool   $reset_shows    "Show again to visitors who already saw it" (BR-11).
	 * @param string $change_summary What changed, for the version history (FR-40).
	 * @param string $channel        Audit channel.
	 * @return Deployment|WP_Error
	 */
	public function publish( string $uid, bool $reset_shows = false, string $change_summary = '', string $channel = AuditLog::CHANNEL_SCREEN ) {
		$version = $this->create_version( $uid, $change_summary );
		if ( $version instanceof WP_Error ) {
			return $version;
		}
		try {
			$deployment = $this->deployments->deploy( $version, 0, $reset_shows );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'campaignbandit_publish_failed', __( 'The campaign could not be published. Try again.', 'campaign-bandit' ), array( 'status' => 500 ) );
		}

		$this->audit->record(
			'campaign.published',
			'campaign',
			$uid,
			$channel,
			array(
				'version'     => $version->number,
				'reset_shows' => $reset_shows,
			)
		);
		do_action( 'campaignbandit_deployments_changed' );

		return $deployment;
	}

	/**
	 * Turn the campaign's draft into its next version without deploying it anywhere
	 * (BR-20). The hub then deploys it to the sites the user chose (FR-41).
	 *
	 * @param string $uid            Campaign ULID.
	 * @param string $change_summary What changed (FR-40).
	 * @return Version|WP_Error
	 */
	public function create_version( string $uid, string $change_summary = '' ) {
		$campaign = $this->guard( $uid );
		if ( $campaign instanceof WP_Error ) {
			return $campaign;
		}
		$post = get_post( $campaign->post_id );
		if ( null === $post ) {
			return new WP_Error( 'campaignbandit_not_found', __( 'No campaign has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}

		/**
		 * Filters whether a new version may be made now. The add-on refuses while a
		 * licence has run out (BR-60): what is live keeps running, and rollback, pause
		 * and resume still work, because none of those is a new version.
		 *
		 * @param WP_Error|null $refusal Why not, or null to go ahead.
		 * @param string        $uid     Campaign ULID.
		 */
		$refusal = apply_filters( 'campaignbandit_version_refusal', null, $uid );
		if ( $refusal instanceof WP_Error ) {
			return $refusal;
		}

		$settings = CampaignSettings::from_json( (string) get_post_meta( $post->ID, CampaignPostType::META_SETTINGS, true ) );
		$checks   = PrePublishChecks::run( parse_blocks( $post->post_content ), $settings, array( self::class, 'measure_image' ), self::palette() );
		foreach ( $checks as $check ) {
			// The first error blocks the publish (FR-32); warnings never do.
			if ( PrePublishChecks::ERROR === $check['severity'] ) {
				return new WP_Error(
					'campaignbandit_' . $check['id'],
					self::check_message( $check['id'], $check['message'], $check['args'] ?? array() ),
					array(
						'status' => 400,
						'checks' => $checks,
					)
				);
			}
		}
		$settings = self::with_saved_conditions( $settings );
		$user     = wp_get_current_user();
		try {
			return $this->versions->insert_next( $uid, $post->post_content, $settings, $change_summary, $user->ID, $user->display_name );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'campaignbandit_publish_failed', __( 'The campaign could not be published. Try again.', 'campaign-bandit' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * What the draft changes compared with the latest version: content, form and rules
	 * (S5 "What changed"). Empty when the draft matches it; null when there is no version yet.
	 *
	 * @param string $uid Campaign ULID.
	 * @return string[]|null Any of 'content', 'form', 'rules'.
	 */
	public function draft_changes( string $uid ): ?array {
		$campaign = $this->campaigns->get( $uid );
		$latest   = $this->versions->find_number( $uid, $this->versions->latest_number( $uid ) );
		$post     = null === $campaign ? null : get_post( $campaign->post_id );
		if ( null === $latest || null === $post ) {
			return null;
		}
		$settings    = CampaignSettings::from_json( (string) get_post_meta( $post->ID, CampaignPostType::META_SETTINGS, true ) );
		$changes     = array();
		$draft_form  = FormSchema::from_blocks( parse_blocks( $post->post_content ) );
		$latest_form = FormSchema::from_blocks( parse_blocks( $latest->content ) );
		if ( $post->post_content !== $latest->content ) {
			$changes[] = 'content';
		}
		if ( wp_json_encode( $draft_form->fields ) !== wp_json_encode( $latest_form->fields ) ) {
			$changes[] = 'form';
		}
		if ( $settings->to_json() !== $latest->settings->to_json() ) {
			$changes[] = 'rules';
		}
		return $changes;
	}

	/**
	 * Switch a live campaign off on this install (Free) or pause it (Pro and above).
	 *
	 * @param string $uid     Campaign ULID.
	 * @param string $channel Audit channel.
	 * @return Deployment|WP_Error
	 */
	public function switch_off( string $uid, string $channel = AuditLog::CHANNEL_SCREEN ) {
		return $this->change_state( $uid, Deployment::PAUSED, 'campaign.paused', $channel );
	}

	/**
	 * Switch a paused campaign back on.
	 *
	 * @param string $uid     Campaign ULID.
	 * @param string $channel Audit channel.
	 * @return Deployment|WP_Error
	 */
	public function switch_on( string $uid, string $channel = AuditLog::CHANNEL_SCREEN ) {
		return $this->change_state( $uid, Deployment::LIVE, 'campaign.resumed', $channel );
	}

	/**
	 * Take a deleted campaign off every site. Runs when its post is deleted, so a
	 * campaign that no longer exists can never keep showing.
	 *
	 * @param string $uid Campaign ULID.
	 */
	public function remove_deleted( string $uid ): void {
		if ( $this->deployments->remove_campaign( $uid ) > 0 ) {
			$this->audit->record( 'campaign.deleted', 'campaign', $uid, AuditLog::CHANNEL_SCREEN );
			do_action( 'campaignbandit_deployments_changed' );
		}
	}

	/**
	 * Change the state of this install's deployment.
	 *
	 * @param string $uid     Campaign ULID.
	 * @param string $state   New state.
	 * @param string $action  Audit action.
	 * @param string $channel Audit channel.
	 * @return Deployment|WP_Error
	 */
	private function change_state( string $uid, string $state, string $action, string $channel ) {
		$campaign = $this->guard( $uid );
		if ( $campaign instanceof WP_Error ) {
			return $campaign;
		}
		$deployment = $this->deployments->find( $uid, 0 );
		if ( null === $deployment ) {
			return new WP_Error( 'campaignbandit_not_published', __( 'This campaign has not been published yet.', 'campaign-bandit' ), array( 'status' => 409 ) );
		}
		if ( $deployment->state !== $state ) {
			$this->deployments->set_state( $deployment, $state );
			$this->audit->record( $action, 'campaign', $uid, $channel, array( 'version' => $deployment->version_number ) );
			do_action( 'campaignbandit_deployments_changed' );
		}
		return $this->deployments->find( $uid, 0 ) ?? $deployment;
	}

	/**
	 * Settings with each saved condition's current rules copied in (BR-07): the copy in a
	 * version is what runs, so later edits to the library wait for the next publish.
	 *
	 * @param CampaignSettings $settings Draft settings.
	 */
	public static function with_saved_conditions( CampaignSettings $settings ): CampaignSettings {
		$array     = $settings->to_array();
		$condition = (array) ( $array['condition'] ?? array() );
		if ( array() === SavedConditions::ids( $condition ) ) {
			return $settings;
		}
		$array['condition'] = SavedConditions::refresh( $condition, ( new SavedConditionStore() )->library() );
		return new CampaignSettings( $array );
	}

	/**
	 * Pre-publish checks for a campaign's current draft (FR-32), for the editor.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return array<int, array{id: string, severity: string, message: string}>
	 */
	public function checks( int $post_id ): array {
		$post     = get_post( $post_id );
		$settings = CampaignSettings::from_json( (string) get_post_meta( $post_id, CampaignPostType::META_SETTINGS, true ) );
		$checks   = PrePublishChecks::run( null === $post ? array() : parse_blocks( $post->post_content ), $settings, array( self::class, 'measure_image' ), self::palette() );
		foreach ( $checks as &$check ) {
			$check['message'] = self::check_message( $check['id'], $check['message'], $check['args'] ?? array() );
		}
		return $checks;
	}

	/**
	 * Translated message for a check.
	 *
	 * @param string                       $id       Check ID.
	 * @param string                       $fallback Untranslated message (holds details such as block names).
	 * @param array<int, string|int|float> $args     Details for checks that have them.
	 */
	private static function check_message( string $id, string $fallback, array $args = array() ): string {
		return match ( (string) preg_replace( '/-\d+$/', '', $id ) ) {
			'empty'               => __( 'Add some content.', 'campaign-bandit' ),
			'no_trigger'          => __( 'Choose a trigger, such as on click, after a delay or on scroll.', 'campaign-bandit' ),
			/* translators: 1: image file name, 2: size in KB. */
			'image_heavy'         => sprintf( __( 'Image %1$s is %2$s KB. Keep each image under 200 KB, so the page stays fast.', 'campaign-bandit' ), (string) ( $args[0] ?? '' ), number_format_i18n( (int) ( $args[1] ?? 0 ) ) ),
			/* translators: %s: size in KB. */
			'image_total'         => sprintf( __( 'The campaign’s images weigh %s KB together. Keep them under 500 KB.', 'campaign-bandit' ), number_format_i18n( (int) ( $args[0] ?? 0 ) ) ),
			/* translators: 1: block name such as "Paragraph", 2: contrast ratio such as "2.1", 3: ratio needed such as "4.5". */
			'contrast'            => sprintf( __( 'Text in a %1$s block is hard to read: %2$s:1 against its background (needs %3$s:1).', 'campaign-bandit' ), (string) ( $args[0] ?? '' ), number_format_i18n( (float) ( $args[1] ?? 0 ), 1 ), number_format_i18n( (float) ( $args[2] ?? 0 ), 1 ) ),
			/* translators: %s: the tokens, such as "{{city}}, {{offer}}". */
			'undefined_variables' => sprintf( __( 'The text uses %s, which this campaign doesn’t define. Visitors will see it as written.', 'campaign-bandit' ), (string) ( $args[0] ?? '' ) ),
			default               => $fallback,
		};
	}

	/**
	 * Measure an image a campaign uses (BR-25): the file at its URL when it is in this
	 * site's uploads (the size that shows, not the original), else the attachment's file.
	 * Null for images elsewhere.
	 *
	 * @param int    $id  Attachment ID, or 0.
	 * @param string $url Image URL, or ''.
	 * @return array{name: string, bytes: int}|null
	 */
	public static function measure_image( int $id, string $url ): ?array {
		$path    = '';
		$uploads = wp_get_upload_dir();
		$clean   = (string) strtok( $url, '?#' );
		if ( '' !== $clean && str_starts_with( set_url_scheme( $clean, 'http' ), set_url_scheme( (string) $uploads['baseurl'], 'http' ) . '/' ) ) {
			$relative = substr( set_url_scheme( $clean, 'http' ), strlen( set_url_scheme( (string) $uploads['baseurl'], 'http' ) ) );
			// No way out of the uploads folder.
			if ( ! str_contains( $relative, '..' ) ) {
				$path = (string) $uploads['basedir'] . rawurldecode( $relative );
			}
		}
		if ( ( '' === $path || ! is_file( $path ) ) && $id > 0 ) {
			$path = (string) get_attached_file( $id );
		}
		if ( '' === $path || ! is_file( $path ) ) {
			return null;
		}
		$bytes = filesize( $path );
		return false === $bytes ? null : array(
			'name'  => wp_basename( $path ),
			'bytes' => (int) $bytes,
		);
	}

	/**
	 * The theme's colours by slug, for the contrast check (default, theme and custom
	 * palettes; later ones win).
	 *
	 * @return array<string, string>
	 */
	public static function palette(): array {
		$out     = array();
		$palette = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'color', 'palette' ) ) : array();
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $palette[ $origin ] ?? array() ) as $colour ) {
				if ( is_array( $colour ) && is_string( $colour['slug'] ?? null ) && is_string( $colour['color'] ?? null ) ) {
					$out[ $colour['slug'] ] = $colour['color'];
				}
			}
		}
		return $out;
	}

	/**
	 * Check the user may publish and the campaign exists.
	 *
	 * @param string $uid Campaign ULID.
	 * @return \CampaignBandit\Campaign\Campaign|WP_Error
	 */
	private function guard( string $uid ) {
		if ( ! current_user_can( Capabilities::PUBLISH_CAMPAIGNS ) ) {
			return new WP_Error( 'campaignbandit_forbidden', __( 'You are not allowed to publish campaigns.', 'campaign-bandit' ), array( 'status' => 403 ) );
		}
		$campaign = $this->campaigns->get( $uid );
		if ( null === $campaign ) {
			return new WP_Error( 'campaignbandit_not_found', __( 'No campaign has that ID.', 'campaign-bandit' ), array( 'status' => 404 ) );
		}
		if ( $campaign->archived ) {
			return new WP_Error( 'campaignbandit_archived', __( 'This campaign is archived. Restore it before publishing it.', 'campaign-bandit' ), array( 'status' => 409 ) );
		}
		return $campaign;
	}
}
