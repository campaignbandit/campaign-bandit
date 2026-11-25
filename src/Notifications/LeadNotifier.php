<?php
/**
 * Sends the new-lead email (FR-56) after the visitor's request has finished.
 *
 * Who sends: the install that stored the lead, except a connected site with a hub
 * campaign's lead, which the hub sends once the lead reaches it (the add-on queues it).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Notifications;

use CampaignBandit\Admin\LeadsScreen;
use CampaignBandit\Connect\Connection;
use CampaignBandit\Connect\PackageApplier;
use CampaignBandit\Leads\Consents;
use CampaignBandit\Leads\LeadCsv;
use CampaignBandit\Leads\LeadRepository;
use CampaignBandit\Leads\LeadSource;
use CampaignBandit\Plugin;
use CampaignBandit\Publishing\VersionRepository;
use CampaignBandit\Settings\LeadEmailSettings;
use WP_Error;

/**
 * New-lead emails.
 */
final class LeadNotifier {

	public const SENT   = 'sent';
	public const FAILED = 'failed';

	/**
	 * Leads waiting to be emailed this request: ULID => a hub site's own addresses.
	 *
	 * @var array<string, string[]>
	 */
	private static array $queue = array();

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'campaignbandit_lead_stored', array( self::class, 'stored' ) );
	}

	/**
	 * A lead was stored on this install.
	 *
	 * @param string $uid Lead ULID.
	 */
	public static function stored( string $uid ): void {
		$lead = ( new LeadRepository() )->find( $uid );
		if ( null === $lead ) {
			return;
		}
		// On a connected site, the hub emails about its own campaigns' leads.
		if ( null !== Connection::get() && PackageApplier::is_hub_campaign( (string) $lead['campaign_uid'] ) ) {
			return;
		}
		self::queue( $uid );
	}

	/**
	 * Email about a lead once the response has gone.
	 *
	 * @param string   $uid      Lead ULID.
	 * @param string[] $override A hub site's own addresses, or [].
	 */
	public static function queue( string $uid, array $override = array() ): void {
		if ( array() === self::$queue ) {
			add_action( 'shutdown', array( self::class, 'flush' ), 5 );
		}
		self::$queue[ $uid ] = $override;
	}

	/**
	 * Send the queued emails, after the response where the server allows it.
	 */
	public static function flush(): void {
		$queue       = self::$queue;
		self::$queue = array();
		if ( array() === $queue ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		foreach ( $queue as $uid => $override ) {
			self::send( (string) $uid, $override );
		}
	}

	/**
	 * Email about one lead now.
	 *
	 * @param string   $uid      Lead ULID.
	 * @param string[] $override A hub site's own addresses, or [].
	 * @return string|null sent or failed, or null when nobody gets it.
	 */
	public static function send( string $uid, array $override = array() ): ?string {
		$repo = new LeadRepository();
		$lead = $repo->find( $uid );
		if ( null === $lead || ! empty( $lead['is_test'] ) ) {
			return null;
		}
		$version = ( new VersionRepository() )->find_number( (string) $lead['campaign_uid'], (int) $lead['version_number'] );
		$notify  = null === $version ? array() : (array) $version->settings->get( 'notify' );
		$to      = LeadEmailRules::recipients( LeadEmailSettings::enabled(), LeadEmailSettings::addresses(), $notify, $override );
		if ( array() === $to ) {
			return null;
		}
		$email  = LeadEmail::build( self::view( $lead ), self::strings() );
		$status = self::mail( $to, $email ) ? self::SENT : self::FAILED;
		$repo->set_notified( $uid, $status );
		return $status;
	}

	/**
	 * Send a sample to the addresses in Settings (Settings › Send test email).
	 *
	 * @return true|WP_Error
	 */
	public static function test() {
		$to = LeadEmailSettings::addresses();
		if ( array() === $to ) {
			return new WP_Error( 'campaignbandit_no_addresses', __( 'Add at least one address first.', 'campaign-bandit' ) );
		}
		$email            = LeadEmail::build(
			array(
				'campaign'  => __( 'Sample campaign', 'campaign-bandit' ),
				'site'      => (string) get_bloginfo( 'name' ),
				'host'      => self::host( home_url() ),
				'client'    => '',
				'name'      => 'Ada Lovelace',
				'fields'    => array(
					array( __( 'Name', 'campaign-bandit' ), 'Ada Lovelace' ),
					array( __( 'Email', 'campaign-bandit' ), 'ada@example.com' ),
				),
				'consents'  => array( array( __( 'Contact about this request', 'campaign-bandit' ), true, __( 'I agree to be contacted about my request.', 'campaign-bandit' ) ) ),
				'page'      => '/',
				'submitted' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				'first'     => 'google / cpc',
				'last'      => '(direct)',
				'url'       => LeadsScreen::url(),
				'reply_to'  => '',
			),
			self::strings()
		);
		$email['subject'] = '[' . __( 'Test', 'campaign-bandit' ) . '] ' . $email['subject'];
		return self::mail( $to, $email ) ? true : new WP_Error( 'campaignbandit_mail_failed', self::failure_message() );
	}

	/**
	 * The lead, ready to show in the email.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array<string, mixed>
	 */
	private static function view( array $lead ): array {
		$manager = Plugin::lead_manager();
		$labels  = $manager->field_labels( $lead );
		$fields  = array();
		foreach ( (array) json_decode( (string) $lead['fields'], true ) as $key => $value ) {
			if ( is_bool( $value ) ) {
				continue;
				// Consent answers have their own section.
			}
			$shown    = LeadCsv::value( $value );
			$fields[] = array( $labels[ $key ] ?? (string) $key, '' === $shown ? '—' : $shown );
		}
		$names    = array(
			'contact' => __( 'Contact about this request', 'campaign-bandit' ),
			'email'   => __( 'Email marketing', 'campaign-bandit' ),
			'sms'     => __( 'Text messages', 'campaign-bandit' ),
		);
		$consents = array();
		foreach ( Consents::for_lead( $lead ) as $purpose => $consent ) {
			$consents[] = array( $names[ $purpose ] ?? $purpose, $consent['given'], $consent['text'] );
		}
		$source = LeadSource::from_json( $lead['source'] ?? null );
		$site   = array(
			'name' => (string) get_bloginfo( 'name' ),
			'host' => self::host( home_url() ),
		);
		/**
		 * Filters the site a new-lead email names. On a hub, a forwarded lead names its
		 * client site, and the client.
		 *
		 * @param array{name: string, host: string, client?: string} $site Site.
		 * @param array<string, mixed>                               $lead Lead row.
		 */
		$site = (array) apply_filters( 'campaignbandit_lead_email_site', $site, $lead );

		return array(
			'campaign'  => $manager->campaign_name( (string) $lead['campaign_uid'] ),
			'site'      => (string) ( $site['name'] ?? '' ),
			'host'      => (string) ( $site['host'] ?? '' ),
			'client'    => (string) ( $site['client'] ?? '' ),
			'name'      => (string) ( $lead['name'] ?? '' ),
			'fields'    => $fields,
			'consents'  => $consents,
			'page'      => (string) wp_parse_url( (string) $lead['page_url'], PHP_URL_PATH ),
			'submitted' => mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), LeadCsv::site_time( (string) $lead['submitted_at'], wp_timezone() ) ),
			'first'     => array() === $source || $source['first'] === $source['last'] ? '' : LeadSource::label( $source['first'] ),
			'last'      => array() === $source ? '' : LeadSource::label( $source['last'] ),
			'url'       => LeadsScreen::url( array( 'lead' => (string) $lead['uid'] ) ),
			'reply_to'  => (string) ( $lead['email'] ?? '' ),
		);
	}

	/**
	 * The email's words, translated.
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return array(
			/* translators: 1: campaign name, 2: site name. */
			'subject'   => __( 'New lead: %1$s · %2$s', 'campaign-bandit' ),
			/* translators: %s: campaign name. */
			'intro'     => __( 'New lead from %s', 'campaign-bandit' ),
			'consent'   => __( 'Consent', 'campaign-bandit' ),
			'given'     => __( 'Yes', 'campaign-bandit' ),
			'not_given' => __( 'No', 'campaign-bandit' ),
			'page'      => __( 'Page', 'campaign-bandit' ),
			'submitted' => __( 'Submitted', 'campaign-bandit' ),
			'first'     => __( 'First visit', 'campaign-bandit' ),
			'this'      => __( 'This visit', 'campaign-bandit' ),
			'open'      => __( 'Open lead', 'campaign-bandit' ),
			/* translators: %s: the lead's name or email address. */
			'reply'     => __( 'Reply to this email to answer %s directly.', 'campaign-bandit' ),
			'no_email'  => __( 'This lead left no email address.', 'campaign-bandit' ),
			/* translators: %s: site address. */
			'sent_by'   => __( 'Sent by Campaign Bandit on %s.', 'campaign-bandit' ),
			'change'    => __( 'Change who gets these emails in Campaign Bandit › Settings.', 'campaign-bandit' ),
		);
	}

	/**
	 * Send through wp_mail: HTML with a plain-text part, Reply-To the lead.
	 *
	 * @param string[]                                                             $to    Addresses.
	 * @param array{subject: string, html: string, text: string, reply_to: string} $email Email.
	 */
	private static function mail( array $to, array $email ): bool {
		$error   = null;
		$catch   = static function ( WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};
		$plain   = static function ( $mailer ) use ( $email ): void {
			$mailer->AltBody = $email['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
		};
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $email['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $email['reply_to'];
		}
		add_action( 'wp_mail_failed', $catch );
		add_action( 'phpmailer_init', $plain );
		$sent = wp_mail( $to, $email['subject'], $email['html'], $headers );
		remove_action( 'phpmailer_init', $plain );
		remove_action( 'wp_mail_failed', $catch );
		if ( ! $sent ) {
			update_option(
				LeadEmailSettings::OPTION_FAILED,
				array(
					'at'    => time(),
					'error' => (string) ( $error ?? '' ),
				),
				false
			);
		}
		return $sent;
	}

	/**
	 * The message shown when WordPress couldn't send an email.
	 */
	public static function failure_message(): string {
		return __( 'WordPress couldn\'t hand the email to your mail server. Sites often need an SMTP plugin to send email reliably.', 'campaign-bandit' );
	}

	/**
	 * Host and path of a URL, without the scheme.
	 *
	 * @param string $url URL.
	 */
	private static function host( string $url ): string {
		return (string) preg_replace( '~^https?://~', '', untrailingslashit( $url ) );
	}
}
