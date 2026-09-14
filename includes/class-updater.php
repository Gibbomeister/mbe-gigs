<?php
/**
 * Update checks against GitHub releases.
 *
 * The point of owning this plugin rather than adding a vendor dependency was "one
 * plugin, versioned by me, updated in one place". That only becomes true if the sites
 * running it can see a new version. This is that piece: each install checks the
 * repository's latest release twice a day and, when the tag is newer than the version
 * in the plugin header, shows the ordinary WordPress update notice.
 *
 * No vendored library. It's one HTTP call and three filters, and a library that does
 * the same thing is another thing to keep current across eight sites.
 *
 * The repository is public, so nothing here handles credentials. If it ever goes
 * private, this file needs a token per site — which is a credential living on eight
 * client machines, and a different decision to make deliberately.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Updater {

	const TRANSIENT = 'mbe_gigs_latest_release';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_directory' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_check' ) );
	}

	/**
	 * @return string e.g. "mbe-gigs/mbe-gigs.php"
	 */
	public static function basename() {
		return plugin_basename( MBE_GIGS_FILE );
	}

	/**
	 * @return string Directory name, which is also the update slug.
	 */
	public static function slug() {
		return dirname( self::basename() );
	}

	/**
	 * Tell WordPress an update is available.
	 *
	 * @param object $transient The update_plugins transient.
	 * @return object
	 */
	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::get_release();

		if ( ! $release ) {
			return $transient;
		}

		$item = (object) array(
			'id'            => MBE_GIGS_REPO,
			'slug'          => self::slug(),
			'plugin'        => self::basename(),
			'new_version'   => $release['version'],
			'url'           => $release['url'],
			'package'       => $release['package'],
			'tested'        => $release['tested'],
			'requires_php'  => '7.4',
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);

		if ( version_compare( $release['version'], MBE_GIGS_VERSION, '>' ) && $release['package'] ) {
			$transient->response[ self::basename() ] = $item;
			unset( $transient->no_update[ self::basename() ] );
		} else {
			// Listing it here is what makes "enable auto-updates" work on the plugins screen.
			$transient->no_update[ self::basename() ] = $item;
		}

		return $transient;
	}

	/**
	 * Fill in the "View details" modal.
	 *
	 * @param false|object|array $result Result from the plugins API.
	 * @param string             $action Requested action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		$release = self::get_release();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'MBE Gigs',
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => '<a href="https://mybusinessengine.com/">My Business Engine</a>',
			'homepage'      => $release['url'],
			'requires'      => '6.4',
			'requires_php'  => '7.4',
			'tested'        => $release['tested'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => wpautop( esc_html__( 'Gig listings as a custom post type, with venues and artists as taxonomies.', 'mbe-gigs' ) ),
				'changelog'   => $release['notes'] ? wpautop( wp_kses_post( $release['notes'] ) ) : '',
			),
		);
	}

	/**
	 * Fetch the latest release, cached.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null
	 */
	public static function get_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			// A previous failure is cached too, briefly, so a dead network doesn't mean
			// an HTTP request on every admin page load.
			if ( 'none' === $cached ) {
				return null;
			}
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/releases/latest', MBE_GIGS_REPO ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'MBE-Gigs/' . MBE_GIGS_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::TRANSIENT, 'none', HOUR_IN_SECONDS );

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::TRANSIENT, 'none', HOUR_IN_SECONDS );

			return null;
		}

		/*
		 * The package must be a release ASSET, not GitHub's source zipball. A zipball
		 * extracts to "owner-repo-<sha>/", which WordPress would install as a second,
		 * separate plugin rather than an update. fix_source_directory() below renames
		 * it as a safety net, but a built asset is the correct answer and the release
		 * workflow produces one.
		 */
		$package = '';

		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['browser_download_url'], -4 ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}

		if ( ! $package && ! empty( $body['zipball_url'] ) ) {
			$package = $body['zipball_url'];
		}

		$release = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'url'       => isset( $body['html_url'] ) ? $body['html_url'] : '',
			'package'   => $package,
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
			'tested'    => get_bloginfo( 'version' ),
		);

		set_site_transient( self::TRANSIENT, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Make sure the extracted folder is named after the plugin.
	 *
	 * Only relevant if a release ever ships without a built asset and WordPress falls
	 * back to the source zipball. Renaming here turns a broken "installed twice"
	 * outcome into a normal update.
	 *
	 * @param string      $source        Extracted source directory.
	 * @param string      $remote_source Parent of the source directory.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra arguments.
	 * @return string|WP_Error
	 */
	public static function fix_source_directory( $source, $remote_source, $upgrader = null, $hook_extra = null ) {
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;

		$desired = trailingslashit( $remote_source ) . self::slug();

		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}

		// Never fail an update over a rename.
		return $source;
	}

	/**
	 * "Check for updates" link on the plugins screen.
	 *
	 * @param array  $links Row meta links.
	 * @param string $file  Plugin file.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( self::basename() !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg( 'mbe_gigs_check_update', '1', admin_url( 'plugins.php' ) ),
					'mbe_gigs_check_update'
				)
			),
			esc_html__( 'Check for updates', 'mbe-gigs' )
		);

		return $links;
	}

	/**
	 * Handle that link.
	 */
	public static function handle_manual_check() {
		if ( ! isset( $_GET['mbe_gigs_check_update'] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		check_admin_referer( 'mbe_gigs_check_update' );

		delete_site_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
