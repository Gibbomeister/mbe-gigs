# Testing MBE Gigs

Work through this once, on a throwaway Local site, before the plugin goes near a client
site. Parts 1–3 take under an hour. Part 4 needs a real staging site.

Tick as you go. If something fails, the last section says what it probably means.

---

## Setup

- [ ] New site in Local by Flywheel. **Match the PHP version to GridPane** — a version
      mismatch here means you test one thing and deploy to another.
- [ ] Install and activate MBE Gigs (upload the zip from the release, or symlink the
      repo into `wp-content/plugins/` so edits are live).
- [ ] **Settings → Permalinks → Save.** The post type registers its rewrite rules on
      activation, but saving permalinks is the reliable way to be sure.
- [ ] Confirm **Gigs** appears in the admin menu with Venues and Artists beneath it.

Local's site shell has WP-CLI. Everything below marked `wp` runs there.

---

## Part 1 — Mechanics, on an empty site

No GigPress data needed. This is the half worth iterating on, because nothing here
matters if it breaks.

### Entering a gig

- [ ] **Gigs → Add Gig.** The screen is the classic editor — *not* the block editor. If
      you see the block editor, the `use_block_editor_for_post_type` filter isn't firing.
- [ ] **There is no WordPress title field.** The screen opens with the "Gig details" box
      at the top, then a labelled **Description** heading above the editor.
- [ ] Set a date, pick **+ Add a new venue**, type a venue name, pick **+ Add a new
      artist**, type an artist name. Publish.
- [ ] After saving, **Listed as:** at the foot of the gig details box reads
      **Artist — Venue, 12 October 2026**, and that's the title in the Gigs list.
- [ ] Re-open it and change the date. The title follows the new date.
- [ ] Type something into **Custom title** and update. That wording wins everywhere.
- [ ] Clear the Custom title and update. The gig goes back to the generated title —
      clearing the field should hand it back, not leave the old custom wording stuck.

### The fields

- [ ] Add a second gig with **no time**. Nothing displays a stray midnight anywhere —
      not the list column, not the front end. Empty means unknown, not `00:00`.
- [ ] Add a gig with a **ticket link** typed as `www.venue.com.au`, with no scheme. It
      saves as `https://www.venue.com.au`.
- [ ] Add a gig with a nonsense date like `2026-02-31` via the REST API or by editing
      the field. It stores empty rather than a plausible wrong date.
- [ ] Set one gig to **Cancelled**. The list shows a red status pill.
- [ ] Add a **multi-day** gig — date today, end date three days out.

### The venue and artist terms

- [ ] **Gigs → Venues.** Your venue is there once. Add address, city, state.
- [ ] The Venues list has a **City** column showing what you entered.
- [ ] Add a second gig and pick the same venue from the dropdown. The option reads
      **Venue name — City**, which is how you tell two RSLs apart.
- [ ] Confirm the venue dropdown is a **select**, not a free-text tag box. Free text is
      what the taxonomy exists to prevent.

### The list screen

- [ ] The list has columns: Gig date, Gig, Venue, Artist, Status. No publish-date column.
- [ ] Default order is by **gig date**, not publish date. Enter a gig for 2028 last and
      confirm it sorts to where the date says, not where you typed it.
- [ ] Click the **Gig date** column header. Order reverses.
- [ ] The **Upcoming** filter shows only today onwards. A past gig disappears.
- [ ] The multi-day gig that spans today shows under **Upcoming**, not Past. This is the
      end-date logic — a festival running right now is current.
- [ ] The **Past** filter shows the opposite set, most recent first.
- [ ] Save a gig with no date at all (clear the field). It vanishes from the normal list
      and appears under **Needs a date**.

### The front end

- [ ] Visit `/gigs/`. You get your theme's default archive template — a plain list of
      titles, no formatting. **This is correct.** The plugin ships no templates; Themer
      does display. The auto-titles make even the plain list readable.
- [ ] Only upcoming gigs appear, soonest first.
- [ ] Visit a venue archive, e.g. `/venue/kingsgrove-rsl/`. It lists that venue's gigs.

### REST

- [ ] `wp eval 'print_r( get_post_meta( <id> ) );'` or hit
      `/wp-json/wp/v2/mbe_gig/<id>` and confirm `mbe_gig_date`, `mbe_gig_time` and the
      rest appear in the `meta` object. If they don't, the block editor and any future
      front end can't see them.

---

## Part 2 — The importer, against real tables

This is the part a blank site can't fake. You need actual GigPress tables.

### Get the tables in

- [ ] From one client site's phpMyAdmin, export `wp_gigpress_shows`, `wp_gigpress_venues`
      and `wp_gigpress_artists` as **SQL**, structure and data. **tedmulrygang** is a good
      first subject: 45 rows, but it covers tours, ticket URLs, interstate venues and one
      multi-day event.
- [ ] Import that SQL into the Local site's database. Match the table prefix, or note
      what it is.

### Inspect before importing

