# Trident Cache for PrestaShop 9

The `tridentcache` module puts a PrestaShop 9 shop behind the Trident HTTP cache:
catalogue pages are cached and invalidated by tag, every purge is delivered
durably, and the visitor's own bits (cart, greeting) come from the browser — a
cached page costs the shop nothing.

- Module: [`tridentcache/`](tridentcache/) — Composer package
  `qoliber/trident-cache-prestashop`, on the shared library `qoliber/trident-php` ^1.7.
- Sample Trident config: [`trident.toml`](trident.toml).
- Live e2e stack: [`tests/prestashop-e2e`](../../../tests/prestashop-e2e).

Tested: PrestaShop **9.1.5** (classic theme 5.0), PHP 8.3, MariaDB 10.11, Trident 1.8.0.

## Install

1. Copy `tridentcache/` to `modules/tridentcache/`, run `composer install --no-dev`
   inside it, then `bin/console prestashop:module install tridentcache`.
2. Tell the module where Trident is — **deployment configuration wins** (as
   `env.php` does for Magento). In `app/config/parameters.php`:
   ```php
   'trident_instances' => '{"default":{"api_url":"http://127.0.0.1:9301","api_token":"…"}}',
   'trident_purge_mode' => 'soft',
   ```
   or the same as `TRIDENT_*` environment variables (`TRIDENT_INSTANCES`,
   `TRIDENT_API_URL` + `TRIDENT_API_TOKEN`, `TRIDENT_PURGE_MODE`, `TRIDENT_TAG_PREFIX`,
   `TRIDENT_ALLOWED_API_HOSTS`, `TRIDENT_DEBUG_HEADERS`) — php-fpm clears the
   environment unless its pool passes them. A one-server shop can use
   **Advanced Parameters → Trident Cache → Settings** instead (URL + token).
3. Trident: start from [`trident.toml`](trident.toml), replace every `CHANGE ME`,
   then generate the never-cache paths for YOUR shop and paste them over its
   `pass_patterns` — cart/checkout/account slugs are translated per language:
   `bin/console trident:config`.
4. **A cron is REQUIRED** (PrestaShop has no scheduler): every minute,
   `bin/console trident:purge:drain`, or a request to the cron URL shown on the
   Settings screen with the token in the `X-Trident-Cron-Token` header (or a POST
   `token` — never in the URL). It retries what failed, delivers scheduled
   purges (a price starting at midnight) and — because a change made from the
   CLI, the database or the webservice is only noticed by the next request that
   reaches PHP, and a warm cache sends few — is what makes such changes appear.

## How it decides

A render is **shared** (`public, s-maxage`, its cache tags, no cookie) only when
it is what any visitor in the same price context would get — decided on the final
HTML (`actionOutputHTMLBefore`): a GET of a catalogue page (home, category,
product, CMS, brand, supplier, listing pages, search), answered 200, for a visitor
who is not logged in and has an empty cart, with no notification on the page, and
whose price-context marker matches the render. Everything else is
`private, no-store`; Trident still serves such visitors the shared page of their
context, and never stores their own render.

PrestaShop writes its visitor cookie (`PrestaShop-<hash>`) and starts a PHP
session on **every** render, a guest's first included. A shared render drops every
`Set-Cookie` and stops the cookie from being written; a guest gets it as soon as
they act (add to cart, log in), on a response that is never shared. The guest form
token (`static_token`) is identical for every guest (computed for customer 0).

**Price context (`trident_ctx`).** Currency, cart and login live in ONE encrypted
cookie, so the module sets a marker when prices differ from the shop default —
another currency, another tax country (a logged-in customer's address), or a group
with prices or category access of its own — and Trident keys by it (`vary_cookies`). A render is
stored only when the marker the visitor sent matches the computed one, so a forged
marker never stores one context's prices under another's key. (A visitor with
no marker and one with `d` are two cache variants of the same default context —
harmless.) The marker is renewed on every response that is not shared.

**First visit and the country.** With `PS_DETECT_COUNTRY` (the default)
PrestaShop would pick the country — and its currency — from the browser's
`Accept-Language`; but Trident serves the default context's page to every
visitor without a marker. So the module keeps the shop's **default country**
for a visitor who has not chosen a currency or country, is not a known
customer and has no address on their cart: anonymous visitors see the default
country's prices until they choose, log in or enter an address. (To make that
explicit, disable "Set customer country from browser language" in
International → Localization.) The marker then lives as long as PrestaShop's
own cookie (`PS_COOKIE_LIFETIME_FO`), HttpOnly, Secure on https; the default
context's marker is `d`.

**Personal content.** Shared pages carry empty placeholders; the library's
`trident-sections.js` fills the cart badge and the greeting from localStorage and
calls `/module/tridentcache/sections` only when the version cookie `trident_pv`
changed (cart save, login, logout). No cookie, no request.

## Tags and purges

