# Webbership SmartShip

A WooCommerce shipping integration for [SmartShip.ro](https://smartship.ro) — live
checkout rates and AWB (waybill) management for every SmartShip home-delivery courier,
through the official SmartShip partner API.

> Independent third-party integration. Not affiliated with, or endorsed by, SmartShip.ro.

## Features

- **Live checkout rates** — real per-courier prices from SmartShip `/cost`, shown at
  checkout. Configurable courier allow-list, per-courier markup and labels, and a
  fallback flat rate. Hard 3-second latency budget with response + failure caching, so a
  slow or down API never hangs checkout (and never blocks a Subscriptions renewal).
- **AWB back-office** — from the order screen: estimate → issue → track → print the PDF
  label → cancel. Destination city is auto-resolved from the order, with a manual override
  when the match isn't confident. Live sender picker; IBAN handling for cash-on-delivery.
- **Hardened API client** — models SmartShip's in-body `status` success convention (it
  returns HTTP 200 with an in-body error code), validates PDF responses by magic bytes,
  and keeps the API key server-side (never in a URL, log, or the browser).
- **EasyBox / locker delivery** — a locker shipping method priced off the live SameDay
  home rate (configurable discount factor, with a flat fallback), plus a map + searchable
  list locker picker at checkout. Classic checkout only for now (see note below). Because
  SmartShip's partner API has no locker AWB endpoint, issuing the EasyBox AWB is a manual
  hand-off from the order's SmartShip metabox rather than automatic.
- **WooCommerce Fulfillments provider** — registers SmartShip as a shipping provider so
  fulfillments show the SmartShip tracking URL and courier name (e.g. in the shipped-order
  email). An AWB pasted into the fulfillment drawer, or into the order's SmartShip metabox
  (available for every order, not just EasyBox hand-offs), is auto-detected and verified
  against the SmartShip API rather than guessed from its format.
- **Couriers**: Cargus, SameDay, FanCourier, DragonStar, DPD, PTT Express, SmartShip
  Delivery (whatever your SmartShip account offers for the route).
- Translation-ready (`webbership-smartship` text domain, `.pot` included), HPOS-compatible,
  no Composer/npm runtime dependencies.

## Requirements

- WordPress with WooCommerce
- PHP 7.4+
- A SmartShip.ro account with an API key (Settings → API)

## Installation

1. Copy this repository into `wp-content/plugins/webbership-smartship/` (or install the
   release zip).
2. Activate **Webbership SmartShip** in *Plugins*.
3. Go to **WooCommerce → SmartShip**, enter your API key, pick a sender, and
   (for cash-on-delivery) add your IBAN. Use *Test connection* to confirm credentials.
4. Add the **SmartShip Live Rates** shipping method (and, if you want locker delivery, the
   **Ridicare Sameday Point / EasyBox** method) to the shipping zones where you want
   live rates.

## A note on SameDay EasyBox / lockers

SmartShip's *partner API* has no locker-AWB endpoint — `/awb/new` cannot issue a waybill
for a locker destination. EasyBox is still supported end-to-end: the shipping rate is
priced from the live SameDay home rate, and the customer picks a locker on a map at
checkout (classic checkout only — WooCommerce Blocks checkout has no locker-collection
integration yet, so the rate is suppressed there). Because the API can't issue the AWB
automatically, issuing it for an EasyBox order is a manual step from the order's SmartShip
metabox instead of the usual estimate → issue flow.

## Development

Standalone smoke tests (no framework, no WordPress required) cover the pure logic:

```bash
for t in tests/smoke-*.php; do php "$t"; done
```

Lint: `php -l <file>`.

## License

[GPL-2.0-or-later](LICENSE). © WEBBERSHIP SRL.
