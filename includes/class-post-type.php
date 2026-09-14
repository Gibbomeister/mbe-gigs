<?php
/**
 * Post type and taxonomy registration.
 *
 * This file is the reason the plugin exists. It must never live in a child theme
 * or in Beaver Themer — if a site moves off the page builder, the content has to
 * survive untouched and only the display gets rebuilt.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

class MBE_Gigs_Post_Type {

	/**
	 * Hooks that do not depend on registration order.
	 */
	public static function hooks() {
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'allow_empty_content' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'auto_title' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'editor' ), 10, 2 );
	}

	/**
	 * Let a gig save with an empty title, editor and excerpt.
	 *
	 * wp_insert_post() refuses to write a post when the title, content and excerpt
	 * are all empty and the post type supports all three. It returns before the
	 * wp_insert_post_data filter, so the generated title never gets a chance to exist
	 * — and before save_post, so the venue and artist terms are never created either.
	 * The admin still redirects with "Post published", because redirect_post() doesn't
	 * check the result. Silent, and exactly what you'd least like to debug.
	 *
	 * A gig is defined by its date and its venue, not by prose. Nothing about it
	 * requires a word of body copy, so the guard doesn't apply to this post type.
	 *
	 * @param bool  $maybe_empty Whether WordPress considers the post empty.
	 * @param array $postarr     Post data.
	 * @return bool
	 */
	public static function allow_empty_content( $maybe_empty, $postarr ) {
		if ( ! $maybe_empty ) {
			return $maybe_empty;
		}

		$post_type = isset( $postarr['post_type'] ) ? $postarr['post_type'] : '';

		return ( MBE_GIGS_CPT === $post_type ) ? false : $maybe_empty;
	}

	/**
	 * Gigs use the classic editor.
	 *
	 * Not nostalgia. The gig fields are one meta box, and in the block editor that box
	 * renders below the canvas while the sidebar separately shows tag-style Venue and
	 * Artist panels — two places to set the same thing, one of which allows the typos
	 * the taxonomy exists to prevent. Classic gives one screen with the fields in the
	 * order someone types them.
	 *
	 * `show_in_rest` stays true regardless, so the REST API and any future front end
	 * are unaffected. This is a choice about the edit screen, not about the data.
	 *
	 * To opt a site back into the block editor:
	 *
	 *     add_filter( 'mbe_gigs_use_block_editor', '__return_true' );
	 *
	 * @param bool   $use       Whether to use the block editor.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function editor( $use, $post_type ) {
		if ( MBE_GIGS_CPT !== $post_type ) {
			return $use;
		}

		return (bool) apply_filters( 'mbe_gigs_use_block_editor', false );
	}

	/**
	 * Register the gig post type and its two taxonomies.
	 */
	public static function register() {
		register_post_type(
			MBE_GIGS_CPT,
			array(
				'labels'             => array(
					'name'                  => __( 'Gigs', 'mbe-gigs' ),
					'singular_name'         => __( 'Gig', 'mbe-gigs' ),
					'add_new'               => __( 'Add Gig', 'mbe-gigs' ),
					'add_new_item'          => __( 'Add Gig', 'mbe-gigs' ),
					'edit_item'             => __( 'Edit Gig', 'mbe-gigs' ),
					'new_item'              => __( 'New Gig', 'mbe-gigs' ),
					'view_item'             => __( 'View Gig', 'mbe-gigs' ),
					'search_items'          => __( 'Search Gigs', 'mbe-gigs' ),
					'not_found'             => __( 'No gigs yet.', 'mbe-gigs' ),
					'not_found_in_trash'    => __( 'No gigs in the trash.', 'mbe-gigs' ),
					'all_items'             => __( 'All Gigs', 'mbe-gigs' ),
					'menu_name'             => __( 'Gigs', 'mbe-gigs' ),
					'item_published'        => __( 'Gig published.', 'mbe-gigs' ),
					'item_updated'          => __( 'Gig updated.', 'mbe-gigs' ),
				),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'menu_position'      => 21,
				'menu_icon'          => 'dashicons-tickets-alt',
				'hierarchical'       => false,
				'has_archive'        => true,
				'rewrite'            => array(
					'slug'       => apply_filters( 'mbe_gigs_rewrite_slug', 'gigs' ),
					'with_front' => false,
				),
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
				'taxonomies'         => array( MBE_GIGS_TAX_VENUE, MBE_GIGS_TAX_ARTIST ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);

		/*
		 * Venues.
		 *
		 * Non-hierarchical, because a site with 140 venues is unusable as a list of
		 * checkboxes. The default tag-style metabox is replaced in class-admin.php
		 * with a single select, so a typo can't quietly mint "The Bridge Hotel"
		 * alongside "Bridge Hotel".
		 */
		register_taxonomy(
			MBE_GIGS_TAX_VENUE,
			array( MBE_GIGS_CPT ),
			array(
				'labels'             => array(
					'name'          => __( 'Venues', 'mbe-gigs' ),
					'singular_name' => __( 'Venue', 'mbe-gigs' ),
					'search_items'  => __( 'Search Venues', 'mbe-gigs' ),
					'all_items'     => __( 'All Venues', 'mbe-gigs' ),
					'edit_item'     => __( 'Edit Venue', 'mbe-gigs' ),
					'update_item'   => __( 'Update Venue', 'mbe-gigs' ),
					'add_new_item'  => __( 'Add Venue', 'mbe-gigs' ),
					'new_item_name' => __( 'New venue name', 'mbe-gigs' ),
					'menu_name'     => __( 'Venues', 'mbe-gigs' ),
					'not_found'     => __( 'No venues yet.', 'mbe-gigs' ),
				),
				'public'             => self::archives_enabled( MBE_GIGS_TAX_VENUE ),
				'publicly_queryable' => self::archives_enabled( MBE_GIGS_TAX_VENUE ),
				'show_ui'            => true,
				'show_admin_column'  => false, // Supplied by our own sortable column instead.
				'show_in_rest'       => true,
				'show_in_quick_edit' => false,
				'hierarchical'       => false,
				'rewrite'            => self::archives_enabled( MBE_GIGS_TAX_VENUE )
					? array(
						'slug'       => apply_filters( 'mbe_gigs_venue_slug', 'venue' ),
						'with_front' => false,
					)
					: false,
			)
		);

		register_taxonomy(
			MBE_GIGS_TAX_ARTIST,
			array( MBE_GIGS_CPT ),
			array(
				'labels'             => array(
					'name'          => __( 'Artists', 'mbe-gigs' ),
					'singular_name' => __( 'Artist', 'mbe-gigs' ),
					'search_items'  => __( 'Search Artists', 'mbe-gigs' ),
					'all_items'     => __( 'All Artists', 'mbe-gigs' ),
					'edit_item'     => __( 'Edit Artist', 'mbe-gigs' ),
					'update_item'   => __( 'Update Artist', 'mbe-gigs' ),
					'add_new_item'  => __( 'Add Artist', 'mbe-gigs' ),
					'new_item_name' => __( 'New artist name', 'mbe-gigs' ),
					'menu_name'     => __( 'Artists', 'mbe-gigs' ),
					'not_found'     => __( 'No artists yet.', 'mbe-gigs' ),
				),
				'public'             => self::archives_enabled( MBE_GIGS_TAX_ARTIST ),
				'publicly_queryable' => self::archives_enabled( MBE_GIGS_TAX_ARTIST ),
				'show_ui'            => true,
				'show_admin_column'  => false,
				'show_in_rest'       => true,
				'show_in_quick_edit' => false,
				'hierarchical'       => false,
				'rewrite'            => self::archives_enabled( MBE_GIGS_TAX_ARTIST )
					? array(
						'slug'       => apply_filters( 'mbe_gigs_artist_slug', 'artist' ),
						'with_front' => false,
					)
					: false,
			)
		);
	}

	/**
	 * Whether a taxonomy gets public archive URLs on this site.
	 *
	 * Some sites already publish their own venue and artist pages. Minting a second
	 * URL for the same venue is how you end up with two pages about the same pub and
	 * no answer for which one wins. On those sites, drop this in wp-config or a
	 * one-line mu-plugin:
	 *
	 *     add_filter( 'mbe_gigs_archives_enabled', '__return_false' );
	 *
	 * The taxonomy still exists, still deduplicates, still holds its term meta. It
	 * just doesn't publish. Link out to the existing page with the term meta field
	 * `mbe_venue_url` instead.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public static function archives_enabled( $taxonomy ) {
		return (bool) apply_filters( 'mbe_gigs_archives_enabled', true, $taxonomy );
	}

	/**
	 * Build the post title from the gig itself.
	 *
	 * A CPT needs a title. Left to invent one, people type "gig", "Sat night" or
	 * nothing at all, and you spend the next two years cleaning it up. If the title
	 * is empty — or still matches what we generated last time — we regenerate it.
	 * A title someone has deliberately written is never overwritten.
	 *
	 * @param array $data    Sanitised post data about to be written.
	 * @param array $postarr Raw post data, including the post ID.
	 * @return array
	 */
	public static function auto_title( $data, $postarr ) {
		if ( MBE_GIGS_CPT !== $data['post_type'] ) {
			return $data;
		}

		if ( in_array( $data['post_status'], array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$current = trim( wp_strip_all_tags( $data['post_title'] ) );
		$stored  = $post_id ? (string) get_post_meta( $post_id, 'mbe_gig_auto_title', true ) : '';

		// Someone wrote their own title. Leave it alone.
		if ( '' !== $current && $current !== $stored ) {
			return $data;
		}

		$generated = self::build_title( $post_id, $postarr );

		if ( '' === $generated ) {
			return $data;
		}

		$data['post_title'] = $generated;

		if ( $post_id ) {
			update_post_meta( $post_id, 'mbe_gig_auto_title', $generated );
		}

		if ( empty( $data['post_name'] ) ) {
			$data['post_name'] = sanitize_title( $generated );
		}

		return $data;
	}

	/**
	 * Assemble "Artist — Venue, 12 October 2026".
	 *
	 * Reads the values being submitted where it can, falling back to what is already
	 * stored. Taxonomy terms are not saved yet at wp_insert_post_data time, so the
	 * submitted values are what we have to work with.
	 *
	 * @param int   $post_id Post ID, 0 for a new post.
	 * @param array $postarr Raw post data.
	 * @return string
	 */
	protected static function build_title( $post_id, $postarr ) {
		$artist = '';
		$venue  = '';
		$date   = '';

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only; the save handler verifies.
		$artist = self::submitted_term_name( MBE_GIGS_TAX_ARTIST, 'mbe_gig_artist_term' );
		$venue  = self::submitted_term_name( MBE_GIGS_TAX_VENUE, 'mbe_gig_venue_term' );

		if ( isset( $_POST['mbe_gig_date'] ) ) {
			$date = sanitize_text_field( wp_unslash( $_POST['mbe_gig_date'] ) );
		}
		// phpcs:enable

		if ( $post_id ) {
			if ( '' === $artist ) {
				$artist = self::first_term_name( $post_id, MBE_GIGS_TAX_ARTIST );
			}
			if ( '' === $venue ) {
				$venue = self::first_term_name( $post_id, MBE_GIGS_TAX_VENUE );
			}
			if ( '' === $date ) {
				$date = (string) get_post_meta( $post_id, 'mbe_gig_date', true );
			}
		}

		$parts = array();

		if ( '' !== $artist ) {
			$parts[] = $artist;
		}

		$tail = array();
		if ( '' !== $venue ) {
			$tail[] = $venue;
		}
		if ( '' !== $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$tail[] = date_i18n( 'j F Y', strtotime( $date . ' 12:00:00' ) );
		}

		if ( $tail ) {
			$parts[] = implode( ', ', $tail );
		}

		return implode( ' — ', array_filter( $parts ) );
	}

	/**
	 * Resolve the submitted venue or artist to a display name.
	 *
	 * The select posts either a term ID or the marker `__new__`, in which case the
	 * name is in a companion field. Terms aren't assigned yet when the title is
	 * generated — wp_insert_post_data runs before save_post — so the submitted values
	 * are what there is to work with.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $field    Field name.
	 * @return string
	 */
	protected static function submitted_term_name( $taxonomy, $field ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only; the save handler verifies.
		if ( ! isset( $_POST[ $field ] ) ) {
			return '';
		}

		$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

		if ( '__new__' === $value ) {
			return isset( $_POST[ $field . '_new' ] )
				? trim( sanitize_text_field( wp_unslash( $_POST[ $field . '_new' ] ) ) )
				: '';
		}
		// phpcs:enable

		if ( '' === $value ) {
			return '';
		}

		$term = get_term( (int) $value, $taxonomy );

		return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}

	/**
	 * First assigned term name for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	public static function first_term_name( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}

		$term = reset( $terms );

		return $term->name;
	}

	/**
	 * Nudge people away from typing a title at all.
	 *
	 * @param string  $text Placeholder text.
	 * @param WP_Post $post Post being edited.
	 * @return string
	 */
	public static function title_placeholder( $text, $post ) {
		if ( $post && MBE_GIGS_CPT === $post->post_type ) {
			return __( 'Leave blank — built from artist, venue and date', 'mbe-gigs' );
		}

		return $text;
	}
}
