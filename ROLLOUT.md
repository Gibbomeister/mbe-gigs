# Rolling MBE Gigs onto a client site

One site at a time, on staging, following Procedure B. Allow an hour for the first,
less after that.

This assumes the plugin is proven — it is, on tedmulrygang's real data in Local. What
this procedure protects against is the two things that are different on a real site:
the staging/live gap, and everywhere GigPress is referenced that isn't the obvious page.

---

## Order to do them in

Start with a site where nothing is upcoming, so a mistake is invisible while the
procedure is still new:

| Order | Site | Gigs | Upcoming | Why |
|---|---|---:|---:|---|
| 1 | georgesich | 86 | 0 | Nothing upcoming since 2017. Proves the pipeline with nothing at stake. |
| 2 | peterpik | 65 | 0 | Also dormant, plus Latvian and German venues — the non-AU path. |
| 3 | tedmulrygang | 45 | 2 | Data already imported once in Local, so any difference is the environment, not the data. |
| 4 | bradmarks | 30 | 1 | Small and simple. |
| 5 | lawlessbreed | 30 | 2 | Small, four artists. |
| 6 | barryleef | 41 | 0 | Fourteen artists on a dormant site. |
| 7 | rudymiranda | 245 | 1 | 46 artists, 144 venues, 23 cancelled gigs. The complicated one. |
| 8 | merilynsteele | 573 | 11 | Biggest, most upcoming gigs, most visible if it goes wrong. Last. |

australianmusichistory is **not** in this list. GigPress stays installed there until the
AMHDB gig widget exists.

---

## Before you start on a site

- [ ] Read the site's `site-brief.md` and `_common/site-editing-standards.md`. The brief
      wins where they disagree.
- [ ] Confirm the right connector is enabled — and only that one.
- [ ] Have that site's venue worksheet to hand: `venues-<site>.csv` in the audit folder.

**Search the whole site for GigPress first**, before touching anything. The gigs page is
the obvious place; the ones that catch people out are a sidebar widget, a second page, a
footer, or a "subscribe to our gigs" link pointing at GigPress's feed.

```
wp post list --post_type=any --post_status=any --format=ids | \
  xargs -n50 wp post get --field=content 2>/dev/null | grep -o '\[gigpress[^]]*\]' | sort | uniq -c
wp option list --search='*gigpress*' --format=table
wp widget list sidebar-1 --format=table
```

Beaver Builder pages keep their content in post meta rather than `post_content`, so
also:

```
wp db query "SELECT post_id FROM wp_postmeta WHERE meta_key='_fl_builder_data' AND meta_value LIKE '%gigpress%';"
```

(`wp db query` may fail on Local; on GridPane it's fine.)

Write down every place it appears. Each one needs replacing or removing before GigPress
is deactivated, or it will render as the literal text `[gigpress]` on a live page.

---

## The run

### 1. Pull and back up

- [ ] **Pull live to staging.** This is what makes the GigPress tables current — the
      importer reads them, and a staging database three months old is three months of
      missing gigs.
- [ ] **Back up live.**

### 2. Install and inspect

- [ ] Install and activate MBE Gigs from the latest GitHub release.
- [ ] Settings → Permalinks → Save.
- [ ] `wp mbe-gigs inspect`

      Read all of it. Every table present, nothing important mapped to `(none)`, the
      deleted count, and the end-date line. If nearly every show "ends after it starts",
      stop — that install uses `show_expire` as a drop-off date and the mapping needs
      removing for that site.

### 3. Import

- [ ] `wp mbe-gigs import --dry-run --limit=20`, read the log.
- [ ] `wp mbe-gigs import --dry-run`, read the summary.
- [ ] `wp mbe-gigs import --venues=/path/to/venues-<site>.csv`
- [ ] Created count = inspect's row count minus its deleted count.
- [ ] `wp post list --post_type=mbe_gig --post_status=future --format=count` → **0**.
      A gig in Scheduled status is invisible on the front end.

### 4. Compare, side by side

- [ ] On the gigs page, add `[mbe_gigs]` **next to** the GigPress shortcode, not instead
      of it. Save as a draft or an unpublished revision if the page is public.
- [ ] Count the upcoming gigs in each. They should match.
- [ ] Spot-check three: dates, venue, city, time, ticket link, any tour.
- [ ] Add `[mbe_gigs direction="past" limit="10"]` if the old page showed past shows.
- [ ] Look at it on a phone.

Anything missing is a display decision, not lost data — check the gig in admin before
assuming the import dropped it.

### 5. Switch over

- [ ] Remove the GigPress shortcode from the page. **Before** deactivating the plugin,
      not after — a deactivated GigPress leaves `[gigpress]` printing as raw text.
- [ ] Deal with every other place the search in step 0 found.
- [ ] Any per-site styling — brand colour on the ticket link, spacing — into the theme
      or Themer CSS.
- [ ] **Deactivate** GigPress. Do not delete it, and do not drop its tables. That is the
      rollback.

### 6. Re-pull, re-import, push

This is the step that is easy to skip and expensive to skip.

- [ ] If more than a day has passed since step 1, **pull live to staging again** and
      re-run the import. It's idempotent — created 0, updated everything — so it costs
      a minute and catches any gig the band added to live GigPress while you were
      working. Without it, the push overwrites those gigs and they are gone.
- [ ] Back up live again if the first backup is now stale.
- [ ] Push staging to live.

### 7. After the push

- [ ] Links still pointing at `staging.`
- [ ] **Settings → Reading → Discourage search engines** — copied across from staging
      every single time.
- [ ] The gigs page on live, on a phone and a desktop.
- [ ] Permalinks saved on live.
- [ ] Change log entry in the site's `site-brief.md`, plus any follow-ups.

---

## If it goes wrong after the push

Restore the live backup. Then, on staging: reactivate GigPress, put its shortcode back,
and work out what happened before trying again. The GigPress tables were never touched,
so nothing has been lost either way.

`wp mbe-gigs rollback --yes` removes exactly what the importer created and nothing else
— useful on staging, and never needed on live if the backup is good.

---

## Tell the client

Worth a sentence before you start, not after: *"I'm replacing the gig listing plugin
today — don't add or edit gigs until I tell you it's done."* A gig entered into live
GigPress during the window is the one thing this procedure can lose, and step 6 only
narrows the gap rather than closing it.

Afterwards, for the bands who enter their own gigs: the screen has changed. Gigs → Add
Gig, no title to fill in, venue picked from a list. Two sentences and a screenshot will
save a phone call.
