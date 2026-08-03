<?php
/**
 * Verify the release is internally consistent. Run before tagging.
 *
 * Catches the two mistakes that ship a broken update to every partner at once:
 *  - the four version strings drifting apart, so PrestaShop never offers the upgrade;
 *  - a release that changed routes/services but has no upgrade script, so the
 *    compiled Symfony container stays stale and the new routes 404.
 *
 * Usage: php tools/check-version.php
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/version-lib.php';

$root = dirname(__DIR__);
$versions = mdf_read_versions($root);
$version = mdf_assert_consistent($versions);

echo "Version: {$version}\n";
foreach ($versions as $label => $value) {
    printf("  %-32s %s\n", $label, $value);
}

$failures = [];

$upgradeFile = $root . '/upgrade/upgrade-' . $version . '.php';
if (!file_exists($upgradeFile)) {
    // Not always required — a release with no schema, route, service or translation
    // change legitimately has none — so this is a prompt to confirm, not a hard error
    // about a file that must exist.
    echo "\nWARNING: upgrade/upgrade-{$version}.php does not exist.\n";
    echo "         Required if this release touched config/routes.yml, config/services.yml,\n";
    echo "         the database schema, or the translations.\n";
} else {
    $function = 'upgrade_module_' . str_replace('.', '_', $version);
    $contents = (string) file_get_contents($upgradeFile);

    if (strpos($contents, 'function ' . $function) === false) {
        $failures[] = "upgrade/upgrade-{$version}.php does not define {$function}().";
    }

    if (strpos($contents, 'TODO') !== false) {
        $failures[] = "upgrade/upgrade-{$version}.php still contains a TODO placeholder.";
    }
}

// The tag is the release trigger, so a tag that already exists means this version
// was published — bumping is required before another release can be cut.
exec('git -C ' . escapeshellarg($root) . ' tag --list ' . escapeshellarg($version), $tags, $status);
if ($status === 0 && !empty(array_filter($tags))) {
    $failures[] = "Git tag {$version} already exists — bump the version before releasing again.";
}

if (!empty($failures)) {
    echo "\n";
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "\nOK — ready to tag {$version}.\n";
