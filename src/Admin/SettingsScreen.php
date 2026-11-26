<?php
/**
 * Settings (S8). At M1 it holds the Data section: lead retention (BR-50) and what
 * happens to data on uninstall (FR-01). Other sections join in later milestones.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Audit\AuditLog;
use CampaignBandit\Campaign\CampaignPostType;
use CampaignBandit\Campaign\CampaignSettings;
use CampaignBandit\Leads\Retention;
use CampaignBandit\Notifications\LeadEmailRules;
use CampaignBandit\Notifications\LeadNotifier;
use CampaignBandit\Privacy\ConsentTools;
use CampaignBandit\Security\Capabilities;
use CampaignBandit\Settings\AnalyticsSettings;
use CampaignBandit\Geo\CountryDatabase;
use CampaignBandit\Geo\CountryHeaders;
use CampaignBandit\Settings\ConsentSettings;
use CampaignBandit\Settings\CountrySettings;
use CampaignBandit\Settings\DataSettings;
use CampaignBandit\Settings\LeadEmailSettings;
use CampaignBandit\Settings\NotificationSettings;
use CampaignBandit\Settings\VisitorSettings;
use CampaignBandit\Security\RoleAssignments;
use CampaignBandit\Licensing\Plan;
use CampaignBandit\Package\PackageWriter;
use WP_Error;

/**
 * Settings screen, built on the Settings API.
 */
final class SettingsScreen {

	public const SLUG = 'campaign-bandit-settings';

	/** The option group. The add-on registers its own sections in it (S8). */
	public const GROUP = 'campaignbandit_settings';

	private const TEST_ACTION = 'campaignbandit_test_lead_email';

