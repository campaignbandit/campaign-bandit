<?php
/**
 * Writes the campaign package to uploads/campaign-bandit/ whenever deployments change (decision 0009).
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Package;

use CampaignBandit\Campaign\CopyVariables;
use CampaignBandit\Campaign\LanguageCopy;
use CampaignBandit\Connect\Connection;
use CampaignBandit\Connect\PackageApplier;
use CampaignBandit\Plugin;
use CampaignBandit\Publishing\DeploymentRepository;
use CampaignBandit\Publishing\PublishingService;
use CampaignBandit\Publishing\Version;
use CampaignBandit\Publishing\VersionRepository;

/**
 * Builds the manifest and content files and keeps the "is anything live" flag
 * the front end checks before enqueueing the runtime (FR-01).
 */
final class PackageWriter {

	public const DIR             = 'campaign-bandit';
	public const OPTION_HAS_LIVE = 'campaignbandit_has_live';

	/**
	 * Core blocks used by live campaigns, whose styles load with the runtime.
	 */
	public const OPTION_BLOCKS   = 'campaignbandit_blocks_used';
	public const OPTION_DELIVERY = 'campaignbandit_package_delivery';
	public const OPTION_DEFAULTS = 'campaignbandit_default_excludes';
	public const DELIVERY_FILES  = 'files';
	public const DELIVERY_REST   = 'rest';

