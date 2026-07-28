# mdfcforps — Marques de France PrestaShop Connector

PrestaShop connector for the [Marques de France](https://www.marques-de-france.fr) platform.
It captures attributed sales, syncs them to the Marques de France Hub, and generates a
product feed for your French-made products.

## Compatibility

- PrestaShop: 1.7.7.5 – 9.x
- PHP: **7.4 or newer** (hard requirement)
- MySQL 5.6+ / MariaDB 10.x

> **PHP 7.4 is required even on PrestaShop 1.7.7.x.** The module uses typed properties
> and arrow functions, so it cannot run on PHP 7.3 or below. PrestaShop 1.7.7 officially
> supports PHP 7.1–7.3, so a 1.7.7.x shop must be hosted on PHP 7.4+ for this module to
> load at all.
>
> On PrestaShop 1.7.7.x running PHP 7.4, keep **debug mode off**. PrestaShop 1.7.7 core
> emits PHP 7.4 deprecation notices; with `display_errors` enabled they are written to the
> response before the feed sets its headers, which makes the feed return an error page
> instead of XML. This is a core/PHP-version interaction, not a module fault.

## Features

- Sales attribution capture from front-office signals (UTM, referrer, click context).
- Sales synchronization with the Marques de France Hub, with retry and reconciliation.
- Google Merchant-compatible product feed for eligible products, with tax-included
  prices and sale prices resolved from specific prices and catalog price rules.
- Back-office dashboard, feed management, and sales views.

## Installation

1. Download the latest module ZIP:
   <https://github.com/Marques-de-France/mdfcforps/releases/latest/download/mdfcforps.zip>
2. In your PrestaShop admin, go to **Modules → Module Manager → Upload a module** and upload the ZIP.
3. Open the **Marques de France** page in the admin menu. The module self-registers with the
   platform on install; enter or confirm your secure token if requested.

> Use the `mdfcforps.zip` release asset above — not GitHub's green "Code → Download ZIP"
> button, which wraps the files in a `mdfcforps-main/` folder that PrestaShop cannot install.

## Updating

1. Download the latest `mdfcforps.zip` from the link above.
2. Upload it again via **Modules → Module Manager → Upload a module**.
3. PrestaShop detects the newer version and shows an **Upgrade** action — click it to apply.

## Configuration

- `MDF_HUB_URL` (optional): override the Hub URL for local/dev environments. Defaults to the
  production Hub.
- A secure token is generated on installation and used for feed/sync communication.
- Feed selection mode:
  - `TAG` (default): products tagged `marques-de-france`.
  - `SERVERLIST`: manual product selection from the module admin (Manage mode).

## Development

Run from the module root:

- PHP syntax check: `composer run lint:php`
- Coding standards (dry run): `composer run lint:cs`
- Static analysis: `_PS_ROOT_DIR_=/path/to/prestashop composer run lint:phpstan`
- Unit tests: `composer test`

## Releasing (maintainers)

Releases are packaged automatically by GitHub Actions:

1. Bump the version in `config.xml`, `config_fr.xml` and `mdfcforps.php` (`VERSION` **and**
   `$this->version`) — all four must match or PrestaShop will not offer the upgrade.
   Add an `upgrade/upgrade-X.Y.Z.php` script if the release changes the database, the
   service definitions in `config/services.yml`, or the translation catalogue.
2. Commit and push to `main`.
3. Tag the version and push the tag: `git tag X.Y.Z && git push origin X.Y.Z`.

The [`.github/workflows/release.yml`](.github/workflows/release.yml) workflow builds a clean
`mdfcforps.zip` (top-level `mdfcforps/` folder, dev files excluded via `.gitattributes`) and
attaches it to a new GitHub Release. The `releases/latest/download/mdfcforps.zip` link always
points to the newest version.

## Changelog

### 1.2.0

**Sales.**
- The sales grid now renders the order reference as a direct link to the corresponding PrestaShop order view.
- The module now preserves attribution context more reliably when front-office AJAX requests are processed, avoiding the 500 response seen during attribution.

**Back office.**
- Sales records now expose the commission-related values needed for affiliation reporting and reconciliation.

**Compatibility.**
- The module release is aligned with the existing 1.2.0 metadata and upgrade flow.

### 1.1.0

**Feed — breaking change to published values.** `<g:price>` now carries the
**tax-included** price. Previous versions published the tax-excluded price by mistake,
so feed prices rise by the applicable VAT rate on upgrade. `<g:sale_price>` is now
populated whenever a specific price or catalog price rule applies; it was always empty
before. Combinations carry their own regular and sale prices.

A specific price that overrides the price outright (its `price` column set rather than
`reduction`) is reported as the new catalog price rather than as a discount — this
matches what PrestaShop itself displays on the storefront.

**Back office.**

- The "Products in feed" price column shows the discounted price with the regular price
  struck through; sorting and filtering run on the same tax-included, discount-aware
  figures rather than on the raw catalog price.
- Fixed the record count for that grid. It always reported `1`, which capped the listing
  at a single page — merchants saw only the first 25 products and had to search for the
  rest.
- The combinations badge is now correctly singular or plural.

**Sales.**

- Sale timestamps are transmitted with their UTC offset. They were sent without a
  timezone, so the Hub resolved them against its own server timezone and stored them up
  to two hours off when it runs in UTC.
- Sales dates are rendered in the shop timezone instead of UTC, and timestamps imported
  from the Hub are converted rather than stored verbatim.
- The module now reports its version to the Hub via `X-Plugin-Version`.

**Fixes.**

- Removed a `class_alias` shim that broke the module Upgrade action with
  `Cannot declare class …\Common\DataColumn, because the name is already in use`.
- Hub self-registration no longer fails outside a legacy module context; it referenced a
  class that is not autoloadable from Symfony controllers, the feed controller or cron.

**Compatibility.** Declared minimum lowered from 1.7.8.0 to 1.7.7.5. Verified against
PrestaShop 1.7.7.5 (PHP 7.4 / MariaDB 10.6) and 8.2.6 (PHP 8.1 / MySQL 8).

## Privacy

The module stores attribution signals (UTM, referrer, and click context) used for sales
attribution. No personal customer data (names, emails, addresses) is collected. Merchants are
responsible for presenting privacy and consent information according to local regulations.

## License

AFL-3.0. See [LICENSE](LICENSE).
