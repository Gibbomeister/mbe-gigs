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

- [ ] On the gig screen, **+ Add a new venue** reveals name, city and state together —
      and only when that option is chosen.
- [ ] Type the state as **Victoria**. It stores as **VIC**. Normalising on entry is what
      stops the five spellings the audit found across the portfolio.
- [ ] **Gigs → Venues.** Your venue is there once, with the city and state you typed.
      No **slug** field on the add or edit screen, and the notes field sits last.
- [ ] The Venues list has a **City** column.
- [ ] Add a second gig and pick that venue from the dropdown. The option reads
      **Venue name — City State**, which is how you tell two Pier Hotels apart.
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

- [ ] Visit `/gigs/`. Without a Themer layout you get the theme's default archive — a
      plain list of titles. **That's correct.** The plugin ships no templates; the list
      comes from the `[mbe_gigs]` shortcode placed in a Themer layout (Part 4). The
      auto-titles make even the bare list readable.
- [ ] Only upcoming gigs appear, soonest first.
- [ ] Visit a venue archive, e.g. `/venue/kingsgrove-rsl/`. It lists **all** that venue's
      gigs, next booking first — not upcoming only.
- [ ] Put `[mbe_gigs]` in an ordinary page to see the list early, before any layout work.

### REST

- [ ] `wp eval 'print_r( get_post_meta( <id> ) );'` or hit
      `/wp-json/wp/v2/mbe_gig/<id>` and confirm `mbe_gig_date`, `mbe_gig_time` and the
      rest appear in the `meta` object. If they don't, the block editor and any future
      front end can't see them.

---

## Part 2 — The importer, against real tables

This is the part a blank site can't fake. You need actual GigPress tables.

### Get the tables in

- [ ] From the site's phpMyAdmin, export **all four** tables — `wp_gigpress_shows`,
      `_venues`, `_artists` and `_tours` — as **SQL**, structure and data. Tours is the
      one easily forgotten, and without it every gig loses its tour grouping.
- [ ] Export from **live**, not staging. Staging databases can be months stale.
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

- [ ] `wp mbe-gigs import --venues=/path/to/venues-<site>.csv`
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

## Part 4 — The front end, on a client staging site

Local can do most of this. The parts that need your licences and the real theme are
best done on staging, following the usual procedure — pull live to staging first.

The gig list comes from the `[mbe_gigs]` shortcode, not from Beaver Builder's Posts
module. The Posts module offers List, Gallery, Masonry and Columns and renders each item
with its own markup, with no way to lay a gig out from its fields — so a ticket link, a
start time and a cancelled badge can't be separate elements there at all.

### The archive layout

- [ ] **Beaver Builder → Themer Layouts → Add New**. Type **Archive**, location
      **Post Type Archive → Gigs**.
- [ ] An **HTML module** containing `[mbe_gigs]`. View `/gigs/`.
- [ ] Only upcoming gigs, soonest first. That's the `pre_get_posts` filter, not
      anything set in Themer's query UI.
- [ ] Paste the starter CSS from the readme into **Tools → Layout CSS & JavaScript**.
- [ ] Add a second row with a heading and `[mbe_gigs direction="past" limit="10"]`.

### What to look at

- [ ] Date, city and venue line up in columns down the page.
- [ ] Time, admission, address, notes and the ticket link share **one** line under each
      gig — not stacked, and the ticket link next to the gig it belongs to.
- [ ] A tour heading sits above the dates it covers, tinted, and those gigs don't repeat
      the tour name on their own rows.
- [ ] The address links to a map that lands on the right venue.
- [ ] A multi-day gig shows a date range, not two columns of layout.
- [ ] A cancelled gig still displays, marked.
- [ ] A gig with no time shows no stray midnight.
- [ ] **At phone width** the grid collapses to one column. A three-column grid at 380px
      is unreadable, and half your visitors are on a phone looking for tonight.

### Venue and artist archives

- [ ] Click a venue. You get every gig at that venue, next booking first, then back
      through the history — not an empty page.
- [ ] Click it from a **past** gig too. That's the case that was broken.

### The single gig page — decide before a client sees one

`/gigs/<some-gig>/` currently renders through the theme's default single template,
which will not look considered.

- [ ] Either build a Themer **Singular** layout for Gigs, using the field connections
      (they appear under **Post** as "Gig: date", "Gig: venue and location" and so on —
      venue detail comes through as *post* properties, so Themer's patchy term meta
      support never comes into it);
- [ ] or turn single gig pages off and let the archive be the only gig URL.

### Compare against GigPress

- [ ] Put the old page and the new one side by side. Anything missing is a layout
      decision, not missing data — check the gig in admin before assuming the import
      dropped it.

---

## Before the first client site

Loose ends, roughly half a day:

- [ ] Push commits and tags to GitHub.
- [ ] Settle the single gig page (above).
- [ ] **Export the Themer layout and import it onto a second site.** The whole rollout
      estimate rests on building this once and adjusting it seven times. Untested so far.
- [ ] Check the grid at phone width.
- [ ] Decide on RSS and iCal feeds. GigPress published both; we have neither. Worth
      checking whether anyone used the old subscribe links before building them.

---

## Per-site rollout

1. Back up. Pull live to staging. Work on staging.
2. Install and activate MBE Gigs. Settings → Permalinks → Save.
3. `wp mbe-gigs inspect` — read the column mapping, the deleted count and the end-date
   line before anything else.
4. `wp mbe-gigs import --dry-run`, read the log, then import with that site's
   `--venues=` worksheet.
5. Spot-check in admin: gig count matches inspect minus deleted, nothing in `future`
   status, venues carry city and state.
6. Import the Themer layout from the first site. Restyle to suit.
7. Replace the old GigPress shortcode or widget on the gigs page.
8. Deactivate GigPress. **Leave its tables** — that's the rollback.
9. Post-push checks: permalinks, and Settings → Reading → Discourage search engines.
10. Push staging to live following the usual procedure. Log the change in the site brief.

---

## If something fails

| Symptom | Likely cause |
|---|---|
| Gig archive 404s | Permalinks not flushed. Settings → Permalinks → Save. |
| Venue archive says "Nothing found" | Pre-1.3.2 behaviour — term archives were filtered to upcoming gigs. |
| Future gigs invisible on the front end | Post status is `future`, not `publish`. Something set `post_date` to the gig date. |
| Gigs sorted apparently at random | Ordering fell back to publish date — the meta clause isn't being applied. |
| A gig is missing from every list | No `mbe_gig_date` meta row. Find it under **Needs a date**. |
| Same venue appears twice | Two terms with the same name and different city meta. Check the venue list and merge. |
| Second import doubled everything | `mbe_gigs_source_id` lookup failed. Roll back before investigating. |
| Two copies of the plugin after updating | Release had no built zip asset. |
| Themer shows no gig fields | Themer loaded before the post type. Check the plugin is active and `init` priority is 5. |
| Columns don't line up | Something is wrapping the cells. The row must stay flat with `display: contents`. |
| Tour tint shows white stripes | `column-gap` is back. A gap can't be painted; use padding on the cells. |
| Tour tint doesn't show at all | Background set on `.mbe-gig--in-tour` rather than on its children. `display: contents` leaves no row box to paint. |
| Details stacked on separate lines | A `<p>` has got inside `.mbe-gig__extra`. Browsers split nested paragraphs apart. |

Nothing in Parts 1–3 touches a client site. Part 4 touches staging only. The GigPress
tables are never modified by any of it — deactivating GigPress and leaving its tables in
place is the real rollback for any site.