	/**
	 * Create the writer.
	 *
	 * @param DeploymentRepository $deployments Deployments.
	 * @param VersionRepository    $versions    Versions.
	 */
	public function __construct(
		private readonly DeploymentRepository $deployments,
		private readonly VersionRepository $versions,
	) {}

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( PublishingService::CHANGED_ACTION, static fn () => Plugin::package_writer()->write() );
	}

	/**
	 * Live deployments with their versions and campaign names.
	 *
	 * @return array<int, array{deployment: \CampaignBandit\Publishing\Deployment, version: Version, name: string}>
	 */
	public function live_entries(): array {
		$entries = array();
		foreach ( $this->deployments->live( 0 ) as $deployment ) {
			$version = $this->versions->find( $deployment->version_id );
			if ( null === $version ) {
				continue;
			}
			$campaign  = Plugin::campaigns()->get( $deployment->campaign_uid );
			$entries[] = array(
				'deployment' => $deployment,
				'version'    => $version,
				'name'       => null === $campaign ? PackageApplier::name( $deployment->campaign_uid ) : $campaign->name,
			);
		}
		return $entries;
	}

	/**
	 * Live entries any visitor may load. On a staging connection, hub campaigns are
	 * left out: they show only to logged-in editors (BR-33, see editor_preview()).
	 *
	 * @return array<int, array{deployment: \CampaignBandit\Publishing\Deployment, version: Version, name: string}>
	 */
	public function public_entries(): array {
		return array_values( array_filter( $this->live_entries(), static fn ( array $entry ): bool => ! self::staging_only( $entry['version']->campaign_uid ) ) );
	}

	/**
	 * Whether a campaign shows only to logged-in editors: a hub campaign on a site
	 * connected as staging (BR-33).
	 *
	 * @param string $campaign_uid Campaign ULID.
	 */
	public static function staging_only( string $campaign_uid ): bool {
		return Connection::is_staging() && PackageApplier::is_hub_campaign( $campaign_uid );
	}

	/**
	 * On a staging connection, the hub campaigns for a logged-in editor: their manifest
	 * entries and content, sent inline with the page so they never sit in a public
	 * file. Null when there are none.
	 *
	 * @return array{deployments: array<int, array<string, mixed>>, content: array<string, string>}|null
	 */
	public function editor_preview(): ?array {
		if ( ! Connection::is_staging() ) {
			return null;
		}
		$entries = array_values( array_filter( $this->live_entries(), static fn ( array $entry ): bool => self::staging_only( $entry['version']->campaign_uid ) ) );
		if ( array() === $entries ) {
			return null;
		}
		$content = array();
		foreach ( $entries as $entry ) {
			$content[ ManifestBuilder::content_path( $entry['version'] ) ] = self::content_html( $entry['version'] );
		}
		return array(
			'deployments' => $this->manifest( $entries )['deployments'],
			'content'     => $content,
		);
	}

	/**
	 * Build the manifest for this install.
	 *
	 * @param array<int, array{deployment: \CampaignBandit\Publishing\Deployment, version: Version, name: string}>|null $entries Entries, or null to load them.
	 * @return array<string, mixed>
	 */
	public function manifest( ?array $entries = null ): array {
		$builder = new ManifestBuilder( wp_timezone(), self::default_excludes() );
		return $builder->build( $entries ?? $this->public_entries(), (int) floor( microtime( true ) * 1000 ) );
	}

	/**
	 * Content file for one version: its blocks rendered on this site.
	 *
	 * @param Version $version Version.
	 * @return array{campaign: string, version: int, html: string}
	 */
	public static function content( Version $version ): array {
		return array(
			'campaign' => $version->campaign_uid,
			'version'  => $version->number,
			'html'     => self::content_html( $version ),
		);
	}

	/**
	 * A version's blocks rendered on this site. Content is filtered before display,
	 * because on connected sites it comes from the hub (05 §8).
	 *
	 * @param Version $version Version.
	 */
	public static function content_html( Version $version ): string {
		$html = wp_kses( do_blocks( self::with_site_text( $version ) ), ContentPolicy::allowed_html() );
		$html = self::with_variables( $html, $version );
		$css  = self::supports_css( $html );
		return '' === $css ? $html : $html . '<style>' . $css . '</style>';
	}

	/**
	 * This version's block markup with a site's own words in place of the campaign's
	 * (BR-21, decision 0029). On the hub's own install, and on a site that has set no
	 * language copy, the content is returned untouched.
	 *
	 * @param Version $version Version.
	 */
	private static function with_site_text( Version $version ): string {
		$replacements = LanguageCopy::normalise( $version->settings->get( 'text' ) );
		if ( array() === $replacements ) {
			return $version->content;
		}
		return serialize_blocks( LanguageCopy::apply( parse_blocks( $version->content ), $replacements ) );
	}

	/**
	 * This version's copy variables substituted into its rendered content (FR-26,
	 * decision 0028). Values are escaped as text, after the content has been filtered,
	 * so a value can never introduce markup. On a connected site the values are that
	 * site's own: the hub writes them over the defaults when it resolves the site's
	 * settings (BR-21).
	 *
	 * @param string  $html    Rendered content.
	 * @param Version $version Version.
	 */
	private static function with_variables( string $html, Version $version ): string {
		$values = CopyVariables::resolve( (array) $version->settings->get( 'variables' ) );
		return CopyVariables::render( $html, array_map( 'esc_html', $values ) );
	}

	/**
	 * The CSS WordPress generated for this content's layout and element settings
	 * (gaps, flex rows, alignment, link colours). On a normal page WordPress prints it
	 * in the page head; campaign HTML is fetched on its own, so it travels with it.
	 * Only rules for classes that appear in the content are kept.
	 *
	 * @param string $html Rendered content.
	 */
	private static function supports_css( string $html ): string {
		if ( ! class_exists( 'WP_Style_Engine_CSS_Rules_Store' ) ) {
			return '';
		}
		$css = '';
		foreach ( \WP_Style_Engine_CSS_Rules_Store::get_store( 'block-supports' )->get_all_rules() as $selector => $rule ) {
			preg_match_all( '/\.(wp-(?:container|elements)-[A-Za-z0-9_-]+)/', (string) $selector, $classes );
			foreach ( array_unique( $classes[1] ) as $class ) {
				if ( str_contains( $html, $class ) ) {
					$css .= $rule->get_css();
					break;
				}
			}
		}
		// It is generated, but it ends up inside a style element: no tags.
		return str_replace( '<', '', $css );
	}

	/**
	 * The core blocks live campaigns use. WordPress loads a core block's stylesheet only
	 * on pages whose own content uses it; campaign HTML arrives separately, so the
	 * runtime loads these with it.
	 *
	 * @param array<int, array{version: Version}> $entries Live entries.
	 * @return string[] Block names without "core/", such as "cover".
	 */
	public static function blocks_used( array $entries ): array {
		$names = array();
		foreach ( $entries as $entry ) {
			preg_match_all( '/<!-- wp:([a-z][a-z0-9-]*)(?=\s)/', $entry['version']->content, $m );
			$names = array_merge( $names, $m[1] );
		}
		$names = array_values( array_unique( $names ) );
		sort( $names );
		return $names;
	}

	/**
	 * Write manifest and content files, or fall back to REST delivery when uploads aren't writable.
	 */
	public function write(): void {
		// The runtime loads when anything is live, including staging-only campaigns for editors.
		$live = $this->live_entries();
		update_option( self::OPTION_HAS_LIVE, array() !== $live );
		update_option( self::OPTION_BLOCKS, self::blocks_used( $live ), true );
		$entries = $this->public_entries();

		$dir = self::directory();
		if ( null === $dir || ! wp_mkdir_p( $dir . '/c' ) || ! wp_is_writable( $dir ) ) {
			update_option( self::OPTION_DELIVERY, self::DELIVERY_REST );
			return;
		}

		$keep = array();
		foreach ( $entries as $entry ) {
			$relative = ManifestBuilder::content_path( $entry['version'] );
			$keep[]   = basename( $relative );
			$file     = $dir . '/' . $relative;
			// Content files never change once written; write each version once.
			if ( ! file_exists( $file ) ) {
				self::write_json( $file, self::content( $entry['version'] ) );
			}
		}

		self::write_json( $dir . '/manifest.json', $this->manifest( $entries ) );
		self::remove_unused( $dir . '/c', $keep );
		update_option( self::OPTION_DELIVERY, self::DELIVERY_FILES );
	}

	/**
	 * Public base URL of the package, with trailing slash.
	 */
	public static function base_url(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( set_url_scheme( $uploads['baseurl'] ) ) . self::DIR . '/';
	}

	/**
	 * Site default exclusions (BR-01): login, admin, and the shop's cart and checkout. [confirm]
	 *
	 * @return string[]
	 */
	public static function default_excludes(): array {
		$saved = get_option( self::OPTION_DEFAULTS, null );
		if ( is_array( $saved ) ) {
			return array_values( array_filter( $saved, 'is_string' ) );
		}

		$defaults = array( '/wp-login.php', '/wp-admin*' );
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$path = wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
					if ( is_string( $path ) && '' !== $path ) {
						$defaults[] = untrailingslashit( $path ) . '*';
					}
				}
			}
		}
		return $defaults;
	}

	/**
	 * Absolute package directory, or null when uploads are unavailable.
	 */
	private static function directory(): ?string {
		$uploads = wp_upload_dir( null, false );
		return empty( $uploads['error'] ) ? $uploads['basedir'] . '/' . self::DIR : null;
	}

	/**
	 * Write JSON atomically: to a temporary file, then rename over the target.
	 *
	 * @param string               $file Target file.
	 * @param array<string, mixed> $data Data.
	 */
	private static function write_json( string $file, array $data ): void {
		$tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false !== file_put_contents( $tmp, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}
	}

	/**
	 * Delete content files no live deployment uses any more.
	 *
	 * @param string   $dir  Content directory.
	 * @param string[] $keep File names to keep.
	 */
	private static function remove_unused( string $dir, array $keep ): void {
		foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
			if ( is_string( $file ) && ! in_array( basename( $file ), $keep, true ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
