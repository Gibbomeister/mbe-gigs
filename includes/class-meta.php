<?php
/**
 * Field definitions, sanitisation and REST exposure.
 *
 * Everything is registered with register_post_meta / register_term_meta so the
 * fields reach the REST API and the block editor without extra work, and so any
 * future front end — Themer today, something else later — has a documented
 * contract to read.
 *
 * Meta keys deliberately do NOT start with an underscore. Underscore-prefixed keys
 * are "protected" and need an auth_callback plus extra handling to appear in REST.
 * These are public gig details, not secrets.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Meta {

	/**
	 * Gig statuses. GigPress had two; postponed is worth the extra line.
	 *
	 * @return array
	 */
	public static function statuses() {
		return apply_filters(
			'mbe_gigs_statuses',
			array(
				'scheduled' => __( 'Scheduled', 'mbe-gigs' ),
				'cancelled' => __( 'Cancelled', 'mbe-gigs' ),
				'postponed' => __( 'Postponed', 'mbe-gigs' ),
			)
		);
	}

	/**
	 * The gig field definitions.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'mbe_gig_date'       => array(
				'label'     => __( 'Date', 'mbe-gigs' ),
				'type'      => 'date',
				'sanitize'  => array( __CLASS__, 'sanitize_date' ),
				'required'  => true,
			),
			'mbe_gig_time'       => array(
				'label'    => __( 'Time', 'mbe-gigs' ),
				'type'     => 'time',
				'sanitize' => array( __CLASS__, 'sanitize_time' ),
			),
			'mbe_gig_end_date'   => array(
				'label'    => __( 'End date', 'mbe-gigs' ),
				'type'     => 'date',
				'sanitize' => array( __CLASS__, 'sanitize_date' ),
				'help'     => __( 'Only for festivals and multi-day runs.', 'mbe-gigs' ),
			),
			'mbe_gig_ticket_url' => array(
				'label'    => __( 'Ticket link', 'mbe-gigs' ),
				'type'     => 'url',
				'sanitize' => array( __CLASS__, 'sanitize_url' ),
			),
			'mbe_gig_price'      => array(
				'label'    => __( 'Price', 'mbe-gigs' ),
				'type'     => 'text',
				'sanitize' => 'sanitize_text_field',
				'help'     => __( 'Free text — "$25", "Free entry", "$30 + booking fee".', 'mbe-gigs' ),
			),
			'mbe_gig_status'     => array(
				'label'    => __( 'Status', 'mbe-gigs' ),
				'type'     => 'select',
				'options'  => self::statuses(),
				'default'  => 'scheduled',
				'sanitize' => array( __CLASS__, 'sanitize_status' ),
			),
			'mbe_gig_title'      => array(
				'label'    => __( 'Custom title', 'mbe-gigs' ),
				'type'     => 'text',
				'sanitize' => 'sanitize_text_field',
				'help'     => __( 'Leave blank and the gig is listed as artist, venue and date.', 'mbe-gigs' ),
			),
			'mbe_gig_tour'       => array(
				'label'    => __( 'Tour', 'mbe-gigs' ),
				'type'     => 'text',
				'sanitize' => 'sanitize_text_field',
				'help'     => __( 'Optional — the tour or billing this gig belongs to.', 'mbe-gigs' ),
			),
		);
	}

	/**
	 * Venue term meta definitions.
	 *
	 * @return array
	 */
	public static function venue_fields() {
		return array(
			'mbe_venue_address'  => array( 'label' => __( 'Address', 'mbe-gigs' ), 'type' => 'text' ),
			'mbe_venue_city'     => array( 'label' => __( 'City / suburb', 'mbe-gigs' ), 'type' => 'text' ),
			'mbe_venue_state'    => array( 'label' => __( 'State', 'mbe-gigs' ), 'type' => 'text' ),
			'mbe_venue_postcode' => array( 'label' => __( 'Postcode', 'mbe-gigs' ), 'type' => 'text' ),
			'mbe_venue_country'  => array( 'label' => __( 'Country', 'mbe-gigs' ), 'type' => 'text', 'default' => 'AU' ),
			'mbe_venue_phone'    => array( 'label' => __( 'Phone', 'mbe-gigs' ), 'type' => 'text' ),
			'mbe_venue_url'      => array( 'label' => __( 'Website', 'mbe-gigs' ), 'type' => 'url' ),
			'mbe_venue_capacity' => array( 'label' => __( 'Capacity', 'mbe-gigs' ), 'type' => 'number' ),
		);
	}

	/**
	 * Artist term meta definitions.
	 *
	 * @return array
	 */
	public static function artist_fields() {
		return array(
			'mbe_artist_url' => array( 'label' => __( 'Website', 'mbe-gigs' ), 'type' => 'url' ),
		);
	}

	public static function hooks() {
		// Term meta editing screens.
		add_action( MBE_GIGS_TAX_VENUE . '_add_form_fields', array( __CLASS__, 'venue_add_fields' ) );
		add_action( MBE_GIGS_TAX_VENUE . '_edit_form_fields', array( __CLASS__, 'venue_edit_fields' ) );
		add_action( MBE_GIGS_TAX_ARTIST . '_add_form_fields', array( __CLASS__, 'artist_add_fields' ) );
		add_action( MBE_GIGS_TAX_ARTIST . '_edit_form_fields', array( __CLASS__, 'artist_edit_fields' ) );

		add_action( 'created_' . MBE_GIGS_TAX_VENUE, array( __CLASS__, 'save_venue_meta' ) );
		add_action( 'edited_' . MBE_GIGS_TAX_VENUE, array( __CLASS__, 'save_venue_meta' ) );
		add_action( 'created_' . MBE_GIGS_TAX_ARTIST, array( __CLASS__, 'save_artist_meta' ) );
		add_action( 'edited_' . MBE_GIGS_TAX_ARTIST, array( __CLASS__, 'save_artist_meta' ) );

		// Venue list table gets a city column — without it every RSL looks the same.
		add_filter( 'manage_edit-' . MBE_GIGS_TAX_VENUE . '_columns', array( __CLASS__, 'venue_columns' ) );
		add_filter( 'manage_' . MBE_GIGS_TAX_VENUE . '_custom_column', array( __CLASS__, 'venue_column_content' ), 10, 3 );

		add_action( 'admin_head', array( __CLASS__, 'term_screen_tidy' ) );
	}

	/**
	 * Hide the slug field on the venue and artist screens.
	 *
	 * The slug is generated from the name and almost never wants changing. Left on
	 * screen it reads as something you're supposed to fill in, and on a venue that
	 * already has an archive URL, editing it breaks every link to that page — a
	 * consequence the field gives no hint of.
	 *
	 * Hidden rather than removed: the input still posts, so an existing slug submits
	 * unchanged and a new term still generates one from its name. To bring it back on
	 * a site where you want to curate URLs by hand:
	 *
	 *     add_filter( 'mbe_gigs_hide_term_slug', '__return_false' );
	 */
	public static function term_screen_tidy() {
		if ( ! apply_filters( 'mbe_gigs_hide_term_slug', true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || empty( $screen->taxonomy ) ) {
			return;
		}

		if ( ! in_array( $screen->taxonomy, array( MBE_GIGS_TAX_VENUE, MBE_GIGS_TAX_ARTIST ), true ) ) {
			return;
		}

		echo '<style>.term-slug-wrap { display: none; }</style>';
	}

	/**
	 * Register post meta and term meta.
	 */
	public static function register() {
		foreach ( self::fields() as $key => $field ) {
			register_post_meta(
				MBE_GIGS_CPT,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => isset( $field['default'] ) ? $field['default'] : '',
					'show_in_rest'      => true,
					'sanitize_callback' => isset( $field['sanitize'] ) ? $field['sanitize'] : 'sanitize_text_field',
					'auth_callback'     => array( __CLASS__, 'can_edit_gigs' ),
				)
			);
		}

		// Internal bookkeeping. Not exposed; nothing outside the plugin should read these.
		foreach ( array( 'mbe_gig_auto_title', 'mbe_gigs_source_id' ) as $key ) {
			register_post_meta(
				MBE_GIGS_CPT,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( __CLASS__, 'can_edit_gigs' ),
				)
			);
		}

		foreach ( self::venue_fields() as $key => $field ) {
			register_term_meta(
				MBE_GIGS_TAX_VENUE,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => ( 'url' === $field['type'] ) ? array( __CLASS__, 'sanitize_url' ) : 'sanitize_text_field',
					'auth_callback'     => array( __CLASS__, 'can_edit_terms' ),
				)
			);
		}

		foreach ( self::artist_fields() as $key => $field ) {
			register_term_meta(
				MBE_GIGS_TAX_ARTIST,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => ( 'url' === $field['type'] ) ? array( __CLASS__, 'sanitize_url' ) : 'sanitize_text_field',
					'auth_callback'     => array( __CLASS__, 'can_edit_terms' ),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Sanitisation
	 * ------------------------------------------------------------------ */

	/**
	 * Dates are stored as YYYY-MM-DD, always, with no time component.
	 *
	 * Anything that isn't a real calendar date becomes an empty string rather than
	 * a plausible-looking wrong date. 2026-02-31 is not a day.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_date( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Times are stored as HH:MM, 24-hour.
	 *
	 * Empty means the time is unknown — which is true of a good share of gigs — and
	 * that is deliberately distinct from midnight. Never write 00:00 as a placeholder.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_time( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		// Accept HH:MM or HH:MM:SS; store HH:MM.
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value, $m ) ) {
			return '';
		}

		return $m[1] . ':' . $m[2];
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_url( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		// People paste "www.venue.com.au" constantly.
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) && ! preg_match( '/^(mailto|tel):/i', $value ) ) {
			$value = 'https://' . $value;
		}

		return esc_url_raw( $value );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_status( $value ) {
		$value    = is_scalar( $value ) ? (string) $value : '';
		$statuses = self::statuses();

		return isset( $statuses[ $value ] ) ? $value : 'scheduled';
	}

	public static function can_edit_gigs() {
		return current_user_can( 'edit_posts' );
	}

	public static function can_edit_terms() {
		return current_user_can( 'manage_categories' );
	}

	/* ---------------------------------------------------------------------
	 * Term meta admin
	 * ------------------------------------------------------------------ */

	public static function venue_add_fields() {
		self::render_add_fields( self::venue_fields() );
	}

	public static function venue_edit_fields( $term ) {
		self::render_edit_fields( self::venue_fields(), $term );
	}

	public static function artist_add_fields() {
		self::render_add_fields( self::artist_fields() );
	}

	public static function artist_edit_fields( $term ) {
		self::render_edit_fields( self::artist_fields(), $term );
	}

	protected static function render_add_fields( $fields ) {
		wp_nonce_field( 'mbe_gigs_term_meta', 'mbe_gigs_term_nonce' );

		foreach ( $fields as $key => $field ) {
			$default = isset( $field['default'] ) ? $field['default'] : '';
			printf(
				'<div class="form-field"><label for="%1$s">%2$s</label><input type="%3$s" name="%1$s" id="%1$s" value="%4$s" /></div>',
				esc_attr( $key ),
				esc_html( $field['label'] ),
				esc_attr( 'number' === $field['type'] ? 'number' : 'text' ),
				esc_attr( $default )
			);
		}
	}

	protected static function render_edit_fields( $fields, $term ) {
		wp_nonce_field( 'mbe_gigs_term_meta', 'mbe_gigs_term_nonce' );

		foreach ( $fields as $key => $field ) {
			$value = get_term_meta( $term->term_id, $key, true );
			printf(
				'<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th>
				<td><input type="%3$s" name="%1$s" id="%1$s" value="%4$s" class="regular-text" /></td></tr>',
				esc_attr( $key ),
				esc_html( $field['label'] ),
				esc_attr( 'number' === $field['type'] ? 'number' : 'text' ),
				esc_attr( is_string( $value ) ? $value : '' )
			);
		}
	}

	public static function save_venue_meta( $term_id ) {
		self::save_term_meta( $term_id, self::venue_fields() );
	}

	public static function save_artist_meta( $term_id ) {
		self::save_term_meta( $term_id, self::artist_fields() );
	}

	protected static function save_term_meta( $term_id, $fields ) {
		if ( ! isset( $_POST['mbe_gigs_term_nonce'] ) ) {
			return; // Term created programmatically (importer, REST) — nothing to read.
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mbe_gigs_term_nonce'] ) ), 'mbe_gigs_term_meta' ) ) {
			return;
		}

		if ( ! self::can_edit_terms() ) {
			return;
		}

		foreach ( $fields as $key => $field ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$raw = wp_unslash( $_POST[ $key ] );
			$val = ( 'url' === $field['type'] ) ? self::sanitize_url( $raw ) : sanitize_text_field( $raw );

			if ( '' === $val ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $val );
			}
		}
	}

	public static function venue_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'name' === $key ) {
				$new['mbe_city'] = __( 'City', 'mbe-gigs' );
			}
		}

		return $new;
	}

	public static function venue_column_content( $content, $column, $term_id ) {
		if ( 'mbe_city' !== $column ) {
			return $content;
		}

		$city  = (string) get_term_meta( $term_id, 'mbe_venue_city', true );
		$state = (string) get_term_meta( $term_id, 'mbe_venue_state', true );
		$out   = trim( $city . ( $state ? ' ' . $state : '' ) );

		return $out ? esc_html( $out ) : '—';
	}
}
