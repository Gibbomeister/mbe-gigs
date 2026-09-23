<?php
/**
 * Plugin Name:       MBE Gigs
 * Plugin URI:        https://mybusinessengine.com/
 * Description:       Gig listings as a custom post type, with venues and artists as taxonomies. Replaces GigPress.
 * Version:           2.2.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            My Business Engine
 * Author URI:        https://mybusinessengine.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       mbe-gigs
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

define( 'MBE_GIGS_VERSION', '2.2.1' );
define( 'MBE_GIGS_FILE', __FILE__ );
define( 'MBE_GIGS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MBE_GIGS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Post type, taxonomy and meta key names.
 *
 * These are the plugin's contract with the data. Everything else — admin screens,
 * Themer layouts, the importer — is replaceable. These are not.
 */
define( 'MBE_GIGS_CPT', 'mbe_gig' );
define( 'MBE_GIGS_TAX_VENUE', 'mbe_venue' );
define( 'MBE_GIGS_TAX_ARTIST', 'mbe_artist' );

/**
 * Where installs look for new versions. owner/repo of the public GitHub repository.
 */
define( 'MBE_GIGS_REPO', 'Gibbomeister/mbe-gigs' );

require_once MBE_GIGS_PATH . 'includes/class-post-type.php';
require_once MBE_GIGS_PATH . 'includes/class-meta.php';
require_once MBE_GIGS_PATH . 'includes/class-query.php';
require_once MBE_GIGS_PATH . 'includes/class-admin.php';
require_once MBE_GIGS_PATH . 'includes/class-themer.php';
require_once MBE_GIGS_PATH . 'includes/class-shortcode.php';
require_once MBE_GIGS_PATH . 'includes/class-updater.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MBE_GIGS_PATH . 'includes/class-importer.php';
}

/**
 * Boot the plugin.
 *
 * Registration happens on `init` at priority 5 so anything hooking `init` at the
 * default priority (Themer, child themes) sees a registered post type.
 */
function mbe_gigs_init() {
	MBE_Gigs_Post_Type::register();
	MBE_Gigs_Meta::register();
}
add_action( 'init', 'mbe_gigs_init', 5 );

MBE_Gigs_Post_Type::hooks();
MBE_Gigs_Meta::hooks();
MBE_Gigs_Query::hooks();
MBE_Gigs_Admin::hooks();
MBE_Gigs_Themer::hooks();
MBE_Gigs_Shortcode::hooks();
MBE_Gigs_Updater::hooks();

/**
 * Activation: register everything once, then flush rewrites.
 *
 * Flushing on activation only. Never on init — it is an expensive write on every
 * page load and a classic cause of mystery slowdowns.
 */
function mbe_gigs_activate() {
	mbe_gigs_init();
	flush_rewrite_rules();
	add_option( 'mbe_gigs_version', MBE_GIGS_VERSION );
}
register_activation_hook( __FILE__, 'mbe_gigs_activate' );

function mbe_gigs_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mbe_gigs_deactivate' );

/**
 * Today's date in the site's timezone, as YYYY-MM-DD.
 *
 * Gig dates are stored as local wall-clock dates with no timezone conversion — a
 * gig at 8pm in Perth is 8pm in Perth. The only thing that has to respect the
 * site's timezone is the cutoff between upcoming and past, which is this.
 *
 * @return string
 */
function mbe_gigs_today() {
	return current_time( 'Y-m-d' );
}
