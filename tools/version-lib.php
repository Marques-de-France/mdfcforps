<?php
/**
 * Shared version helpers for tools/bump-version.php and tools/check-version.php.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

/**
 * Read the version out of every place it is declared.
 *
 * @return array<string, string> label => version ('' when not found)
 */
function mdf_read_versions(string $root): array
{
    $read = static function (string $path): string {
        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    };

    $extract = static function (string $subject, string $pattern): string {
        return preg_match($pattern, $subject, $m) === 1 ? trim($m[1]) : '';
    };

    $main = $read($root . '/mdfcforps.php');

    return [
        'config.xml' => $extract(
            $read($root . '/config.xml'),
            '#<version>\s*<!\[CDATA\[([^\]]*)\]\]>\s*</version>#'
        ),
        'config_fr.xml' => $extract(
            $read($root . '/config_fr.xml'),
            '#<version>\s*<!\[CDATA\[([^\]]*)\]\]>\s*</version>#'
        ),
        'mdfcforps.php::VERSION' => $extract($main, "#public const VERSION = '([^']*)';#"),
        'mdfcforps.php $this->version' => $extract($main, "#\\\$this->version = '([^']*)';#"),
    ];
}

/**
 * Assert every declared version is present and identical, and return it.
 *
 * Exits non-zero on disagreement: a mismatch means PrestaShop will either not offer
 * the upgrade at all, or offer one that never completes.
 *
 * @param array<string, string> $versions
 */
function mdf_assert_consistent(array $versions): string
{
    $missing = array_keys(array_filter($versions, static function (string $v): bool {
        return $v === '';
    }));

    if (!empty($missing)) {
        fwrite(STDERR, 'Could not read the version from: ' . implode(', ', $missing) . "\n");
        exit(1);
    }

    $unique = array_unique(array_values($versions));

    if (count($unique) !== 1) {
        fwrite(STDERR, "Version strings disagree:\n");
        foreach ($versions as $label => $version) {
            fwrite(STDERR, sprintf("  %-32s %s\n", $label, $version));
        }
        fwrite(STDERR, "All four must match or PrestaShop will not offer the upgrade.\n");
        exit(1);
    }

    return (string) $unique[0];
}

/**
 * Increment one component of a semantic version.
 */
function mdf_bump(string $version, string $flag): string
{
    $parts = array_map('intval', explode('.', $version) + [0, 0, 0]);

    if ($flag === '--major') {
        return sprintf('%d.0.0', $parts[0] + 1);
    }

    if ($flag === '--minor') {
        return sprintf('%d.%d.0', $parts[0], $parts[1] + 1);
    }

    return sprintf('%d.%d.%d', $parts[0], $parts[1], $parts[2] + 1);
}
