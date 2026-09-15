<?php
/**
 * Beaver Themer integration.
 *
 * Two jobs:
 *
 * 1. Expose gig fields as Themer field connections, so layouts are built visually
 *    instead of as PHP templates maintained across eight sites.
 * 2. Surface venue detail — address, city, state — as POST properties rather than
 *    term properties. Themer's handling of post meta is solid; its term meta support
 *    varies by version, and on a gig the venue detail is one hop away anyway. Reading
 *    it through the post sidesteps the whole question.
 *
 * Everything here is guarded. The plugin must work with Themer absent, disabled, or
 * removed halfway through a client's contract — the data lives in the post type, not
 * in the page builder.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Themer {

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'register_connections' ), 20 );
		add_filter( 'fl_builder_loop_query_args', array( __CLASS__, 'loop_query_args' ) );
	}

	/**
	 * Register field connections with Themer's page data API.
	 */
	public static function register_connections() {
		if ( ! class_exists( 'FLPageData' ) || ! method_exists( 'FLPageData', 'add_post_property' ) ) {
			return;
		}

		$properties = array(
			'mbe_gig_date'       => __( 'Gig: date', 'mbe-gigs' ),
			'mbe_gig_time'       => __( 'Gig: time', 'mbe-gigs' ),
			'mbe_gig_end_date'   => __( 'Gig: end date', 'mbe-gigs' ),
			'mbe_gig_price'      => __( 'Gig: price', 'mbe-gigs' ),
			'mbe_gig_tour'       => __( 'Gig: tour', 'mbe-gigs' ),
			'mbe_gig_status'     => __( 'Gig: status', 'mbe-gigs' ),
			'mbe_gig_venue'      => __( 'Gig: venue name', 'mbe-gigs' ),
			'mbe_gig_venue_line' => __( 'Gig: venue and location', 'mbe-gigs' ),
			'mbe_gig_venue_city' => __( 'Gig: venue city', 'mbe-gigs' ),
			'mbe_gig_venue_state' => __( 'Gig: venue state', 'mbe-gigs' ),
			'mbe_gig_venue_address' => __( 'Gig: venue address', 'mbe-gigs' ),
			'mbe_gig_artist'     => __( 'Gig: artist', 'mbe-gigs' ),
		);

		foreach ( $properties as $key => $label ) {
			FLPageData::add_post_property(
				$key,
				array(
					'label'  => $label,
					'group'  => 'post',
					'type'   => array( 'string' ),
					'getter' => array( __CLASS__, 'get_property' ),
				)
			);
		}

		// URLs get their own type so Themer offers them as link targets.
		$links = array(
			'mbe_gig_ticket_url' => __( 'Gig: ticket link', 'mbe-gigs' ),
			'mbe_gig_venue_url'  => __( 'Gig: venue website', 'mbe-gigs' ),
		);

		foreach ( $links as $key => $label ) {
			FLPageData::add_post_property(
				$key,
				array(
					'label'  => $label,
					'group'  => 'post',
					'type'   => array( 'string', 'link', 'url' ),
					'getter' => array( __CLASS__, 'get_property' ),
				)
			);
		}
	}

	/**
	 * Resolve one property for the current post.
	 *
	 * @param array  $settings Themer field settings (unused).
	 * @param string $property Property key.
	 * @return string
	 */
	public static function get_property( $settings = array(), $property = '' ) {
		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return '';
		}

		switch ( $property ) {
			case 'mbe_gig_date':
				return MBE_Gigs_Query::get_date( $post_id );

			case 'mbe_gig_time':
				return MBE_Gigs_Query::get_time( $post_id );

			case 'mbe_gig_end_date':
				$end = (string) get_post_meta( $post_id, 'mbe_gig_end_date', true );

				return $end ? date_i18n( get_option( 'date_format' ), strtotime( $end . ' 12:00:00' ) ) : '';

			case 'mbe_gig_status':
				$statuses = MBE_Gigs_Meta::statuses();
				$status   = (string) get_post_meta( $post_id, 'mbe_gig_status', true );

				// Scheduled is the normal case and shouldn't print a badge on every gig.
				if ( '' === $status || 'scheduled' === $status ) {
					return '';
				}

				return isset( $statuses[ $status ] ) ? $statuses[ $status ] : '';

			case 'mbe_gig_venue':
				$venue = MBE_Gigs_Query::get_venue( $post_id );

				return $venue ? $venue->name : '';

			case 'mbe_gig_venue_line':
				return MBE_Gigs_Query::get_venue_line( $post_id );

			case 'mbe_gig_artist':
				return MBE_Gigs_Post_Type::first_term_name( $post_id, MBE_GIGS_TAX_ARTIST );

			case 'mbe_gig_venue_city':
			case 'mbe_gig_venue_state':
			case 'mbe_gig_venue_address':
			case 'mbe_gig_venue_url':
				$venue = MBE_Gigs_Query::get_venue( $post_id );

				if ( ! $venue ) {
					return '';
				}

				$map = array(
					'mbe_gig_venue_city'    => 'mbe_venue_city',
					'mbe_gig_venue_state'   => 'mbe_venue_state',
					'mbe_gig_venue_address' => 'mbe_venue_address',
					'mbe_gig_venue_url'     => 'mbe_venue_url',
				);

				return (string) get_term_meta( $venue->term_id, $map[ $property ], true );

			default:
				return (string) get_post_meta( $post_id, $property, true );
		}
	}

	/**
	 * Apply the gig window and ordering to Beaver Builder Posts modules.
	 *
	 * Themer's query UI can order by a meta value, but it cannot compare one against
	 * today as a date — which is the entire upcoming/past distinction. Any Posts
	 * module set to query gigs gets the correct window here instead.
	 *
	 * For a past-gigs module on a particular site:
	 *
	 *     add_filter( 'mbe_gigs_loop_direction', function ( $direction, $args ) {
	 *         return 'past';
	 *     }, 10, 2 );
	 *
	 * @param array $args Query args assembled by Beaver Builder.
	 * @return array
	 */
	public static function loop_query_args( $args ) {
		$post_type = isset( $args['post_type'] ) ? (array) $args['post_type'] : array();

		if ( ! in_array( MBE_GIGS_CPT, $post_type, true ) ) {
			return $args;
		}

		$direction = apply_filters( 'mbe_gigs_loop_direction', 'upcoming', $args );

		if ( ! in_array( $direction, array( 'upcoming', 'past', 'all' ), true ) ) {
			return $args;
		}

		$order = ( 'past' === $direction ) ? 'DESC' : 'ASC';

		$args['meta_query'] = MBE_Gigs_Query::meta_query( $direction, mbe_gigs_today() );
		$args['orderby']    = array(
			'mbe_gig_date_clause' => $order,
			'title'               => 'ASC',
		);

		// Beaver Builder sets these from its own UI; they would fight the clause above.
		unset( $args['meta_key'], $args['meta_value'] );

		return $args;
	}
}