- [ ] `wp mbe-gigs inspect`

      This writes nothing. Read all of it.

- [ ] Every expected table is present with a sensible row count.
- [ ] **The column mapping.** Any role showing `(none)` will not import — decide now
      whether that matters.
- [ ] **The end-date line.** `inspect` reports how many shows end after they start.
      GigPress writes `show_expire = show_date` for an ordinary gig and a later date
      only for a genuine multi-day event, so that count should match the number of
      festivals you'd expect — a handful, not the whole table. Verified on
      tedmulrygang: 46 of 47 equal, 1 later, and that one row is the only one with
      `show_multi` set. If a site ever reports nearly every show ending after it
      starts, stop: that install is using `show_expire` as a drop-off date and the
      mapping needs removing for that site.

### Dry run

- [ ] `wp mbe-gigs import --dry-run --limit=20`
- [ ] Open the log CSV it names. Check venue names, that dates parsed, and that nothing
      says `failed`.
- [ ] `wp mbe-gigs import --dry-run` — the full set. Same check, bigger sample.

### Real import

- [ ] `wp mbe-gigs import --venues=/path/to/venues-worksheet.csv`
- [ ] Counts at the end match the row count from `inspect`, minus the shows it
      reported as deleted in GigPress. Those are soft-deleted rows — GigPress keeps
      them and hides them, so the table holds more shows than the site displays, and
      importing them would resurrect gigs someone removed on purpose.

### Verify the result

- [ ] **A future gig is `publish`, not `future`.** Check the list screen — a gig in
      Scheduled status is invisible on the front end, and five of your nine sites have so
      few upcoming gigs you might not notice. This is the single most important check
      here.
- [ ] A **cancelled** gig imported as cancelled and still displays.
- [ ] A gig with **no time** shows a date and nothing else.
- [ ] Venue terms have **city and state** in their term meta.
- [ ] Venue count is roughly what the audit predicted. Wildly more means the name+city
      matching isn't working.
- [ ] Notes came through as **post content**, with line breaks intact.

### Idempotency and rollback — do not skip these

- [ ] Run the **same import again**. The result should be all updated/skipped and
      **zero created**. If the gig count doubles, stop: the source-ID lookup isn't
      matching, and that bug on a live site means cleaning up by hand.
- [ ] `wp mbe-gigs rollback --yes`, then confirm the gigs and imported venues are gone
      and the GigPress tables are **untouched**.
- [ ] Import once more to leave the site populated.

---

## Part 3 — The update channel

Needs the repo pushed and `v1.0.0` released.

- [ ] Edit the installed copy's header and `MBE_GIGS_VERSION` down to `0.9.0` to fake an
      older install.
- [ ] **Plugins → Check for updates** (the link in the plugin's row).
- [ ] An update notice appears offering 1.0.0. **View details** shows the release notes.
- [ ] Run the update. Afterwards there is **one** plugin called MBE Gigs, not two, and
      the folder is still `mbe-gigs`. Two plugins means the release shipped without a
      built zip asset and WordPress installed the source zipball.
- [ ] Gigs, venues and settings all survived the update.

---

## Part 4 — Themer, on a client staging site

Local can't do this properly without your licences. Do it on staging, following the
usual procedure — pull live to staging first.

- [ ] Themer archive layout for the gig archive. Field connections appear under **Post**
      as "Gig: date", "Gig: venue and location", "Gig: artist".
- [ ] Venue city and state render through the **post** properties
      (`Gig: venue city`), not via term-meta connections.
- [ ] The archive is ordered soonest-first and shows only upcoming gigs — that's the
      `pre_get_posts` filter, not anything you set in Themer's query UI.
- [ ] A **Posts module** set to query gigs gets the same window.
- [ ] `Gig: status` renders nothing on a normal gig and "Cancelled" on a cancelled one.
- [ ] Compare against the old GigPress output. Anything missing is a layout decision,
      not missing data — check the gig in admin before assuming the import dropped it.

---

## If something fails

| Symptom | Likely cause |
|---|---|
| Gig archive 404s | Permalinks not flushed. Settings → Permalinks → Save. |
| Future gigs invisible on the front end | Post status is `future`, not `publish`. Something set `post_date` to the gig date. |
| Gigs sorted apparently at random | Ordering fell back to publish date — the meta clause isn't being applied. |
| A gig is missing from every list | No `mbe_gig_date` meta row. Find it under **Needs a date**. |
| Same venue appears twice | Two terms with the same name and different city meta. Check the venue list and merge. |
| Second import doubled everything | `mbe_gigs_source_id` lookup failed. Roll back before investigating. |
| Two copies of the plugin after updating | Release had no built zip asset. |
| Themer shows no gig fields | Themer loaded before the post type. Check the plugin is active and `init` priority is 5. |

Nothing in Parts 1–3 touches a client site. Part 4 touches staging only. The GigPress
tables are never modified by any of it — deactivating GigPress and leaving its tables in
place is the real rollback for any site.
