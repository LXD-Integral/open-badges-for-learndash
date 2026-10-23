# Open Badges for LearnDash

A self-contained WordPress plugin that automatically issues Open Badges to LearnDash learners
through [Open Badge Factory](https://openbadgefactory.com) or [CanCred](https://cancred.ca)
(Open Badge Factory API v2) — no Zapier or other external automation required.

**Status: 1.0.0, submitted to the WordPress.org plugin directory.** Developed and published
independently by LXD Integral Learning Ltd.; not affiliated with or endorsed by Open Badge
Factory, CanCred, LearnDash, or StellarWP.

## What it does

- Connects to Open Badge Factory and/or CanCred and shows a live, verified connection status.
- Syncs the badges available in each connected account.
- Lets you define **triggers** — "when a learner completes *this* course, lesson, topic, or quiz,
  award *that* badge" — with an optional minimum score on quiz triggers.
- Issues the badge automatically in the background when a learner completes the item, retrying if
  the provider is temporarily unreachable and never issuing the same badge twice.
- Lets you award badges by hand, and **backfill** a trigger over learners who finished before the
  trigger existed.
- Displays a learner's earned badges via the `[user_badges]` shortcode or the **Earned Badges**
  block — each badge with a **Save & share** link (the owner's page at the provider, where the
  badge can be saved to Open Badge Passport / CanCred Passport or downloaded) and a public
  **Verify** link to the provider's hosted validator, which confirms in plain language that the
  Open Badges 3.0 credential is valid, unrevoked, and unexpired. With linking enabled, the badge
  image opens the provider's shareable badge page.
- Keeps badge lists truthful after issuance: every hour (or on demand from the Badges screen)
  the plugin checks each issued credential's status at the provider — **revoked** badges are
  hidden, badges with an **expiry** show "Expires …" / "Expired …", and once a learner has saved
  a badge to their Passport the "Save & share" nudge becomes a **Saved to Passport** mark.
- Cooperates with full-page caches: viewer-specific badge lists are excluded from caching, and
  issuing a badge (or a status change) purges cached pages so lists stay current.
- Records every issuance attempt in an activity log you can filter and export to CSV.
- Surfaces configuration and connection problems as specific, actionable admin notices — this
  plugin is built never to fail silently.

## Requirements

- PHP 8.1+
- WordPress 6.5+
- LearnDash (developed against 4.x and 5.x; the plugin activates and can be configured without it)
- An Open Badge Factory or CanCred account with API access (Pro subscription) — API credentials
  are created in the provider's **Admin tools → API** screen

## Installing

Install it from the WordPress plugin directory (**Plugins → Add New**, search for "Open Badges for
LearnDash"), or download the zip attached to the latest entry on the
[Releases page](https://github.com/LXD-Integral/open-badges-for-learndash/releases) and install it
through **Plugins → Add New → Upload Plugin**. The archive contains only runtime files — no
development dependencies, tests, or build tooling. (Development builds are also available as the
`plugin-zip` artifact of any CI run.)

## Using it

1. **Settings** — enter the Client ID and Client Secret for Open Badge Factory and/or CanCred,
   save, then use *Test Connection* to confirm.
2. **Badges** — click *Sync Now* to pull in the badges from each connected account. *Refresh
   Award Status* on the same screen re-checks every issued badge (revoked / expiry / Passport)
   immediately instead of waiting for the hourly check.
3. **Triggers** — add a rule mapping a LearnDash completion to a badge.
4. **Issue Badges** — award a badge by hand, or backfill a trigger over past completers.
5. **Activity Log** — see every attempt, filter by outcome, export to CSV.
6. Add the **Earned Badges** block, or the `[user_badges]` shortcode, to any page.

### `[user_badges]`

| Attribute | Default | Notes |
|---|---|---|
| `user` | current visitor | User ID to show badges for |
| `columns` | `3` | 1–6 |
| `size` | `96` | Badge image size in px, 32–256 |
| `show_name` | `1` | Show the badge name under each image |
| `link` | `0` | Link each badge image to the provider's badge page (falls back to the image file until the badge's links are fetched) |
| `export` | `1` | "Save & share" link to the provider's badge page — rendered only when the viewer is the badge's owner (it is a claim link); becomes a "Saved to Passport" mark once the provider reports the badge saved |
| `verify` | `1` | "Verify" link to the provider's hosted validator (valid / not revoked / not expired), shown to every visitor |

Links appear once the plugin has fetched them from the provider: immediately for new badges,
and within an hour (via the retry sweep) for badges issued before this feature existed. Revoked
badges are never listed; expired badges stay listed with an "Expired" label.

## Development

```bash
composer install        # development tooling only (PHPUnit, PHPCS); the plugin has no runtime dependencies
composer lint           # PHPCS (WordPress Coding Standards)
composer test           # PHPUnit
npm install             # block editor toolchain
npm run build           # build the Earned Badges block from blocks/ into build/
```

The plugin loads its own classes with a PSR-4 autoloader in `open-badges-for-learndash.php`, so a
source checkout runs without Composer. The block must be built before it appears in the editor;
the shortcode works either way. The compiled block script in `build/` is produced from the source
in `blocks/` with `@wordpress/scripts`; both ship in the release.

CI (GitHub Actions) lints and tests on PHP 8.1–8.4, stages the release archive, runs the
WordPress.org [Plugin Check](https://wordpress.org/plugins/plugin-check/) against it, and installs
the archive on WordPress 6.5 and the latest release.

User documentation — an FAQ and material expanding on this README — lives in
[docs/](docs/) and ships inside the release archive. The WordPress.org listing text is
`readme.txt`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
