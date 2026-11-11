# Ad Network

A WordPress plugin for running your own ad network: image and HTML ads in fixed-size slots,
served in weighted random rotation, with served / viewed / clicked tracking. Built on
[WP Foundation](https://github.com/bignall/wp-foundation) and designed to work with
[Affiliate Link Cloaker](https://github.com/bignall/aff-link-cloaker).

## Roadmap

| Phase | Scope | Status |
| --- | --- | --- |
| 1 | Sizes, placements, ads (image/HTML), cache-safe JS rotation, event logging | **This version** |
| 2 | Daily rollups, pruning, stats pages, CSV export | Planned |
| 3 | Popup and sticky placements, frequency caps, scheduling, targeting, per-post opt-out | Planned |
| 4 | Member accounts, credit ledger, earning credits for views/clicks, spending credits on ads, review queue | Planned |
| 5 | Buying credits and placements | Planned |
| 6 | Etch integration, A/B comparison | Planned |

## How it works

- **Sizes.** Standard IAB units (leaderboard 728×90, medium rectangle 300×250, half page 300×600,
  ...). The admin chooses which sizes the site uses in **Ads → Settings**. Add more with the
  `adn_sizes` filter.
- **Placements.** Header (after `<body>`), footer, in-content (after paragraph *N*) and sidebar.
  Each has a desktop size and a phone size (or none). Add more with `adn_placements`.
- **Ads.** A post type. Each ad has one size and is either an image (with a link) or HTML.
  Publish to run it; switch to Draft to pause it. *Weight* sets how often it's picked compared
  with other ads of the same size.
- **Links.** If Affiliate Link Cloaker is active, ads pick one of its links. Clicks go through
  the cloaked URL with the sub-ID `adn-{ad ID}`, so the cloaker's click log shows which ad sent
  each click. HTML ads can use `{{url}}` for that link, or `[alc_link]` shortcodes.
- **Page caches.** Slots are printed empty with their size reserved, so the cached HTML is the
  same for everyone. `assets/frontend.js` asks `POST /wp-json/adn/v1/serve` which ad goes in
  each slot. There is one request per page and the same ad never appears twice on a page.
- **Tracking.** The server logs a *serve* when it hands out an ad, together with a signed
  token. The browser reports a *view* (half the ad on screen for one second) and a *click* with
  that token. Each served ad counts at most one view and one click. Bots and editors are left
  out by default, and IPs are never stored.
- **Third-party code.** Scripts and iframes (e.g. AdSense) are stripped unless the admin allows
  them *and* the person saving the ad has `unfiltered_html`. Such ads are flagged as third-party
  and will never earn credits, because networks like AdSense ban rewarded views and clicks.

## Placing ads by hand

```
[adn_slot placement="sidebar"]
[adn_slot size="medium_rectangle"]
[adn_slot size="leaderboard" mobile="mobile_banner"]
[adn_slot size="half_page" mobile="none"]
```

```php
<?php adn_slot( 'sidebar' ); ?>
```

There's also an **Ad Slot** widget. Slots added to the page after load can be filled with
`window.adnLoad()`.

## Development

```bash
composer install     # also prefixes WP Foundation into vendor-prefixed/ with Strauss
composer test
bin/build-zip.sh     # installable zip in dist/
```

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `adn_sizes` | filter | Add or change sizes |
| `adn_placements` | filter | Add placements |
| `adn_show_ads` | filter | Turn ads off for a request |
| `adn_should_track` | filter | Skip tracking for a request |
| `adn_ad_html` | filter | Change an ad's markup before it is served |
| `adn_event_recorded` | action | A new view or click was recorded (`$ad_id, $event, $serve_id`) |
