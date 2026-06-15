# mdfcforps

PrestaShop connector for the Marques de France platform.

## Compatibility

- PrestaShop: 8.0+
- PHP: 7.4+

## Features

- Sales attribution capture from front-office signals.
- Sales synchronization with Marques de France Hub.
- Product feed generation for eligible products.
- Back-office dashboard, feed management, and sales views.

## Installation

1. Copy the module to your PrestaShop modules directory.
2. Install from the Back Office module manager.
3. Configure the module from the Marques de France admin page.

## Configuration

- `MDF_HUB_URL` (optional): override Hub URL for local/dev environments.
- Secure token is generated on installation and used for feed/sync communication.
- Feed mode supports:
- `TAG` (default): products tagged `marques-de-france`.
- `SERVERLIST`: manual selection from module admin.

## Development checks

Run in module root:

- PHP syntax check:

	`composer run lint:php`

- Coding standards dry run:

	`composer run lint:cs`

- Static analysis:

	`_PS_ROOT_DIR_=/path/to/prestashop composer run lint:phpstan`

- Unit tests:

	`composer test`

## Privacy notes

The module stores attribution signals (UTM/referrer/click context) used for sales attribution.
Merchants are responsible for presenting privacy and consent information according to local regulations.

## License

AFL-3.0. See LICENSE.
