<?php
/**
 * Downloads DB-IP Lite and builds the packed country index (BR-04, decision 0027).
 *
 * Only after an admin turns country targeting on. The work runs on WP-Cron in short
 * chunks with a time budget, so a shared host never has to do it all in one request,
 * and it refreshes monthly. Nothing about a visitor is involved: this reads a public
 * database and writes two files.
 *
 * The files are streamed with fopen/fwrite rather than WP_Filesystem, which only
 * writes a file whole: the point of streaming is never to hold 32 MB in memory.
 *
 * @package CampaignBandit
 */

namespace CampaignBandit\Geo;

use CampaignBandit\Settings\CountrySettings;

/**
 * The country database job.
 */
final class CountryDatabase {

	public const HOOK = 'campaignbandit_country_database';

	/** Where DB-IP publishes the free country database (CC BY 4.0). */
	public const SOURCE = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';

	/** What the job is doing, and what it built. */
	public const OPTION = 'campaignbandit_country_db';

	public const IDLE     = 'idle';
	public const BUILDING = 'building';
	public const READY    = 'ready';
	public const FAILED   = 'failed';

	/** Seconds of work per run, so a chunk fits well inside any host's limit. */
	private const BUDGET = 12;

	/** Lines read between budget checks. */
	private const CHUNK = 20000;

