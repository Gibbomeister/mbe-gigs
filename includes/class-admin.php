<?php
/**
 * Admin screens.
 *
 * The people using this are band members, not WordPress developers. The edit screen
 * is one box with the fields in the order someone actually types them, and the list
 * screen sorts by gig date rather than publish date — which is the difference
 * between a usable entry screen and an annoying one.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Admin {

	public static function hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'render_top' ) );
		add_action( 'save_post_' . MBE_GIGS_CPT, array( __CLASS__, 'save' ), 10, 2 );

		add_filter( 'manage_' . MBE_GIGS_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . MBE_GIGS_CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . MBE_GIGS_CPT . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );

		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_query' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dateless_notice' ) );

		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
	}

	/* ---------------------------------------------------------------------
	 * Edit screen
	 * ------------------------------------------------------------------ */

	public static function meta_boxes() {
		// The default tag-style boxes would let a typo mint a second "Bridge Hotel".
		remove_meta_box( 'tagsdiv-' . MBE_GIGS_TAX_VENUE, MBE_GIGS_CPT, 'side' );
		remove_meta_box( 'tagsdiv-' . MBE_GIGS_TAX_ARTIST, MBE_GIGS_CPT, 'side' );

		/*
		 * A custom context, rendered by render_top() on edit_form_after_title. The
		 * normal context puts meta boxes below the editor, which reads backwards here:
		 * the date and the venue are the gig, and the description is an afterthought.
		 */
		add_meta_box(
			'mbe-gig-details',
			__( 'Gig details', 'mbe-gigs' ),
			array( __CLASS__, 'render_meta_box' ),
			MBE_GIGS_CPT,
			'mbe_gig_top',
			'high'
		);
	}

	/**
	 * Put the gig fields above the editor, and give the editor a label.
	 *
	 * WordPress gives the content editor no label at all, which is why it reads as a
	 * mystery box to anyone who didn't build the screen. One heading and one line of
	 * guidance removes the need to train people on it.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_top( $post ) {
		if ( ! $post || MBE_GIGS_CPT !== $post->post_type ) {
			return;
		}

		do_meta_boxes( get_current_screen(), 'mbe_gig_top', $post );

		printf(
			'<h2 class="mbe-editor-heading">%s</h2><p class="mbe-help mbe-editor-help">%s</p>',
			esc_html__( 'Description', 'mbe-gigs' ),
			esc_html__( 'Optional. Support acts, ticket details, anything worth saying about this gig. It shows underneath the gig on the website.', 'mbe-gigs' )
		);
	}

	/**
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'mbe_gigs_save', 'mbe_gigs_nonce' );

		$fields = MBE_Gigs_Meta::fields();
		$values = array();

		foreach ( $fields as $key => $field ) {
			$stored = get_post_meta( $post->ID, $key, true );

			if ( '' === $stored && isset( $field['default'] ) ) {
				$stored = $field['default'];
			}

			$values[ $key ] = is_string( $stored ) ? $stored : '';
		}

		$venue  = MBE_Gigs_Query::get_venue( $post->ID );
		$artist = get_the_terms( $post->ID, MBE_GIGS_TAX_ARTIST );
		$artist = ( $artist && ! is_wp_error( $artist ) ) ? reset( $artist ) : null;

		echo '<div class="mbe-gig-grid">';

		self::field_row(
			'mbe_gig_date',
			$fields['mbe_gig_date']['label'] . ' <span class="mbe-req">*</span>',
			/*
			 * No HTML5 `required` here. A browser refusing to submit is silent when it
			 * can't focus the field — a collapsed meta box is enough — and a Publish
			 * button that does nothing at all is the worst failure this screen could
			 * have. A gig with no date is surfaced by the "Needs a date" filter and the
			 * notice above the list instead.
			 */
			sprintf(
				'<input type="date" id="mbe_gig_date" name="mbe_gig_date" value="%s" />',
				esc_attr( $values['mbe_gig_date'] )
			)
		);

		self::field_row(
			'mbe_gig_time',
			$fields['mbe_gig_time']['label'],
			sprintf(
				'<input type="time" id="mbe_gig_time" name="mbe_gig_time" value="%s" /> <span class="mbe-help">%s</span>',
				esc_attr( $values['mbe_gig_time'] ),
				esc_html__( 'Leave blank if it is not confirmed yet.', 'mbe-gigs' )
			)
		);

		self::field_row(
			'mbe_gig_venue_term',
			__( 'Venue', 'mbe-gigs' ),
			self::term_select( MBE_GIGS_TAX_VENUE, 'mbe_gig_venue_term', $venue, __( 'venue', 'mbe-gigs' ) )
		);

		self::field_row(
			'mbe_gig_artist_term',
			__( 'Artist', 'mbe-gigs' ),
			self::term_select( MBE_GIGS_TAX_ARTIST, 'mbe_gig_artist_term', $artist, __( 'artist', 'mbe-gigs' ) )
		);

		self::field_row(
			'mbe_gig_ticket_url',
			$fields['mbe_gig_ticket_url']['label'],
			sprintf(
				'<input type="text" class="large-text" id="mbe_gig_ticket_url" name="mbe_gig_ticket_url" value="%s" placeholder="https://" />',
				esc_attr( $values['mbe_gig_ticket_url'] )
			)
		);

		self::field_row(
			'mbe_gig_price',
			$fields['mbe_gig_price']['label'],
			sprintf(
				'<input type="text" id="mbe_gig_price" name="mbe_gig_price" value="%s" /> <span class="mbe-help">%s</span>',
				esc_attr( $values['mbe_gig_price'] ),
				esc_html( $fields['mbe_gig_price']['help'] )
			)
		);

		$options = '';
		foreach ( MBE_Gigs_Meta::statuses() as $value => $label ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $values['mbe_gig_status'], $value, false ),
				esc_html( $label )
			);
		}

		self::field_row(
			'mbe_gig_status',
			$fields['mbe_gig_status']['label'],
			'<select id="mbe_gig_status" name="mbe_gig_status">' . $options . '</select>'
		);

		self::field_row(
			'mbe_gig_tour',
			$fields['mbe_gig_tour']['label'],
			sprintf(
				'<input type="text" class="large-text" id="mbe_gig_tour" name="mbe_gig_tour" value="%s" placeholder="%s" />',
				esc_attr( $values['mbe_gig_tour'] ),
				esc_attr( $fields['mbe_gig_tour']['help'] )
			)
		);

		self::field_row(
			'mbe_gig_end_date',
			$fields['mbe_gig_end_date']['label'],
			sprintf(
				'<input type="date" id="mbe_gig_end_date" name="mbe_gig_end_date" value="%s" /> <span class="mbe-help">%s</span>',
				esc_attr( $values['mbe_gig_end_date'] ),
				esc_html( $fields['mbe_gig_end_date']['help'] )
			)
		);

		self::field_row(
			'mbe_gig_title',
			$fields['mbe_gig_title']['label'],
			sprintf(
				'<input type="text" class="large-text" id="mbe_gig_title" name="mbe_gig_title" value="%s" placeholder="%s" />',
				esc_attr( $values['mbe_gig_title'] ),
				esc_attr( $fields['mbe_gig_title']['help'] )
			)
		);

		echo '</div>';

		// What this gig is actually called, so the generated title isn't a mystery.
		$listed_as = MBE_Gigs_Post_Type::preview_title( $post->ID );

		if ( '' !== $listed_as ) {
			printf(
				'<p class="mbe-listed-as">%s <strong>%s</strong></p>',
				esc_html__( 'Listed as:', 'mbe-gigs' ),
				esc_html( $listed_as )
			);
		}
	}

	/**
	 * One labelled row.
	 *
	 * @param string $for   Input id.
	 * @param string $label Label text (may contain a required marker).
	 * @param string $input Pre-escaped input markup.
	 */
	protected static function field_row( $for, $label, $input ) {
		printf(
			'<p class="mbe-gig-field"><label for="%s">%s</label><span class="mbe-gig-input">%s</span></p>',
			esc_attr( $for ),
			wp_kses( $label, array( 'span' => array( 'class' => array() ) ) ),
			// Inputs are assembled from escaped values above.
			$input // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * A single-select of existing terms, plus an "add new" text field.
	 *
	 * A select rather than the default tag input because the whole reason venues are
	 * a taxonomy is that "The Bridge Hotel" and "Bridge Hotel" must not both exist.
	 * Free-text entry undoes that on the first typo.
	 *
	 * @param string       $taxonomy Taxonomy name.
	 * @param string       $name     Field name.
	 * @param WP_Term|null $current  Currently assigned term.
	 * @param string       $noun     Label noun, e.g. "venue".
	 * @return string
	 */
	protected static function term_select( $taxonomy, $name, $current, $noun ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$html = sprintf(
			'<select id="%1$s" name="%1$s" class="mbe-term-select" data-new-wrap="%1$s_wrap">',
			esc_attr( $name )
		);

		$html .= sprintf(
			'<option value="">%s</option>',
			esc_html( sprintf( /* translators: %s: venue or artist */ __( '— select a %s —', 'mbe-gigs' ), $noun ) )
		);

		foreach ( $terms as $term ) {
			$label = $term->name;

			if ( MBE_GIGS_TAX_VENUE === $taxonomy ) {
				// Name alone doesn't identify a venue — there are two Pier Hotels.
				$city     = (string) get_term_meta( $term->term_id, 'mbe_venue_city', true );
				$state    = (string) get_term_meta( $term->term_id, 'mbe_venue_state', true );
				$location = trim( $city . ( ( '' !== $city && '' !== $state ) ? ' ' : '' ) . $state );

				if ( '' !== $location ) {
					$label .= ' — ' . $location;
				}
			}

			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $term->term_id ),
				selected( $current ? $current->term_id : 0, $term->term_id, false ),
				esc_html( $label )
			);
		}

		$html .= sprintf(
			'<option value="__new__">%s</option>',
			esc_html( sprintf( /* translators: %s: venue or artist */ __( '+ Add a new %s', 'mbe-gigs' ), $noun ) )
		);

		$html .= '</select>';

		/*
		 * A new venue is captured with its city and state here rather than left for
		 * someone to fill in later on the Venues screen. Later never comes, and a
		 * venue with no city can't be told apart from the identically-named pub in
		 * another state.
		 */
		$html .= sprintf( '<span class="mbe-term-new-wrap" id="%s_wrap" hidden>', esc_attr( $name ) );

		$html .= sprintf(
			'<input type="text" name="%1$s_new" id="%1$s_new" placeholder="%2$s" />',
			esc_attr( $name ),
			esc_attr( sprintf( /* translators: %s: venue or artist */ __( 'New %s name', 'mbe-gigs' ), $noun ) )
		);

		if ( MBE_GIGS_TAX_VENUE === $taxonomy ) {
			$html .= sprintf(
				'<input type="text" name="%1$s_new_city" id="%1$s_new_city" placeholder="%2$s" />',
				esc_attr( $name ),
				esc_attr__( 'City or suburb', 'mbe-gigs' )
			);

			$html .= sprintf(
				'<input type="text" name="%1$s_new_state" id="%1$s_new_state" class="mbe-state" placeholder="%2$s" />',
				esc_attr( $name ),
				esc_attr__( 'State', 'mbe-gigs' )
			);
		}

		$html .= '</span>';

		return $html;
	}

	/**
	 * Save the gig.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['mbe_gigs_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mbe_gigs_nonce'] ) ), 'mbe_gigs_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		foreach ( MBE_Gigs_Meta::fields() as $key => $field ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$callback = isset( $field['sanitize'] ) ? $field['sanitize'] : 'sanitize_text_field';
			$value    = call_user_func( $callback, wp_unslash( $_POST[ $key ] ) );

			/*
			 * Empty values are deleted, not stored as empty strings. The upcoming
			 * query asks whether an end date exists; a row holding '' would answer
			 * yes and then fail every date comparison.
			 */
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		self::save_term( $post_id, MBE_GIGS_TAX_VENUE, 'mbe_gig_venue_term' );
		self::save_term( $post_id, MBE_GIGS_TAX_ARTIST, 'mbe_gig_artist_term' );
	}

	/**
	 * Assign exactly one term, creating it if the user chose "add new".
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @param string $field    Field name.
	 */
	protected static function save_term( $post_id, $taxonomy, $field ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in save().
		$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

		if ( '__new__' === $value ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in save().
			$name  = isset( $_POST[ $field . '_new' ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field . '_new' ] ) ) ) : '';
			$city  = isset( $_POST[ $field . '_new_city' ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field . '_new_city' ] ) ) ) : '';
			$state = isset( $_POST[ $field . '_new_state' ] ) ? MBE_Gigs_Meta::sanitize_state( wp_unslash( $_POST[ $field . '_new_state' ] ) ) : '';
			// phpcs:enable

			if ( '' === $name ) {
				return;
			}

			// Name plus city, so two pubs of the same name in different towns stay apart.
			if ( MBE_GIGS_TAX_VENUE === $taxonomy ) {
				$term_id = MBE_Gigs_Meta::find_venue( $name, $city );
			} else {
				$existing = get_term_by( 'name', $name, $taxonomy );
				$term_id  = $existing ? (int) $existing->term_id : 0;
			}

			if ( ! $term_id ) {
				$created = wp_insert_term( $name, $taxonomy );

				if ( is_wp_error( $created ) ) {
					$created = wp_insert_term( $name, $taxonomy, array( 'slug' => sanitize_title( $name . ' ' . $city ) ) );
				}

				if ( is_wp_error( $created ) ) {
					return;
				}

				$term_id = (int) $created['term_id'];
			}

			if ( MBE_GIGS_TAX_VENUE === $taxonomy ) {
				// Never overwrite detail already recorded against an existing venue.
				if ( '' !== $city && '' === (string) get_term_meta( $term_id, 'mbe_venue_city', true ) ) {
					update_term_meta( $term_id, 'mbe_venue_city', $city );
				}

				if ( '' !== $state && '' === (string) get_term_meta( $term_id, 'mbe_venue_state', true ) ) {
					update_term_meta( $term_id, 'mbe_venue_state', $state );
				}
			}

			wp_set_object_terms( $post_id, array( (int) $term_id ), $taxonomy, false );

			return;
		}

		if ( '' === $value ) {
			wp_set_object_terms( $post_id, array(), $taxonomy, false );

			return;
		}

		wp_set_object_terms( $post_id, array( (int) $value ), $taxonomy, false );
	}

	/* ---------------------------------------------------------------------
	 * List screen
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$new = array(
			'cb'             => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'mbe_gig_date'   => __( 'Gig date', 'mbe-gigs' ),
			'title'          => __( 'Gig', 'mbe-gigs' ),
			'mbe_venue'      => __( 'Venue', 'mbe-gigs' ),
			'mbe_artist'     => __( 'Artist', 'mbe-gigs' ),
			'mbe_gig_status' => __( 'Status', 'mbe-gigs' ),
		);

		return $new;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'mbe_gig_date':
				$date = (string) get_post_meta( $post_id, 'mbe_gig_date', true );

				if ( '' === $date ) {
					echo '<span class="mbe-missing">' . esc_html__( 'No date set', 'mbe-gigs' ) . '</span>';
					break;
				}

				$out  = '<strong>' . esc_html( MBE_Gigs_Query::get_date( $post_id ) ) . '</strong>';
				$end  = (string) get_post_meta( $post_id, 'mbe_gig_end_date', true );
				$time = MBE_Gigs_Query::get_time( $post_id );

				if ( '' !== $end ) {
					$out .= '<br /><span class="mbe-help">'
						. esc_html__( 'until', 'mbe-gigs' ) . ' '
						. esc_html( date_i18n( get_option( 'date_format' ), strtotime( $end . ' 12:00:00' ) ) )
						. '</span>';
				} elseif ( '' !== $time ) {
					$out .= '<br /><span class="mbe-help">' . esc_html( $time ) . '</span>';
				}

				if ( $date < mbe_gigs_today() ) {
					$out .= ' <span class="mbe-past">' . esc_html__( 'past', 'mbe-gigs' ) . '</span>';
				}

				echo wp_kses_post( $out );
				break;

			case 'mbe_venue':
				$venue = MBE_Gigs_Query::get_venue( $post_id );

				if ( ! $venue ) {
					echo '<span class="mbe-missing">—</span>';
					break;
				}

				$city = (string) get_term_meta( $venue->term_id, 'mbe_venue_city', true );

				echo esc_html( $venue->name );

				if ( '' !== $city ) {
					echo '<br /><span class="mbe-help">' . esc_html( $city ) . '</span>';
				}
				break;

			case 'mbe_artist':
				$name = MBE_Gigs_Post_Type::first_term_name( $post_id, MBE_GIGS_TAX_ARTIST );
				echo $name ? esc_html( $name ) : '<span class="mbe-missing">—</span>';
				break;

			case 'mbe_gig_status':
				$status   = (string) get_post_meta( $post_id, 'mbe_gig_status', true );
				$statuses = MBE_Gigs_Meta::statuses();
				$status   = isset( $statuses[ $status ] ) ? $status : 'scheduled';

				printf(
					'<span class="mbe-status mbe-status-%s">%s</span>',
					esc_attr( $status ),
					esc_html( $statuses[ $status ] )
				);
				break;
		}
	}

	/**
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['mbe_gig_date'] = 'mbe_gig_date';

		return $columns;
	}

	/**
	 * Upcoming / past filter above the list.
	 *
	 * @param string $post_type Current post type.
	 */
	public static function filter_dropdown( $post_type ) {
		if ( MBE_GIGS_CPT !== $post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset( $_GET['mbe_when'] ) ? sanitize_key( wp_unslash( $_GET['mbe_when'] ) ) : '';

		$options = array(
			''         => __( 'All gigs', 'mbe-gigs' ),
			'upcoming' => __( 'Upcoming', 'mbe-gigs' ),
			'past'     => __( 'Past', 'mbe-gigs' ),
			'nodate'   => __( 'Needs a date', 'mbe-gigs' ),
		);

		echo '<select name="mbe_when">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Sort the admin list by gig date, not publish date.
	 *
	 * Publish-date ordering is useless here: a gig entered last week may be two years
	 * away, and the whole list reads as random.
	 *
	 * @param WP_Query $query Query object.
	 */
	public static function admin_query( $query ) {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow ) {
			return;
		}

		if ( MBE_GIGS_CPT !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$when = isset( $_GET['mbe_when'] ) ? sanitize_key( wp_unslash( $_GET['mbe_when'] ) ) : '';

		/*
		 * A gig saved without a date can't appear in a list ordered by date — the
		 * ordering clause requires the meta row to exist. Rather than let one become
		 * invisible, "Needs a date" finds them explicitly.
		 */
		if ( 'nodate' === $when ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => 'mbe_gig_date',
						'compare' => 'NOT EXISTS',
					),
				)
			);

			return;
		}

		$direction = in_array( $when, array( 'upcoming', 'past' ), true ) ? $when : 'all';
		$today     = mbe_gigs_today();

		$order = $query->get( 'order' );
		$order = in_array( strtoupper( (string) $order ), array( 'ASC', 'DESC' ), true )
			? strtoupper( $order )
			// Default: newest gig first, so next weekend is at the top rather than 2016.
			: ( 'upcoming' === $direction ? 'ASC' : 'DESC' );

		$query->set( 'meta_query', MBE_Gigs_Query::meta_query( $direction, $today ) );
		$query->set( 'orderby', array( 'mbe_gig_date_clause' => $order ) );
	}

	/**
	 * Say so when gigs are hidden from the list because they have no date.
	 *
	 * The default list is ordered by gig date, which means it can only contain gigs
	 * that have one. Without this notice a half-entered gig is invisible on the exact
	 * screen you'd go to looking for it.
	 */
	public static function dateless_notice() {
		global $pagenow;

		if ( 'edit.php' !== $pagenow || MBE_GIGS_CPT !== get_query_var( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if ( isset( $_GET['mbe_when'] ) && 'nodate' === sanitize_key( wp_unslash( $_GET['mbe_when'] ) ) ) {
			return;
		}

		$orphans = get_posts(
			array(
				'post_type'      => MBE_GIGS_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => 'mbe_gig_date',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		if ( ! $orphans ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of gigs */
					_n(
						'%d gig has no date, so it is not shown in this list.',
						'%d gigs have no date, so they are not shown in this list.',
						count( $orphans ),
						'mbe-gigs'
					),
					count( $orphans )
				)
			),
			esc_url( add_query_arg( array( 'post_type' => MBE_GIGS_CPT, 'mbe_when' => 'nodate' ), admin_url( 'edit.php' ) ) ),
			esc_html__( 'Show them', 'mbe-gigs' )
		);
	}

	/**
	 * A handful of rules and the one piece of behaviour the edit screen needs.
	 */
	public static function styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || MBE_GIGS_CPT !== $screen->post_type ) {
			return;
		}
		?>
		<style>
			.mbe-gig-field { display: flex; align-items: center; margin: 0 0 12px; }
			.mbe-gig-field > label { flex: 0 0 130px; font-weight: 600; }
			.mbe-gig-input { flex: 1 1 auto; }
			.mbe-help { color: #646970; font-size: 12px; }
			.mbe-req { color: #b32d2e; }
			.mbe-missing { color: #a7aaad; }
			.mbe-past { color: #646970; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
			.mbe-status { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 12px; background: #edeff0; }
			.mbe-status-cancelled { background: #f7dcdc; color: #8a1f1f; }
			.mbe-status-postponed { background: #fcf1dc; color: #8a5b1f; }
			.mbe-listed-as { margin: 4px 0 0; padding-top: 10px; border-top: 1px solid #f0f0f1; color: #50575e; }
			.mbe-editor-heading { margin: 22px 0 2px; font-size: 14px; }
			.mbe-editor-help { margin: 0 0 8px; }
			#mbe-gig-details .inside { padding-top: 12px; }
			/* [hidden] must win — an author display rule beats the browser's own. */
			.mbe-term-new-wrap[hidden] { display: none; }
			.mbe-term-new-wrap { display: inline-flex; gap: 6px; margin-left: 6px; }
			.mbe-term-new-wrap input { min-width: 180px; }
			.mbe-term-new-wrap input.mbe-state { min-width: 80px; text-transform: uppercase; }
			.column-mbe_gig_date { width: 160px; }
			.column-mbe_gig_status { width: 110px; }
		</style>
		<script>
			document.addEventListener( 'change', function ( e ) {
				if ( ! e.target.classList || ! e.target.classList.contains( 'mbe-term-select' ) ) {
					return;
				}
				var wrap = document.getElementById( e.target.dataset.newWrap );
				if ( ! wrap ) {
					return;
				}
				var adding = '__new__' === e.target.value;
				var inputs = wrap.querySelectorAll( 'input' );
				wrap.hidden = ! adding;
				if ( adding ) {
					inputs[ 0 ].focus();
				} else {
					// Clear, so switching back to an existing term can't leave a stray name behind.
					inputs.forEach( function ( input ) { input.value = ''; } );
				}
			} );
		</script>
		<?php
	}
}
