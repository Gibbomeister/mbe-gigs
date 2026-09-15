# MBE Gigs

Gig listings as a proper custom post type. Replaces GigPress.

Venues and artists are taxonomies, so they deduplicate at the database level and get
autocomplete and archive pages for free. Gig detail is post meta, registered through
`register_post_meta` so it reaches REST and any future front end. Display is Beaver
Themer's job — this plugin ships no templates and no shortcode.

**The one rule:** the post type, the taxonomies and the meta are registered here and
only here. Never in a child theme, never in Themer. If a site leaves Beaver Builder,
you rebuild the display and the content is untouched.

---

## What's in it

| File | Does |
|---|---|
| `mbe-gigs.php` | Constants, bootstrap, activation, `mbe_gigs_today()` |
| `includes/class-post-type.php` | CPT + venue/artist taxonomies, automatic post titles |
| `includes/class-meta.php` | Field definitions, sanitisation, REST, term meta screens |
| `includes/class-admin.php` | The edit screen, list columns, sorting, filters |
| `includes/class-query.php` | Upcoming/past queries, archive filtering, display helpers |
| `includes/class-themer.php` | Themer field connections and loop query args |
| `includes/class-updater.php` | Update checks against GitHub releases |
| `includes/class-importer.php` | WP-CLI GigPress import |

## The data

**Post type:** `mbe_gig` — `show_in_rest`, archive at `/gigs/`.

**Taxonomies:** `mbe_venue`, `mbe_artist` — non-hierarchical, `show_in_rest`.

**Post meta**

| Key | Format | Notes |
|---|---|---|
| `mbe_gig_date` | `YYYY-MM-DD` | Required. A gig without one appears in no list. |
| `mbe_gig_time` | `HH:MM` | Optional. Empty means unknown, and that is never `00:00`. |
| `mbe_gig_end_date` | `YYYY-MM-DD` | Festivals and multi-day runs only. |
| `mbe_gig_ticket_url` | URL | |
| `mbe_gig_price` | text | Free text — "$25", "Free entry". |
| `mbe_gig_status` | `scheduled` / `cancelled` / `postponed` | |
| `mbe_gig_tour` | text | The tour or billing, e.g. "Final Blitz with Sweet". A name, not a taxonomy. |
| `mbe_gig_title` | text | Optional override. Blank means the title is generated. |

The post type does **not** support `title`. A gig is titled *Artist — Venue, 12 October
2026*, built on save from the fields, because a title field someone has to be told to
leave blank is a field in the wrong place. `post_title` is still set normally, so
search, admin columns and permalinks behave as they would anywhere else. The optional
`mbe_gig_title` field overrides it; clearing that field hands the gig back to the
generator.

**Venue term meta:** `mbe_venue_address`, `_city`, `_state`, `_postcode`, `_country`,
`_phone`, `_url`, `_capacity`.

**Artist term meta:** `mbe_artist_url`.

Empty values are deleted rather than stored as empty strings. The upcoming query asks
whether an end date *exists*; a row holding `''` would answer yes and then fail every
date comparison after it.

## Dates and timezones

Dates and times are stored as local wall-clock values with no conversion. A gig at
8pm in Perth is 8pm in Perth; round-tripping through UTC is how a gig ends up
displayed on the wrong day.

The only thing that respects the site's timezone is the cutoff between upcoming and
past — `mbe_gigs_today()`, which wraps `current_time( 'Y-m-d' )`. Never `date()`,
which reads the server.

Every meta comparison on a date sets `'type' => 'DATE'`. Without it MySQL compares the
values as strings, which happens to work for `YYYY-MM-DD` right up until it doesn't.

The upcoming/past comparison uses **dates only**, never a concatenated date and time.
A large share of gigs have no time, and comparing `"2026-09-14 "` against `"2026-09-14 20:00"`
sorts them unpredictably within the day.

## Using it in code

```php
$gigs = MBE_Gigs_Query::upcoming( array( 'limit' => 5 ) );

while ( $gigs->have_posts() ) {
    $gigs->the_post();
    echo esc_html( MBE_Gigs_Query::get_date() );       // 12 October 2026
    echo esc_html( MBE_Gigs_Query::get_time() );       // 8:00 pm, or '' if unknown
    echo esc_html( MBE_Gigs_Query::get_venue_line() ); // Kingsgrove RSL, Kingsgrove NSW
}

wp_reset_postdata();

MBE_Gigs_Query::past( array( 'limit' => -1 ) );
MBE_Gigs_Query::get( array( 'direction' => 'past', 'artist' => 'the-defenders' ) );
```

## Per-site filters

Drop these in a one-line mu-plugin, not in a child theme.

