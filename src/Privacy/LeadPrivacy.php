<?php
/**
 * Leads in WordPress's personal data tools (FR-55): Tools › Export Personal Data and
 * Tools › Erase Personal Data, plus suggested text for the site's privacy policy.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Privacy;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Leads\Consents;
use CampaignBandit\Leads\LeadCsv;
use CampaignBandit\Leads\LeadRepository;
use CampaignBandit\Leads\LeadSource;
use CampaignBandit\Plugin;
use CampaignBandit\Settings\DataSettings;

/**
 * Personal data exporter and eraser for leads.
 */
final class LeadPrivacy {

	public const KEY = 'campaign-bandit-leads';

	private const PER_PAGE = 50;

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_exporter( array $exporters ): array {
		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Campaign Bandit leads', 'campaign-bandit' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_eraser( array $erasers ): array {
		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Campaign Bandit leads', 'campaign-bandit' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export the leads for an email address, one page at a time.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$manager = Plugin::lead_manager();
		$leads   = ( new LeadRepository() )->find_by_email( $email, self::PER_PAGE, ( max( 1, $page ) - 1 ) * self::PER_PAGE );
		$data    = array();

		foreach ( $leads as $lead ) {
			$labels = $manager->field_labels( $lead );
			$items  = array(
				array(
					'name'  => __( 'Lead ID', 'campaign-bandit' ),
					'value' => (string) $lead['uid'],
				),
				array(
					'name'  => __( 'Received', 'campaign-bandit' ),
					'value' => LeadCsv::site_time( (string) $lead['submitted_at'], wp_timezone() ),
				),
				array(
					'name'  => __( 'Campaign', 'campaign-bandit' ),
					'value' => $manager->campaign_name( (string) $lead['campaign_uid'] ),
				),
				array(
					'name'  => __( 'Page', 'campaign-bandit' ),
					'value' => (string) $lead['page_url'],
				),
			);
			foreach ( (array) json_decode( (string) $lead['fields'], true ) as $key => $value ) {
				$items[] = array(
					'name'  => $labels[ $key ] ?? (string) $key,
					'value' => LeadCsv::value( $value ),
				);
			}
			$names = array(
				'contact' => __( 'Consent: contact about this request', 'campaign-bandit' ),
				'email'   => __( 'Consent: email marketing', 'campaign-bandit' ),
				'sms'     => __( 'Consent: text messages', 'campaign-bandit' ),
			);
			foreach ( Consents::for_lead( $lead ) as $purpose => $consent ) {
				$items[] = array(
					'name'  => $names[ $purpose ] ?? $purpose,
					'value' => ( $consent['given'] ? __( 'Given', 'campaign-bandit' ) : __( 'Not given', 'campaign-bandit' ) ) . ( '' !== $consent['text'] ? ' — ' . $consent['text'] : '' ),
				);
			}
			$source = LeadSource::from_json( $lead['source'] ?? null );
			if ( array() !== $source ) {
				$items[] = array(
					'name'  => __( 'First visit', 'campaign-bandit' ),
					'value' => LeadSource::label( $source['first'] ) . ' · ' . $source['first']['landing_page'],
				);
				$items[] = array(
					'name'  => __( 'Pages viewed', 'campaign-bandit' ),
					'value' => implode( ', ', $source['pages'] ),
				);
				foreach ( $source['click_ids'] as $name => $value ) {
					$items[] = array(
						'name'  => $name,
						'value' => $value,
					);
				}
			}
			foreach ( array_filter( (array) json_decode( (string) ( $lead['utm'] ?? '' ), true ) ) as $part => $value ) {
				$items[] = array(
					'name'  => 'utm_' . $part,
					'value' => LeadCsv::value( $value ),
				);
			}

			$data[] = array(
				'group_id'          => self::KEY,
				'group_label'       => __( 'Campaign Bandit leads', 'campaign-bandit' ),
				'group_description' => __( 'Details you submitted through forms on this site.', 'campaign-bandit' ),
				'item_id'           => 'lead-' . strtolower( (string) $lead['uid'] ),
				'data'              => $items,
			);
		}//end foreach

		return array(
			'data' => $data,
			'done' => count( $leads ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase the leads for an email address. Deleted rows drop out of the query, so
	 * each call takes the first page until none are left.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused: see above).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page.
		$manager = Plugin::lead_manager();
		$leads   = ( new LeadRepository() )->find_by_email( $email, self::PER_PAGE );
		$removed = 0;
		foreach ( $leads as $lead ) {
			if ( $manager->delete( (string) $lead['uid'], AuditLog::CHANNEL_SYSTEM ) ) {
				++$removed;
			}
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $removed < count( $leads ),
			'messages'       => $removed < count( $leads ) ? array( __( 'Some Campaign Bandit leads could not be deleted. Try again, or delete them on the Leads screen.', 'campaign-bandit' ) ) : array(),
			// Stop when the last page is reached, or when nothing could be deleted (avoids an endless loop).
			'done'           => count( $leads ) < self::PER_PAGE || 0 === $removed,
		);
	}

	/**
	 * Suggested text for Settings › Privacy (05 §7).
	 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days = DataSettings::retention_days();
		$kept = 0 === $days
			? __( 'We keep these details until we delete them, or until you ask us to.', 'campaign-bandit' )
			/* translators: %d: number of days. */
			: sprintf( __( 'We delete these details automatically after %d days, or sooner if you ask us to.', 'campaign-bandit' ), $days );

		wp_add_privacy_policy_content(
			__( 'Campaign Bandit', 'campaign-bandit' ),
			wp_kses_post(
				'<p>' . __( 'When you fill in a form in one of our popups, slide-ins, bars or inline forms, we store what you entered, the consent texts you agreed to or declined, the page you were on and when, and where you came from: campaign tracking values (such as utm_source), the referring site, the page you first landed on, the last few pages you viewed on this site, how many visits you made and any ad click identifiers. Your browser keeps that last part for this site only, for up to 90 days after your last visit, and sends it only when you submit a form. Where this site asks for cookie consent, it is kept only after you agree to marketing cookies. If this site uses Google Analytics or Google Tag Manager, it is told when a campaign is shown, closed or completed, without anything you entered.', 'campaign-bandit' ) . '</p>'
				. '<p>' . $kept . '</p>'
				. '<p>' . __( 'To decide which campaign to show and how often, your browser keeps a small record on your device of which campaigns you have seen, closed or answered. It holds no personal data and is not sent to us. We do not store your IP address.', 'campaign-bandit' ) . '</p>'
			)
		);
	}
}
