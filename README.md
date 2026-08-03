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

From version 1.4.0 the module updates itself. When a newer version is published, the
**Marques de France → Dashboard** page shows a banner with an **Update now** button. One
click downloads the new version, verifies it, replaces the module files and runs the
database upgrade. There is nothing to download or upload.

The check runs when you open the dashboard, and once a day in the background so the
banner is already there when you next log in.

> Version 1.4.0 itself must be installed manually — earlier versions have no updater.
> Follow the manual procedure below once, and subsequent updates are one click.

**Manual update** (still supported, and the fallback if your hosting blocks the automatic
one):

1. Download the latest `mdfcforps.zip` from the link above.
2. Upload it again via **Modules → Module Manager → Upload a module**.
3. PrestaShop detects the newer version and shows an **Upgrade** action — click it to apply.

### Requirements for the automatic update

If any of these is missing the dashboard says so explicitly and offers the manual route
instead — it never fails silently. Send this list to your hosting provider if the button
is disabled:

- `modules/` and the module's own directory writable by PHP
- `var/cache/` writable by PHP
- the PHP **zip** extension (`ZipArchive`)
- `allow_url_fopen` enabled, and `rename` / `unlink` / `rmdir` not in `disable_functions`
- about 20 MB of free disk space
- outbound HTTPS to `flux.marques-de-france.fr` and `github.com` /
  `objects.githubusercontent.com`
- **OPcache** either with `opcache.validate_timestamps=1` (the default), or with PHP
  restarted after each update. With timestamp validation off *and* `opcache_reset()`
  blocked, PHP would keep running the old code from cache while the database records the
  new version — so the module refuses to update at all in that configuration rather than
  leave the shop in a state that looks fine but is not.

### What the update does to your files

The module directory is **replaced wholesale** by the contents of the release archive, so
any customisation made inside `modules/mdfcforps/` is lost. The previous version is kept
as `modules/mdfcforps_bak_<random>/` for 7 days, then deleted automatically.

### Recovery

- **"An update is half-finished"** — the files were replaced but the database step did not
  run (a PHP timeout, or the browser tab was closed). Click **Finish the update**.
- **"the database upgrade could not be completed automatically"** — the shop is already
  running the new code; only the database record lags. Open **Modules → Module Manager**,
  find Marques de France and click **Upgrade**. Or use **Restore previous version** on the
  dashboard to go back.
- **The module directory is missing** (the rare case where both the swap and the automatic
  restore failed) — the dashboard prints the exact command to run. It is:

  ```
  mv modules/mdfcforps_bak_<random> modules/mdfcforps
  ```

Every step is written to **Advanced Parameters → Logs** with the `[MDF][update]` prefix.
Failures are also reported to the Marques de France Hub with the failing step and your
PHP/PrestaShop versions, so support can diagnose without asking you for anything.

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

**The order matters.** Merchants download the moment the Hub announces a version, so the
release asset must exist *before* the Hub is told about it — otherwise every partner gets
a 404.

1. **Bump the version.** The version lives in four places across three files and they must
   all agree, or PrestaShop will not offer the upgrade:

   ```
   composer run bump 1.4.1        # or: --patch | --minor | --major, plus --dry-run
   ```

   This rewrites `config.xml`, `config_fr.xml` and both strings in `mdfcforps.php`,
   verifies all four match afterwards, and scaffolds `upgrade/upgrade-X.Y.Z.php`.

2. **Fill in the upgrade script.** It is **mandatory** whenever the release touched
   `config/routes.yml`, `config/services.yml`, the database schema or the translations —
   without the Symfony cache clear it performs, the compiled container stays stale and new
   routes 404.

3. **Verify, commit, tag.** Tagging *is* releasing: the workflow fires on any pushed tag.

   ```
   composer run check:version
   git add -A && git commit -m "release: prepare 1.4.1"
   git tag 1.4.1 && git push && git push origin 1.4.1
   ```

4. **Test the exact release ZIP** on PrestaShop 1.7.8, 8.x and 9.x before step 5. Once the
   Hub announces it, every partner shop can install it unattended.

5. **Announce it to partner shops** — one line on the Hub (`mdf-connectors-hub`), then
   restart:

   ```
   PS_MODULE_LATEST_VERSION=1.4.1
   ```

   The download URL and the SHA-256 are derived from that version, the checksum coming
   from the `mdfcforps.zip.sha256` asset this workflow publishes. If the release assets
   are not up yet, nothing is announced at all rather than something unverifiable — so
   bumping early is harmless, it just does nothing until the workflow finishes.

