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
- **Couriers**: Cargus, SameDay, FanCourier, DragonStar, DPD, PTT Express, SmartShip
  Delivery (whatever your SmartShip account offers for the route).
- Translation-ready (`webbership-smartship` text domain, EN/RO), HPOS-compatible,
  no Composer/npm runtime dependencies.

## Requirements

- WordPress with WooCommerce
- PHP 7.4+
- A SmartShip.ro account with an API key (Settings → API)

## Installation

1. Copy this repository into `wp-content/plugins/webbership-smartship/` (or install the
   release zip).
2. Activate **Webbership SmartShip** in *Plugins*.
3. Go to **WooCommerce → Settings → SmartShip**, enter your API key, pick a sender, and
   (for cash-on-delivery) add your IBAN. Use *Test connection* to confirm credentials.
4. Add the **Webbership SmartShip** shipping method to the shipping zones where you want
   live rates.

## A note on SameDay EasyBox / lockers

Locker delivery is **not** available through the SmartShip *partner API* — `/cost` never
returns a locker option, there is no locker field on the AWB schema, and the locker
courier IDs the SmartShip website uses internally are not exposed to integrations. EasyBox
is therefore intentionally out of scope until SmartShip exposes it on the partner API.

## Development

Standalone smoke tests (no framework, no WordPress required) cover the pure logic:

```bash
for t in tests/smoke-*.php; do php "$t"; done
```

Lint: `php -l <file>`.

## License

[GPL-2.0-or-later](LICENSE). © WEBBERSHIP SRL.