	/**
	 * Hook into WordPress.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action(
			'campaignbandit_deactivated',
			static function (): void {
				wp_clear_scheduled_hook( self::HOOK );
			}
		);
		// Turning country targeting on starts the first build; turning it off clears it.
		add_action( 'update_option_' . CountrySettings::OPTION, array( self::class, 'setting_changed' ), 10, 2 );
		add_action(
			'add_option_' . CountrySettings::OPTION,
			static function ( $name, $value ): void {
				unset( $name );
				self::setting_changed( '', $value );
			},
			10,
			2
		);
	}

	/**
	 * Keep the daily check scheduled while the database is wanted. The run itself does
	 * nothing unless there is work: a month-old index, or an unfinished build.
	 */
	public static function schedule(): void {
		if ( ! CountrySettings::database_enabled() ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Start a build when the setting is turned on; clear everything when turned off.
	 *
	 * @param mixed $before Old value.
	 * @param mixed $after  New value.
	 */
	public static function setting_changed( $before, $after ): void {
		if ( $before === $after ) {
			return;
		}
		if ( CountrySettings::DATABASE === $after ) {
			self::save( array( 'state' => self::BUILDING ) );
			self::schedule();
			if ( false === wp_next_scheduled( self::HOOK ) || wp_next_scheduled( self::HOOK ) > time() + 60 ) {
				wp_schedule_single_event( time() + 5, self::HOOK );
			}
			return;
		}
		self::remove();
	}

	/**
	 * What the job knows: state, the month built, records, when, and any error.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'state'      => self::IDLE,
				'version'    => '',
				'records'    => 0,
				'updated_at' => 0,
				'error'      => '',
				'lines'      => 0,
				'building'   => '',
				'file'       => '',
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * The folder the index lives in, created if needed, or '' when it can't be.
	 */
	public static function directory(): string {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$dir = $uploads['basedir'] . '/campaign-bandit/geo';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		// The index is a public database, but there is no reason to serve it.
		$guard = $dir . '/.htaccess';
		if ( ! is_file( $guard ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a plain guard file.
			file_put_contents( $guard, "Require all denied\n" );
		}
		return $dir;
	}

	/**
	 * Do a chunk of work: download the month's file, then read it into the index.
	 * Reschedules itself until the build is done.
	 */
	public static function run(): void {
		if ( ! CountrySettings::database_enabled() ) {
			return;
		}
		$status = self::status();
		$month  = gmdate( 'Y-m' );
		$dir    = self::directory();
		if ( '' === $dir ) {
			self::fail( __( 'The uploads folder can’t be written to, so the country database can’t be stored.', 'campaign-bandit' ) );
			return;
		}
		// Nothing to do: this month's index is in place. An index built in the last
		// week also waits, so a new month doesn't fetch the file again every day
		// before DB-IP has published it.
		if ( self::READY === $status['state']
			&& ( $status['version'] === $month || (int) $status['updated_at'] > time() - WEEK_IN_SECONDS ) ) {
			return;
		}
		if ( $status['building'] !== $month ) {
			// A new month: start again.
			self::clean_temp( $dir );
			$status = array_merge(
				$status,
				array(
					'state'    => self::BUILDING,
					'building' => $month,
					'lines'    => 0,
					'records'  => 0,
					'error'    => '',
					'file'     => '',
				)
			);
			self::save( $status );
		}

		$source = $dir . '/source.csv.gz';
		// The file is usually this month's, but early in a month it can be the last one.
		$file = is_file( $source ) ? (string) $status['file'] : self::download( $month, $source );
		if ( '' === $file ) {
			return;
		}
		if ( $status['file'] !== $file ) {
			self::save( array( 'file' => $file ) );
		}
		self::build( $dir, $source, (int) $status['lines'], $file );
	}

	/**
	 * Fetch the month's file, falling back to the previous month early in a new one.
	 *
	 * @param string $month  Y-m.
	 * @param string $target Where to put it.
	 * @return string The month downloaded, or '' when none could be.
	 */
	private static function download( string $month, string $target ): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$months = array( $month, gmdate( 'Y-m', strtotime( $month . '-01 -1 month' ) ) );
		foreach ( $months as $try ) {
			$temp = download_url( sprintf( self::SOURCE, $try ), 120 );
			if ( ! is_wp_error( $temp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- moving our own temp file; a failure is handled below.
				if ( @rename( $temp, $target ) ) {
					return $try;
				}
				wp_delete_file( $temp );
			}
		}
		self::fail( __( 'The country database could not be downloaded. The site may not be allowed to make outgoing requests.', 'campaign-bandit' ) );
		return '';
	}

	/**
	 * Read the CSV into the index, from the line reached last time, for a budget of
	 * seconds. Saves where it got to and comes back if there is more.
	 *
	 * @param string $dir    Index folder.
	 * @param string $source The downloaded .gz.
	 * @param int    $done   Lines already written.
	 * @param string $file   Y-m of the file being read.
	 */
	private static function build( string $dir, string $source, int $done, string $file ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed writes; see the file comment.
		$gz = gzopen( $source, 'rb' );
		if ( false === $gz ) {
			self::fail( __( 'The downloaded country database could not be read.', 'campaign-bandit' ) );
			return;
		}
		$v4 = fopen( $dir . '/ipv4.tmp', 0 === $done ? 'wb' : 'ab' );
		$v6 = fopen( $dir . '/ipv6.tmp', 0 === $done ? 'wb' : 'ab' );
		if ( false === $v4 || false === $v6 ) {
			gzclose( $gz );
			self::fail( __( 'The country index could not be written to the uploads folder.', 'campaign-bandit' ) );
			return;
		}

		$start = microtime( true );
		$line  = 0;
		// Skip what earlier runs already wrote.
		while ( $line < $done && ! gzeof( $gz ) ) {
			gzgets( $gz );
			++$line;
		}

		$packed4 = '';
		$packed6 = '';
		$written = 0;
		while ( ! gzeof( $gz ) ) {
			$row = gzgets( $gz );
			if ( false === $row ) {
				break;
			}
			++$line;
			$record = self::record( $row );
			if ( null !== $record ) {
				if ( 6 === strlen( $record ) ) {
					$packed4 .= $record;
				} else {
					$packed6 .= $record;
				}
				++$written;
			}
			if ( 0 === $line % self::CHUNK ) {
				fwrite( $v4, $packed4 );
				fwrite( $v6, $packed6 );
				$packed4 = '';
				$packed6 = '';
				if ( microtime( true ) - $start > self::BUDGET ) {
					break;
				}
			}
		}//end while
		fwrite( $v4, $packed4 );
		fwrite( $v6, $packed6 );
		$finished = gzeof( $gz );
		gzclose( $gz );
		fclose( $v4 );
		fclose( $v6 );

		$status            = self::status();
		$status['lines']   = $line;
		$status['records'] = (int) $status['records'] + $written;
		if ( ! $finished ) {
			self::save( $status );
			wp_schedule_single_event( time() + 30, self::HOOK );
			return;
		}
		self::finish( $dir, $file, $status );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/**
	 * Put the finished index in place and tidy up.
	 *
	 * @param string               $dir    Index folder.
	 * @param string               $file   Y-m of the file built from.
	 * @param array<string, mixed> $status Status so far.
	 */
	private static function finish( string $dir, string $file, array $status ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- moving our own files into place.
		$moved = @rename( $dir . '/ipv4.tmp', $dir . '/' . CountryIndex::V4_FILE ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- handled below.
			&& @rename( $dir . '/ipv6.tmp', $dir . '/' . CountryIndex::V6_FILE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- handled below.
		// phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
		self::clean_temp( $dir );
		if ( ! $moved ) {
			self::fail( __( 'The country index could not be saved.', 'campaign-bandit' ) );
			return;
		}
		self::save(
			array(
				'state'      => self::READY,
				'version'    => $file,
				'records'    => (int) $status['records'],
				'updated_at' => time(),
				'error'      => '',
				'lines'      => 0,
				'building'   => '',
				'file'       => '',
			)
		);
	}

	/**
	 * One CSV row as an index record, or null when it isn't one.
	 *
	 * @param string $row "1.0.0.0,1.0.0.255,AU".
	 */
	public static function record( string $row ): ?string {
		$parts = explode( ',', trim( $row ) );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$country = strtoupper( trim( $parts[2] ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return null;
		}
		$start = @inet_pton( trim( $parts[0] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false for a malformed address.
		if ( false === $start || ! in_array( strlen( $start ), array( 4, 16 ), true ) ) {
			return null;
		}
		return $start . $country;
	}

	/**
	 * Remove the index and everything about it (country targeting was turned off).
	 */
	public static function remove(): void {
		$dir = self::directory();
		if ( '' !== $dir ) {
			self::clean_temp( $dir );
			foreach ( array( CountryIndex::V4_FILE, CountryIndex::V6_FILE ) as $file ) {
				if ( is_file( $dir . '/' . $file ) ) {
					wp_delete_file( $dir . '/' . $file );
				}
			}
		}
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Delete the working files.
	 *
	 * @param string $dir Index folder.
	 */
	private static function clean_temp( string $dir ): void {
		foreach ( array( 'ipv4.tmp', 'ipv6.tmp', 'source.csv.gz' ) as $file ) {
			if ( is_file( $dir . '/' . $file ) ) {
				wp_delete_file( $dir . '/' . $file );
			}
		}
	}

	/**
	 * Record a failure, for Settings to show.
	 *
	 * @param string $message What went wrong.
	 */
	private static function fail( string $message ): void {
		self::save(
			array(
				'state' => self::FAILED,
				'error' => $message,
			)
		);
	}

	/**
	 * Save the status.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 */
	private static function save( array $changes ): void {
		update_option( self::OPTION, array_merge( self::status(), $changes ), false );
	}
}
