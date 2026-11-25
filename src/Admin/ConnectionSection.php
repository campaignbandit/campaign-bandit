<?php
/**
 * Settings › Connection (S9): paste a pairing key to connect to a hub, or see the
 * connection and disconnect.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Admin;

use CampaignBandit\Connect\Connection;
use CampaignBandit\Connect\WhiteLabel;
use CampaignBandit\Connect\Connector;
use CampaignBandit\Connect\PackageApplier;
use CampaignBandit\Security\Capabilities;
use CampaignBandit\Site\ModeStore;
use CampaignBandit\Site\SiteMode;

/**
 * The Connection section at the top of Settings.
 */
final class ConnectionSection {

	private const ACTION = 'campaignbandit_connection';

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Render the section.
	 */
	public static function render(): void {
		$mode = ModeStore::current();
		?>
		<div class="cbandit-connection">
			<h2><?php esc_html_e( 'Connection', 'campaign-bandit' ); ?></h2>
			<?php self::notice(); ?>
			<?php
			if ( SiteMode::Hub === $mode ) {
				echo '<p>' . esc_html__( 'This site is a hub. Connect client sites on the Sites screen.', 'campaign-bandit' ) . '</p>';
			} elseif ( SiteMode::Connected === $mode ) {
				self::render_connected();
			} else {
				self::render_connect();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The pairing key field.
	 */
	private static function render_connect(): void {
		?>
		<p><?php esc_html_e( 'Let an agency hub manage campaigns on this site. Its campaigns run alongside your own, and leads go to the hub too.', 'campaign-bandit' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="op" value="pair" />
			<?php wp_nonce_field( self::ACTION . '_pair' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cbandit-pairing-key"><?php esc_html_e( 'Pairing key', 'campaign-bandit' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" name="pairing_key" id="cbandit-pairing-key" autocomplete="off" spellcheck="false" aria-describedby="cbandit-pairing-help" />
						<p class="description" id="cbandit-pairing-help"><?php esc_html_e( 'Copy it from the hub’s Sites screen › Connect a site. Each key works once, for 24 hours.', 'campaign-bandit' ); ?></p>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Connect to hub', 'campaign-bandit' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * The connection and Disconnect.
	 */
	private static function render_connected(): void {
		$connection = Connection::get();
		if ( null === $connection ) {
			echo '<p>' . esc_html__( 'The connection details can’t be read, for example after the site’s security keys changed. Disconnect, then pair again with a new key.', 'campaign-bandit' ) . '</p>';
			self::disconnect_button();
			return;
		}
		$moved = Connection::current_url() !== $connection['paired_url'];
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html( WhiteLabel::active() ? __( 'Managed by', 'campaign-bandit' ) : __( 'Hub', 'campaign-bandit' ) ); ?></th>
				<td>
					<?php if ( WhiteLabel::active() ) : ?>
						<?php
						// White label (FR-72): the agency's name and logo, and no hub address.
						?>
						<?php if ( '' !== WhiteLabel::logo() ) : ?>
							<img class="cbandit-white-label__logo" src="<?php echo esc_url( WhiteLabel::logo() ); ?>" alt="<?php echo esc_attr( WhiteLabel::name() ); ?>" />
						<?php else : ?>
							<?php echo esc_html( WhiteLabel::name() ); ?>
						<?php endif; ?>
						<?php if ( '' !== WhiteLabel::support_url() ) : ?>
							<br /><a href="<?php echo esc_url( WhiteLabel::support_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get support', 'campaign-bandit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'campaign-bandit' ); ?></span></a>
						<?php endif; ?>
					<?php else : ?>
						<?php echo esc_html( '' !== $connection['hub_name'] ? $connection['hub_name'] : $connection['hub_url'] ); ?>
						<?php if ( '' !== $connection['hub_name'] ) : ?>
							<br /><span class="cbandit-muted"><?php echo esc_html( $connection['hub_url'] ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'campaign-bandit' ); ?></th>
				<td>
					<?php if ( $moved ) : ?>
						<span class="cbandit-status cbandit-status--bad"><?php esc_html_e( 'Site address changed', 'campaign-bandit' ); ?></span>
						<p class="description"><?php esc_html_e( 'Hub campaigns are paused and leads are held on this site until the agency confirms the move on the hub.', 'campaign-bandit' ); ?></p>
					<?php else : ?>
						<span class="cbandit-status cbandit-status--good"><?php echo esc_html( 'staging' === $connection['connection'] ? __( 'Connected as staging', 'campaign-bandit' ) : __( 'Connected', 'campaign-bandit' ) ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last sync', 'campaign-bandit' ); ?></th>
				<td>
					<?php
					echo esc_html(
						null === $connection['last_sync_at']
							? __( 'Not yet', 'campaign-bandit' )
							/* translators: %s: time span, such as "2 minutes". */
							: sprintf( __( '%s ago', 'campaign-bandit' ), human_time_diff( $connection['last_sync_at'] ) )
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connector version', 'campaign-bandit' ); ?></th>
				<td><?php echo esc_html( CAMPAIGNBANDIT_VERSION ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Hub campaigns', 'campaign-bandit' ); ?></th>
				<td>
					<?php $hub_campaigns = PackageApplier::known(); ?>
					<?php if ( array() === $hub_campaigns ) : ?>
						<span class="cbandit-muted"><?php esc_html_e( 'None yet. Campaigns the hub publishes to this site appear here.', 'campaign-bandit' ); ?></span>
					<?php else : ?>
						<ul class="cbandit-hub-campaigns">
							<?php foreach ( $hub_campaigns as $hub_campaign ) : ?>
								<li>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: campaign name, 2: version number. */
											__( '%1$s · version %2$d', 'campaign-bandit' ),
											'' !== $hub_campaign['name'] ? $hub_campaign['name'] : __( '(no name)', 'campaign-bandit' ),
											$hub_campaign['version']
										)
									);
									?>
									<span class="cbandit-status cbandit-status--<?php echo esc_attr( 'live' === $hub_campaign['state'] ? 'good' : 'neutral' ); ?>"><?php echo esc_html( 'live' === $hub_campaign['state'] ? __( 'Live', 'campaign-bandit' ) : __( 'Paused', 'campaign-bandit' ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="description"><?php esc_html_e( 'Managed on the hub: they can’t be edited here. Your own campaigns keep running alongside them.', 'campaign-bandit' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
		$stored = get_option( Connection::OPTION );
		if ( is_array( $stored ) && is_string( $stored['last_error'] ?? null ) && '' !== $stored['last_error'] ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				/* translators: %s: error message. */
				esc_html( sprintf( __( 'Last sync failed: %s The site keeps showing the campaigns it has.', 'campaign-bandit' ), $stored['last_error'] ) )
			);
		}
		self::disconnect_button();
	}

	/**
	 * Disconnect, with a confirmation.
	 */
	private static function disconnect_button(): void {
		$confirm = __( 'Disconnect from the hub? Hub campaigns stop showing on this site. Your own campaigns keep running.', 'campaign-bandit' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm( <?php echo esc_attr( (string) wp_json_encode( $confirm ) ); ?> );">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="op" value="disconnect" />
			<?php wp_nonce_field( self::ACTION . '_disconnect' ); ?>
			<p><button type="submit" class="button cbandit-button-delete"><?php esc_html_e( 'Disconnect', 'campaign-bandit' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Handle pairing and disconnecting.
	 */
	public static function handle(): void {
		$op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		check_admin_referer( self::ACTION . '_' . $op );
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change Campaign Bandit settings.', 'campaign-bandit' ), 403 );
		}
		$result = true;
		if ( 'pair' === $op ) {
			// The key is a credential: trimmed, never sanitised into something else.
			$key    = isset( $_POST['pairing_key'] ) ? trim( (string) wp_unslash( $_POST['pairing_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PairingKey::parse() accepts only the key alphabet.
			$result = Connector::pair( $key );
		} elseif ( 'disconnect' === $op ) {
			Connector::disconnect();
		}
		if ( is_wp_error( $result ) ) {
			set_transient( 'cbandit_connection_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( 'connection', is_wp_error( $result ) ? 'error' : $op . 'ed', SettingsScreen::url() ) );
		exit;
	}

	/**
	 * Result of the last action.
	 */
	private static function notice(): void {
		$done = isset( $_GET['connection'] ) ? sanitize_key( $_GET['connection'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'error' === $done ) {
			$name    = 'cbandit_connection_error_' . get_current_user_id();
			$message = get_transient( $name );
			delete_transient( $name );
			if ( is_string( $message ) ) {
				printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $message ) );
			}
		} elseif ( 'paired' === $done ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Connected. The hub can now publish campaigns to this site.', 'campaign-bandit' ) . '</p></div>';
		} elseif ( 'disconnected' === $done ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Disconnected. This site is standalone again.', 'campaign-bandit' ) . '</p></div>';
		}
	}
}
