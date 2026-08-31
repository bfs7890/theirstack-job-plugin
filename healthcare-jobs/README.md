# Healthcare Jobs Aggregator

A WordPress plugin that imports UK healthcare vacancies from the
[TheirStack Jobs API](https://theirstack.com/en/docs/api-reference/jobs/search_jobs_v1)
into a local database and serves them as a fast, searchable job board via
the `[healthcare_jobs]` shortcode (and an optional Gutenberg block).

Data flow:

```
TheirStack API → WP-Cron / manual import → wp_healthcare_jobs (local DB)
               → [healthcare_jobs] search page → single job page → Apply on original source
```

The plugin never queries TheirStack on a visitor request. All searching
and filtering happens against the local database.

---

## Requirements

- WordPress 5.9+
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+ (for `FULLTEXT` support on InnoDB, used by search)
- A TheirStack API key (https://theirstack.com)

## Installation

1. Copy the `healthcare-jobs` folder into `wp-content/plugins/`.
2. Activate **Healthcare Jobs Aggregator** from **Plugins**.
   - Activation creates five dedicated database tables (see below), grants
     the `manage_healthcare_jobs` capability to the Administrator role, and
     seeds the default healthcare categories/job titles.
   - Nothing in the active theme or any other plugin is modified.
3. Go to **Healthcare Jobs → Settings** and add your TheirStack API key.
4. Click **Test API Connection** to confirm it works.
5. Go to **Healthcare Jobs → Import Jobs** and click **Import Now**, or
   wait for the next scheduled automatic import (every 6 hours by default).
6. Add the shortcode `[healthcare_jobs]` to any page to display the job
   board, or use the **Healthcare Jobs Board** block in the block editor.

Because URLs for individual jobs are handled by a custom rewrite rule, if
job pages 404 after activation, visit **Settings → Permalinks** and click
**Save Changes** once to flush rewrite rules (activation attempts this
automatically, but some hosts require the manual nudge).

## TheirStack API Setup

1. Sign up at https://theirstack.com and generate an API key.
2. Configure the key one of two ways:
   - **Recommended:** define it as a constant in `wp-config.php`, which
     keeps it out of the database entirely:
     ```php
     define( 'HEALTHCARE_JOBS_THEIRSTACK_API_KEY', 'sk-your-key-here' );
     ```
   - **Or** paste it into **Healthcare Jobs → Settings → TheirStack API
     Key**. It is encrypted at rest (AES-256-CBC, keyed from your site's
     own `AUTH_KEY`/`SECURE_AUTH_KEY` salts) before being saved to the
     `wp_options` table, and the settings screen only ever displays a
     masked version.
3. The API key is never sent to the browser: not in page HTML, not in any
   AJAX/REST response, and it is redacted from log messages and the
   import history's error list.

The plugin calls `POST https://api.theirstack.com/v1/jobs/search` with a
`Bearer` token, requesting only the fields it needs and paging through
results up to your configured maximum per import (TheirStack bills one
credit per job returned, so the import is bounded and predictable).

**A note on field mapping:** TheirStack's job schema evolves. The importer
maps fields defensively (trying several known key names, e.g. both
`final_url` and `url`) and always stores the full raw API response in the
job's `raw_data` column, so nothing is ever lost even if a field is
renamed upstream. If you notice a field mapping in your account, use the
`healthcare_jobs_map_job` filter to correct it without waiting on a plugin
update:

```php
add_filter( 'healthcare_jobs_map_job', function ( $job_data, $raw ) {
    // Adjust $job_data using $raw as needed.
    return $job_data;
}, 10, 2 );
```

## Database Structure

All data lives in five dedicated tables (never in `wp_posts`), created and
upgraded via `dbDelta()`:

| Table | Purpose |
|---|---|
| `wp_healthcare_jobs` | One row per vacancy. Indexed on `external_job_id` (unique), `title`, `company_name`, `location`, `country_code`, `category`, `posted_at`, `status`, plus a `FULLTEXT` index on `title, description, company_name` for fast keyword search. |
| `wp_healthcare_companies` | One row per employer. A job's `company_id` links here; a company can have many jobs. This is the foundation for the future employer directory. |
| `wp_healthcare_import_log` | One row per import run: timing, counts (found/imported/updated/skipped/expired), trigger type, and a JSON list of errors. |
| `wp_healthcare_categories` | Admin-configurable healthcare categories (Doctors, Nursing, etc.). |
| `wp_healthcare_job_titles` | Admin-configurable job titles, each linked to a category. Drives both the TheirStack search filter and job classification. |

Notable columns beyond the original spec, added for URL routing and the
future employer roadmap:

- `wp_healthcare_jobs.slug` — unique, URL-safe slug for the job's public page.
- `wp_healthcare_jobs.job_source_type` — `aggregated` (TheirStack) or
  `direct` (a future employer-submitted job), so direct postings can be
  added later without a schema change.
- `wp_healthcare_jobs.employer_type` / `wp_healthcare_companies.employer_type`
  — NHS / Private Hospital / Dental / Pharmacy / Care Home / etc.
  Classification is evidence-based (e.g. "NHS" appearing in the company
  name), never assumed. Extend it via the
  `healthcare_jobs_classify_employer_type` filter as your rules improve.

Deduplication: jobs are matched on `external_job_id` before insert. A job
that reappears in a later import updates the existing row (including
`last_updated_at`) rather than creating a duplicate.

## Import Process

**Healthcare Jobs → Import Jobs** shows the current effective settings and
an **Import Now** button (AJAX; runs synchronously and reports a summary).
Each import run:

1. Takes a transient lock (`healthcare_jobs_import_lock`, 15 minutes) so a
   manual click can never overlap a cron-triggered run, or vice versa.
2. Builds the search filter from **Settings** (country, job age, status)
   plus every job title configured under **Healthcare Jobs → Categories**.
3. Pages through TheirStack results (up to 500 per page) until either the
   configured **Maximum Jobs Per Import** is reached or a short page
   signals no more results.
4. For each job: validates it has a title and ID, classifies it into a
   healthcare category (skipping anything that matches none of your
   configured titles), upserts the employer into
   `wp_healthcare_companies`, then upserts the job itself.
5. Marks jobs TheirStack reports as closed, then expires (never deletes)
   jobs older than the configured maximum age or not re-seen in any recent
   import.
6. Writes a row to `wp_healthcare_import_log` with full counts and any
   per-job errors (each error skips only that one job; the run continues).

A single bad record never aborts the run, and a failed API call never
deletes existing jobs — errors are logged and the next scheduled import
retries automatically.

## WP-Cron

Automatic imports are enabled by default, every 6 hours, via a custom
`healthcare_jobs_six_hours` cron schedule (also offers hourly, 3-hourly,
12-hourly, and daily under **Settings → Import Frequency**). Disable
automatic imports entirely with the **Automatic Import Enabled** checkbox.

WP-Cron only fires on incoming site traffic. For a low-traffic site,
consider disabling `DISABLE_WP_CRON` and triggering `wp-cron.php` via a
real system cron job for reliable timing — this is standard WordPress
practice and outside the scope of this plugin.

## Healthcare Categories & Job Title Configuration

**Healthcare Jobs → Categories** manages both without touching code:
categories (Doctors, Nursing, Allied Health, Pharmacy, Dental, Healthcare
Management, seeded on first activation) and, within each, the job titles
that both (a) get searched for on TheirStack and (b) classify an imported
job into that category. Add, or remove entries freely; changes take effect
on the next import.

The plugin deliberately does **not** hard-code any commercial search term
(e.g. "private clinic") into the import filter — categorisation of an
employer as NHS, private, or another type is evidence-based (see
`employer_type` above) and refined separately from what gets imported.

## Shortcode

```
[healthcare_jobs]
[healthcare_jobs category="Doctors" location="London"]
[healthcare_jobs limit="10"]
```

| Attribute | Description |
|---|---|
| `category` | Pre-fills the category filter. |
| `location` | Pre-fills the location filter. |
| `limit` | Results per page (defaults to **Settings → Search Results Per Page**). |

The same rendering is also available as the **Healthcare Jobs Board**
block in the block editor (Settings sidebar exposes the same three
options), for sites that prefer blocks over shortcodes.

Search/filtering runs over AJAX (vanilla JavaScript, no framework) against
the local database only — TheirStack is never called from a frontend
request. Individual job pages are served at SEO-friendly URLs such as
`/healthcare-jobs/consultant-cardiologist-london/` via a lightweight
rewrite rule (no custom post type, so imported jobs never bloat `wp_posts`).

## SEO

Each job page includes:

- A canonical `<link>` tag.
- `schema.org` `JobPosting` JSON-LD, built only from fields the plugin
  actually has data for — salary, closing date, and other optional fields
  are omitted rather than fabricated when TheirStack didn't provide them.
- A `noindex` directive once a job is closed/expired (the historical
  record stays in the database and in the admin, just out of search
  engines).

## Uninstalling

Deactivating the plugin only stops cron and does not touch your data.
Deleting it via **Plugins → Delete** also leaves all imported jobs,
companies, and settings in place **unless** you have explicitly ticked
**Settings → Delete Data on Uninstall**, in which case `uninstall.php`
drops all five plugin tables and removes plugin options/capabilities.

## Security

- Every admin screen and AJAX action checks the `manage_healthcare_jobs`
  capability (granted to Administrators on activation) — a dedicated
  capability, not a hard-coded `current_user_can( 'manage_options' )`, so a
  future non-admin role can be granted access without full site admin
  rights.
- Every state-changing form/AJAX request is nonce-protected
  (`wp_nonce_field` / `check_ajax_referer`), CSRF-safe by default. The
  read-only public job search AJAX endpoint intentionally has no nonce, so
  it keeps working under full-page caching — it only ever reads from the
  local database with sanitised, prepared queries.
- All SQL uses `$wpdb->prepare()`; all output is escaped with the
  appropriate `esc_html()` / `esc_url()` / `esc_attr()` / `wp_kses_post()`.
- The API key is encrypted at rest, never printed to any page, script,
  REST response, or AJAX response, and is actively redacted from log
  messages and stored import errors.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "No TheirStack API key configured" | Add a key in Settings, or define `HEALTHCARE_JOBS_THEIRSTACK_API_KEY` in `wp-config.php`. |
| Test connection fails with "unauthorized" | The key is invalid/revoked — generate a new one at theirstack.com. |
| **Test Connection succeeds but Import Now (or a scheduled import) later fails with "unauthorized"** | Both use the exact same request code, so this means the *stored* key value changed or failed to decrypt between the two calls, not that they authenticate differently. Two causes were found and fixed: (1) saving Settings for any other reason (e.g. changing import frequency) without retyping the API key used to silently blank it out — the field is now only cleared via the explicit **Remove the stored API key** checkbox; (2) the encryption key used to be derived from `AUTH_KEY`/`SECURE_AUTH_KEY`, which can legitimately differ across servers behind a load balancer or get rotated, silently breaking decryption on whichever request didn't originally encrypt it — it's now derived from a dedicated secret stored once in the database, identical on every server/cron run, with a one-time automatic migration for existing installs. If you hit this on an older copy of the plugin, update it and re-enter the key once in Settings to be safe. The auth-failure message itself now also shows whether a key was present, its length, and its last 4 characters (never the full key) so this is diagnosable directly from Import History. |
| Import runs but imports 0 jobs | Check **Categories** has job titles configured; TheirStack's title match is an OR filter, so results outside your configured titles are intentionally skipped and logged. If the *first* page of a run returns zero jobs, Import History now also logs the exact request parameters sent and TheirStack's reported `total_results`, to distinguish a rejected request from a genuinely empty result. |
| "An import is already running" | A previous run is still inside its 15-minute lock window, or crashed without releasing it (rare) — wait, or clear the `healthcare_jobs_import_lock` transient. |
| Job pages 404 | Visit **Settings → Permalinks** and click Save to flush rewrite rules. |
| Search feels slow at scale | Confirm the `wp_healthcare_jobs` table has its `FULLTEXT` index (recreated automatically via `dbDelta` on any plugin update); avoid disabling MySQL's InnoDB `FULLTEXT` support. |

## Testing

PHPUnit tests live in `tests/` and follow the standard WordPress plugin
test scaffold (`WP_UnitTestCase`). To run them locally:

```bash
composer install
bash bin/install-wp-tests.sh healthcare_jobs_test root '' localhost latest
vendor/bin/phpunit
```

Coverage includes: API authentication and failure handling (401/429/500,
malformed JSON, network errors), the "Test API Connection" flow,
successful import + pagination, duplicate detection/updates, company
matching across multiple jobs, invalid-record skipping, job expiration
(by age and by disappearance), search and every filter combination,
SQL-injection-safe search input, cron scheduling, capability/nonce
enforcement on every admin and AJAX handler, and API-key secrecy across
logs, errors, and AJAX responses.

## Future Employer Functionality

The schema is deliberately ready for the next phase without requiring a
rebuild:

- `wp_healthcare_jobs.job_source_type` (`aggregated` | `direct`) lets
  employer-submitted jobs coexist with TheirStack imports in the same
  table, search, and templates.
- `wp_healthcare_companies` already supports one company having many jobs,
  active-job counts, logo/industry/location — ready to back employer
  profile pages and a business directory.
- `employer_type` on both jobs and companies is ready for filtering a
  directory by NHS / Private Hospital / Private Clinic / Dental /
  Pharmacy / Care Home / Care Provider / Healthcare Technology / Medical
  Recruitment / Other Healthcare, refined over time via the
  `healthcare_jobs_classify_employer_type` filter rather than a schema
  migration.

Not built yet, intentionally: employer registration/login/dashboard, paid
job packages, featured jobs, and lead generation. These are the next
phase, layered on top of this schema.
