<?php
/**
 * Free plugin bootstrap.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Campaign\CampaignActions;
use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignService;
use CampaignBandit\Database\Migrator;
use CampaignBandit\Database\Schema;
use CampaignBandit\Editor\Editor;
use CampaignBandit\Forms\FormBlocks;
use CampaignBandit\Frontend\Frontend;
use CampaignBandit\Admin\DashboardScreen;
use CampaignBandit\Admin\LeadsScreen;
use CampaignBandit\Leads\LeadManager;
use CampaignBandit\Leads\LeadRepository;
use CampaignBandit\Leads\LeadService;
use CampaignBandit\Leads\RetentionJob;
use CampaignBandit\Privacy\LeadPrivacy;
use CampaignBandit\Admin\SettingsScreen;
use CampaignBandit\Metrics\MetricsService;
use CampaignBandit\Package\PackageWriter;
use CampaignBandit\Patterns\StarterPatterns;
use CampaignBandit\Publishing\DeploymentRepository;
use CampaignBandit\Publishing\Deployment;
use CampaignBandit\Publishing\PublishingService;
use CampaignBandit\Publishing\Version;
use CampaignBandit\Publishing\VersionRepository;
use CampaignBandit\Rest\RestController;
use CampaignBandit\Security\Roles;

/**
 * Wires the free plugin into WordPress and holds its shared services.
 */
final class Plugin {

	/**
	 * Campaign service, created on first use.
	 *
	 * @var CampaignService|null
	 */
	private static ?CampaignService $campaigns = null;

	/**
	 * Publishing service, created on first use.
	 *
	 * @var PublishingService|null
	 */
	private static ?PublishingService $publishing = null;

	/**
	 * Package writer, created on first use.
	 *
	 * @var PackageWriter|null
	 */
	private static ?PackageWriter $package_writer = null;

	/**
	 * Start the plugin. Runs on plugins_loaded.
	 */
	public static function boot(): void {
		if ( Migrator::maybe_upgrade( new Schema() ) ) {
			Roles::install();
		}

		Roles::register();
		CampaignPostType::register();
		PackageWriter::register();
		RestController::register();
		Frontend::register();
		Editor::register();
		FormBlocks::register();
		StarterPatterns::register();
		DashboardScreen::register();
		LeadsScreen::register();
		SettingsScreen::register();
		// Settings › Visitors (S8): what a new campaign starts with.
		Settings\VisitorSettings::register();
		Admin\ConnectionSection::register();
		Admin\CampaignScreen::register();
		Admin\CampaignListColumns::register();
		Connect\SyncClient::register();
		// A hub that white labels its sites renames this one (FR-72, decision 0031).
		Connect\WhiteLabel::register();
		Connect\StructureCollector::register();
		Preview\PreviewService::register();
		// The country database, downloaded only when an admin asks for it (BR-04).
		Geo\CountryDatabase::register();
		Explain\PageExplainer::register();
		Admin\ExplainPanel::register();
		// The targeting library (BR-07, FR-36, decision 0024).
		Conditions\SavedConditionStore::register();
		Conditions\ConditionsRest::register();
		Admin\ConditionsScreen::register();
		Connect\LeadForwarder::register();
		Connect\NonceStore::register();
		RetentionJob::register();
		LeadPrivacy::register();
		Notifications\LeadNotifier::register();
		Campaign\ButtonActions::register();

		/**
		 * Fires once the free plugin has booted. The add-on starts here.
		 */
		do_action( 'campaignbandit_loaded' );
	}

	/**
	 * Shared campaign service.
	 */
	public static function campaigns(): CampaignService {
		return self::$campaigns ??= new CampaignService();
	}

	/**
	 * Shared campaign actions: duplicate, archive, restore.
	 */
	public static function campaign_actions(): CampaignActions {
		return new CampaignActions( self::campaigns(), new DeploymentRepository(), new AuditLog() );
	}

	/**
	 * Shared publishing service.
	 */
	public static function publishing(): PublishingService {
		return self::$publishing ??= new PublishingService(
			self::campaigns(),
			new VersionRepository(),
			new DeploymentRepository(),
			new AuditLog()
		);
	}

	/**
	 * Lead capture service.
	 */
	public static function leads(): LeadService {
		return new LeadService( new LeadRepository(), new DeploymentRepository(), new VersionRepository() );
	}

	/**
	 * Lead records service: list, export, delete (FR-54).
	 */
	public static function lead_manager(): LeadManager {
		return new LeadManager( new LeadRepository(), self::campaigns(), new VersionRepository(), new AuditLog(), wp_timezone() );
	}

	/**
	 * Shared metrics service.
	 */
	public static function metrics(): MetricsService {
		return new MetricsService( new DeploymentRepository() );
	}

	/**
	 * The version of a campaign that is live on this install, if any.
	 *
	 * @param string $uid Campaign ULID.
	 */
	public static function live_version( string $uid ): ?Version {
		$deployment = ( new DeploymentRepository() )->find( $uid, 0 );
		if ( null === $deployment || Deployment::LIVE !== $deployment->state ) {
			return null;
		}
		return ( new VersionRepository() )->find( $deployment->version_id );
	}

	/**
	 * Shared package writer.
	 */
	public static function package_writer(): PackageWriter {
		return self::$package_writer ??= new PackageWriter( new DeploymentRepository(), new VersionRepository() );
	}
}