```php
// This site's gig archive lists past gigs instead of upcoming.
add_filter( 'mbe_gigs_archive_direction', function () { return 'past'; } );

// Beaver Builder Posts modules querying gigs show past gigs.
add_filter( 'mbe_gigs_loop_direction', function () { return 'past'; }, 10, 2 );

// No public venue/artist archive URLs — for a site that already publishes its own
// venue or artist pages and doesn't want a second URL competing with them.
add_filter( 'mbe_gigs_archives_enabled', '__return_false' );

// URL slugs.
add_filter( 'mbe_gigs_rewrite_slug', function () { return 'shows'; } );
add_filter( 'mbe_gigs_venue_slug',   function () { return 'venues'; } );

// Put gigs back in the block editor.
add_filter( 'mbe_gigs_use_block_editor', '__return_true' );

// Show the slug field on the venue and artist screens, for curating URLs by hand.
add_filter( 'mbe_gigs_hide_term_slug', '__return_false' );
```

Flush permalinks after changing any slug or archive filter: **Settings → Permalinks → Save**.

## Displaying gigs

```
[mbe_gigs]
[mbe_gigs direction="past" limit="20"]
[mbe_gigs venue="the-bridge-hotel"]
[mbe_gigs show_artist="no" empty="Nothing booked just now — check back soon."]
```

| Attribute | Default | |
|---|---|---|
| `direction` | `upcoming` | `upcoming`, `past` or `all` |
| `limit` | `-1` | Number of gigs, `-1` for all |
| `order` | auto | `asc` or `desc`; defaults to soonest-first for upcoming, most-recent-first for past |
| `venue` | — | Venue slug or term ID |
| `artist` | — | Artist slug or term ID |
| `show_artist` | `auto` | `auto` prints the artist only on sites with more than one |
| `date_format` | site setting | Any PHP date format |
| `empty` | — | Message when there are no gigs |
| `class` | — | Extra class on the wrapper |

Drop it into a Themer archive layout in an HTML or Text module. Beaver Builder's Posts
module renders each item with its own markup and can't lay a gig out from its fields,
so a ticket button, a start time and a cancelled badge can't be separate elements
there. This can.

**It ships no CSS.** What comes out is semantic markup with predictable classes; how a
site looks is per-site work, which is the part that should differ between a country act
and a pub rock band. Empty fields are omitted rather than rendered blank, so no
stylesheet has to hide an empty element.

```
div.mbe-gigs.mbe-gigs--upcoming
  ul.mbe-gigs__list
    li.mbe-gig.mbe-gig--scheduled            (or --cancelled, --postponed, --multi-day)
      div.mbe-gig__dates
        time.mbe-gig__date
          span.mbe-gig__weekday  Thu
          span.mbe-gig__day      19
          span.mbe-gig__month    Nov
          span.mbe-gig__year     2026
          span.mbe-gig__date-full
        time.mbe-gig__end-date               (multi-day only, same inner spans)
      div.mbe-gig__details
        p.mbe-gig__artist                    (multi-artist sites only)
        p.mbe-gig__venue                     (linked when archives are on)
        p.mbe-gig__location
        p.mbe-gig__time
        p.mbe-gig__tour
        p.mbe-gig__price
        p.mbe-gig__status                    (only when not scheduled)
        div.mbe-gig__description
      p.mbe-gig__actions
        a.mbe-gig__tickets
```

The date parts exist so a calendar-tile date block is pure CSS — stack the weekday, day
and month, hide `__date-full`; or show `__date-full` and hide the parts. No PHP either way.

Starter CSS to paste into the Themer layout's CSS panel and then make your own:

```css
.mbe-gigs__list { list-style: none; margin: 0; padding: 0; }
.mbe-gig { display: flex; gap: 1.25rem; align-items: flex-start;
           padding: 1rem 0; border-bottom: 1px solid rgba(0,0,0,.1); }
.mbe-gig__dates { flex: 0 0 4.5rem; text-align: center; line-height: 1.1; }
.mbe-gig__weekday, .mbe-gig__month { display: block; font-size: .75rem;
           text-transform: uppercase; letter-spacing: .06em; opacity: .7; }
.mbe-gig__day { display: block; font-size: 1.9rem; font-weight: 700; }
.mbe-gig__year, .mbe-gig__date-full { display: none; }

/* Multi-day: a second tile under the first, with a dash between. */
.mbe-gig__end-date { display: block; }
.mbe-gig__end-date::before { content: "–"; display: block; opacity: .5; }
.mbe-gig__end-date .mbe-gig__weekday { display: none; }

/* Past gigs span years, so the year earns its place there. */
.mbe-gigs--past .mbe-gig__year { display: block; font-size: .75rem; opacity: .7; }
.mbe-gig__details { flex: 1 1 auto; }
.mbe-gig__venue { font-size: 1.1rem; font-weight: 600; margin: 0; }
.mbe-gig__location, .mbe-gig__time, .mbe-gig__tour, .mbe-gig__price { margin: .15rem 0 0; opacity: .8; }
.mbe-gig__status { display: inline-block; margin: .4rem 0 0; padding: .15rem .5rem;
           border-radius: 2px; font-size: .8rem; text-transform: uppercase;
           background: #f7dcdc; color: #8a1f1f; }
.mbe-gig--cancelled .mbe-gig__venue { text-decoration: line-through; }
.mbe-gig__actions { flex: 0 0 auto; margin: 0; }
```

## Beaver Themer

