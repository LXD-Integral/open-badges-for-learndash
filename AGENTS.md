# Open Badges for LearnDash — agent notes

A WordPress plugin that issues Open Badges to LearnDash learners via Open Badge Factory / CanCred
(API v2). This is a fresh build replacing the never-functional "Pathwise Badge Connect" plugin,
whose code survives in git history as a behavioural reference only.

`docs/` is **public user documentation** (FAQ etc.) and ships inside the release archive —
write for plugin users there, never session notes.

Key background lives in `dan-docs/` — Dan's personal reference, deliberately untracked
(gitignored) and present only on this machine:
- `dan-docs/assessment-2026-07-20.md` — verified findings on the old plugin (security, bugs).
- `dan-docs/obf-api-research-2026-07-20.md` — OBF API v1/v2 and Open Badges 3.0 research.
- `dan-docs/activity-log/` — one entry per working session.
- `dan-docs/reference/obf-openapi-v2-202602.yaml` — the pulled OBF API v2 OpenAPI spec.

## Release and WordPress.org

`readme.txt` is the WordPress.org listing (header, description, FAQ, changelog); `README.md` is
the GitHub front page. Keep the version in sync across the plugin header, `OBLD_VERSION`,
`package.json`, `readme.txt` (`Stable tag`) and the changelog — CI asserts the first three and
the stable tag agree. The plugin ships no `vendor/`: `open-badges-for-learndash.php` carries a
PSR-4 autoloader for `src/`, and Composer exists only for PHPUnit and PHPCS. CI stages the
release by allow-list, runs Plugin Check against the staged directory, and installs the zip on
WordPress 6.6 and latest with `WP_DEBUG` on (every admin screen is loaded and the debug log must
stay free of plugin notices). The minimum is 6.6 because the compiled block depends on the
`react-jsx-runtime` script, which WordPress registers from 6.6. Plugin Check warnings about direct database queries are expected:
the plugin owns four custom tables, and every value is bound through `$wpdb->prepare()`.
