# mdfcforps — Marques de France PrestaShop Connector

PrestaShop connector for the [Marques de France](https://www.marques-de-france.fr) platform.
It captures attributed sales, syncs them to the Marques de France Hub, and generates a
product feed for your French-made products.

## Compatibility

- PrestaShop: 1.7.8 – 9.x
- PHP: 7.4+

## Features

- Sales attribution capture from front-office signals (UTM, referrer, click context).
- Sales synchronization with the Marques de France Hub, with retry and reconciliation.
- Google Merchant-compatible product feed for eligible products.
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

1. Bump the version in `config.xml` and `mdfcforps.php` (`VERSION` and `$this->version`).
   Add an `upgrade/upgrade-X.Y.Z.php` script if the release changes the database or structure.
2. Commit and push to `main`.
3. Tag the version and push the tag: `git tag X.Y.Z && git push origin X.Y.Z`.

The [`.github/workflows/release.yml`](.github/workflows/release.yml) workflow builds a clean
`mdfcforps.zip` (top-level `mdfcforps/` folder, dev files excluded via `.gitattributes`) and
attaches it to a new GitHub Release. The `releases/latest/download/mdfcforps.zip` link always
points to the newest version.

## Privacy

The module stores attribution signals (UTM, referrer, and click context) used for sales
attribution. No personal customer data (names, emails, addresses) is collected. Merchants are
responsible for presenting privacy and consent information according to local regulations.

## License

AFL-3.0. See [LICENSE](LICENSE).
