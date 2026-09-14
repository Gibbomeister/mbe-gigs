<?php
/**
 * Query helpers.
 *
 * Two rules run through all of this:
 *
 * 1. Meta comparisons on the gig date set 'type' => 'DATE'. Without it MySQL
 *    compares the values as strings, which happens to work for YYYY-MM-DD right up
 *    until it doesn't.
 * 2. The upcoming/past cutoff compares DATES ONLY, never a concatenated date and
 *    time. A large share of gigs have no time at all, and a string comparison
 *    against "2026-09-14 " sorts them unpredictably within the day.
 *
 * Flipping `direction` is the whole difference between a client site's "what's on"
 * list and a historical archive.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Query {

	public static function hooks() {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_front_end_archives' ) );
	}

	/**
	 * Build WP_Query arguments for a gig list.
	 *
	 * @param array $args {
	 *     @type string $direction 'upcoming', 'past' or 'all'. Default 'upcoming'.
	 *     @type string $order     'ASC' or 'DESC'. Defaults to soonest-first for
	 *                             upcoming, most-recent-first for past.
	 *     @type int    $limit     Posts per page. Default 10, -1 for all.
	 *     @type mixed  $venue     Venue term ID, slug, or array of either.
	 *     @type mixed  $artist    Artist term ID, slug, or array of either.
	 *     @type string $date      Cutoff date, YYYY-MM-DD. Defaults to today in the
	 *                             site's timezone.
	 * }
	 * @return array
	 */
	public static function args( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'direction' => 'upcoming',
				'order'     => '',
				'limit'     => 10,
				'venue'     => '',
				'artist'    => '',
				'date'      => '',
				'status'    => '',
			)
		);

		$direction = in_array( $args['direction'], array( 'upcoming', 'past', 'all' ), true )
			? $args['direction']
			: 'upcoming';

		$today = $args['date'] ? MBE_Gigs_Meta::sanitize_date( $args['date'] ) : mbe_gigs_today();

		if ( '' === $today ) {
			$today = mbe_gigs_today();
		}

		$order = strtoupper( $args['order'] );

		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			// Upcoming reads forwards from today; past reads backwards from today.
			$order = ( 'past' === $direction ) ? 'DESC' : 'ASC';
		}

		$query = array(
			'post_type'           => MBE_GIGS_CPT,
			'post_status'         => 'publish',
			'posts_per_page'      => (int) $args['limit'],
			'ignore_sticky_posts' => true,
			'no_found_rows'       => ( (int) $args['limit'] > 0 ) ? false : true,
			'meta_query'          => self::meta_query( $direction, $today ),
			'orderby'             => array(
				'mbe_gig_date_clause' => $order,
				'title'               => 'ASC',
			),
		);

		$tax_query = array();

		if ( $args['venue'] ) {
			$tax_query[] = self::tax_clause( MBE_GIGS_TAX_VENUE, $args['venue'] );
		}

		if ( $args['artist'] ) {
			$tax_query[] = self::tax_clause( MBE_GIGS_TAX_ARTIST, $args['artist'] );
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		if ( $tax_query ) {
			$query['tax_query'] = $tax_query;
		}

		if ( $args['status'] ) {
			$query['meta_query']['gig_status_clause'] = array(
				'key'     => 'mbe_gig_status',
				'value'   => (array) $args['status'],
				'compare' => 'IN',
			);
		}

		return apply_filters( 'mbe_gigs_query_args', $query, $args, $direction, $today );
	}

	/**
	 * The meta query, including the ordering clause.
	 *
	 * `mbe_gig_date_clause` exists purely so `orderby` has something named to sort
	 * on. It uses EXISTS, which means a gig with no date is excluded from every
	 * list — which is correct. A gig without a date is not a gig yet.
	 *
	 * Multi-day events are handled by comparing against the end date where one is
	 * set: a festival running across today reads as current, not past.
	 *
	 * @param string $direction 'upcoming', 'past' or 'all'.
	 * @param string $today     Cutoff date, YYYY-MM-DD.
	 * @return array
	 */
	public static function meta_query( $direction, $today ) {
		$meta_query = array(
			'mbe_gig_date_clause' => array(
				'key'     => 'mbe_gig_date',
				'type'    => 'DATE',
				'compare' => 'EXISTS',
			),
		);

		if ( 'all' === $direction ) {
			return $meta_query;
		}

		// "This gig has no end date" — either no row at all, or an empty one.
		$no_end_date = array(
			'relation' => 'OR',
			array(
				'key'     => 'mbe_gig_end_date',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => 'mbe_gig_end_date',
				'value'   => '',
				'compare' => '=',
			),
		);

		if ( 'upcoming' === $direction ) {
			$window = array(
				'relation' => 'OR',
				// Multi-day event that hasn't finished yet.
				array(
					'key'     => 'mbe_gig_end_date',
					'value'   => $today,
					'compare' => '>=',
					'type'    => 'DATE',
				),
				// Single-day gig, today or later.
				array(
					'relation' => 'AND',
					$no_end_date,
					array(
						'key'     => 'mbe_gig_date',
						'value'   => $today,
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
			);
		} else {
			$window = array(
				'relation' => 'OR',
				// Multi-day event that has finished.
				array(
					'key'     => 'mbe_gig_end_date',
					'value'   => $today,
					'compare' => '<',
					'type'    => 'DATE',
				),
				// Single-day gig before today.
				array(
					'relation' => 'AND',
					$no_end_date,
					array(
						'key'     => 'mbe_gig_date',
						'value'   => $today,
						'compare' => '<',
						'type'    => 'DATE',
					),
				),
			);
		}

		$meta_query['relation']         = 'AND';
		$meta_query['mbe_gig_window']   = $window;

		return $meta_query;
	}

	/**
	 * @param string $taxonomy Taxonomy name.
	 * @param mixed  $value    Term ID(s) or slug(s).
	 * @return array
	 */
	protected static function tax_clause( $taxonomy, $value ) {
		$values  = (array) $value;
		$numeric = true;

		foreach ( $values as $v ) {
			if ( ! is_numeric( $v ) ) {
				$numeric = false;
				break;
			}
		}

		return array(
			'taxonomy' => $taxonomy,
			'field'    => $numeric ? 'term_id' : 'slug',
			'terms'    => $numeric ? array_map( 'intval', $values ) : array_map( 'sanitize_title', $values ),
		);
	}

	/**
	 * Run a gig query.
	 *
	 * @param array $args See args().
	 * @return WP_Query
	 */
	public static function get( $args = array() ) {
		return new WP_Query( self::args( $args ) );
	}

	/**
	 * @param array $args See args().
	 * @return WP_Query
	 */
	public static function upcoming( $args = array() ) {
		$args['direction'] = 'upcoming';

		return self::get( $args );
	}

	/**
	 * @param array $args See args().
	 * @return WP_Query
	 */
	public static function past( $args = array() ) {
		$args['direction'] = 'past';

		return self::get( $args );
	}

	/**
	 * Apply the same ordering and window to the real archive queries.
	 *
	 * This is what Beaver Themer's archive layouts run on — Themer renders the main
	 * query, so filtering it here is what makes "upcoming gigs, soonest first"
	 * possible at all. Themer's own UI can order by a meta value but cannot compare
	 * one against today as a date.
	 *
	 * Per-site override, in a one-line mu-plugin:
	 *
	 *     add_filter( 'mbe_gigs_archive_direction', function () { return 'past'; } );
	 *
	 * @param WP_Query $query Query object.
	 */
	public static function filter_front_end_archives( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$is_gig_archive = $query->is_post_type_archive( MBE_GIGS_CPT )
			|| $query->is_tax( MBE_GIGS_TAX_VENUE )
			|| $query->is_tax( MBE_GIGS_TAX_ARTIST );

		if ( ! $is_gig_archive ) {
			return;
		}

		$direction = apply_filters( 'mbe_gigs_archive_direction', 'upcoming', $query );

		if ( ! in_array( $direction, array( 'upcoming', 'past', 'all' ), true ) ) {
			return;
		}

		$today = mbe_gigs_today();
		$order = ( 'past' === $direction ) ? 'DESC' : 'ASC';

		$query->set( 'meta_query', self::meta_query( $direction, $today ) );
		$query->set(
			'orderby',
			array(
				'mbe_gig_date_clause' => $order,
				'title'               => 'ASC',
			)
		);
	}

	/**
	 * Formatted date for display.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $format  Optional PHP date format. Defaults to the site setting.
	 * @return string
	 */
	public static function get_date( $post_id = 0, $format = '' ) {
		$post_id = $post_id ? (int) $post_id : get_the_ID();
		$date    = (string) get_post_meta( $post_id, 'mbe_gig_date', true );

		if ( '' === $date ) {
			return '';
		}

		$format = $format ? $format : get_option( 'date_format' );

		// Midday avoids any chance of a timezone shift moving the day.
		return date_i18n( $format, strtotime( $date . ' 12:00:00' ) );
	}

	/**
	 * Formatted time for display. Empty string when the time is unknown.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $format  Optional PHP time format.
	 * @return string
	 */
	public static function get_time( $post_id = 0, $format = '' ) {
		$post_id = $post_id ? (int) $post_id : get_the_ID();
		$time    = (string) get_post_meta( $post_id, 'mbe_gig_time', true );

		if ( '' === $time ) {
			return '';
		}

		$format = $format ? $format : get_option( 'time_format' );

		return date_i18n( $format, strtotime( '2000-01-01 ' . $time . ':00' ) );
	}

	/**
	 * Venue term for a gig.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Term|null
	 */
	public static function get_venue( $post_id = 0 ) {
		$post_id = $post_id ? (int) $post_id : get_the_ID();
		$terms   = get_the_terms( $post_id, MBE_GIGS_TAX_VENUE );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}

		return reset( $terms );
	}

	/**
	 * "Kingsgrove RSL, Kingsgrove NSW" — venue name plus whatever location detail exists.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_venue_line( $post_id = 0 ) {
		$venue = self::get_venue( $post_id );

		if ( ! $venue ) {
			return '';
		}

		$city  = (string) get_term_meta( $venue->term_id, 'mbe_venue_city', true );
		$state = (string) get_term_meta( $venue->term_id, 'mbe_venue_state', true );

		$location = trim( $city . ( $state ? ' ' . $state : '' ) );

		return $location ? $venue->name . ', ' . $location : $venue->name;
	}
}
