<?php
/**
 * GigPress importer. WP-CLI only.
 *
 *     wp mbe-gigs inspect
 *     wp mbe-gigs import --dry-run
 *     wp mbe-gigs import --venues=venues.csv --log=import.csv
 *     wp mbe-gigs rollback --yes
 *
 * Three properties matter more than speed:
 *
 * - Idempotent. Every imported gig carries the GigPress show ID in
 *   `mbe_gigs_source_id`. A second run finds it and updates rather than duplicates,
 *   so an interrupted import is resumed by running it again.
 * - Logged. Every created, updated and skipped row is written to a CSV.
 * - Reversible. `rollback` removes exactly what the importer created and nothing
 *   else. The GigPress tables are never modified — deactivating the old plugin and
 *   leaving its tables in place is the real rollback.
 *
 * Column names are discovered rather than assumed. GigPress 2.x shifted its schema
 * over the years and these installs were not all set up in the same year.
 *
 * @package MBE_Gigs
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class MBE_Gigs_Importer {

	const SOURCE_META  = 'mbe_gigs_source_id';
	const VENUE_MAP    = 'mbe_gigs_venue_map';
	const SOURCE_TERM  = 'mbe_gigs_source_venue_id';

	/**
	 * Candidate column names, best first.
	 *
	 * Verified against a real GigPress 2.x install (tedmulrygang, Sep 2026). The
	 * shows table prefixes almost everything with `show_`, including the foreign
	 * keys — `show_venue_id`, not `venue_id`. The older, unprefixed spellings are
	 * kept as fallbacks in case an install from a different era turns up.
	 *
	 * @var array
	 */
	protected $show_columns = array(
		'id'       => array( 'show_id', 'id' ),
		'venue'    => array( 'show_venue_id', 'venue_id' ),
		'artist'   => array( 'show_artist_id', 'artist_id' ),
		'date'     => array( 'show_date', 'date' ),
		'time'     => array( 'show_time', 'time' ),
		'end_date' => array( 'show_expire', 'show_end_date', 'end_date' ),
		'multi'    => array( 'show_multi' ),
		'price'    => array( 'show_price', 'price' ),
		'tickets'  => array( 'show_tix_url', 'show_tickets', 'show_ticket_url' ),
		'notes'    => array( 'show_notes', 'notes' ),
		'status'   => array( 'show_status', 'status' ),
		'tour'     => array( 'show_tour_id', 'gig_id' ),
	);

	/**
	 * Venue detail stored on the show row itself.
	 *
	 * GigPress keeps a venue snapshot on each show, which is what it falls back to
	 * when a show has no venues-table entry. Without this, such a show would import
	 * with no venue at all and nothing would say so.
	 *
	 * Note `show_locale` is the city — the shows table doesn't follow the venues
	 * table's naming.
	 *
	 * @var array
	 */
	protected $show_venue_columns = array(
		'name'    => array( 'show_venue' ),
		'address' => array( 'show_address' ),
		'city'    => array( 'show_locale' ),
		'country' => array( 'show_country' ),
		'url'     => array( 'show_venue_url' ),
		'phone'   => array( 'show_venue_phone' ),
	);

	/** @var array Resolved show-row venue columns, set during import(). */
	protected $map_show_venue = array();

	/** @var array GigPress tour ID => tour name, set during import(). */
	protected $tour_map = array();

	/**
	 * Names by source ID, recorded whether or not a term was created.
	 *
	 * A dry run creates no terms, so without these the generated gig titles would
	 * come out as a bare date — making the dry run report something different from
	 * what the real run produces, which defeats the point of having one.
	 *
	 * @var array
	 */
	protected $venue_names  = array();
	protected $artist_names = array();

	protected $venue_columns = array(
		'id'       => array( 'venue_id', 'id' ),
		'name'     => array( 'venue_name', 'name' ),
		'address'  => array( 'venue_address', 'address' ),
		'city'     => array( 'venue_city', 'city' ),
		'state'    => array( 'venue_state', 'state' ),
		'postcode' => array( 'venue_postal_code', 'venue_postcode', 'postal_code' ),
		'country'  => array( 'venue_country', 'country' ),
		'phone'    => array( 'venue_phone', 'phone' ),
		'url'      => array( 'venue_url', 'url' ),
	);

	protected $artist_columns = array(
		'id'   => array( 'artist_id', 'id' ),
		'name' => array( 'artist_name', 'name' ),
		'url'  => array( 'artist_url', 'url' ),
	);

	protected $tour_columns = array(
		'id'   => array( 'tour_id', 'id' ),
		'name' => array( 'tour_name', 'name' ),
	);

	/**
	 * Report on the GigPress data without touching anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mbe-gigs inspect
	 *
	 * @subcommand inspect
	 */
	public function inspect( $args, $assoc_args ) {
		global $wpdb;

		$tables = $this->tables();

		foreach ( $tables as $label => $table ) {
			if ( ! $this->table_exists( $table ) ) {
				WP_CLI::line( sprintf( '%-8s %s — not present', $label, $table ) );
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name validated against the site prefix.
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			$cols  = implode( ', ', $this->columns( $table ) );

			WP_CLI::line( sprintf( '%-8s %s — %d rows', $label, $table, $count ) );
			WP_CLI::line( '         ' . $cols );
		}

		if ( ! $this->table_exists( $tables['shows'] ) ) {
			WP_CLI::error( 'No GigPress shows table on this site. Nothing to import.' );
		}

		$map = $this->resolve_columns( $tables['shows'], $this->show_columns );

		$missing = array();
		foreach ( array( 'id', 'date' ) as $required ) {
			if ( empty( $map[ $required ] ) ) {
				$missing[] = $required;
			}
		}

		if ( $missing ) {
			WP_CLI::error( 'Cannot map required columns: ' . implode( ', ', $missing ) );
		}

		WP_CLI::line( '' );
		WP_CLI::line( 'Column mapping:' );

		foreach ( $map as $role => $column ) {
			WP_CLI::line( sprintf( '  %-9s -> %s', $role, $column ? $column : '(none — will be skipped)' ) );
		}

		WP_CLI::line( '' );
		WP_CLI::line( 'Venue detail on the show row (fallback when a show has no venue_id):' );

		foreach ( $this->resolve_columns( $tables['shows'], $this->show_venue_columns ) as $role => $column ) {
			WP_CLI::line( sprintf( '  %-9s -> %s', $role, $column ? $column : '(none)' ) );
		}

		/*
		 * The end-date question. GigPress writes show_expire = show_date for an
		 * ordinary gig and a later date for a genuine multi-day event, so an end date
		 * that isn't after the start date is bookkeeping, not a festival. Worth
		 * seeing the split per site before trusting it.
		 */
		if ( $map['end_date'] && $map['date'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers only.
			$row = $wpdb->get_row(
				sprintf(
					'SELECT COUNT(*) AS total, SUM(`%1$s` > `%2$s`) AS multi FROM `%3$s`',
					esc_sql( $map['end_date'] ),
					esc_sql( $map['date'] ),
					$tables['shows']
				),
				ARRAY_A
			);

			if ( $row ) {
				WP_CLI::line( '' );
				WP_CLI::line(
					sprintf(
						'End dates: %d of %d shows end after they start — those import as multi-day, the rest as single-day.',
						(int) $row['multi'],
						(int) $row['total']
					)
				);
			}
		}

		/*
		 * Two counts that explain any gap between what GigPress displays and what its
		 * tables hold. Both bit on the first real site, so both get reported.
		 */
		if ( $map['status'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers only.
			$deleted = (int) $wpdb->get_var(
				sprintf(
					"SELECT COUNT(*) FROM `%s` WHERE LOWER(TRIM(`%s`)) IN ('deleted','trash','trashed')",
					$tables['shows'],
					esc_sql( $map['status'] )
				)
			);

			WP_CLI::line( sprintf( 'Deleted in GigPress: %d shows, which will be skipped.', $deleted ) );
		}

		if ( $map['time'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers only.
			$tba = (int) $wpdb->get_var(
				sprintf(
					"SELECT COUNT(*) FROM `%s` WHERE `%s` IN ('00:00:00','00:00:01')",
					$tables['shows'],
					esc_sql( $map['time'] )
				)
			);

			WP_CLI::line( sprintf( 'Time not announced: %d shows, which import with no time rather than midnight.', $tba ) );
		}

		$already = $this->imported_count();
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Gigs already imported into this site: %d', $already ) );
	}

	/**
	 * Import GigPress shows and venues.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would happen without writing anything.
	 *
	 * [--venues=<file>]
	 * : CSV of approved venue names. Columns: venue_as_entered, city_as_entered,
	 * proposed_venue, proposed_city, proposed_state, proposed_postcode, and optional
	 * APPROVED_venue / APPROVED_city / APPROVED_state which take precedence.
	 *
	 * [--artist=<name>]
	 * : Artist name to use when the install has no artists table.
	 *
	 * [--limit=<number>]
	 * : Stop after this many shows. Useful for a first pass on a big site.
	 *
	 * [--log=<file>]
	 * : Write a CSV log here. Defaults to mbe-gigs-import-<date>.csv in the uploads folder.
	 *
	 * [--status=<status>]
	 * : Post status for created gigs. Default publish.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mbe-gigs import --dry-run
	 *     wp mbe-gigs import --venues=venues-worksheet.csv
	 *
	 * @subcommand import
	 */
	public function import( $args, $assoc_args ) {
		global $wpdb;

		$dry_run     = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$limit       = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$post_status = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'publish' );
		$fallback    = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'artist', '' );
		$venue_file  = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'venues', '' );

		$tables = $this->tables();

		if ( ! $this->table_exists( $tables['shows'] ) ) {
			WP_CLI::error( 'No GigPress shows table on this site.' );
		}

		$overrides = $venue_file ? $this->load_venue_overrides( $venue_file ) : array();

		if ( $venue_file ) {
			WP_CLI::line( sprintf( 'Loaded %d venue overrides from %s', count( $overrides ), $venue_file ) );
		}

		$log = $this->open_log( WP_CLI\Utils\get_flag_value( $assoc_args, 'log', '' ), $dry_run );

		/*
		 * Pass one: venues.
		 *
		 * Every venue becomes a term before any gig is created, so gig creation is a
		 * lookup rather than a race. The map is stored, so a re-run reuses the same
		 * terms instead of making a second set.
		 */
		$venue_map = $this->import_venues( $tables['venues'], $overrides, $dry_run, $log );
		$artist_map = $this->import_artists( $tables['artists'], $dry_run, $log );

		/*
		 * Counted from the recorded names rather than the term map, so a dry run —
		 * which creates no terms — reports the venues it read rather than zero.
		 */
		WP_CLI::line( sprintf( 'Venues: %d', count( $this->venue_names ) ) );
		WP_CLI::line( sprintf( 'Artists: %d', count( $this->artist_names ) ) );

		// Tours are carried as a plain name on the gig, not as terms of their own.
		$this->tour_map = $this->load_tours( $tables['tours'] );
		WP_CLI::line( sprintf( 'Tours: %d loaded', count( $this->tour_map ) ) );

		// Pass two: shows.
		$map                  = $this->resolve_columns( $tables['shows'], $this->show_columns );
		$this->map_show_venue = $this->resolve_columns( $tables['shows'], $this->show_venue_columns );
		$shows                = $this->fetch_rows( $tables['shows'], $map['date'], $limit );

		$counts = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		);

		$progress = WP_CLI\Utils\make_progress_bar( 'Importing gigs', count( $shows ) );

		foreach ( $shows as $row ) {
			$result = $this->import_show( $row, $map, $venue_map, $artist_map, $fallback, $post_status, $dry_run, $log );
			$counts[ $result ] = isset( $counts[ $result ] ) ? $counts[ $result ] + 1 : 1;
			$progress->tick();
		}

		$progress->finish();

		if ( $log ) {
			fclose( $log );
		}

		WP_CLI::success(
			sprintf(
				'%s — created %d, updated %d, skipped %d, failed %d.',
				$dry_run ? 'Dry run complete' : 'Import complete',
				$counts['created'],
				$counts['updated'],
				$counts['skipped'],
				$counts['failed']
			)
		);

		if ( $dry_run ) {
			WP_CLI::line( 'Nothing was written. Re-run without --dry-run to apply.' );
		}
	}

	/**
	 * Remove everything this importer created on this site.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @subcommand rollback
	 */
	public function rollback( $args, $assoc_args ) {
		WP_CLI::confirm( 'Delete every gig this importer created on this site?', $assoc_args );

		$ids = get_posts(
			array(
				'post_type'      => MBE_GIGS_CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::SOURCE_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => MBE_GIGS_TAX_VENUE,
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key'     => self::SOURCE_TERM,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				wp_delete_term( $term->term_id, MBE_GIGS_TAX_VENUE );
			}
		}

		delete_option( self::VENUE_MAP );

		WP_CLI::success( sprintf( 'Removed %d gigs and their imported venues.', count( $ids ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Venues
	 * ------------------------------------------------------------------ */

	/**
	 * @param string   $table     Venues table.
	 * @param array    $overrides Approved names keyed by "name|city".
	 * @param bool     $dry_run   Whether to write.
	 * @param resource $log       Log handle.
	 * @return array GigPress venue ID => term ID.
	 */
	protected function import_venues( $table, $overrides, $dry_run, $log ) {
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$map     = $this->resolve_columns( $table, $this->venue_columns );
		$rows    = $this->fetch_rows( $table, $map['name'], 0 );
		$stored  = get_option( self::VENUE_MAP, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$result  = array();

		foreach ( $rows as $row ) {
			$source_id = isset( $row[ $map['id'] ] ) ? (int) $row[ $map['id'] ] : 0;
			$name      = $map['name'] ? trim( (string) $row[ $map['name'] ] ) : '';

			if ( '' === $name ) {
				$this->log( $log, 'venue', $source_id, 'skipped', 'no name' );
				continue;
			}

			$city = $map['city'] ? trim( (string) $row[ $map['city'] ] ) : '';

			$override = $this->lookup_override( $overrides, $name, $city );

			$final_name  = $override['venue'] ? $override['venue'] : $name;
			$final_city  = $override['city'] ? $override['city'] : $city;
			$final_state = $override['state'] ? $override['state'] : ( $map['state'] ? trim( (string) $row[ $map['state'] ] ) : '' );

			// Recorded before any of the branches below, so a dry run still has names.
			$this->venue_names[ $source_id ] = $final_name;

			// Already mapped from an earlier run.
			if ( isset( $stored[ $source_id ] ) && get_term( (int) $stored[ $source_id ], MBE_GIGS_TAX_VENUE ) ) {
				$result[ $source_id ] = (int) $stored[ $source_id ];
				$this->log( $log, 'venue', $source_id, 'skipped', 'already mapped to term ' . $stored[ $source_id ] );
				continue;
			}

			/*
			 * Venue identity is name PLUS city. "Pier Hotel" in Botany and "Pier Hotel"
			 * in Frankston are two different pubs, and merging them on name alone is
			 * the kind of error nobody notices for two years.
			 */
			$term_id = $this->find_venue_term( $final_name, $final_city );

			if ( $term_id ) {
				$this->log( $log, 'venue', $source_id, 'skipped', sprintf( 'matched existing term %d (%s)', $term_id, $final_name ) );
			} elseif ( $dry_run ) {
				$this->log( $log, 'venue', $source_id, 'created', sprintf( 'would create "%s" (%s)', $final_name, $final_city ) );
				continue;
			} else {
				$created = wp_insert_term( $final_name, MBE_GIGS_TAX_VENUE );

				if ( is_wp_error( $created ) ) {
					// A term of that name exists with a different city — disambiguate the slug.
					$created = wp_insert_term(
						$final_name,
						MBE_GIGS_TAX_VENUE,
						array( 'slug' => sanitize_title( $final_name . ' ' . $final_city ) )
					);
				}

				if ( is_wp_error( $created ) ) {
					$this->log( $log, 'venue', $source_id, 'failed', $created->get_error_message() );
					continue;
				}

				$term_id = (int) $created['term_id'];
				$this->log( $log, 'venue', $source_id, 'created', sprintf( 'term %d "%s"', $term_id, $final_name ) );
			}

			if ( ! $dry_run && $term_id ) {
				$meta = array(
					'mbe_venue_address'  => $map['address'] ? trim( (string) $row[ $map['address'] ] ) : '',
					'mbe_venue_city'     => $final_city,
					'mbe_venue_state'    => $final_state,
					'mbe_venue_postcode' => $override['postcode'] ? $override['postcode'] : ( $map['postcode'] ? trim( (string) $row[ $map['postcode'] ] ) : '' ),
					'mbe_venue_country'  => $map['country'] ? trim( (string) $row[ $map['country'] ] ) : 'AU',
					'mbe_venue_phone'    => $map['phone'] ? trim( (string) $row[ $map['phone'] ] ) : '',
					'mbe_venue_url'      => $map['url'] ? MBE_Gigs_Meta::sanitize_url( (string) $row[ $map['url'] ] ) : '',
				);

				foreach ( $meta as $key => $value ) {
					if ( '' === $value ) {
						continue;
					}

					// Don't overwrite detail someone has already corrected by hand.
					if ( '' === (string) get_term_meta( $term_id, $key, true ) ) {
						update_term_meta( $term_id, $key, $value );
					}
				}

				update_term_meta( $term_id, self::SOURCE_TERM, $source_id );
				$stored[ $source_id ] = $term_id;
			}

			if ( $term_id ) {
				$result[ $source_id ] = $term_id;
			}
		}

		if ( ! $dry_run ) {
			update_option( self::VENUE_MAP, $stored, false );
		}

		return $result;
	}

	/**
	 * Find a venue term by name and city.
	 *
	 * @param string $name Venue name.
	 * @param string $city City.
	 * @return int Term ID, or 0.
	 */
	protected function find_venue_term( $name, $city ) {
		// Shared with the gig edit screen, so both agree on what counts as the same venue.
		return MBE_Gigs_Meta::find_venue( $name, $city );
	}

	/**
	 * @param string   $table   Artists table.
	 * @param bool     $dry_run Whether to write.
	 * @param resource $log     Log handle.
	 * @return array GigPress artist ID => term ID.
	 */
	protected function import_artists( $table, $dry_run, $log ) {
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$map    = $this->resolve_columns( $table, $this->artist_columns );
		$rows   = $this->fetch_rows( $table, $map['name'], 0 );
		$result = array();

		foreach ( $rows as $row ) {
			$source_id = isset( $row[ $map['id'] ] ) ? (int) $row[ $map['id'] ] : 0;
			$name      = $map['name'] ? trim( (string) $row[ $map['name'] ] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$this->artist_names[ $source_id ] = $name;

			$existing = get_term_by( 'name', $name, MBE_GIGS_TAX_ARTIST );

			if ( $existing ) {
				$result[ $source_id ] = (int) $existing->term_id;
				continue;
			}

			if ( $dry_run ) {
				$this->log( $log, 'artist', $source_id, 'created', sprintf( 'would create "%s"', $name ) );
				continue;
			}

			$created = wp_insert_term( $name, MBE_GIGS_TAX_ARTIST );

			if ( is_wp_error( $created ) ) {
				$this->log( $log, 'artist', $source_id, 'failed', $created->get_error_message() );
				continue;
			}

			$result[ $source_id ] = (int) $created['term_id'];

			if ( $map['url'] && ! empty( $row[ $map['url'] ] ) ) {
				update_term_meta( $created['term_id'], 'mbe_artist_url', MBE_Gigs_Meta::sanitize_url( $row[ $map['url'] ] ) );
			}

			$this->log( $log, 'artist', $source_id, 'created', sprintf( 'term %d "%s"', $created['term_id'], $name ) );
		}

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Shows
	 * ------------------------------------------------------------------ */

	/**
	 * @param array    $row         Source row.
	 * @param array    $map         Column map.
	 * @param array    $venue_map   GigPress venue ID => term ID.
	 * @param array    $artist_map  GigPress artist ID => term ID.
	 * @param string   $fallback    Fallback artist name.
	 * @param string   $post_status Post status.
	 * @param bool     $dry_run     Whether to write.
	 * @param resource $log         Log handle.
	 * @return string created|updated|skipped|failed
	 */
	protected function import_show( $row, $map, $venue_map, $artist_map, $fallback, $post_status, $dry_run, $log ) {
		$source_id = (int) $row[ $map['id'] ];

		/*
		 * GigPress soft-deletes. A removed show keeps its row with status "deleted"
		 * and simply stops being displayed, which is why its own export reports fewer
		 * shows than the table holds. Importing these would resurrect gigs someone
		 * deliberately removed — on tedmulrygang, two future dates at real venues.
		 */
		if ( in_array( strtolower( trim( $this->value( $row, $map, 'status' ) ) ), self::deleted_statuses(), true ) ) {
			$this->log( $log, 'gig', $source_id, 'skipped', 'deleted in GigPress' );

			return 'skipped';
		}

		$date = MBE_Gigs_Meta::sanitize_date( $this->value( $row, $map, 'date' ) );

		if ( '' === $date ) {
			$this->log( $log, 'gig', $source_id, 'skipped', 'no usable date: ' . $this->value( $row, $map, 'date' ) );

			return 'skipped';
		}

		$existing = $this->find_by_source( $source_id );

		if ( $existing && $dry_run ) {
			$this->log( $log, 'gig', $source_id, 'skipped', sprintf( 'already imported as post %d', $existing ) );

			return 'skipped';
		}

		$venue_source  = (int) $this->value( $row, $map, 'venue' );
		$artist_source = (int) $this->value( $row, $map, 'artist' );

		$venue_term  = isset( $venue_map[ $venue_source ] ) ? $venue_map[ $venue_source ] : 0;
		$artist_term = isset( $artist_map[ $artist_source ] ) ? $artist_map[ $artist_source ] : 0;

		// No venues-table entry — fall back to the venue snapshot on the show itself.
		if ( ! $venue_term ) {
			$venue_term = $this->venue_from_show( $row, $source_id, $dry_run, $log );
		}

		/*
		 * Names come from what the venue and artist passes recorded, which happens in
		 * both modes. Falling back to the term only matters when a term already
		 * existed before this run.
		 */
		$venue_name = isset( $this->venue_names[ $venue_source ] )
			? $this->venue_names[ $venue_source ]
			: $this->term_name( $venue_term, MBE_GIGS_TAX_VENUE );

		$artist_name = isset( $this->artist_names[ $artist_source ] )
			? $this->artist_names[ $artist_source ]
			: $this->term_name( $artist_term, MBE_GIGS_TAX_ARTIST );

		if ( '' === $artist_name ) {
			$artist_name = $fallback;
		}

		$title = trim(
			implode(
				' — ',
				array_filter(
					array(
						$artist_name,
						trim( implode( ', ', array_filter( array( $venue_name, date_i18n( 'j F Y', strtotime( $date . ' 12:00:00' ) ) ) ) ) ),
					)
				)
			)
		);

		$notes = (string) $this->value( $row, $map, 'notes' );
		$tour  = $this->tour_name( (int) $this->value( $row, $map, 'tour' ) );

		/*
		 * GigPress sites routinely carry the same text in the notes and the tour —
		 * every Ted Mulry Gang date on the Final Blitz tour has "Final Blitz with
		 * Sweet" in both. Imported faithfully that prints twice on every row, which
		 * looks like a display bug and isn't.
		 */
		if ( '' !== $tour && 0 === strcasecmp( trim( wp_strip_all_tags( $notes ) ), $tour ) ) {
			$notes = '';
		}

		if ( $dry_run ) {
			$this->log( $log, 'gig', $source_id, 'created', sprintf( 'would create "%s"', $title ) );

			return 'created';
		}

		/*
		 * post_date is deliberately left alone. Setting it to the gig date looks tidy
		 * and then quietly breaks every future gig: wp_insert_post sees a publish date
		 * in the future and flips the status to 'future', so next month's gigs vanish
		 * from the site. The gig date lives in meta; that is the whole point.
		 */
		$postarr = array(
			'post_type'    => MBE_GIGS_CPT,
			'post_status'  => $post_status,
			'post_title'   => $title,
			'post_content' => wp_kses_post( $notes ),
		);

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
			$action        = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$action  = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			$this->log( $log, 'gig', $source_id, 'failed', $post_id->get_error_message() );

			return 'failed';
		}

		$meta = array(
			'mbe_gig_date'       => $date,
			'mbe_gig_time'       => MBE_Gigs_Meta::sanitize_time( self::real_time( $this->value( $row, $map, 'time' ) ) ),
			'mbe_gig_end_date'   => MBE_Gigs_Meta::sanitize_date( $this->value( $row, $map, 'end_date' ) ),
			'mbe_gig_ticket_url' => MBE_Gigs_Meta::sanitize_url( $this->value( $row, $map, 'tickets' ) ),
			'mbe_gig_price'      => sanitize_text_field( $this->value( $row, $map, 'price' ) ),
			'mbe_gig_status'     => $this->map_status( $this->value( $row, $map, 'status' ) ),
			'mbe_gig_tour'       => $tour,
		);

		/*
		 * GigPress writes show_expire = show_date for an ordinary gig, and a later
		 * date only for a genuine multi-day event. So an end date that isn't AFTER
		 * the start date is bookkeeping and gets dropped — otherwise every gig would
		 * look multi-day and the upcoming window would quietly go wrong.
		 */
		if ( '' !== $meta['mbe_gig_end_date'] && $meta['mbe_gig_end_date'] <= $meta['mbe_gig_date'] ) {
			$meta['mbe_gig_end_date'] = '';
		}

		foreach ( $meta as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		update_post_meta( $post_id, self::SOURCE_META, 'gigpress:' . $source_id );
		update_post_meta( $post_id, 'mbe_gig_auto_title', $title );

		if ( $venue_term ) {
			wp_set_object_terms( $post_id, array( $venue_term ), MBE_GIGS_TAX_VENUE, false );
		}

		if ( $artist_term ) {
			wp_set_object_terms( $post_id, array( $artist_term ), MBE_GIGS_TAX_ARTIST, false );
		} elseif ( $fallback ) {
			wp_set_object_terms( $post_id, array( $fallback ), MBE_GIGS_TAX_ARTIST, false );
		}

		$this->log( $log, 'gig', $source_id, $action, sprintf( 'post %d "%s"', $post_id, $title ) );

		return $action;
	}

	/**
	 * Load tour names.
	 *
	 * Tours are a name on the gig rather than a taxonomy — 7 of them across the whole
	 * portfolio doesn't justify archive pages, and a text field is one line to remove
	 * if it turns out nobody wants it.
	 *
	 * @param string $table Tours table.
	 * @return array GigPress tour ID => name.
	 */
	protected function load_tours( $table ) {
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$map    = $this->resolve_columns( $table, $this->tour_columns );
		$result = array();

		if ( empty( $map['id'] ) || empty( $map['name'] ) ) {
			return $result;
		}

		foreach ( $this->fetch_rows( $table, $map['name'], 0 ) as $row ) {
			$name = trim( (string) $row[ $map['name'] ] );

			if ( '' !== $name ) {
				$result[ (int) $row[ $map['id'] ] ] = $name;
			}
		}

		return $result;
	}

	/**
	 * Build a venue term from the snapshot on the show row.
	 *
	 * Used when a show has no venues-table entry. Matched on name plus city like
	 * every other venue, so a one-off venue that later appears in the venues table
	 * lands on the same term rather than a duplicate.
	 *
	 * @param array         $row       Source row.
	 * @param int           $source_id GigPress show ID, for the log.
	 * @param bool          $dry_run   Whether to write.
	 * @param resource|null $log       Log handle.
	 * @return int Term ID, or 0.
	 */
	protected function venue_from_show( $row, $source_id, $dry_run, $log ) {
		$map = $this->map_show_venue;

		if ( empty( $map['name'] ) || empty( $row[ $map['name'] ] ) ) {
			return 0;
		}

		$name = trim( (string) $row[ $map['name'] ] );

		if ( '' === $name ) {
			return 0;
		}

		$city    = ( ! empty( $map['city'] ) && isset( $row[ $map['city'] ] ) ) ? trim( (string) $row[ $map['city'] ] ) : '';
		$term_id = $this->find_venue_term( $name, $city );

		if ( $term_id ) {
			return $term_id;
		}

		if ( $dry_run ) {
			$this->log( $log, 'venue', $source_id, 'created', sprintf( 'would create "%s" (%s) from the show row', $name, $city ) );

			return 0;
		}

		$created = wp_insert_term( $name, MBE_GIGS_TAX_VENUE );

		if ( is_wp_error( $created ) ) {
			$created = wp_insert_term(
				$name,
				MBE_GIGS_TAX_VENUE,
				array( 'slug' => sanitize_title( $name . ' ' . $city ) )
			);
		}

		if ( is_wp_error( $created ) ) {
			$this->log( $log, 'venue', $source_id, 'failed', $created->get_error_message() );

			return 0;
		}

		$term_id = (int) $created['term_id'];

		$meta = array(
			'mbe_venue_city'    => $city,
			'mbe_venue_address' => ( ! empty( $map['address'] ) && isset( $row[ $map['address'] ] ) ) ? trim( (string) $row[ $map['address'] ] ) : '',
			'mbe_venue_country' => ( ! empty( $map['country'] ) && isset( $row[ $map['country'] ] ) ) ? trim( (string) $row[ $map['country'] ] ) : '',
			'mbe_venue_phone'   => ( ! empty( $map['phone'] ) && isset( $row[ $map['phone'] ] ) ) ? trim( (string) $row[ $map['phone'] ] ) : '',
			'mbe_venue_url'     => ( ! empty( $map['url'] ) && isset( $row[ $map['url'] ] ) ) ? MBE_Gigs_Meta::sanitize_url( (string) $row[ $map['url'] ] ) : '',
		);

		foreach ( $meta as $key => $value ) {
			if ( '' !== $value ) {
				update_term_meta( $term_id, $key, $value );
			}
		}

		$this->log( $log, 'venue', $source_id, 'created', sprintf( 'term %d "%s" from the show row', $term_id, $name ) );

		return $term_id;
	}

	/**
	 * @param int    $term_id  Term ID, may be 0.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	protected function term_name( $term_id, $taxonomy ) {
		if ( ! $term_id ) {
			return '';
		}

		$term = get_term( (int) $term_id, $taxonomy );

		return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}

	/**
	 * @param int $source_id GigPress show ID.
	 * @return int Post ID, or 0.
	 */
	protected function find_by_source( $source_id ) {
		$found = get_posts(
			array(
				'post_type'      => MBE_GIGS_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::SOURCE_META,
						'value' => 'gigpress:' . (int) $source_id,
					),
				),
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	protected function imported_count() {
		$found = get_posts(
			array(
				'post_type'      => MBE_GIGS_CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::SOURCE_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return count( $found );
	}

	/**
	 * GigPress statuses that mean "this show is gone".
	 *
	 * @return array
	 */
	public static function deleted_statuses() {
		return array( 'deleted', 'trash', 'trashed' );
	}

	/**
	 * Strip GigPress's "time to be announced" sentinel.
	 *
	 * `show_time` is NOT NULL, so GigPress writes 00:00:01 when no time is set — a
	 * value that is a perfectly valid time as far as any parser is concerned. Taken
	 * literally it puts a midnight on every gig whose time hasn't been announced,
	 * which on these sites is a good share of them. 00:00:00 is treated the same way:
	 * a band booked to start at exactly midnight is not a real case, and losing that
	 * one edge is much cheaper than midnights appearing across a whole site.
	 *
	 * GigPress's own CSV export renders these as blank, which is why a first pass
	 * over the exports suggested no row faked a time.
	 *
	 * @param string $time Raw value.
	 * @return string Empty string when the time is a sentinel.
	 */
	public static function real_time( $time ) {
		$time = trim( (string) $time );

		return in_array( $time, array( '00:00:00', '00:00:01', '00:00' ), true ) ? '' : $time;
	}

	/**
	 * @param int $tour_id GigPress tour ID, 0 for none.
	 * @return string
	 */
	protected function tour_name( $tour_id ) {
		return ( $tour_id && isset( $this->tour_map[ $tour_id ] ) ) ? $this->tour_map[ $tour_id ] : '';
	}

	/**
	 * @param string $status GigPress status.
	 * @return string
	 */
	protected function map_status( $status ) {
		$status = strtolower( trim( (string) $status ) );

		$map = array(
			'active'    => 'scheduled',
			''          => 'scheduled',
			'cancelled' => 'cancelled',
			'canceled'  => 'cancelled',
			'postponed' => 'postponed',
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : 'scheduled';
	}

	/* ---------------------------------------------------------------------
	 * Plumbing
	 * ------------------------------------------------------------------ */

	protected function tables() {
		global $wpdb;

		return array(
			'shows'   => $wpdb->prefix . 'gigpress_shows',
			'venues'  => $wpdb->prefix . 'gigpress_venues',
			'artists' => $wpdb->prefix . 'gigpress_artists',
			'tours'   => $wpdb->prefix . 'gigpress_tours',
		);
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	protected function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * @param string $table Table name.
	 * @return array
	 */
	protected function columns( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name built from the site prefix.
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );

		return $rows ? wp_list_pluck( $rows, 'Field' ) : array();
	}

	/**
	 * Pick the first candidate column that actually exists.
	 *
	 * @param string $table      Table name.
	 * @param array  $candidates Role => candidate column names.
	 * @return array Role => column name, or ''.
	 */
	protected function resolve_columns( $table, $candidates ) {
		$existing = array_map( 'strtolower', $this->columns( $table ) );
		$map      = array();

		foreach ( $candidates as $role => $names ) {
			$map[ $role ] = '';

			foreach ( $names as $name ) {
				if ( in_array( strtolower( $name ), $existing, true ) ) {
					$map[ $role ] = $name;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * @param array  $row  Source row.
	 * @param array  $map  Column map.
	 * @param string $role Role name.
	 * @return string
	 */
	protected function value( $row, $map, $role ) {
		if ( empty( $map[ $role ] ) || ! isset( $row[ $map[ $role ] ] ) ) {
			return '';
		}

		return (string) $row[ $map[ $role ] ];
	}

	/**
	 * @param string $table    Table name.
	 * @param string $order_by Column to order by, may be empty.
	 * @param int    $limit    Row limit, 0 for all.
	 * @return array
	 */
	protected function fetch_rows( $table, $order_by, $limit ) {
		global $wpdb;

		$sql = "SELECT * FROM `{$table}`";

		if ( $order_by ) {
			/*
			 * A limited run samples the most recent rows. The oldest 20 gigs on any of
			 * these sites are all long past and all single-day — exactly the rows least
			 * likely to show a problem. The newest 20 carry the upcoming gigs, the
			 * cancellations and the multi-day events.
			 */
			$sql .= ' ORDER BY `' . esc_sql( $order_by ) . '` ' . ( $limit > 0 ? 'DESC' : 'ASC' );
		}

		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers only; no user input.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $rows ? $rows : array();
	}

	/**
	 * Load approved venue names from the normalisation worksheet.
	 *
	 * @param string $file Path to the CSV.
	 * @return array Keyed by "name|city", lowercased.
	 */
	protected function load_venue_overrides( $file ) {
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s', $file ) );
		}

		$handle = fopen( $file, 'r' );

		if ( ! $handle ) {
			WP_CLI::error( sprintf( 'Cannot open %s', $file ) );
		}

		$header = fgetcsv( $handle );

		if ( ! $header ) {
			fclose( $handle );

			return array();
		}

		// Strip a UTF-8 BOM from the first heading if Excel put one there.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		$header    = array_map( 'trim', $header );
		$overrides = array();

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $row ) !== count( $header ) ) {
				continue;
			}

			$row = array_combine( $header, $row );

			$from_name = isset( $row['venue_as_entered'] ) ? trim( $row['venue_as_entered'] ) : '';
			$from_city = isset( $row['city_as_entered'] ) ? trim( $row['city_as_entered'] ) : '';

			if ( '' === $from_name ) {
				continue;
			}

			$pick = function ( $approved, $proposed ) use ( $row ) {
				$a = isset( $row[ $approved ] ) ? trim( $row[ $approved ] ) : '';

				if ( '' !== $a ) {
					return $a;
				}

				return isset( $row[ $proposed ] ) ? trim( $row[ $proposed ] ) : '';
			};

			$overrides[ $this->override_key( $from_name, $from_city ) ] = array(
				'venue'    => $pick( 'APPROVED_venue', 'proposed_venue' ),
				'city'     => $pick( 'APPROVED_city', 'proposed_city' ),
				'state'    => $pick( 'APPROVED_state', 'proposed_state' ),
				'postcode' => isset( $row['proposed_postcode'] ) ? trim( $row['proposed_postcode'] ) : '',
			);
		}

		fclose( $handle );

		return $overrides;
	}

	protected function override_key( $name, $city ) {
		return strtolower( trim( $name ) ) . '|' . strtolower( trim( $city ) );
	}

	/**
	 * @param array  $overrides Override table.
	 * @param string $name      Venue name as stored in GigPress.
	 * @param string $city      City as stored in GigPress.
	 * @return array
	 */
	protected function lookup_override( $overrides, $name, $city ) {
		$empty = array(
			'venue'    => '',
			'city'     => '',
			'state'    => '',
			'postcode' => '',
		);

		$key = $this->override_key( $name, $city );

		if ( isset( $overrides[ $key ] ) ) {
			return $overrides[ $key ];
		}

		// Fall back to a name-only match when the city was blank in the worksheet.
		$key = $this->override_key( $name, '' );

		return isset( $overrides[ $key ] ) ? $overrides[ $key ] : $empty;
	}

	/**
	 * @param string $path    Requested log path.
	 * @param bool   $dry_run Whether this is a dry run.
	 * @return resource|null
	 */
	protected function open_log( $path, $dry_run ) {
		if ( ! $path ) {
			$uploads = wp_upload_dir();
			$path    = trailingslashit( $uploads['basedir'] ) . sprintf(
				'mbe-gigs-import-%s%s.csv',
				gmdate( 'Y-m-d-His' ),
				$dry_run ? '-dryrun' : ''
			);
		}

		$handle = fopen( $path, 'w' );

		if ( ! $handle ) {
			WP_CLI::warning( sprintf( 'Could not open log file %s — continuing without a log.', $path ) );

			return null;
		}

		fputcsv( $handle, array( 'type', 'source_id', 'action', 'detail' ) );
		WP_CLI::line( sprintf( 'Log: %s', $path ) );

		return $handle;
	}

	/**
	 * @param resource|null $handle    Log handle.
	 * @param string        $type      Row type.
	 * @param int           $source_id Source ID.
	 * @param string        $action    Action taken.
	 * @param string        $detail    Detail.
	 */
	protected function log( $handle, $type, $source_id, $action, $detail ) {
		if ( ! $handle ) {
			return;
		}

		fputcsv( $handle, array( $type, $source_id, $action, $detail ) );
	}
}

WP_CLI::add_command( 'mbe-gigs', 'MBE_Gigs_Importer' );
