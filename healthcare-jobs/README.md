# Healthcare Jobs Aggregator

A WordPress plugin that imports UK healthcare vacancies from the
[TheirStack Jobs API](https://theirstack.com/en/docs/api-reference/jobs/search_jobs_v1)
and, optionally, the [Adzuna Jobs API](https://developer.adzuna.com/docs/search),
and synchronises them into **Directorist** as real listings, using the same
listing type, categories, locations, and custom fields as the site's own
"Add Listing" submission form.

**Directorist is the authoritative job listing system.** TheirStack and
Adzuna are only external sources of aggregated jobs. This plugin's
importers synchronise both into Directorist through the identical
mapping/sync pipeline; it never keeps a separate, competing copy of job
content, and the public job board never queries either API directly.

Data flow:

```
TheirStack API ─┐
                 ├→ Classifier → Directorist Mapper → Directorist Sync
Adzuna API ──────┘
              → Directorist listings (post type at_biz_dir)
              → [healthcare_jobs] 3-column job board → Directorist single-listing page
              → Apply Now → original source URL
```

Each source has its own importer (`Healthcare_Jobs_Importer` for
TheirStack, `Healthcare_Jobs_Adzuna_Importer` for Adzuna) and its own API
client, but both funnel into the same `Healthcare_Jobs_Classifier` and
`Healthcare_Jobs_Directorist_Mapper`/`Healthcare_Jobs_Directorist_Sync`
calls — an Adzuna job renders on exactly the same single-listing page
layout as a TheirStack job, with the same fields (Company Website button
included, when the source provides one — see **Adzuna API Setup** below).

---

## Requirements

- WordPress 5.9+
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- **Directorist** (business directory / job board plugin), with a "Jobs"
  listing type already configured — this plugin does not create a listing
  type, categories, or locations of its own; it reuses whatever this site
  already has.
- A TheirStack API key (https://theirstack.com)
- (Optional) An Adzuna App ID + App Key (https://developer.adzuna.com) to
  also import from Adzuna alongside TheirStack — see **Adzuna API Setup**.

## Installation

1. Ensure Directorist is installed, active, and has a "Jobs" listing type
   with the categories/locations/custom fields you want imported jobs to
   use (see **Directorist Field Mapping** below).
2. Copy the `healthcare-jobs` folder into `wp-content/plugins/` and
   activate **Healthcare Jobs Aggregator**.
   - Activation creates a small set of internal tables used only for
     TheirStack↔Directorist sync bookkeeping and import history (see
     **Database Structure**) — it never creates the listings themselves in
     these tables. It also grants the `manage_healthcare_jobs` capability
     to the Administrator role and links its classification rules to your
     site's real Directorist categories.
   - Nothing in Directorist's own data, the active theme, or any other
     plugin is modified.
3. Go to **Healthcare Jobs → Settings** and add your TheirStack API key.
4. Click **Test API Connection** to confirm it works.
5. If this site already had jobs imported by an earlier version of this
   plugin (before it wrote to Directorist), the Dashboard will show a
   **Migrate Existing Jobs to Directorist** prompt — see **Migrating from
   an earlier version** below.
6. Go to **Healthcare Jobs → Import Jobs** and click **Import Now**, or
   wait for the next scheduled automatic import (every 6 hours by default).
7. Add the shortcode `[healthcare_jobs]` to any page to display the job
   board, or use the **Healthcare Jobs Board** block in the block editor.

## TheirStack API Setup

1. Sign up at https://theirstack.com and generate an API key.
2. Configure the key one of two ways:
   - **Recommended:** define it as a constant in `wp-config.php`, which
     keeps it out of the database entirely:
     ```php
     define( 'HEALTHCARE_JOBS_THEIRSTACK_API_KEY', 'sk-your-key-here' );
     ```
   - **Or** paste it into **Healthcare Jobs → Settings → TheirStack API
     Key**. It is encrypted at rest before being saved to `wp_options`, and
     the settings screen only ever displays a masked version.
3. The API key is never sent to the browser: not in page HTML, not in any
   AJAX/REST response, and it is redacted from log messages and the
   import history's error list.

The plugin calls `POST https://api.theirstack.com/v1/jobs/search` with a
`Bearer` token. **TheirStack's free plan caps results at 25 per page** —
the importer never requests a page size above this
(`Healthcare_Jobs_TheirStack_API::MAX_PAGE_SIZE`, filterable via
`healthcare_jobs_theirstack_max_page_size` for a paid plan with a higher
cap) and pages through multiple requests instead, up to your configured
**Maximum Jobs Per Import**.

**A note on field mapping:** TheirStack's job schema evolves. The importer
maps fields defensively (trying several known key names) and always stores
the full raw API response, so nothing is lost even if a field is renamed
upstream. Adjust mapping without a plugin update via the
`healthcare_jobs_map_job` filter:

```php
add_filter( 'healthcare_jobs_map_job', function ( $job_data, $raw ) {
    // Adjust $job_data using $raw as needed.
    return $job_data;
}, 10, 2 );
```

## Adzuna API Setup (optional second source)

Adzuna is a second, independent job source you can enable alongside
TheirStack. It is off by default (`adzuna_import_enabled` defaults to 0)
and requires its own credentials — nothing runs against Adzuna until both
are configured.

1. Sign up at https://developer.adzuna.com and create an app to get an
   **App ID** and **App Key** (Adzuna authenticates with this pair via
   query-string parameters, not a bearer token like TheirStack).
2. Configure them one of two ways:
   - **Recommended:** define them as constants in `wp-config.php`, kept
     entirely out of the database:
     ```php
     define( 'HEALTHCARE_JOBS_ADZUNA_APP_ID', 'your-app-id' );
     define( 'HEALTHCARE_JOBS_ADZUNA_APP_KEY', 'your-app-key' );
     ```
   - **Or** enter them at **Healthcare Jobs → Settings → Adzuna API**. The
     App Key is encrypted at rest the same way the TheirStack API key is;
     the App ID is stored as plain text (it identifies the app, not a
     secret credential — Adzuna's own docs treat it this way).
3. Tick **Enable Adzuna Import** and click **Test Adzuna Connection**.
4. Once enabled, Adzuna jobs are imported using the exact same **Default
   Country**, **Default Job Age**, **Maximum Jobs Per Import**, and
   configured job titles/categories as TheirStack — there are no separate
   Adzuna-only settings for these, by design, so both sources search for
   the same thing.

**Two real differences from TheirStack that are worth knowing about
up front:**

- **Country coverage.** Adzuna's endpoint is per-country
  (`/v1/api/jobs/{country}/search/...`) and only covers a fixed list of
  countries (see Adzuna's docs) — unlike TheirStack, which accepts an
  arbitrary ISO code. If **Default Country** isn't one Adzuna covers, the
  Adzuna import will report an API error rather than silently returning
  nothing.
- **No company website.** Adzuna's job search response includes a company
  *display name* but not a website URL, so `_custom-url` is left empty for
  Adzuna-sourced listings — the [Company Website button](#company-website-button)
  simply will not appear on them. Everything else (title, description,
  company name, location, salary, employment type, source URL, category)
  maps the same way TheirStack's does.

Adzuna runs as a fully separate importer
(`Healthcare_Jobs_Adzuna_Importer`) with its own lock and its own
`wp_healthcare_import_log` row per run — **Import Now** and the cron
schedule both trigger it right after the TheirStack run when enabled, and
its dedupe key is the Adzuna job ID prefixed `adzuna-` so it can never
collide with a TheirStack (or any other source's) `external_job_id`.

## Directorist Field Mapping

Every imported job is created/updated as a real `at_biz_dir` post under
your site's configured "Jobs" listing type
(`Healthcare_Jobs_Directorist_Mapper::get_job_listing_type_term_id()`,
filterable via `healthcare_jobs_directorist_listing_type_id`), using the
same field keys your Directory Builder submission form writes to:

| TheirStack field | Directorist field | Notes |
|---|---|---|
| Job title | `post_title` | |
| Description | `post_content` | |
| Company name | `_custom-text` | Directorist's generic "Company/Organisation" Field Builder field on this install. |
| Company website | `_custom-url` | |
| City / region / location | `at_biz_dir-location` taxonomy | Resolved via keyword matching (`healthcare_jobs_directorist_location_map` filter) with a country-level fallback. |
| Employment type | `_custom-select` | Mapped onto Directorist's fixed dropdown options. |
| Remote/onsite/hybrid | `_dirjob_job_type` | The dJobs add-on's "Job Type" field. |
| Salary | `_dirjob_salary` + `_custom-number` | Range string plus a single sortable number. |
| Source/application URL | `_djobs-apply-now` | dJobs' external-apply-URL field — this is what "Apply Now" uses. |
| — (deliberately blank) | `_dirjob_apply_form` | Directorist's *on-site* application form field. Never populated for aggregated jobs — this site must never appear to be the employer accepting applications. |
| Healthcare category | `at_biz_dir-category` taxonomy | See **Classification** below. |
| TheirStack job ID | `healthcare_jobs_theirstack_id` postmeta | The sync key — see below. |
| Closing date | `_dirjob_deadline` + `_custom-date` | |
| Postcode | `_zip` | |
| Company logo | Featured image | Best-effort `media_sideload_image()`; never blocks the import if it fails. |

## Deduplication & Sync Key

The TheirStack job ID is the **only** dedupe key
(`Healthcare_Jobs_Directorist_Mapper::get_external_id_meta_key()`, stored as
the `healthcare_jobs_theirstack_id` postmeta on the Directorist post — never
the job title). Before writing anything, the importer looks this postmeta
up directly (`Healthcare_Jobs_Directorist_Sync::find_existing_post_id()`);
if found, the existing listing is **updated**, never duplicated. Running
the importer repeatedly is always safe.

A small internal table (`wp_healthcare_jobs`, trimmed down from earlier
plugin versions — see **Database Structure**) also keeps a copy of this
mapping purely as a fast lookup index and audit trail; Directorist's own
postmeta is the source of truth the sync actually checks.

A job TheirStack reports as closed is **never deleted**. Its existing
listing is updated to Directorist's own expiry status (the `expired`
post status Directorist registers, or `draft` as a safe fallback if that
status isn't available) via `Healthcare_Jobs_Directorist_Mapper::
get_closed_post_status()`, so historical records and URLs are preserved
and Directorist's own expiry/renewal/deletion rules apply uniformly to
aggregated and directly-submitted listings alike.

## Classification

Jobs are classified into a Directorist category using
`Healthcare_Jobs_Classifier`, a multi-signal matcher — **not** naive
substring matching. This exists specifically to fix false positives like
"IT Consultant", "Health & Safety Consultant", "Tile Sales Consultant", and
"GP Reception Administrator" all being misclassified as doctor roles by a
single-word match on "Consultant" or "GP":

- **Unambiguous titles** (Dentist, Pharmacist, Registered Nurse, ...) match
  on their own, as a whole word/phrase.
- **Ambiguous titles** (currently: "Consultant") additionally require a
  co-occurring **context term** — a clinical modifier such as "Cardiologist"
  or "Physician" — to appear in the title or description before they count.
  "Consultant" alone, with no clinical signal anywhere, is never classified.
- **Exclusion terms** veto a match outright even when the title matched,
  e.g. "Consultant"/"GP" + "IT", "Sales", "Recruitment", "Reception",
  "Administrator", "Health & Safety", etc.
- The **longest matching rule wins** when more than one matches (e.g. "GP
  Reception Administrator" matches the literal Receptionists rule, not the
  shorter, exclusion-vetoed "GP" rule).

Rules are entirely data-driven (**Healthcare Jobs → Categories**), each
linked to a real term in your site's `at_biz_dir-category` taxonomy —
seeded once, on first activation, resolving against whatever categories
this Directorist install actually has (a slug this site doesn't have is
skipped, never invented). Add, edit, or remove rules without a code
change; mark a new title "ambiguous" and give it context/exclusion terms
from the Categories screen for the same protection on future edge cases.

## Database Structure

| Table | Purpose |
|---|---|
| `wp_healthcare_jobs` | **Not** the authoritative job store. One row per TheirStack job purely for fast external-ID → Directorist post-ID lookup and a raw-response audit trail (`external_job_id`, `directorist_post_id`, `sync_status`, `raw_data`). Directorist's own `wp_posts`/`wp_postmeta` hold the actual listing content. |
| `wp_healthcare_companies` | Unused by the current sync path (Directorist has no separate company entity on this install — the employer name lives on the listing itself, `_custom-text`). Left in place, untouched, in case a future phase needs it; not read from or written to by the importer. |
| `wp_healthcare_import_log` | One row per import run: timing, counts, trigger type, and a JSON list of errors. |
| `wp_healthcare_categories` | Admin-configurable classification rule groups, each linked to a real Directorist category term via `directorist_term_id`. |
| `wp_healthcare_job_titles` | Job title classification rules (title, `is_ambiguous`, `context_terms`, `exclusion_terms`), each under a category. Also drives the TheirStack `job_title_or` search filter. |

### Migrating from an earlier version

Versions of this plugin before 2.0.0 used `wp_healthcare_jobs` as their own
authoritative job store (title/description/company/location/etc. columns).
`dbDelta()` never drops columns, so upgrading an existing install leaves
that data physically in place — nothing is deleted automatically. If the
Dashboard shows pending legacy jobs, click **Migrate Existing Jobs to
Directorist**: `Healthcare_Jobs_Migration` re-classifies each one with the
current classifier (never trusting an old, possibly wrong, stored category)
and syncs it into Directorist exactly like a live import would. The legacy
rows themselves are never deleted, so nothing is lost if you need to
inspect the original data afterwards.

## Import Process

**Healthcare Jobs → Import Jobs** shows the current effective settings and
an **Import Now** button (AJAX; runs synchronously and reports a summary).
Each import run:

1. Takes a transient lock (`healthcare_jobs_import_lock`, 15 minutes) so a
   manual click can never overlap a cron-triggered run.
2. Builds the search filter from **Settings** (country, job age, status)
   plus every job title configured under **Healthcare Jobs → Categories**.
3. Pages through TheirStack results (≤25 per page on the free plan) until
   the configured **Maximum Jobs Per Import** is reached or a short page
   signals no more results.
4. For each job: validates it has a title and ID, classifies it
   (`Healthcare_Jobs_Classifier`), maps it onto Directorist's field schema
   (`Healthcare_Jobs_Directorist_Mapper`), and creates or updates the
   Directorist listing (`Healthcare_Jobs_Directorist_Sync`). A failure at
   any one stage skips only that job — the run continues — and the error
   message is tagged with the stage it failed at (`[validation]`,
   `[classification]`, or `[directorist-sync]`).
5. Writes a row to `wp_healthcare_import_log` with Found / Created /
   Updated / Skipped / Closed / Failed counts and any per-job errors.

If Adzuna import is enabled (see **Adzuna API Setup**), clicking **Import
Now** runs the TheirStack import first as above, then runs the Adzuna
import the same way (own lock, own log row, own `[Adzuna]`-tagged errors)
and merges both runs' counts into the one summary shown on screen — a
failure in one source's search (e.g. an invalid Adzuna App Key) never
hides the other source's results.

Expiration is Directorist's own responsibility from this point on (its
`_expiry_date`/cron mechanism), not a separate process this plugin runs.

## WP-Cron

Automatic imports are enabled by default, every 6 hours, via a custom
`healthcare_jobs_six_hours` cron schedule (also offers hourly, 3-hourly,
12-hourly, and daily under **Settings → Import Frequency**). Disable
automatic imports entirely with the **Automatic Import Enabled** checkbox.
This single schedule drives both sources: the TheirStack import always
runs, and the Adzuna import runs immediately after it whenever Adzuna
import is enabled and configured.

WP-Cron only fires on incoming site traffic. For a low-traffic site,
consider disabling `DISABLE_WP_CRON` and triggering `wp-cron.php` via a
real system cron job for reliable timing.

## Shortcode

```
[healthcare_jobs]
[healthcare_jobs category="Doctors" location="London"]
[healthcare_jobs limit="10"]
```

| Attribute | Description |
|---|---|
| `category` | Pre-fills the category filter (Directorist category name, slug, or term ID). |
| `location` | Pre-fills the location filter. |
| `limit` | Results per page (defaults to **Settings → Search Results Per Page**). |

The same rendering is also available as the **Healthcare Jobs Board**
block in the block editor. Search/filtering runs over AJAX against real
Directorist listings — TheirStack is never called from a frontend request,
and neither is `wp_healthcare_jobs`. The 3-column responsive grid
(`public/css/frontend.css`) is presentation only; every job shown, and
every single-job page linked to, is a genuine Directorist listing served
by Directorist's own permalink and template — this plugin does not
register a competing rewrite rule or template for individual jobs.

## Company Website Button

On a Jobs listing's single page (Directorist's own template — see **SEO**
below), this plugin adds a **Company Website** button next to Directorist's
Bookmark/Share buttons, linking to that specific job's company website
(the `_custom-url` field, see **Directorist Field Mapping** above). The
button is only added when a listing is in the "Jobs" listing type and has
a company website set (`Healthcare_Jobs_Single_Listing::maybe_enqueue_assets()`).

Because Directorist owns the single-listing template and its header markup
varies by theme/version, the button isn't inserted via PHP template
output. Instead `public/js/single-listing.js` locates Directorist's
rendered Bookmark (or Share) button by its visible label and inserts the
Company Website button immediately before it in the same action row, so it
works without depending on a specific Directorist template structure or
action hook.

## SEO

Single job pages are Directorist's own listing pages, so canonical URLs,
`JobPosting` schema markup, and noindex-on-expiry are whatever Directorist
itself is configured to do (this install has `enable_schema_markup`
turned on) — this plugin does not duplicate that layer.

## Security

- Every admin screen and AJAX action checks the `manage_healthcare_jobs`
  capability — a dedicated capability, not a hard-coded
  `current_user_can( 'manage_options' )`.
- Every state-changing form/AJAX request is nonce-protected. The read-only
  public job search AJAX endpoint intentionally has no nonce, so it keeps
  working under full-page caching — it only ever reads published
  Directorist listings with sanitised, prepared queries.
- All SQL uses `$wpdb->prepare()`; all output is escaped appropriately.
  Directorist writes go through the same core functions
  (`wp_insert_post()`/`wp_update_post()`/`update_post_meta()`/
  `wp_set_object_terms()`) Directorist's own submission form ultimately
  calls, and the `atbdp_listing_inserted`/`atbdp_listing_updated` hooks are
  fired explicitly so Directorist's own internal caching/indexing runs.
- The API key is encrypted at rest, never printed to any page, script,
  REST response, or AJAX response, and is actively redacted from logs.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "No TheirStack API key configured" | Add a key in Settings, or define `HEALTHCARE_JOBS_THEIRSTACK_API_KEY` in `wp-config.php`. |
| Test connection fails with HTTP 401/403/402/422 | See the detailed diagnostic in the error message — it includes TheirStack's own response text and distinguishes an invalid key (401) from a plan/access restriction (403) from a billing issue (402) from a rejected request body (422). |
| Import Now shows "Authentication/API request failed" instead of job counts | Intentional: an auth/billing/rate-limit failure happens before any search could run, so it's never shown as an indistinguishable "Found: 0". |
| A job doesn't appear on the board | Check Import History for a `[classification]` skip (title didn't match any configured healthcare title) or a `[directorist-sync]` failure for that job specifically — one bad job never blocks the rest of the run. |
| Dashboard shows "jobs pending migration" | This site was upgraded from a pre-2.0.0 version of this plugin. Click **Migrate Existing Jobs to Directorist** on the Dashboard. |
| "An import is already running" | A previous run is still inside its 15-minute lock window, or crashed without releasing it — wait, or clear the `healthcare_jobs_import_lock` transient. |
| A job title is being classified wrong | Add/edit a rule under **Healthcare Jobs → Categories**: mark it "ambiguous" and give it context terms (must also appear) and/or exclusion terms (must never appear) rather than relying on a single keyword. |

## Testing

PHPUnit tests live in `tests/` and follow the standard WordPress plugin
test scaffold (`WP_UnitTestCase`). Since Directorist itself isn't installed
in the test environment, `tests/includes/directorist-stub.php` registers
just enough of its schema (the `at_biz_dir` post type and its three
taxonomies, plus representative terms) for the mapper/sync/classifier to
be exercised end-to-end. To run them locally:

```bash
composer install
bash bin/install-wp-tests.sh healthcare_jobs_test root '' localhost latest
vendor/bin/phpunit
```

Coverage includes: API authentication and failure handling, the "Test API
Connection" flow, successful import + pagination + Directorist listing
creation, duplicate detection/updates by external ID, the specific
classification false-positives this plugin was built to fix (IT
Consultant, Health & Safety Consultant, Tile Sales Consultant, GP
Reception Administrator) alongside the genuine healthcare titles that must
still classify correctly (GP, Consultant Physician, Specialty Doctor,
Registered Nurse, Practice Nurse, Qualified Dental Nurse, Pharmacist),
legacy-data migration, frontend search/filter/pagination against real
Directorist listings, SQL-injection-safe search input, cron scheduling,
capability/nonce enforcement, and API-key secrecy across logs, errors, and
AJAX/search responses.