Every page: `all`, `menu`; product `pp_<id>` + `p_<id>`; category / CMS / brand /
supplier `c_` / `cms_` / `m_` / `s_<id>`; home `home`; plus `p_<id>` of every
product shown (`data-id-product`). Product save → `p_<id>` + its categories;
stock → `p_<id>` only when availability flips (an order does not purge listings);
category / CMS save → its page + parent, `menu` only when a field the menu shows
changed; specific price → its product; price rule or currency rate → `all`.
Bounded to 200 tags / 4 KiB with per-kind overflow tags.

Also purged: combinations, images, features and values, attributes and groups,
taxes and tax rules, customer groups, currencies, brands, suppliers, module
content (ps_imageslider, ps_customtext, link blocks) — all through the generic
`actionObject*` hooks — and the prices-drop / new-products listings on every
product change. A scheduled specific price or catalogue price rule is purged
again when it starts and when it ends (on PrestaShop's minute).

**Durable (X02):** each purge is recorded per instance in
`PREFIX_trident_purge_outbox` and removed only on acknowledgement; refusals and
outages are retried with backoff (cron, cron URL, and after back-office requests);
each purge goes out once more ~10 s later. `trident:purge:status [--stale-after]`
exits 1 when a purge is owed too long; `trident:purge:forget <instance>`.

## Back office

**Advanced Parameters → Trident Cache**: the shared screens (dashboard, purge,
cached pages, tags, coverage, warmer, launch, reflect, denoisers, bans, backends,
discovery, events) and Settings. View permission to read, edit to act; every action
is a POST with a CSRF token (HMAC of the employee's session, keyed with the shop
secret); actions that change what everyone is served need an explicit
confirmation, checked on the server; host/pattern purges are limited to the shop's
hosts.

## Security

- The back-office token is sealed to its URL (`TokenVault`, key from
  `_COOKIE_KEY_`): editing the URL cannot send it elsewhere; it is never shown.
- A deployment token goes only to deployment URLs. Instance URLs are
  `http(s)://host[:port][/path]`; link-local/metadata addresses are refused on the
  resolved, pinned address; no environment proxy. Set `TRIDENT_ALLOWED_API_HOSTS`
  in production.
- A client-sent `Surrogate-Capability` is dropped; the cron token is compared in
  constant time.

## Modules that print per-visitor HTML and are NOT detected

The module keeps a render private for its own rules and for ps_viewedproduct,
ps_googleanalytics and PrestaShop's notifications. Other modules that write
something about THE VISITOR into the page are not known to it, for example:

- cookie-consent banners rendered server-side (the visitor's choice in the HTML);
- ps_facebook (the Pixel `event_id`), psxmarketingwithgoogle, GTM /
  enhanced-ecommerce `dataLayer` modules that print cart or customer data;
- ps_checkout express buttons with a per-cart configuration;
- third-party "recently viewed", A/B testing and geo-IP modules;
- blockwishlist shows empty hearts on cached pages (its state loads per visitor).

Before going live with such a module, check it like cases 58 and 80 do (two
visitors, the second must not see the first one's data), and keep its pages
private if needed (`trident_is_private_page`-style rules in your own module, or
a pass rule in trident.toml).

## Known limits

- PrestaShop saves are not transactional. Before every ObjectModel save the
  module records a backstop purge due in 120 s (deduplicated); the After hook
  purges at once. If the process dies between the save and its After hook, the
  backstop still purges the change.
- **Changes made by direct SQL** (imports that bypass ObjectModel, custom
  scripts) are never seen: purge afterwards (Purge → Everything, or tags).
- A shopper's own cold render stays private; they get the pages anonymous
  visitors cached.
- The release zip should prefix its vendored libraries (Guzzle, the library) as the
  WooCommerce plugin does with Strauss; not done yet.
- Trident's warmer uses a browser `Accept-Encoding` (part of the key): warmed pages
  are HITs for browsers, not for `curl`.
- Settings saved through `Configuration` are watched only for the keys that
  change every page (shop enable, catalogue mode, tax display, default
  currency/country/language, sorting, the main menu, featured products, …); any
  other setting a theme or module reads needs a manual purge.

## Versioning

Versions follow Trident: this module 1.8.x works with Trident 1.8. MAJOR.MINOR moves
with the engine (every Trident X.Y.0 release is also a release of this package,
changed or not); the PATCH number is this package's own. The 1.8 line is published as `1.8.0-beta.1` (the module file itself says 1.8.0: PrestaShop stores module versions in a `varchar(8)` column). The
admin screens warn when a connected Trident runs another release line.

## This repository is a mirror

`qoliber/trident-cache-prestashop` is developed in the Trident repository together with the
shared library [`qoliber/trident-php`](https://github.com/qoliber/trident-php)
and the live end-to-end test stacks, and published to
[github.com/qoliber/trident-cache-prestashop](https://github.com/qoliber/trident-cache-prestashop) automatically:
every commit there is a "Sync from trident-cache@…" snapshot. **Please open
issues there**; pull requests against the mirror cannot be merged, because the
next sync would overwrite them. Releases are the tags of that repository.
