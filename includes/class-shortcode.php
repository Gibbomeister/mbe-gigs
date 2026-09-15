<?php
/**
 * The gig list shortcode.
 *
 *     [mbe_gigs]
 *     [mbe_gigs direction="past" limit="20"]
 *     [mbe_gigs venue="the-bridge-hotel"]
 *
 * This exists because Beaver Builder's Posts module renders each item with its own
 * markup — image, title, excerpt, meta — and offers no way to lay an item out from
 * the gig's own fields. Without this, a ticket button, a start time and a cancelled
 * badge could not be separate elements on an archive at all.
 *
 * It ships NO styling, deliberately. What it produces is semantic markup with
 * predictable class names; how a site looks stays a per-site job done in CSS, which
 * is the part that should differ between a country act and a pub rock band. Empty
 * fields are omitted entirely rather than rendered blank, so no stylesheet ever has
 * to hide an empty element.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Shortcode {

	public static function hooks() {
		add_shortcode( 'mbe_gigs', array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'direction'     => 'upcoming',
				'limit'         => -1,
				'order'         => '',
				'venue'         => '',
				'artist'        => '',
				'show_artist'   => 'auto',
				'group_by_tour' => 'yes',
				'tour_label'    => __( 'Tour:', 'mbe-gigs' ),
				'venue_link'    => 'archive',
				'map'           => 'yes',
				'tickets_label' => __( 'Tickets', 'mbe-gigs' ),
				'date_format'   => '',
				'empty'         => __( 'No gigs listed at the moment.', 'mbe-gigs' ),
				'class'         => '',
			),
			$atts,
			'mbe_gigs'
		);

		$query = MBE_Gigs_Query::get(
			array(
				'direction' => $atts['direction'],
				'limit'     => (int) $atts['limit'],
				'order'     => $atts['order'],
				'venue'     => $atts['venue'],
				'artist'    => $atts['artist'],
			)
		);

		$classes = array( 'mbe-gigs', 'mbe-gigs--' . sanitize_html_class( $atts['direction'] ) );

		if ( $atts['class'] ) {
			$classes[] = sanitize_html_class( $atts['class'] );
		}

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();

			return sprintf(
				'<div class="%s"><p class="mbe-gigs__empty">%s</p></div>',
				esc_attr( implode( ' ', $classes ) ),
				esc_html( $atts['empty'] )
			);
		}

		$show_artist = self::show_artist( $atts['show_artist'] );
		$group_tours = 'no' !== $atts['group_by_tour'];

		$out  = sprintf( '<div class="%s">', esc_attr( implode( ' ', $classes ) ) );
		$out .= '<ul class="mbe-gigs__list">';

		/*
		 * Tours break on change rather than being sorted together — walk the list in
		 * date order and start a new heading whenever the tour differs from the last
		 * row. A run of tour dates groups under one heading; a one-off in the middle
		 * of them ends the run, which is what actually happened and what a reader
		 * expects to see.
		 */
		$current_tour = '';

		while ( $query->have_posts() ) {
			$query->the_post();

			$post_id = get_the_ID();
			$tour    = (string) get_post_meta( $post_id, 'mbe_gig_tour', true );

			if ( $group_tours && $tour !== $current_tour ) {
				$current_tour = $tour;

				if ( '' !== $tour ) {
					$out .= sprintf(
						'<li class="mbe-gigs__tour"><span class="mbe-gigs__tour-label">%s</span> <span class="mbe-gigs__tour-name">%s</span></li>',
						esc_html( $atts['tour_label'] ),
						esc_html( $tour )
					);
				}
			}

			$out .= self::render_gig( $post_id, $show_artist, $atts, $group_tours && '' !== $tour );
		}

		$out .= '</ul></div>';

		wp_reset_postdata();

		return $out;
	}

	/**
	 * Whether to print the artist line.
	 *
	 * On a single-act site the artist is the same on every row and printing it is
	 * noise. On Merilyn Steele's site there are twelve acts and on Rudy Miranda's
	 * forty-six, where it's the most important thing on the row. "auto" decides by
	 * counting the terms, which gets it right on both without a per-site setting.
	 *
	 * @param string $setting yes, no or auto.
	 * @return bool
	 */
	protected static function show_artist( $setting ) {
		if ( 'yes' === $setting ) {
			return true;
		}

		if ( 'no' === $setting ) {
			return false;
		}

		$count = wp_count_terms(
			array(
				'taxonomy'   => MBE_GIGS_TAX_ARTIST,
				'hide_empty' => true,
			)
		);

		return ! is_wp_error( $count ) && (int) $count > 1;
	}

	/**
	 * One gig.
	 *
	 * @param int   $post_id     Post ID.
	 * @param bool  $show_artist Whether to print the artist.
	 * @param array $atts        Shortcode attributes.
	 * @param bool  $in_tour     Whether a tour heading above already names this gig's tour.
	 * @return string
	 */
	protected static function render_gig( $post_id, $show_artist, $atts, $in_tour = false ) {
		$date   = (string) get_post_meta( $post_id, 'mbe_gig_date', true );
		$end    = (string) get_post_meta( $post_id, 'mbe_gig_end_date', true );
		$status = (string) get_post_meta( $post_id, 'mbe_gig_status', true );
		$status = $status ? $status : 'scheduled';

		$classes = array( 'mbe-gig', 'mbe-gig--' . sanitize_html_class( $status ) );

		if ( $end ) {
			$classes[] = 'mbe-gig--multi-day';
		}

		if ( $in_tour ) {
			$classes[] = 'mbe-gig--in-tour';
		}

		$out = sprintf( '<li class="%s">', esc_attr( implode( ' ', $classes ) ) );

		$out .= self::date_block( $date, $end, $atts['date_format'] );

		$out .= '<div class="mbe-gig__details">';

		if ( $show_artist ) {
			$artist = MBE_Gigs_Post_Type::first_term_name( $post_id, MBE_GIGS_TAX_ARTIST );

			if ( '' !== $artist ) {
				$out .= sprintf( '<p class="mbe-gig__artist">%s</p>', esc_html( $artist ) );
			}
		}

		$venue = MBE_Gigs_Query::get_venue( $post_id );

		if ( $venue ) {
			$out .= sprintf( '<p class="mbe-gig__venue">%s</p>', self::venue_name( $venue, $atts['venue_link'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			$city  = (string) get_term_meta( $venue->term_id, 'mbe_venue_city', true );
			$state = (string) get_term_meta( $venue->term_id, 'mbe_venue_state', true );
			$where = trim( $city . ( ( '' !== $city && '' !== $state ) ? ' ' : '' ) . $state );

			if ( '' !== $where ) {
				$out .= sprintf( '<p class="mbe-gig__location">%s</p>', esc_html( $where ) );
			}
		}

		$time = MBE_Gigs_Query::get_time( $post_id );

		if ( '' !== $time ) {
			$out .= self::detail( 'time', __( 'Time', 'mbe-gigs' ), esc_html( $time ) );
		}

		// The tour is on the heading above when grouping is on, so don't repeat it.
		$tour = (string) get_post_meta( $post_id, 'mbe_gig_tour', true );

		if ( '' !== $tour && ! $in_tour ) {
			$out .= self::detail( 'tour', __( 'Tour', 'mbe-gigs' ), esc_html( $tour ) );
		}

		$price = (string) get_post_meta( $post_id, 'mbe_gig_price', true );

		if ( '' !== $price ) {
			$out .= self::detail( 'price', __( 'Admission', 'mbe-gigs' ), esc_html( $price ) );
		}

		if ( $venue ) {
			$address = self::address( $venue, 'no' !== $atts['map'] );

			if ( '' !== $address ) {
				$out .= self::detail( 'address', __( 'Address', 'mbe-gigs' ), $address );
			}
		}

		if ( 'scheduled' !== $status ) {
			$labels = MBE_Gigs_Meta::statuses();

			$out .= sprintf(
				'<p class="mbe-gig__status">%s</p>',
				esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status )
			);
		}

		$content = get_post_field( 'post_content', $post_id );

		if ( '' !== trim( (string) $content ) ) {
			$out .= sprintf(
				'<div class="mbe-gig__description">%s</div>',
				wpautop( wp_kses_post( $content ) )
			);
		}

		$out .= '</div>';

		$tickets = (string) get_post_meta( $post_id, 'mbe_gig_ticket_url', true );

		if ( '' !== $tickets ) {
			$out .= sprintf(
				'<p class="mbe-gig__actions"><a class="mbe-gig__tickets" href="%s" rel="noopener">%s</a></p>',
				esc_url( $tickets ),
				esc_html( $atts['tickets_label'] )
			);
		}

		$out .= '</li>';

		return $out;
	}

	/**
	 * One labelled detail line.
	 *
	 * The label ships in the markup and is hidden by the starter CSS. It costs a span,
	 * it reads correctly in a screen reader, and a site that wants GigPress's old
	 * "Time: 8:30pm. Address: …" style gets it with one CSS rule instead of a
	 * different shortcode.
	 *
	 * @param string $key   Element key.
	 * @param string $label Human label.
	 * @param string $value Already-escaped value.
	 * @return string
	 */
	protected static function detail( $key, $label, $value ) {
		return sprintf(
			'<p class="mbe-gig__%1$s"><span class="mbe-gig__label">%2$s</span> <span class="mbe-gig__value">%3$s</span></p>',
			esc_attr( $key ),
			esc_html( $label ),
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		);
	}

	/**
	 * The venue name, linked according to the `venue_link` attribute.
	 *
	 * GigPress linked the venue to the venue's own website, which sends a visitor off
	 * the band's site. The default here is the venue archive instead — every gig ever
	 * played there — with the old behaviour available for sites that preferred it.
	 *
	 * @param WP_Term $venue Venue term.
	 * @param string  $mode  archive, website or none.
	 * @return string
	 */
	protected static function venue_name( $venue, $mode ) {
		$name = esc_html( $venue->name );

		if ( 'none' === $mode ) {
			return $name;
		}

		if ( 'website' === $mode ) {
			$url = (string) get_term_meta( $venue->term_id, 'mbe_venue_url', true );

			return $url
				? sprintf( '<a href="%s" rel="noopener">%s</a>', esc_url( $url ), $name )
				: $name;
		}

		if ( ! MBE_Gigs_Post_Type::archives_enabled( MBE_GIGS_TAX_VENUE ) ) {
			return $name;
		}

		$link = get_term_link( $venue );

		return ( $link && ! is_wp_error( $link ) )
			? sprintf( '<a href="%s">%s</a>', esc_url( $link ), $name )
			: $name;
	}

	/**
	 * The venue's street address, optionally linked to a map.
	 *
	 * Only the street address is the link text; the city, state, postcode and country
	 * go into the map query so the pin lands in the right town. A venue with no street
	 * address returns nothing rather than a map of the suburb.
	 *
	 * @param WP_Term $venue Venue term.
	 * @param bool    $map   Whether to link to a map.
	 * @return string
	 */
	protected static function address( $venue, $map ) {
		$address = (string) get_term_meta( $venue->term_id, 'mbe_venue_address', true );

		if ( '' === trim( $address ) ) {
			return '';
		}

		if ( ! $map ) {
			return esc_html( $address );
		}

		$parts = array( $address );

		foreach ( array( 'mbe_venue_city', 'mbe_venue_state', 'mbe_venue_postcode', 'mbe_venue_country' ) as $key ) {
			$value = (string) get_term_meta( $venue->term_id, $key, true );

			if ( '' !== trim( $value ) ) {
				$parts[] = $value;
			}
		}

		$url = 'https://maps.google.com/maps?q=' . rawurlencode( implode( ', ', $parts ) );

		return sprintf(
			'<a class="mbe-gig__map" href="%s" rel="noopener">%s</a>',
			esc_url( $url ),
			esc_html( $address )
		);
	}

	/**
	 * The date, both as one formatted string and broken into parts.
	 *
	 * The parts are there so a site can build a calendar-tile style date block in CSS
	 * without any PHP — stack the weekday, day and month, hide what it doesn't want.
	 *
	 * @param string $date        Start date.
	 * @param string $end         End date, may be empty.
	 * @param string $date_format Optional format override.
	 * @return string
	 */
	protected static function date_block( $date, $end, $date_format ) {
		if ( '' === $date ) {
			return '';
		}

		$stamp  = strtotime( $date . ' 12:00:00' );
		$format = $date_format ? $date_format : get_option( 'date_format' );

		/*
		 * Both dates live inside one wrapper so a multi-day gig doesn't become a third
		 * column in whatever layout the site uses. The row is always: dates, details,
		 * actions — however many dates there are.
		 */
		$out = '<div class="mbe-gig__dates">';

		$out .= sprintf( '<time class="mbe-gig__date" datetime="%s">', esc_attr( $date ) );

		$out .= sprintf( '<span class="mbe-gig__weekday">%s</span>', esc_html( date_i18n( 'D', $stamp ) ) );
		$out .= sprintf( '<span class="mbe-gig__day">%s</span>', esc_html( date_i18n( 'j', $stamp ) ) );
		$out .= sprintf( '<span class="mbe-gig__month">%s</span>', esc_html( date_i18n( 'M', $stamp ) ) );
		$out .= sprintf( '<span class="mbe-gig__year">%s</span>', esc_html( date_i18n( 'Y', $stamp ) ) );
		$out .= sprintf( '<span class="mbe-gig__date-full">%s</span>', esc_html( date_i18n( $format, $stamp ) ) );

		$out .= '</time>';

		if ( '' !== $end ) {
			$end_stamp = strtotime( $end . ' 12:00:00' );

			$out .= sprintf( '<time class="mbe-gig__end-date" datetime="%s">', esc_attr( $end ) );

			$out .= sprintf( '<span class="mbe-gig__weekday">%s</span>', esc_html( date_i18n( 'D', $end_stamp ) ) );
			$out .= sprintf( '<span class="mbe-gig__day">%s</span>', esc_html( date_i18n( 'j', $end_stamp ) ) );
			$out .= sprintf( '<span class="mbe-gig__month">%s</span>', esc_html( date_i18n( 'M', $end_stamp ) ) );
			$out .= sprintf( '<span class="mbe-gig__year">%s</span>', esc_html( date_i18n( 'Y', $end_stamp ) ) );
			$out .= sprintf( '<span class="mbe-gig__date-full">%s</span>', esc_html( date_i18n( $format, $end_stamp ) ) );

			$out .= '</time>';
		}

		$out .= '</div>';

		return $out;
	}
}
