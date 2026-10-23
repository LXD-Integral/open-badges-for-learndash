# Frequently asked questions

## Setup and providers

### Does this work with Open Badge Factory or CanCred?

Both, including both at once. Open Badge Factory (openbadgefactory.com) and CanCred Factory
(factory.cancred.ca) are functionally identical platforms with different geographical mandates;
the plugin connects to each with its own Client ID and Client Secret, and every badge remembers
which provider it came from. A single site can issue some badges from OBF and others from
CanCred.

### Where do I get API credentials?

In the provider's console under **Admin tools → API**, create an API key. This requires a
subscription tier with API access (Pro). Paste the Client ID and Client Secret into the
plugin's **Settings** screen and use *Test Connection*.

### I added a badge in the provider's console but it isn't in the plugin.

Badges are synced, not live-queried. Go to **Badges → Sync Now** to pull the current library.
Draft badges are listed but can't be issued until published in the provider's console.

## Issuing

### A learner completed the course — why doesn't their badge show up immediately?

Issuance is deliberately asynchronous. When a learner completes a course, lesson, topic, or
quiz, the plugin records the award and hands it to a background job, so the learner's
completion is never slowed down — or broken — by the badge provider. The badge is normally
issued within a minute or two; an hourly safety sweep catches anything that slipped through.
If your site uses a full-page cache, the plugin purges it when a badge is issued so the
learner's badge page updates promptly.

If a badge still hasn't appeared after an hour, check the plugin's **Activity Log** — every
attempt is recorded there with a specific reason on failure.

### Will a learner ever get the same badge twice?

No. Awards are idempotent per trigger: once a learner holds a badge from a given trigger rule,
re-completing the same item does not issue a duplicate.

### What happens if the provider is down?

The award is queued and retried with increasing backoff (up to five attempts), and the hourly
sweep re-processes anything still outstanding. Failures appear in the Activity Log; the
learner's course completion is unaffected.

### Can I award badges for things that happened before I created a trigger?

Yes — **Issue Badges → Backfill** runs a trigger over learners who completed the item before
the trigger existed. You can also issue any badge to any learner by hand from the same screen.

### Can I require a minimum quiz score?

Yes. Quiz triggers accept an optional minimum percentage. A completion without a score never
satisfies a score condition — the plugin will not award on a missing score.

## What learners and visitors see

### What do the links under each earned badge do?

- **Save & share** — opens the badge's claim page at the provider (the same link the provider
  emails). From there the learner can save the badge to **Open Badge Passport** /
  **CanCred Passport**, share it, or download it. This link appears **only to the badge's
  owner**: it is a claim link, so it must never be shown to other visitors.
- **Verify** — opens the provider's public validator, which confirms in plain language that
  the credential is valid, has not been revoked, and has not expired, and lets a verifier
  confirm the recipient by email without the page ever revealing that email. This link is
  shown to every visitor — verification is the point of an Open Badge.
- With the **link** option enabled, clicking the badge image opens the provider's shareable
  badge page (image, description, issuer, criteria).

### Why do the links appear a little after the badge does?

The badge is issued first; its share/verify links are fetched from the provider immediately
afterwards. If that fetch fails (for example, a momentary provider hiccup), the links stay
hidden and the hourly sweep fills them in — nothing is ever lost.

### Can I show one learner's badges publicly?

Yes. The shortcode accepts a `user` attribute (see the README's attribute table); with it,
any visitor sees that learner's badges — names, images, and Verify links, never the owner's
claim link. Without the attribute, the list always shows the currently signed-in visitor's
own badges.

### What does a learner see before they've earned anything?

Their badge page explains that badges are awarded automatically as they complete courses,
lessons, topics, or quizzes, and that a new badge can take a few minutes to appear.

## Verification and portability

### Are these "real" Open Badges?

Yes. Every badge is issued by Open Badge Factory / CanCred Factory as a hosted **Open Badges
3.0** verifiable credential. The Verify link resolves to the provider's validator for that
exact credential; the underlying signed JSON remains available for machine consumers at the
provider.

### What if a badge is revoked at the provider?

Revocation happens at the provider. The plugin checks every issued badge's status there once
an hour (and immediately when you click **Refresh Award Status** on the Badges screen); a
revoked badge disappears from the learner's list and from every public showcase, and the
Activity Log records the change. The award record itself is kept, so the learner cannot be
re-issued the same badge by the same trigger. The Verify link on a still-cached page tells the
truth in the meantime — the validator shows the badge as revoked.

### Do badges expire?

Only if the badge was set up with an expiry at the provider. When it was, the badge list shows
"Expires <date>" under the badge and, once the date passes, "Expired <date>". Expired badges stay
listed — the achievement happened — but the owner's "Save & share" link is dropped, and the
Verify link reports the expiry.

### What does "Saved to Passport" mean?

Once the learner has saved the badge to Open Badge Passport / CanCred Passport, the provider
reports that back and the plugin replaces the "Save & share" link with a **Saved to Passport**
mark. Merely opening the claim page is not enough; the link stays until the Passport has the
badge. Like the link it replaces, the mark is shown only to the badge's owner.

### How current is the status information?

Statuses are re-checked hourly by the same background sweep that retries issuance, using one
request per provider rather than one per badge. An administrator can force a check at any time
with **Refresh Award Status** on the Badges screen, which also reports what changed. If a check
fails (for example, the provider is unreachable), the Badges screen says so; the hourly sweep
does not write repeated failures to the Activity Log, but a manual refresh does.

## Caching

### Does the plugin work with page caches like LiteSpeed Cache?

Yes, and it cooperates actively: pages showing a visitor's own badge list are excluded from
full-page caching (so one visitor's badges are never served to another, and fresh badges are
not hidden by a stale copy), and issuing a badge — or a status change such as a revocation —
purges the page cache. Public showcase pages (shortcodes with a fixed `user` attribute) remain
fully cacheable.
