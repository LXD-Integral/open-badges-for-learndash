=== Open Badges for LearnDash ===
Contributors: lxdintegral
Tags: open badges, learndash, badges, credentials, cancred
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically issue Open Badges to LearnDash learners through Open Badge Factory or CanCred when they complete courses, lessons, topics, or quizzes.

== Description ==

Open Badges for LearnDash connects your LearnDash site to **Open Badge Factory** or **CanCred Factory** and issues real, verifiable Open Badges 3.0 credentials to learners automatically, with no Zapier or other external automation required.

**What it does**

* Connects to Open Badge Factory and/or CanCred and shows a live, verified connection status.
* Syncs the badges available in each connected account.
* Lets you define **triggers**: "when a learner completes *this* course, lesson, topic, or quiz, award *that* badge", with an optional minimum score on quiz triggers.
* Issues the badge automatically in the background when a learner completes the item, retrying if the provider is temporarily unreachable and never issuing the same badge twice.
* Lets you award badges by hand, and **backfill** a trigger over learners who finished before the trigger existed.
* Displays a learner's earned badges with the `[user_badges]` shortcode or the **Earned Badges** block. Each badge carries a **Save & share** link (the owner's page at the provider, where the badge can be saved to Open Badge Passport / CanCred Passport or downloaded) and a public **Verify** link to the provider's hosted validator.
* Keeps badge lists truthful after issuance: every hour, or on demand, the plugin checks each issued credential's status at the provider. Revoked badges are hidden, badges with an expiry show "Expires" or "Expired", and a badge saved to a Passport shows a "Saved to Passport" mark.
* Cooperates with full-page caches: viewer-specific badge lists are excluded from caching, and issuing a badge purges cached pages.
* Records every issuance attempt in an activity log you can filter and export to CSV.
* Surfaces configuration and connection problems as specific, actionable admin notices. The plugin is built never to fail silently.

**Independent plugin**

Open Badges for LearnDash is developed and published independently by LXD Integral Learning Ltd. It is not affiliated with, supported by, or endorsed by Open Badge Factory, CanCred, Learning Agents, LearnDash, or StellarWP. "LearnDash" is a trademark of its respective owner and is used only to describe which product this plugin works with.

**Requirements**

* WordPress 6.5 or newer and PHP 8.1 or newer.
* LearnDash LMS (a separate, commercial plugin). The plugin activates and can be configured without LearnDash, but no badges are awarded until LearnDash is active.
* An Open Badge Factory or CanCred Factory account with API access. API credentials (Client ID and Client Secret) are created in the provider's console under **Admin tools → API**.

**Third-party services**

This plugin relies on an external badge-issuing service that you choose and configure. Nothing is sent anywhere until you enter API credentials on the plugin's Settings screen.

