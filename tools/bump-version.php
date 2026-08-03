<?php
/**
 * Bump the module version, everywhere it lives.
 *
 * The version appears in FOUR places across THREE files, and PrestaShop only offers
 * an upgrade when they all agree. A partial bump is the worst outcome available: with
 * config.xml at the new version and $this->version at the old one, PrestaShop offers
 * an Upgrade that immediately re-offers itself. So every replacement below is anchored,
 * counted, and verified — the script exits non-zero rather than leaving a half-bump.
 *
 * Usage:
 *   php tools/bump-version.php 1.4.1
 *   php tools/bump-version.php --patch | --minor | --major
 *
 *   --dry-run      show what would change, write nothing
 *   --no-upgrade   do not scaffold upgrade/upgrade-X.Y.Z.php
 *   --tag          create the git tag as well (OFF by default, see below)
 *
 * Tagging is off by default because .github/workflows/release.yml fires on ANY pushed
 * tag: the tag IS the release. That should be a deliberate step taken after review,
 * not a side effect of editing version strings.
 *
 * Dependency-free on purpose — it must run on a bare checkout with no vendor/.
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

// register_argc_argv is off in some CLI php.ini setups, which would otherwise make
// this fail with an undefined-variable notice instead of a usable message.
if (!isset($argv) || !is_array($argv)) {
    fwrite(STDERR, "Cannot read command-line arguments — enable register_argc_argv in php.ini.\n");
    exit(1);
}

$argvRest = array_slice($argv, 1);

$dryRun = in_array('--dry-run', $argvRest, true);
$noUpgrade = in_array('--no-upgrade', $argvRest, true);
$doTag = in_array('--tag', $argvRest, true);

$positional = array_values(array_filter($argvRest, static function (string $arg): bool {
    return strpos($arg, '--') !== 0;
}));

$current = mdf_read_versions($root);
$currentVersion = mdf_assert_consistent($current);

$bumpFlags = array_intersect(['--patch', '--minor', '--major'], $argvRest);

if (!empty($positional)) {
    $next = $positional[0];
} elseif (!empty($bumpFlags)) {
    $next = mdf_bump($currentVersion, (string) array_values($bumpFlags)[0]);
} else {
    fwrite(STDERR, "Usage: php tools/bump-version.php <version> | --patch | --minor | --major\n");
    fwrite(STDERR, "Current version: {$currentVersion}\n");
    exit(1);
}

if (preg_match('/^\d+\.\d+\.\d+$/', $next) !== 1) {
    fwrite(STDERR, "Invalid version '{$next}'. Expected X.Y.Z.\n");
    exit(1);
}

if (version_compare($next, $currentVersion, '<=')) {
    fwrite(STDERR, "Refusing to bump: {$next} is not newer than {$currentVersion}.\n");
    exit(1);
}

printf("Bumping %s → %s%s\n\n", $currentVersion, $next, $dryRun ? ' (dry run)' : '');

// Each entry is an anchored pattern that must match EXACTLY once. Anchored rather
// than a global search-and-replace so an unrelated "1.3.0" elsewhere in the file
// (a changelog line, a cache-buster) is never touched.
$edits = [
    ['file' => 'config.xml',    'pattern' => '#(<version>\s*<!\[CDATA\[)[^\]]*(\]\]>\s*</version>)#'],
    ['file' => 'config_fr.xml', 'pattern' => '#(<version>\s*<!\[CDATA\[)[^\]]*(\]\]>\s*</version>)#'],
    ['file' => 'mdfcforps.php', 'pattern' => "#(public const VERSION = ')[^']*(';)#"],
    ['file' => 'mdfcforps.php', 'pattern' => "#(\\\$this->version = ')[^']*(';)#"],
];

$pending = [];

foreach ($edits as $edit) {
    $path = $root . '/' . $edit['file'];
    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "Cannot read {$edit['file']}.\n");
        exit(1);
    }

    $source = $pending[$path] ?? $contents;
    $count = 0;
    $updated = preg_replace($edit['pattern'], '${1}' . $next . '${2}', $source, -1, $count);

    if ($updated === null || $count !== 1) {
        fwrite(STDERR, sprintf(
            "Pattern matched %d time(s) in %s (expected exactly 1): %s\n",
            $count,
            $edit['file'],
            $edit['pattern']
        ));
        fwrite(STDERR, "Nothing was written. Fix the file or the pattern and re-run.\n");
        exit(1);
    }

    $pending[$path] = $updated;
    printf("  %-16s %s\n", $edit['file'], 'ok');
}

if ($dryRun) {
    echo "\nDry run — no files written.\n";
    exit(0);
}

foreach ($pending as $path => $contents) {
    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "Failed to write {$path}.\n");
        exit(1);
    }
}

// Re-read from disk: the whole point of this script is that all four agree, so it
// proves it rather than assuming the writes landed.
$after = mdf_read_versions($root);
$verified = mdf_assert_consistent($after);

if ($verified !== $next) {
    fwrite(STDERR, "Verification failed: files now report '{$verified}', expected '{$next}'.\n");
    exit(1);
}

echo "\nAll four version strings now read {$next}.\n";

// ---------------------------------------------------------------------------
// Upgrade script scaffold
// ---------------------------------------------------------------------------

$upgradeFile = $root . '/upgrade/upgrade-' . $next . '.php';

if (!$noUpgrade && !file_exists($upgradeFile)) {
    $function = 'upgrade_module_' . str_replace('.', '_', $next);

    $template = <<<PHP
<?php
/**
 * Upgrade script for v{$next}.
 *
 * Changes in this version:
 * - TODO: describe what changed, and why this script does what it does.
 *
 * The cache clear below is MANDATORY whenever this release touched config/routes.yml,
 * config/services.yml or the translations: the compiled Symfony container on disk
 * would otherwise no longer match config/, and new routes would 404.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function {$function}(Module \$module): bool
{
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    if (method_exists('Tools', 'clearSmartyCache')) {
        Tools::clearSmartyCache();
    }

    if (method_exists('Tools', 'clearSf2Cache')) {
        Tools::clearSf2Cache('dev');
        Tools::clearSf2Cache('prod');
    }

    return true;
}

PHP;

    if (file_put_contents($upgradeFile, $template) === false) {
        fwrite(STDERR, "Failed to write upgrade/upgrade-{$next}.php.\n");
        exit(1);
    }

    echo "Scaffolded upgrade/upgrade-{$next}.php — fill in the changelog block.\n";
} elseif (file_exists($upgradeFile)) {
    echo "upgrade/upgrade-{$next}.php already exists — left untouched.\n";
}

// ---------------------------------------------------------------------------
// Next steps
// ---------------------------------------------------------------------------

if ($doTag) {
    passthru('git -C ' . escapeshellarg($root) . ' tag ' . escapeshellarg($next), $status);
    if ($status !== 0) {
        fwrite(STDERR, "git tag failed.\n");
        exit(1);
    }
    echo "Created tag {$next}. Push it with: git push origin {$next}\n";
}

echo <<<TXT

Next steps:

  php tools/check-version.php
  git add -A && git commit -m "release: prepare {$next}"

  # Tagging IS releasing — the workflow fires on any pushed tag.
  git tag {$next} && git push && git push origin {$next}

  # ONLY once the GitHub release asset exists, announce it to partner shops
  # by setting these on the Hub (mdf-connectors-hub):
  #   PS_MODULE_LATEST_VERSION={$next}
  #   PS_MODULE_SHA256=<from the mdfcforps.zip.sha256 release asset>
  #   PS_MODULE_SIZE=<byte size of mdfcforps.zip>
  #   PS_MODULE_UPDATE_ENABLED=true
  # Bumping the Hub first hands every partner a 404.

TXT;