**To stop a bad release reaching anyone**, set `PS_MODULE_UPDATE_ENABLED=false` on the Hub.
The banner disappears on the next dashboard load; no module release is needed.

The [`.github/workflows/release.yml`](.github/workflows/release.yml) workflow builds a clean
`mdfcforps.zip` (top-level `mdfcforps/` folder, dev files excluded via `.gitattributes`),
publishes its SHA-256 as `mdfcforps.zip.sha256`, and attaches both to a new GitHub Release.
The `releases/latest/download/mdfcforps.zip` link always points to the newest version.

### Testing the self-update locally

Use [`tools/update-test-env.sh`](tools/update-test-env.sh), which creates a throwaway shop,
serves test packages and exposes the fault-injection switches:

```
tools/update-test-env.sh up [1.7.7.5|8]   # throwaway shop + module installed
tools/update-test-env.sh package 1.4.1    # build + serve a test ZIP, print the Hub env
tools/update-test-env.sh fault fail_rename2
tools/update-test-env.sh state            # versions, phase, leftover backups
tools/update-test-env.sh logs             # the [MDF][update] log lines
tools/update-test-env.sh reset | down
```

It packages from the **working tree**, not `HEAD`, so uncommitted changes are testable —
`git archive HEAD` would silently omit exactly the new code under test.

The download host allowlist blocks anything outside `github.com` and the Hub — deliberately,
since a compromised Hub would otherwise be able to drop arbitrary code onto every partner
shop. Two escape hatches exist for testing, both honoured **only when `_PS_MODE_DEV_` is
true**, so they are inert in production:

- `MDFCFORPS_UPDATE_ALLOWED_HOSTS` — comma-separated extra download hosts, e.g.
  `host.docker.internal`.
- `MDFCFORPS_UPDATE_FAULT` — comma-separated fault injection points, to exercise the
  failure paths that are otherwise unreachable: `fail_download`, `fail_checksum`,
  `fail_extract`, `fail_promote`, `fail_rename1`, `fail_rename2`, `fail_restore`,
  `fail_upgrade`. `fail_rename2` alone tests the automatic rollback;
  `fail_rename2,fail_restore` tests the "module directory missing" recovery path.

Build test packages with the real command so packaging is identical to a release:

```
git archive --prefix=mdfcforps/ --format=zip -o /tmp/zips/mdfcforps.zip HEAD
```

When running PrestaShop in Docker, **do not bind-mount `modules/mdfcforps`** — a bind mount
makes it a mount point, `rename()` on it fails with `EBUSY`/`EXDEV`, and you would be
testing a filesystem topology no real shop has. Use `docker cp` instead.

## Changelog

### 1.4.0

**One-click updates.** The module now tells you when a new version is available and
installs it for you. Open **Marques de France → Dashboard**, click **Update now**, and the
module downloads, verifies and installs the new version itself — no ZIP to download, no
upload, no Module Manager. It also checks once a day in the background, so the notice is
waiting for you rather than depending on you thinking to look.

Before replacing anything, the module checks that your hosting can actually complete the
update — write permissions, the zip extension, disk space, and the OPcache configuration —
and if something is missing it says exactly what, and points you at the manual procedure
instead of failing halfway.

Your previous version is kept for 7 days, so an update can be undone. If anything goes
wrong the module restores the previous version by itself; in the rare case where it cannot,
the dashboard prints the one command your host needs to run.

**This version must be installed manually** (earlier versions have no updater). Every
version after it is one click.

**Compatibility.** No database change.

### 1.3.0

**Sales — your figures now cover Marques de France orders only.** The dashboard, the
Sales tab and the sale notifications report exclusively the orders that Marques de France
brought to your shop. Orders reaching you through your own channels are not counted in
these figures.

Expect lower totals after updating: they now measure your Marques de France volume
specifically rather than your overall activity. Records created before this version are
left unchanged, so all-time totals still include them.

**Attribution — considerably more reliable.**
- Campaign links are recognised whether the Marques de France marker sits on the
  campaign source, medium or campaign name.
- Attribution is now also detected on arrival without JavaScript, so it survives ad
  blockers, content-blocking extensions and script errors from other modules — and it is
  remembered for 60 days rather than for the browsing session alone.
- Newsletter and hand-built links carrying `?ref=marques-de-france` benefit from the same
  detection. The value must contain the full `marques-de-france` token.
- A returning visitor whose browser had cleared the tracking cookies is recognised again
  on their next visit.

**Cancellations and refunds** are now reflected on the Marques de France side, so they no
longer count towards confirmed revenue.

**Compatibility.** No database change.

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