	private const ROLE_ACTION = 'campaignbandit_set_role';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		// Late, so Settings stays the last item after the add-on's screens (menu order in 03).
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_filter( 'option_page_capability_' . self::GROUP, static fn (): string => Capabilities::MANAGE_SETTINGS );
		add_action( 'update_option_' . DataSettings::OPTION_RETENTION_DAYS, array( self::class, 'audit_retention' ), 10, 2 );
		add_action( 'admin_post_' . self::TEST_ACTION, array( self::class, 'send_test' ) );
		add_action( 'admin_post_' . self::ROLE_ACTION, array( self::class, 'save_role' ) );
	}

	/**
	 * Add Settings under the Campaign Bandit menu.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . CampaignPostType::POST_TYPE,
			__( 'Settings', 'campaign-bandit' ),
			__( 'Settings', 'campaign-bandit' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( false !== $hook ) {
			add_action(
				'load-' . $hook,
				static fn () => wp_enqueue_style( 'campaign-bandit-admin', plugins_url( 'assets/admin.css', CAMPAIGNBANDIT_FILE ), array(), CAMPAIGNBANDIT_VERSION )
			);
		}
	}

	/**
	 * The screen URL.
	 */
	public static function url(): string {
		return add_query_arg(
			array(
				'post_type' => CampaignPostType::POST_TYPE,
				'page'      => self::SLUG,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Register the settings and the Data section.
	 */
	public static function settings(): void {
		register_setting(
			self::GROUP,
			DataSettings::OPTION_RETENTION_DAYS,
			array(
				'type'              => 'integer',
				'default'           => Retention::DEFAULT_DAYS,
				'sanitize_callback' => array( Retention::class, 'days' ),
			)
		);
		register_setting(
			self::GROUP,
			DataSettings::OPTION_DELETE_ON_UNINSTALL,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => static fn ( $value ): bool => '1' === (string) $value,
			)
		);

		register_setting(
			self::GROUP,
			LeadEmailSettings::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => static fn ( $value ): string => '1' === (string) $value ? '1' : '0',
			)
		);
		register_setting(
			self::GROUP,
			LeadEmailSettings::OPTION_TO,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( LeadEmailRules::class, 'addresses' ),
			)
		);
		add_settings_section(
			'campaignbandit_new_leads',
			__( 'New leads', 'campaign-bandit' ),
			array( self::class, 'section_new_leads' ),
			self::SLUG
		);
		add_settings_field(
			LeadEmailSettings::OPTION_ENABLED,
			__( 'Email new leads', 'campaign-bandit' ),
			array( self::class, 'field_lead_emails' ),
			self::SLUG,
			'campaignbandit_new_leads'
		);
		add_settings_field(
			LeadEmailSettings::OPTION_TO,
			__( 'Send to', 'campaign-bandit' ),
			array( self::class, 'field_lead_email_to' ),
			self::SLUG,
			'campaignbandit_new_leads',
			array( 'label_for' => LeadEmailSettings::OPTION_TO )
		);

		register_setting(
			self::GROUP,
			ConsentSettings::OPTION,
			array(
				'type'              => 'string',
				'default'           => ConsentSettings::AUTO,
				'sanitize_callback' => array( ConsentSettings::class, 'clean' ),
			)
		);
		add_settings_section(
			'campaignbandit_consent',
			__( 'Cookie consent', 'campaign-bandit' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Where visitors came from (the lead\'s source: campaign tracking values, ad click IDs and pages viewed) is kept in their browser. Many places treat that as marketing storage that needs consent. Campaigns show either way.', 'campaign-bandit' ) . '</p>';
			},
			self::SLUG
		);
		add_settings_field(
			ConsentSettings::OPTION,
			__( 'Keep the lead source', 'campaign-bandit' ),
			array( self::class, 'field_consent' ),
			self::SLUG,
			'campaignbandit_consent'
		);

		register_setting(
			self::GROUP,
			AnalyticsSettings::OPTION,
			array(
				'type'              => 'string',
				'default'           => AnalyticsSettings::BOTH,
				'sanitize_callback' => array( AnalyticsSettings::class, 'clean' ),
			)
		);
		add_settings_section(
			'campaignbandit_analytics',
			__( 'Analytics events', 'campaign-bandit' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Campaign Bandit tells your own analytics when a campaign is shown, closed or converts. It never loads a tag or sends anything itself.', 'campaign-bandit' ) . '</p>';
			},
			self::SLUG
		);
		add_settings_field(
			AnalyticsSettings::OPTION,
			__( 'Send events to', 'campaign-bandit' ),
			array( self::class, 'field_analytics' ),
			self::SLUG,
			'campaignbandit_analytics'
		);

		register_setting(
			self::GROUP,
			CountrySettings::OPTION,
			array(
				'type'              => 'string',
				'default'           => CountrySettings::HEADERS,
				'sanitize_callback' => array( CountrySettings::class, 'clean' ),
			)
		);
		self::visitor_settings();
		add_settings_section(
			'campaignbandit_visitors',
			__( 'Visitors', 'campaign-bandit' ),
			static function (): void {
				echo '<p>' . esc_html__( 'What a new campaign starts with, and the pages every campaign stays off. A campaign that sets its own keeps it.', 'campaign-bandit' ) . '</p>';
			},
			self::SLUG
		);
		if ( Plan::current()->includes( Plan::Pro ) ) {
			add_settings_field(
				VisitorSettings::OPTION_FREQUENCY_TYPE,
				__( 'Show a campaign', 'campaign-bandit' ),
				array( self::class, 'field_frequency' ),
				self::SLUG,
				'campaignbandit_visitors'
			);
			add_settings_field(
				VisitorSettings::OPTION_DISMISS_DAYS,
				__( 'After someone closes it', 'campaign-bandit' ),
				array( self::class, 'field_dismiss' ),
				self::SLUG,
				'campaignbandit_visitors',
				array( 'label_for' => VisitorSettings::OPTION_DISMISS_DAYS )
			);
			add_settings_field(
				VisitorSettings::OPTION_SUPPRESS,
				__( 'After someone converts', 'campaign-bandit' ),
				array( self::class, 'field_suppress' ),
				self::SLUG,
				'campaignbandit_visitors'
			);
		} else {
			add_settings_field(
				'campaignbandit_frequency_free',
				__( 'Show a campaign', 'campaign-bandit' ),
				static function (): void {
					echo '<p class="description">' . esc_html__( 'Once every 7 days to the same visitor, and for 30 days after they close it. Pro lets each campaign set its own.', 'campaign-bandit' ) . '</p>';
				},
				self::SLUG,
				'campaignbandit_visitors'
			);
		}//end if
		add_settings_field(
			CountrySettings::OPTION,
			__( 'Country comes from', 'campaign-bandit' ),
			array( self::class, 'field_country' ),
			self::SLUG,
			'campaignbandit_visitors'
		);
		add_settings_field(
			PackageWriter::OPTION_DEFAULTS,
			__( 'Never show on', 'campaign-bandit' ),
			array( self::class, 'field_excludes' ),
			self::SLUG,
			'campaignbandit_visitors',
			array( 'label_for' => PackageWriter::OPTION_DEFAULTS )
		);

		self::notification_settings();

		add_settings_section(
			'campaignbandit_data',
			__( 'Data', 'campaign-bandit' ),
			static function (): void {
				echo '<p>' . esc_html__( 'How long Campaign Bandit keeps leads on this site, and what happens to its data if the plugin is removed.', 'campaign-bandit' ) . '</p>';
			},
			self::SLUG
		);
		add_settings_field(
			DataSettings::OPTION_RETENTION_DAYS,
			__( 'Keep leads for', 'campaign-bandit' ),
			array( self::class, 'field_retention' ),
			self::SLUG,
			'campaignbandit_data',
			array( 'label_for' => DataSettings::OPTION_RETENTION_DAYS )
		);
		add_settings_field(
			DataSettings::OPTION_DELETE_ON_UNINSTALL,
			__( 'When the plugin is deleted', 'campaign-bandit' ),
			array( self::class, 'field_uninstall' ),
			self::SLUG,
			'campaignbandit_data'
		);
	}

	/**
	 * New leads (FR-56): what it does, the test result, and the last failure.
	 */
	public static function section_new_leads(): void {
		echo '<p>' . esc_html__( 'An email to these addresses each time a lead arrives. Campaigns can send to their own addresses instead, in the editor\'s Notifications panel.', 'campaign-bandit' ) . '</p>';
		$result = isset( $_GET['lead_email_test'] ) ? sanitize_key( $_GET['lead_email_test'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'sent' === $result ) {
			echo '<p><span class="cbandit-status cbandit-status--good">' . esc_html__( 'Test email sent', 'campaign-bandit' ) . '</span></p>';
		} elseif ( 'no_addresses' === $result ) {
			echo '<p><span class="cbandit-status cbandit-status--bad">' . esc_html__( 'Nothing to send to', 'campaign-bandit' ) . '</span> ' . esc_html__( 'Add at least one address first.', 'campaign-bandit' ) . '</p>';
		} elseif ( 'failed' === $result ) {
			echo '<p><span class="cbandit-status cbandit-status--bad">' . esc_html__( 'Couldn\'t send', 'campaign-bandit' ) . '</span> ' . esc_html( LeadNotifier::failure_message() ) . '</p>';
		}
		$failed = LeadEmailSettings::last_failure();
		if ( null !== $failed && 'failed' !== $result ) {
			echo '<p class="cbandit-muted">' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'The last lead email failed on %s. Leads are still stored; check that WordPress can send email.', 'campaign-bandit' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $failed['at'] )
				)
			) . '</p>';
		}
	}

	/**
	 * Cookie consent (BR-48): the three choices, the consent tool found, and a warning
	 * when Always wait meets no tool.
	 */
	public static function field_consent(): void {
		$name    = ConsentSettings::OPTION;
		$current = ConsentSettings::mode();
		$tool    = ConsentTools::detect();
		$choices = array(
			ConsentSettings::AUTO   => __( 'Automatic: after marketing consent when this site has a consent tool; otherwise always', 'campaign-bandit' ),
			ConsentSettings::ALWAYS => __( 'Only after marketing consent', 'campaign-bandit' ),
			ConsentSettings::NEVER  => __( 'Always, whatever the consent tool says', 'campaign-bandit' ),
		);
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Keep the lead source', 'campaign-bandit' ); ?></legend>
			<?php foreach ( $choices as $value => $label ) : ?>
				<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> /> <?php echo esc_html( $label ); ?></label><br />
			<?php endforeach; ?>
		</fieldset>
		<p class="description">
			<?php
			echo esc_html(
				'' !== $tool
					/* translators: %s: consent tool, such as Complianz. */
					? sprintf( __( 'Consent tool found on this site: %s.', 'campaign-bandit' ), ConsentTools::name( $tool ) )
					: __( 'No consent tool found on this site. Whether it needs a cookie banner is up to the site owner.', 'campaign-bandit' )
			);
			?>
		</p>
		<?php if ( '' === $tool && ConsentSettings::ALWAYS === $current ) : ?>
			<p><span class="cbandit-status cbandit-status--attention"><?php esc_html_e( 'No lead source will be kept', 'campaign-bandit' ); ?></span> <?php esc_html_e( 'With no consent tool, visitors can\'t give marketing consent. Add one (Complianz, CookieYes, Cookiebot or another that supports the WP Consent API), or choose Automatic.', 'campaign-bandit' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Country targeting (BR-04, decision 0027): a header from the host or CDN, or also
	 * the DB-IP Lite database, which this site downloads and refreshes monthly.
	 */
	public static function field_country(): void {
		$name    = CountrySettings::OPTION;
		$current = CountrySettings::mode();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CountryHeaders validates every value.
		$header  = CountryHeaders::from( wp_unslash( $_SERVER ) );
		$status  = CountryDatabase::status();
		$choices = array(
			CountrySettings::HEADERS  => __( 'Your host or CDN, when it sends one', 'campaign-bandit' ),
			CountrySettings::DATABASE => __( 'Your host or CDN, then a country database this site downloads', 'campaign-bandit' ),
		);
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Country comes from', 'campaign-bandit' ); ?></legend>
			<?php foreach ( $choices as $value => $label ) : ?>
				<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> /> <?php echo esc_html( $label ); ?></label><br />
			<?php endforeach; ?>
		</fieldset>
		<p class="description">
			<?php
			echo esc_html(
				'' !== $header
					/* translators: %s: a country code, such as GB. */
					? sprintf( __( 'This site’s host or CDN sends a country header: it reads %s for your own request. Nothing is downloaded.', 'campaign-bandit' ), $header )
					: __( 'No country header reached this request, so without the database every visitor’s country is “unknown”, and country rules never match.', 'campaign-bandit' )
			);
			?>
		</p>
		<?php if ( CountrySettings::DATABASE === $current ) : ?>
			<p>
				<?php
				switch ( $status['state'] ) {
					case CountryDatabase::READY:
						echo '<span class="cbandit-status cbandit-status--good">' . esc_html__( 'Ready', 'campaign-bandit' ) . '</span> ';
						echo esc_html(
							sprintf(
								/* translators: 1: month, such as 2026-11, 2: number of ranges, 3: time span. */
								__( 'DB-IP Lite %1$s, %2$s ranges, updated %3$s ago. It refreshes monthly.', 'campaign-bandit' ),
								(string) $status['version'],
								number_format_i18n( (int) $status['records'] ),
								human_time_diff( (int) $status['updated_at'] )
							)
						);
						break;
					case CountryDatabase::FAILED:
						echo '<span class="cbandit-status cbandit-status--bad">' . esc_html__( 'Not downloaded', 'campaign-bandit' ) . '</span> ';
						echo esc_html( (string) $status['error'] );
						break;
					default:
						echo '<span class="cbandit-status cbandit-status--neutral">' . esc_html__( 'Downloading', 'campaign-bandit' ) . '</span> ';
						echo esc_html__( 'The database is about 5 MB and is read in on a schedule, so it can take a few minutes. Country rules stay “unknown” until it is ready.', 'campaign-bandit' );
				}//end switch
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: opening link tag to db-ip.com, 2: closing link tag, 3: opening link tag to the licence, 4: closing link tag. */
					esc_html__( 'IP geolocation by %1$sDB-IP%2$s, used under the %3$sCreative Commons Attribution 4.0 licence%4$s. Its licence asks for a link back on pages that use it, so a campaign whose country rule the database decided carries a small “IP geolocation by DB-IP” credit. Campaigns on sites with a country header carry none.', 'campaign-bandit' ),
					'<a href="https://db-ip.com" target="_blank" rel="noopener">',
					'</a>',
					'<a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">',
					'</a>'
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Analytics events: off, Tag Manager only, or Tag Manager and Google tag, then the
	 * events and their fields.
	 */
	public static function field_analytics(): void {
		$name    = AnalyticsSettings::OPTION;
		$current = AnalyticsSettings::mode();
		$choices = array(
			AnalyticsSettings::OFF       => __( 'Off', 'campaign-bandit' ),
			AnalyticsSettings::DATALAYER => __( 'Tag Manager only (dataLayer)', 'campaign-bandit' ),
			AnalyticsSettings::BOTH      => __( 'Tag Manager and Google tag (gtag)', 'campaign-bandit' ),
		);
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Send events to', 'campaign-bandit' ); ?></legend>
			<?php foreach ( $choices as $value => $label ) : ?>
				<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> /> <?php echo esc_html( $label ); ?></label><br />
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php esc_html_e( 'If your Google Analytics tag runs inside Tag Manager, choose Tag Manager only, so events aren\'t counted twice. Staging sites send none.', 'campaign-bandit' ); ?></p>
		<details class="cbandit-events-help">
			<summary><?php esc_html_e( 'Events sent', 'campaign-bandit' ); ?></summary>
			<p><code>campaign_bandit_view</code>, <code>campaign_bandit_close</code>, <code>campaign_bandit_conversion</code></p>
			<p class="description">
				<?php esc_html_e( 'Each carries campaign_id, campaign_name, campaign_version, campaign_format and offer. Form answers and consent are never included. Developers can also listen for cbandit:view, cbandit:close and cbandit:conversion on document.', 'campaign-bandit' ); ?>
			</p>
		</details>
		<?php
	}

	/**
	 * Email new leads: on or off.
	 */
	public static function field_lead_emails(): void {
		$name = LeadEmailSettings::OPTION_ENABLED;
		?>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
		<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( LeadEmailSettings::enabled() ); ?> /> <?php esc_html_e( 'Send an email when a lead arrives', 'campaign-bandit' ); ?></label>
		<p class="description"><?php esc_html_e( 'Off: no lead emails at all. Leads are still stored.', 'campaign-bandit' ); ?></p>
		<?php
	}

	/**
	 * The addresses, one per line, and Send test email.
	 */
	public static function field_lead_email_to(): void {
		$name = LeadEmailSettings::OPTION_TO;
		$test = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::TEST_ACTION ), self::TEST_ACTION );
		?>
		<textarea name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" rows="3" class="regular-text" aria-describedby="cbandit-lead-email-help"><?php echo esc_textarea( implode( "\n", LeadEmailSettings::addresses() ) ); ?></textarea>
		<p class="description" id="cbandit-lead-email-help"><?php esc_html_e( 'One address per line, up to 10. Addresses that aren\'t valid are left out when you save.', 'campaign-bandit' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $test ); ?>"><?php esc_html_e( 'Send test email', 'campaign-bandit' ); ?></a></p>
		<?php
	}

	/**
	 * Send test email: a sample to the saved addresses.
	 */
	public static function send_test(): void {
		check_admin_referer( self::TEST_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change Campaign Bandit settings.', 'campaign-bandit' ), 403 );
		}
		$result = LeadNotifier::test();
		// A failure has a reason, and "no addresses saved" is not a mail problem. Carry
		// the code through, so the notice can say which it was rather than sending
		// everybody off to check their SMTP settings.
		$state = true === $result ? 'sent' : 'failed';
		if ( is_wp_error( $result ) && 'campaignbandit_no_addresses' === $result->get_error_code() ) {
			$state = 'no_addresses';
		}
		wp_safe_redirect( add_query_arg( 'lead_email_test', $state, self::url() ) );
		exit;
	}

	/**
	 * Retention select.
	 */
	public static function field_retention(): void {
		$current = DataSettings::retention_days();
		?>
		<select name="<?php echo esc_attr( DataSettings::OPTION_RETENTION_DAYS ); ?>" id="<?php echo esc_attr( DataSettings::OPTION_RETENTION_DAYS ); ?>" aria-describedby="cbandit-retention-help">
			<?php foreach ( Retention::PERIODS as $days ) : ?>
				<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $current, $days ); ?>>
					<?php
					echo esc_html(
						0 === $days
							? __( 'Until I delete them', 'campaign-bandit' )
							/* translators: %d: number of days. */
							: sprintf( _n( '%d day', '%d days', $days, 'campaign-bandit' ), $days )
					);
					?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description" id="cbandit-retention-help">
			<?php esc_html_e( 'Older leads are deleted automatically once a day. Export them first if you need a copy.', 'campaign-bandit' ); ?>
		</p>
		<?php
	}

	/**
	 * Uninstall choice: keep (default) or delete.
	 */
	public static function field_uninstall(): void {
		$delete = DataSettings::delete_on_uninstall();
		$name   = DataSettings::OPTION_DELETE_ON_UNINSTALL;
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'When the plugin is deleted', 'campaign-bandit' ); ?></legend>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="0" <?php checked( ! $delete ); ?> /> <?php esc_html_e( 'Keep campaigns, leads and settings (recommended)', 'campaign-bandit' ); ?></label><br />
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $delete ); ?> /> <?php esc_html_e( 'Delete all Campaign Bandit data, including leads', 'campaign-bandit' ); ?></label>
		</fieldset>
		<?php
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change Campaign Bandit settings.', 'campaign-bandit' ), 403 );
		}
		?>
		<?php $tab = isset( $_GET['tab'] ) && 'audit' === $_GET['tab'] ? 'audit' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view. ?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'campaign-bandit' ); ?></h1>
			<nav class="nav-tab-wrapper cbandit-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'campaign-bandit' ); ?>">
				<a class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url() ); ?>"<?php echo 'settings' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'General', 'campaign-bandit' ); ?></a>
				<a class="nav-tab<?php echo 'audit' === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'audit', self::url() ) ); ?>"<?php echo 'audit' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Audit log', 'campaign-bandit' ); ?></a>
			</nav>
			<?php if ( 'audit' === $tab ) : ?>
				<?php AuditLogView::render(); ?>
			<?php else : ?>
				<?php settings_errors(); ?>
				<?php
				// A role change posts on its own and comes back with its result (BR-70).
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; the change itself checked its nonce.
				$notice = isset( $_GET['cbandit_message'] ) ? sanitize_text_field( wp_unslash( $_GET['cbandit_message'] ) ) : '';
				if ( '' !== $notice ) {
					echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
				}
				?>
				<div class="cbandit-split cbandit-settings">
					<div class="cbandit-split__main">
						<?php ConnectionSection::render(); ?>
						<?php
						/**
						 * Fires above the settings form. The add-on puts Licence here.
						 * These panels carry their own forms, so they cannot live inside
						 * the settings form: a form inside a form is ignored, and its
						 * buttons submit the outer one.
						 */
						do_action( 'campaignbandit_settings_before_form' );
						?>
						<?php self::render_roles_panel(); ?>
						<form method="post" action="options.php">
							<?php
							settings_fields( self::GROUP );
							do_settings_sections( self::SLUG );
							submit_button();
							?>
						</form>
						<?php
						/**
						 * Fires below the settings form, for panels that act rather than
						 * save options.
						 */
						do_action( 'campaignbandit_settings_after_form' );
						?>
					</div>
					<div class="cbandit-split__aside">
						<?php if ( UpgradePanel::shown() ) : ?>
							<?php UpgradePanel::render(); ?>
						<?php endif; ?>
						<?php self::render_help(); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register the Visitors settings (S8): the defaults a new campaign starts with, and
	 * the paths every campaign stays off (BR-01).
	 */
	private static function visitor_settings(): void {
		register_setting(
			self::GROUP,
			VisitorSettings::OPTION_FREQUENCY_TYPE,
			array(
				'type'              => 'string',
				'default'           => 'every_n_days',
				'sanitize_callback' => static fn ( $value ): string => in_array( (string) $value, CampaignSettings::FREQUENCIES, true ) ? (string) $value : 'every_n_days',
			)
		);
		register_setting(
			self::GROUP,
			VisitorSettings::OPTION_FREQUENCY_DAYS,
			array(
				'type'              => 'integer',
				'default'           => 7,
				'sanitize_callback' => static fn ( $value ): int => VisitorSettings::clamp( $value, 1, 365, 7 ),
			)
		);
		register_setting(
			self::GROUP,
			VisitorSettings::OPTION_DISMISS_DAYS,
			array(
				'type'              => 'integer',
				'default'           => 30,
				'sanitize_callback' => static fn ( $value ): int => VisitorSettings::clamp( $value, 0, 365, 30 ),
			)
		);
		register_setting(
			self::GROUP,
			VisitorSettings::OPTION_SUPPRESS,
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => static fn ( $value ): string => '1' === (string) $value ? '1' : '0',
			)
		);
		register_setting(
			self::GROUP,
			PackageWriter::OPTION_DEFAULTS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'clean_excludes' ),
			)
		);
	}

	/**
	 * Register the Notifications settings (S8, BR-31).
	 */
	private static function notification_settings(): void {
		register_setting(
			self::GROUP,
			NotificationSettings::OPTION_TO,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( LeadEmailRules::class, 'addresses' ),
			)
		);
		register_setting(
			self::GROUP,
			NotificationSettings::OPTION_EVENTS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( NotificationSettings::class, 'clean' ),
			)
		);
		add_settings_section(
			'campaignbandit_notifications',
			__( 'Notifications', 'campaign-bandit' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Emails about Campaign Bandit itself, for whoever keeps it running. New-lead emails are set under New leads.', 'campaign-bandit' ) . '</p>';
			},
			self::SLUG
		);
		add_settings_field(
			NotificationSettings::OPTION_TO,
			__( 'Send to', 'campaign-bandit' ),
			array( self::class, 'field_notify_to' ),
			self::SLUG,
			'campaignbandit_notifications',
			array( 'label_for' => NotificationSettings::OPTION_TO )
		);
		add_settings_field(
			NotificationSettings::OPTION_EVENTS,
			__( 'Email me about', 'campaign-bandit' ),
			array( self::class, 'field_notify_events' ),
			self::SLUG,
			'campaignbandit_notifications'
		);
	}

	/**
	 * The Roles section (S8, BR-70). Its rows post on their own, because a role change
	 * is not one of the options this screen saves.
	 */
	private static function render_roles_panel(): void {
		?>
		<section class="cbandit-settings__panel" aria-labelledby="cbandit-roles-title">
			<h2 id="cbandit-roles-title"><?php esc_html_e( 'Roles', 'campaign-bandit' ); ?></h2>
			<p><?php esc_html_e( 'Who can use Campaign Bandit, alongside their own WordPress role. Managers publish, pause and handle leads; Editors write drafts and see how campaigns did, but never see leads. Administrators always have everything.', 'campaign-bandit' ); ?></p>
			<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
				<p class="description"><?php esc_html_e( 'Only Administrators can change roles.', 'campaign-bandit' ); ?></p>
			<?php endif; ?>
			<?php self::field_roles(); ?>
		</section>
		<?php
	}

	/**
	 * What this screen is for, beside it (S8).
	 */
	private static function render_help(): void {
		?>
		<aside class="cbandit-card cbandit-settings__help" aria-labelledby="cbandit-settings-help-title">
			<h2 id="cbandit-settings-help-title"><?php esc_html_e( 'About these settings', 'campaign-bandit' ); ?></h2>
			<p><?php esc_html_e( 'These apply to this site. A campaign that sets its own keeps it: nothing here changes a campaign that is already running.', 'campaign-bandit' ); ?></p>
			<h3><?php esc_html_e( 'Visitors', 'campaign-bandit' ); ?></h3>
			<p><?php esc_html_e( 'What a new campaign starts with — how often someone sees it, how long closing it lasts — and the pages every campaign stays off, such as checkout and admin.', 'campaign-bandit' ); ?></p>
			<h3><?php esc_html_e( 'Roles', 'campaign-bandit' ); ?></h3>
			<p><?php esc_html_e( 'A Campaign Bandit role sits beside someone’s WordPress role: giving it takes nothing away, and removing it leaves their own role alone. Only Administrators can change roles, and every change is recorded in the audit log.', 'campaign-bandit' ); ?></p>
			<h3><?php esc_html_e( 'Data', 'campaign-bandit' ); ?></h3>
			<p><?php esc_html_e( 'Leads are stored on this site first, then delivered. Retention decides how long they stay here; deleting the plugin keeps them unless you say otherwise.', 'campaign-bandit' ); ?></p>
			<h3><?php esc_html_e( 'Emails', 'campaign-bandit' ); ?></h3>
			<p><?php esc_html_e( 'New leads go to whoever handles leads. Notifications go to whoever keeps Campaign Bandit running — a site that stops syncing, for example. They are set separately on purpose.', 'campaign-bandit' ); ?></p>
			<p><a href="<?php echo esc_url( add_query_arg( 'tab', 'audit', self::url() ) ); ?>"><?php esc_html_e( 'See what has changed, in the audit log', 'campaign-bandit' ); ?></a></p>
		</aside>
		<?php
	}

	/**
	 * How often a visitor sees a campaign (BR-11).
	 */
	public static function field_frequency(): void {
		$type    = VisitorSettings::frequency_type();
		$days    = VisitorSettings::frequency_days();
		$options = array(
			'every_n_days'   => __( 'Once every', 'campaign-bandit' ),
			'once_per_visit' => __( 'Once per visit', 'campaign-bandit' ),
			'every_view'     => __( 'On every page view', 'campaign-bandit' ),
		);
		echo '<fieldset>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( VisitorSettings::OPTION_FREQUENCY_TYPE ),
				esc_attr( $value ),
				checked( $type, $value, false ),
				esc_html( $label )
			);
			if ( 'every_n_days' === $value ) {
				printf(
					' <input type="number" name="%1$s" class="small-text" min="1" max="365" value="%2$d" aria-label="%3$s" /> %4$s',
					esc_attr( VisitorSettings::OPTION_FREQUENCY_DAYS ),
					(int) $days,
					esc_attr__( 'Days between shows', 'campaign-bandit' ),
					esc_html__( 'days', 'campaign-bandit' )
				);
			}
			echo '<br />';
		}
		echo '</fieldset>';
	}

	/**
	 * How long a dismissal lasts (BR-12).
	 */
	public static function field_dismiss(): void {
		printf(
			'<input type="number" id="%1$s" name="%1$s" class="small-text" min="0" max="365" value="%2$d" /> %3$s<p class="description">%4$s</p>',
			esc_attr( VisitorSettings::OPTION_DISMISS_DAYS ),
			(int) VisitorSettings::dismiss_days(),
			esc_html__( 'days', 'campaign-bandit' ),
			esc_html__( 'How long a campaign stays away after a visitor closes it. 0 means it can come back on the next page.', 'campaign-bandit' )
		);
	}

	/**
	 * Whether converting hides the campaign (BR-13).
	 */
	public static function field_suppress(): void {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( VisitorSettings::OPTION_SUPPRESS ),
			checked( VisitorSettings::suppress_after_conversion(), true, false ),
			esc_html__( 'Do not show it to them again', 'campaign-bandit' )
		);
	}

	/**
	 * The paths no campaign shows on (BR-01).
	 */
	public static function field_excludes(): void {
		printf(
			'<textarea id="%1$s" name="%1$s" rows="4" class="large-text code" aria-describedby="%1$s-help">%2$s</textarea><p class="description" id="%1$s-help">%3$s</p>',
			esc_attr( PackageWriter::OPTION_DEFAULTS ),
			esc_textarea( implode( "\n", PackageWriter::default_excludes() ) ),
			esc_html__( 'One path per line, such as /checkout; use * for any characters: /wp-admin*. A campaign can be allowed on these pages one at a time, in its own display conditions.', 'campaign-bandit' )
		);
	}

	/**
	 * Clean the excluded paths: one per line, each starting with a slash.
	 *
	 * @param mixed $value Submitted text or list.
	 * @return string[]
	 */
	public static function clean_excludes( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
		$out   = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$path = trim( (string) $line );
			if ( '' === $path || count( $out ) >= 50 ) {
				continue;
			}
			$path = '/' . ltrim( (string) preg_replace( '#[^a-z0-9_:%/.*~-]#i', '', $path ), '/' );
			if ( '/' !== $path && ! in_array( $path, $out, true ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * Who gets the critical emails (BR-31).
	 */
	public static function field_notify_to(): void {
		printf(
			'<input type="text" id="%1$s" name="%1$s" class="regular-text" value="%2$s" aria-describedby="%1$s-help" /><p class="description" id="%1$s-help">%3$s</p>',
			esc_attr( NotificationSettings::OPTION_TO ),
			esc_attr( implode( ', ', NotificationSettings::addresses() ) ),
			esc_html__( 'Separate addresses with commas. Empty: this site&#8217;s admin address.', 'campaign-bandit' )
		);
	}

	/**
	 * Which events send an email (BR-31).
	 */
	public static function field_notify_events(): void {
		$labels = array(
			NotificationSettings::EVENT_STALE_SYNC => __( 'A connected site stops syncing for 24 hours', 'campaign-bandit' ),
		);
		echo '<fieldset>';
		foreach ( $labels as $event => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label><br />',
				esc_attr( NotificationSettings::OPTION_EVENTS ),
				esc_attr( $event ),
				checked( NotificationSettings::sends( $event ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		if ( ! Plan::current()->is_hub_plan() ) {
			echo '<p class="description">' . esc_html__( 'This site has no connected sites, so nothing sends these yet.', 'campaign-bandit' ) . '</p>';
		}
	}

	/**
	 * Who has a Campaign Bandit role, and how an Administrator changes it (BR-70).
	 */
	public static function field_roles(): void {
		$assigned = RoleAssignments::assigned();
		$can      = current_user_can( 'manage_options' );
		$choices  = RoleAssignments::choices();
		echo '<table class="widefat striped cbandit-roles"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Person', 'campaign-bandit' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Campaign Bandit role', 'campaign-bandit' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( array() === $assigned ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Nobody yet. Administrators can already do everything.', 'campaign-bandit' ) . '</td></tr>';
		}
		foreach ( $assigned as $person ) {
			echo '<tr><td>' . esc_html( $person['name'] ) . ' <span class="cbandit-muted">' . esc_html( $person['email'] ) . '</span></td><td>';
			if ( $can ) {
				self::role_form( (int) $person['id'], (string) $person['role'], $choices );
			} else {
				echo esc_html( $choices[ $person['role'] ] ?? '' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $can ) {
			echo '<p class="cbandit-roles__add">';
			self::role_form( 0, RoleAssignments::NONE, $choices, true );
			echo '</p>';
		}
	}

	/**
	 * One row&#8217;s form: a role for a person.
	 *
	 * @param int                   $user_id User, or 0 to choose one.
	 * @param string                $role    Current role.
	 * @param array<string, string> $choices Roles that can be given.
	 * @param bool                  $pick    Whether to show a user picker.
	 */
	private static function role_form( int $user_id, string $role, array $choices, bool $pick = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cbandit-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ROLE_ACTION ); ?>" />
			<?php wp_nonce_field( self::ROLE_ACTION ); ?>
			<?php if ( $pick ) : ?>
				<label class="screen-reader-text" for="cbandit-role-user"><?php esc_html_e( 'Person', 'campaign-bandit' ); ?></label>
				<?php
				wp_dropdown_users(
					array(
						'name'              => 'user',
						'id'                => 'cbandit-role-user',
						'show_option_none'  => __( 'Choose someone...', 'campaign-bandit' ),
						'option_none_value' => '0',
						'number'            => 200,
					)
				);
				?>
			<?php else : ?>
				<input type="hidden" name="user" value="<?php echo esc_attr( (string) $user_id ); ?>" />
			<?php endif; ?>
			<label class="screen-reader-text" for="cbandit-role-<?php echo esc_attr( (string) $user_id ); ?>"><?php esc_html_e( 'Campaign Bandit role', 'campaign-bandit' ); ?></label>
			<select name="role" id="cbandit-role-<?php echo esc_attr( (string) $user_id ); ?>">
				<?php foreach ( $choices as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $role, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php echo $pick ? esc_html__( 'Add', 'campaign-bandit' ) : esc_html__( 'Save', 'campaign-bandit' ); ?></button>
		</form>
		<?php
	}

	/**
	 * POST a role change (BR-70). Administrators only; recorded either way (BR-71).
	 */
	public static function save_role(): void {
		check_admin_referer( self::ROLE_ACTION );
		$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		$role    = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
		$result  = 0 === $user_id
			? new WP_Error( 'campaignbandit_user', __( 'Choose someone first.', 'campaign-bandit' ) )
			: RoleAssignments::set( $user_id, $role );
		wp_safe_redirect(
			add_query_arg(
				'cbandit_message',
				rawurlencode( is_wp_error( $result ) ? $result->get_error_message() : __( 'Role saved.', 'campaign-bandit' ) ),
				self::url()
			)
		);
		exit;
	}

	/**
	 * A retention change goes in the audit trail (BR-71).
	 *
	 * @param mixed $old_value Previous days.
	 * @param mixed $value     New days.
	 */
	public static function audit_retention( $old_value, $value ): void {
		( new AuditLog() )->record(
			'settings.retention_changed',
			'setting',
			DataSettings::OPTION_RETENTION_DAYS,
			AuditLog::CHANNEL_SCREEN,
			array(
				'from' => Retention::days( $old_value ),
				'to'   => Retention::days( $value ),
			)
		);
	}
}