Field connections appear under **Post** as "Gig: date", "Gig: venue and location",
"Gig: artist" and so on.

Venue detail is exposed as **post** properties rather than term properties —
`mbe_gig_venue_city`, `mbe_gig_venue_state`, `mbe_gig_venue_address`,
`mbe_gig_venue_url`. Themer's post meta handling is solid; its term meta support
varies by version, and on a gig the venue is one hop away anyway. Reading it through
the post sidesteps the question entirely.

Ordering by a date meta value *compared against today* is beyond Themer's query UI, so
the archive query is filtered in `pre_get_posts` and Posts modules in
`fl_builder_loop_query_args`. Build the layout in Themer; leave the ordering alone.

`Gig: status` returns an empty string for a scheduled gig, so a status badge connected
to it simply doesn't render on normal gigs.

## Importing from GigPress

```bash
wp mbe-gigs inspect                              # tables, columns, row counts — writes nothing
wp mbe-gigs import --dry-run                     # full run, nothing written, log produced
wp mbe-gigs import --venues=venues-worksheet.csv # the real thing
wp mbe-gigs rollback --yes                       # remove exactly what the importer made
```

**Always `inspect` first.** Column names are discovered rather than assumed, because
GigPress 2.x shifted its schema over the years and these installs were not all set up
in the same year. `inspect` prints the mapping it will use; if a role maps to
`(none)`, that field won't import and you should know before, not after.

Options:

- `--venues=<file>` — the normalisation worksheet. Columns `venue_as_entered` and
  `city_as_entered` match against GigPress; `APPROVED_venue` / `APPROVED_city` /
  `APPROVED_state` override `proposed_*` when filled.
- `--artist=<name>` — the artist name to use when a site has no artists table.
- `--limit=<n>` — stop after n shows. Worth doing on a big site before committing.
- `--status=<status>` — post status for created gigs. Default `publish`.
- `--log=<file>` — defaults to `mbe-gigs-import-<timestamp>.csv` in uploads.

**Idempotent.** Every imported gig stores `mbe_gigs_source_id` = `gigpress:<show_id>`.
A second run updates rather than duplicates, so an interrupted import is resumed by
running it again.

**Venue identity is name + city**, never name alone — the Pier Hotel in Botany and the
Pier Hotel in Frankston are two different pubs. Venue term meta that already has a
value is never overwritten, so hand corrections survive re-runs.

The GigPress tables are never read-modified or dropped. The real rollback for a site is
deactivating GigPress and leaving its tables in place.

### Verify on the first site before trusting it anywhere

1. `wp mbe-gigs inspect` — read the column mapping and the end-date line. GigPress
   writes `show_expire = show_date` for an ordinary gig and a later date only for a
   genuine multi-day event, so the reported count of shows ending after they start
   should be a handful. If it's most of the table, that install treats `show_expire` as
   a drop-off date and the mapping needs removing for that site.
2. `wp mbe-gigs import --dry-run --limit=20`, read the log.
3. Import for real, then check: a gig next month is visible on the front end and is
   *not* in "Scheduled" post status; a cancelled gig still displays; a gig with no time
   shows a date and no stray midnight.
4. Only then deactivate GigPress. Don't delete its tables.

## Per-site rollout

1. Back up. Work on staging.
2. Install and activate MBE Gigs.
3. `wp mbe-gigs inspect`, then `--dry-run`, then import.
4. Spot-check the gig list in admin, sorted by gig date.
5. Build the Themer layout for the gig archive, and a singular layout if the site needs one.
6. Replace the old GigPress shortcode or widget on the gigs page.
7. Deactivate GigPress. Leave the tables.
8. Check permalinks, then push staging to live following the usual procedure.

## Releasing

Sites check `MBE_GIGS_REPO` for a newer release twice a day and then show the ordinary
WordPress update notice. That only works if a release carries a **built zip asset**
whose top-level folder is `mbe-gigs` — GitHub's own source zipball unpacks to
`owner-repo-<sha>/`, which WordPress installs as a *second* plugin rather than an
update. The release workflow builds the correct zip; don't publish a release by hand
without one.

To cut a release:

1. Bump `Version:` in the plugin header **and** `MBE_GIGS_VERSION`.
2. Commit.
3. `git tag v1.0.1 && git push origin v1.0.1`

The workflow checks the tag against the header (a mismatch fails the build rather than
shipping a version that lies about itself), lints every PHP file, builds the zip and
publishes the release with generated notes.

Sites cache the lookup for 12 hours. "Check for updates" on the plugins screen clears
it when you don't want to wait.

The repository is public, so no site needs credentials. If it ever goes private, every
install needs a GitHub token in `wp-config.php` — a credential on eight client sites,
and a rotation problem. Worth deciding deliberately rather than by drifting into it.

## Installing on a site

First install is manual — upload the zip from the latest release, or `wp plugin install
<url-of-the-zip> --activate`. Every version after that arrives as a normal update
notice.

## Requirements

WordPress 6.4+, PHP 7.4+. Beaver Themer optional — nothing here depends on it.