* **Open Badge Factory** (`https://openbadgefactory.com`), operated by Open Badge Factory Oy, Finland. [Terms of use](https://openbadgefactory.com/en/terms-of-use-eng/) · [Privacy notice](https://openbadgefactory.com/en/privacy-notice-2/)
* **CanCred Factory** (`https://factory.cancred.ca`), operated by Learning Agents, Canada. [Terms of use](https://factory.cancred.ca/terms) · [Privacy policy](https://factory.cancred.ca/privacy)

When a badge is issued, the plugin sends the learner's **email address** and **display name** and the badge identifier to the configured provider over HTTPS, because the provider delivers the credential to that email address. The plugin also fetches your badge library, the share and verification links of each issued credential, and (hourly, and on demand) the status of the credentials it issued. Badge images shown in wp-admin and on your pages are loaded from the provider's servers, and the Save & share and Verify links open pages at the provider. No data is sent to LXD Integral or to any other party, and the plugin contains no tracking.

**Data stored on your site**

The plugin keeps its own tables: the synced badge library, your trigger rules, one award record per issued badge (learner user ID and email address, badge, status, dates, and the credential's links), and an activity log whose entries may name the learner. Award and log records are retained after a user account is deleted; the plugin registers with WordPress's **Export Personal Data** and **Erase Personal Data** tools, so a learner's awards can be exported and their email address removed from award records and their log entries deleted. All plugin data is removed on uninstall only if you enable "Delete all plugin data when the plugin is uninstalled" on the Settings screen.

Your Client Secret is stored encrypted (libsodium) with a key derived from the site's authentication salts. Define `AUTH_KEY` and `SECURE_AUTH_KEY` in `wp-config.php`, as WordPress recommends, so that key is not itself stored in the database. If the salts are ever changed, the plugin asks you to re-enter the secret.

Background work (issuing, retries, link fetching, status checks) runs through WP-Cron. If your site has disabled WP-Cron, make sure a system cron job calls `wp-cron.php`. The plugin is designed for single-site installs; on a multisite network, activate it per site.

**Source code**

The block editor script ships as compiled JavaScript in `build/`; its source is included in `blocks/` and in the public repository at [github.com/LXD-Integral/open-badges-for-learndash](https://github.com/LXD-Integral/open-badges-for-learndash), together with the build instructions.

== Installation ==

1. Install the plugin from the WordPress plugin directory, or upload the zip under **Plugins → Add New → Upload Plugin**, and activate it.
2. Go to **Open Badges → Settings**, enter the Client ID and Client Secret for Open Badge Factory and/or CanCred, save, then click **Test Connection**.
3. Go to **Open Badges → Badges** and click **Sync Now** to pull in the badges from each connected account.
4. Go to **Open Badges → Triggers** and add a rule mapping a LearnDash completion to a badge.
5. Add the **Earned Badges** block, or the `[user_badges]` shortcode, to any page.

The `[user_badges]` shortcode accepts these optional attributes: `user` (user ID, defaults to the current visitor), `columns` (1–6, default 3), `size` (badge image size in px, 32–256, default 96), `show_name` (1/0), `link` (1/0, link each badge image to the provider's badge page), `export` (1/0, "Save & share" link, owner only), `verify` (1/0, "Verify" link).

== Frequently Asked Questions ==

= Does this work with Open Badge Factory or CanCred? =

Both, including both at once. Open Badge Factory and CanCred Factory are functionally identical platforms with different geographical mandates. The plugin connects to each with its own Client ID and Client Secret, and every badge remembers which provider it came from.

= Where do I get API credentials? =

In the provider's console under **Admin tools → API**, create an API key. This requires a subscription tier with API access. Paste the Client ID and Client Secret into the plugin's Settings screen and use Test Connection.

= I added a badge in the provider's console but it isn't in the plugin. =

Badges are synced, not live-queried. Go to **Badges → Sync Now** to pull the current library. Draft badges are listed but can't be issued until published in the provider's console.

= A learner completed the course. Why doesn't their badge show up immediately? =

Issuance is deliberately asynchronous. When a learner completes an item, the plugin records the award and hands it to a background job, so the learner's completion is never slowed down or broken by the badge provider. The badge is normally issued within a minute or two, and an hourly safety sweep catches anything that slipped through. If a badge still hasn't appeared after an hour, check the **Activity Log**, where every attempt is recorded with a specific reason on failure.

= Will a learner ever get the same badge twice? =

No. Awards are idempotent per trigger: once a learner holds a badge from a given trigger rule, re-completing the same item does not issue a duplicate.

= What happens if the provider is down? =

The award is queued and retried with increasing backoff (up to five attempts), and the hourly sweep re-processes anything still outstanding. Failures appear in the Activity Log. The learner's course completion is unaffected.

= Can I award badges for things that happened before I created a trigger? =

Yes. **Issue Badges → Backfill** runs a trigger over learners who completed the item before the trigger existed. You can also issue any badge to any learner by hand from the same screen.

= Can I require a minimum quiz score? =

Yes. Quiz triggers accept an optional minimum percentage. A completion without a score never satisfies a score condition.

= What do the links under each earned badge do? =

**Save & share** opens the badge's claim page at the provider, where the learner can save the badge to Open Badge Passport / CanCred Passport, share it, or download it. It appears only to the badge's owner. **Verify** opens the provider's public validator, which confirms that the credential is valid, not revoked, and not expired. It is shown to every visitor.

= Can I show one learner's badges publicly? =

Yes. The shortcode accepts a `user` attribute. With it, any visitor sees that learner's badge names, images, and Verify links, never the owner's claim link. Without the attribute, the list always shows the signed-in visitor's own badges.

= Are these "real" Open Badges? =

Yes. Every badge is issued by Open Badge Factory / CanCred Factory as a hosted Open Badges 3.0 verifiable credential.

= What if a badge is revoked at the provider? =

The plugin checks every issued badge's status at the provider once an hour, and immediately when you click **Refresh Award Status** on the Badges screen. A revoked badge disappears from the learner's list and from every public showcase, and the Activity Log records the change.

= Do badges expire? =

Only if the badge was set up with an expiry at the provider. The badge list then shows "Expires" with the date and, once the date passes, "Expired". Expired badges stay listed, but the owner's Save & share link is dropped.

= Does the plugin work with page caches like LiteSpeed Cache? =

Yes. Pages showing a visitor's own badge list are excluded from full-page caching, and issuing a badge or a status change purges the page cache. Public showcase pages remain fully cacheable.

= Can learners request their data? =

Yes. The plugin plugs into WordPress's **Tools → Export Personal Data** and **Erase Personal Data**: an export lists every badge awarded to the email address, and an erasure removes the email address from the award records and deletes the learner's activity-log entries. The award records themselves are kept, so a badge is never issued twice, and the credential remains at the provider.

= Does it work on multisite? =

Per site. Activate and configure it on each site that needs it; network activation is not supported.

= What happens to my data when I uninstall? =

Nothing is deleted by default: uninstalling preserves your badges, triggers, award history, and log. Tick **Delete all plugin data when the plugin is uninstalled** on the Settings screen if you want everything removed.

== Screenshots ==

1. Settings: provider credentials with verified connection status.
2. Badges: the synced badge library, with Sync Now and Refresh Award Status.
3. Triggers: rules mapping LearnDash completions to badges.
4. Issue Badges: award by hand or backfill a trigger.
5. Activity Log: every attempt, filterable and exportable.
6. The Earned Badges block on a learner's page, with Save & share, Verify, and Saved to Passport.

== Changelog ==

= 1.0.0 =
* First release in the WordPress plugin directory.
* Replaced the Composer autoloader with a built-in PSR-4 autoloader; the plugin ships no vendor directory.
* Activity log CSV export now neutralises spreadsheet formula characters at the start of a cell.
* Uninstall clears queued per-award cron events with `wp_unschedule_hook()`.
* Export and Erase Personal Data support for award and log records.
* Admin script and styles now go through the enqueue API; the provider base-URL filter accepts HTTPS only; API requests never follow redirects.
* Tested on WordPress 6.5 and 7.1, PHP 8.1 to 8.4.

= 0.4.0 =
* Badge status sync: revoked badges are hidden, expiry dates shown, and a "Saved to Passport" mark replaces the claim link once the learner has saved the badge.
* Refresh Award Status action on the Badges screen.

= 0.3.0 =
* Verify opens the provider's hosted validator; linked badge images open the provider's badge page.
* Page-cache cooperation: viewer-specific lists are not cached and issuance purges the cache.

= 0.2.0 =
* Save & share and Verify links on every earned badge.

= 0.1.0 =
* First demo build: provider connections, badge sync, triggers, automatic and manual issuance, backfill, shortcode and block display, activity log.

== Upgrade Notice ==

= 1.0.0 =
First directory release. Existing 0.x installs upgrade in place; the database schema is upgraded automatically.
