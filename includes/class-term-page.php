<?php
/**
 * Venue and artist pages.
 *
 * Left to the theme, /venue/<slug>/ renders as a blog archive: each gig a post
 * heading with a "Read More" link to a single gig page that doesn't exist (they're
 * off by default and redirect to /gigs/). Themer can't fix that for the same reason it
 * can't lay out the main gig list — its Posts module has no way to build an item from
 * gig fields.
 *
 * So the plugin draws these pages itself, the same way [mbe_gigs] draws the gig list:
 *
 *     Venue name
 *     Street address (map link) · City STATE postcode · Website
 *     Played here 14 times, 1978–2019
 *
 *     Upcoming      — only when something is booked
 *     Past gigs     — the history, most recent first
 *
 * The page is wrapped in the theme's own header and footer, so it sits inside the
 * site like any other page. The lists are the [mbe_gigs] markup and stylesheet, so a
 * fix to the gig list reaches these pages through the same update.
 *
 * Stepping aside:
 *
 * - A Themer archive layout assigned to venues or artists wins. Someone who built
 *   one wanted it.
 * - add_filter( 'mbe_gigs_term_template', '__return_false' ) hands the page back to
 *   the theme on one site.
 *
 * Thin pages: most venues on a band site have one or two gigs. Pages below
 * mbe_gigs_term_index_min (default 3) are noindexed and left out of the sitemap, so a
 * search engine sees the handful of venues with a real history rather than hundreds of
 * near-empty pages. They still work for a visitor who clicks through.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Term_Page {

	public static function hooks() {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wpseo_robots_array', array( __CLASS__, 'yoast_robots' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'sitemap_query_args' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_term_ids', array( __CLASS__, 'yoast_sitemap_exclude' ) );
	}

	/**
	 * The venue or artist term being viewed, or null anywhere else.
	 *
	 * @return WP_Term|null
	 */
	public static function current_term() {
		if ( ! is_tax( array( MBE_GIGS_TAX_VENUE, MBE_GIGS_TAX_ARTIST ) ) ) {
			return null;
		}

		$term = get_queried_object();

		return ( $term instanceof WP_Term ) ? $term : null;
	}

	/**
	 * Whether the plugin draws venue and artist pages on this site.
	 *
	 * @param WP_Term $term Term being viewed.
	 * @return bool
	 */
	public static function enabled( $term ) {
		return (bool) apply_filters( 'mbe_gigs_term_template', true, $term );
	}

	/**
	 * Swap in the plugin's template on venue and artist pages.
	 *
	 * @param string $template Template the theme chose.
	 * @return string
	 */
	public static function template_include( $template ) {
		$term = self::current_term();

		if ( ! $term || ! self::enabled( $term ) || self::themer_has_layout() ) {
			return $template;
		}

		return MBE_GIGS_PATH . 'templates/term.php';
	}

	/**
	 * Whether a Beaver Themer layout is assigned to the current page.
	 *
	 * Guarded, because Themer may be absent, disabled or a version with a different API.
	 * If it can't be asked, assume no layout — the plugin page is the safe default.
	 *
	 * @return bool
	 */
	protected static function themer_has_layout() {
		if ( ! class_exists( 'FLThemeBuilderLayoutData' ) || ! method_exists( 'FLThemeBuilderLayoutData', 'get_current_page_content_ids' ) ) {
			return false;
		}

		$ids = FLThemeBuilderLayoutData::get_current_page_content_ids();

		return ! empty( $ids );
	}

	/**
	 * Gig dates for a term, most recent first.
	 *
	 * IDs only, and one meta read per gig — the largest site has 573 gigs across all
	 * its venues, so a single venue is a few dozen rows at most.
	 *
	 * @param WP_Term $term      Venue or artist.
	 * @param string  $direction upcoming or past.
	 * @return string[] YYYY-MM-DD dates in query order.
	 */
	public static function dates( $term, $direction ) {
		$key  = ( MBE_GIGS_TAX_VENUE === $term->taxonomy ) ? 'venue' : 'artist';
		$args = MBE_Gigs_Query::args(
			array(
				'direction' => $direction,
				'limit'     => -1,
				$key        => $term->term_id,
			)
		);

		$args['fields']        = 'ids';
		$args['no_found_rows'] = true;

		$ids   = get_posts( $args );
		$dates = array();

		foreach ( $ids as $id ) {
			$dates[] = (string) get_post_meta( $id, 'mbe_gig_date', true );
		}

		return $dates;
	}

	/**
	 * "Played here 14 times, 1978–2019" for a venue; "14 gigs, 1978–2019" for an artist.
	 *
	 * @param WP_Term  $term       Venue or artist.
	 * @param string[] $past_dates Past gig dates, most recent first.
	 * @return string
	 */
	public static function summary( $term, $past_dates ) {
		$count = count( $past_dates );

		if ( ! $count ) {
			return '';
		}

		$latest   = substr( reset( $past_dates ), 0, 4 );
		$earliest = substr( end( $past_dates ), 0, 4 );
		$span     = ( $earliest === $latest ) ? $latest : $earliest . '–' . $latest;

		if ( MBE_GIGS_TAX_VENUE === $term->taxonomy ) {
			$text = ( 1 === $count )
				/* translators: %s: year */
				? sprintf( __( 'Played here once, in %s', 'mbe-gigs' ), $span )
				/* translators: 1: number of gigs, 2: year or range of years */
				: sprintf( _n( 'Played here %1$s time, %2$s', 'Played here %1$s times, %2$s', $count, 'mbe-gigs' ), number_format_i18n( $count ), $span );
		} else {
			/* translators: 1: number of gigs, 2: year or range of years */
			$text = sprintf( _n( '%1$s gig, %2$s', '%1$s gigs, %2$s', $count, 'mbe-gigs' ), number_format_i18n( $count ), $span );
		}

		return (string) apply_filters( 'mbe_gigs_term_summary', $text, $term, $past_dates );
	}

	/**
	 * The venue's address, place, and website as one line of inline items.
	 *
	 * @param WP_Term $venue Venue term.
	 * @return string Escaped HTML, empty when there's nothing to show.
	 */
	public static function venue_details( $venue ) {
		$meta = array();

		foreach ( array( 'address', 'city', 'state', 'postcode', 'country', 'url' ) as $key ) {
			$meta[ $key ] = trim( (string) get_term_meta( $venue->term_id, 'mbe_venue_' . $key, true ) );
		}

		$place = trim( $meta['city'] . ' ' . $meta['state'] . ' ' . $meta['postcode'] );
		$place = preg_replace( '/\s+/', ' ', $place );
		$items = array();

		if ( '' !== $meta['address'] ) {
			// Only the street is the link text; the rest steers the pin into the right town.
			$query = implode( ', ', array_filter( array( $meta['address'], $meta['city'], $meta['state'], $meta['postcode'], $meta['country'] ) ) );

			$items[] = sprintf(
				'<a class="mbe-gigs-term__address" href="%s" rel="noopener">%s</a>',
				esc_url( 'https://maps.google.com/maps?q=' . rawurlencode( $query ) ),
				esc_html( $meta['address'] )
			);
		}

		if ( '' !== $place ) {
			$items[] = sprintf( '<span class="mbe-gigs-term__place">%s</span>', esc_html( $place ) );
		}

		if ( '' !== $meta['url'] ) {
			$items[] = sprintf(
				'<a class="mbe-gigs-term__website" href="%s" rel="noopener">%s</a>',
				esc_url( $meta['url'] ),
				esc_html__( 'Website', 'mbe-gigs' )
			);
		}

		if ( ! $items ) {
			return '';
		}

		return '<p class="mbe-gigs-term__details"><span>' . implode( '</span> <span>', $items ) . '</span></p>';
	}

	/**
	 * Shortcode attributes for one list on the page.
	 *
	 * Per site, e.g. calendar tiles on venue pages:
	 *
	 *     add_filter( 'mbe_gigs_term_shortcode_atts', function ( $atts ) {
	 *         $atts['layout'] = 'tiles';
	 *         return $atts;
	 *     } );
	 *
	 * @param WP_Term $term      Venue or artist.
	 * @param string  $direction upcoming or past.
	 * @return array
	 */
	public static function list_atts( $term, $direction ) {
		$is_venue = ( MBE_GIGS_TAX_VENUE === $term->taxonomy );

		$atts = array(
			'direction'   => $direction,
			'limit'       => -1,
			$is_venue ? 'venue' : 'artist' => $term->term_id,
			// A venue page linking every row back to itself is noise.
			'venue_link'  => $is_venue ? 'none' : 'archive',
			// Same for the artist on their own page.
			'show_artist' => $is_venue ? 'auto' : 'no',
		);

		return (array) apply_filters( 'mbe_gigs_term_shortcode_atts', $atts, $term, $direction );
	}

	/**
	 * Whether this term's page is worth indexing.
	 *
	 *     add_filter( 'mbe_gigs_term_index_min', function () { return 1; } ); // index all
	 *
	 * @param WP_Term $term Venue or artist.
	 * @return bool
	 */
	public static function indexable( $term ) {
		return (int) $term->count >= self::index_min();
	}

	/**
	 * @return int
	 */
	protected static function index_min() {
		return max( 0, (int) apply_filters( 'mbe_gigs_term_index_min', 3 ) );
	}

	/**
	 * Core robots meta.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		$term = self::current_term();

		if ( $term && ! self::indexable( $term ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['max-image-preview'] );
		}

		return $robots;
	}

	/**
	 * Yoast replaces core's robots output with its own.
	 *
	 * @param array $robots Directives, keyed by name.
	 * @return array
	 */
	public static function yoast_robots( $robots ) {
		$term = self::current_term();

		if ( $term && ! self::indexable( $term ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
		}

		return $robots;
	}

	/**
	 * Thin terms out of the core sitemap.
	 *
	 * @param array  $args     get_terms() arguments.
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	public static function sitemap_query_args( $args, $taxonomy ) {
		if ( ! in_array( $taxonomy, array( MBE_GIGS_TAX_VENUE, MBE_GIGS_TAX_ARTIST ), true ) ) {
			return $args;
		}

		$thin = self::thin_term_ids( $taxonomy );

		if ( $thin ) {
			$args['exclude'] = array_merge( isset( $args['exclude'] ) ? (array) $args['exclude'] : array(), $thin );
		}

		return $args;
	}

	/**
	 * Thin terms out of Yoast's sitemap.
	 *
	 * @param int[] $ids Excluded term IDs.
	 * @return int[]
	 */
	public static function yoast_sitemap_exclude( $ids ) {
		return array_merge(
			(array) $ids,
			self::thin_term_ids( MBE_GIGS_TAX_VENUE ),
			self::thin_term_ids( MBE_GIGS_TAX_ARTIST )
		);
	}

	/**
	 * Term IDs below the index threshold.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	protected static function thin_term_ids( $taxonomy ) {
		$min = self::index_min();

		if ( $min < 1 ) {
			return array();
		}

		$counts = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'id=>count',
			)
		);

		if ( is_wp_error( $counts ) ) {
			return array();
		}

		$thin = array();

		foreach ( $counts as $id => $count ) {
			if ( (int) $count < $min ) {
				$thin[] = (int) $id;
			}
		}

		return $thin;
	}
}
