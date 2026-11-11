=== Ad Network ===
Contributors: arbe
Tags: ads, ad rotation, banners, affiliate, advertising
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run your own ad network: image and HTML ads in header, footer, in-content and sidebar slots, rotated randomly and tracked.

== Description ==

* Standard ad sizes (728×90, 300×250, 300×600 and more). Choose which ones your site uses.
* Header, footer, in-content and sidebar placements, with a separate size for phones.
* Image ads or HTML ads. HTML ads can carry several links.
* Weighted random rotation that works with page caches.
* Counts how often each ad is served, viewed and clicked.
* Works with Affiliate Link Cloaker: ads use your cloaked links, and clicks are tagged with the ad that sent them.

== Changelog ==

= 0.2.0 =
* Stats page (Ads → Stats): served, viewed, clicks and CTR for any date range, per ad, per placement and per page, with daily charts and CSV export.
* Daily totals are rolled up every hour, so detailed events can be pruned after a set number of days (90 by default) without losing history.
* Shows clicks recorded by Affiliate Link Cloaker for each ad (needs Link Cloaker 0.4.0).
* "Stats" link on each ad in the Ads list.

= 0.1.0 =
* First version: sizes, placements, ads, rotation and tracking.
