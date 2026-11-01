<?php
/**
 * Builds the module as PrestaShop installs it:
 *   build/smstunnel/     the module folder, with its Composer autoloader in vendor/
 *   build/smstunnel.zip  the same folder zipped (upload it in Modules > Module Manager > Upload a module,
 *                        or submit it to PrestaShop Addons)
 *
 * Needs PHP with the zip extension and Composer. tools/build.sh runs it in the composer:2 Docker image.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
$root = dirname(__DIR__);
$source = $root . '/smstunnel';
$buildDir = $root . '/build';
$target = $buildDir . '/smstunnel';
$zipFile = $buildDir . '/smstunnel.zip';
$indexTemplate = (string) file_get_contents($root . '/tools/index.php.tpl');

// Never shipped: Composer output of a local install, caches, OS files, PrestaShop's generated config_xx.xml.
$skip = '#(^|/)(vendor|\.php-cs-fixer\.cache|\.phpunit\.result\.cache|composer\.lock|\.DS_Store|Thumbs\.db|config_[a-z]{2}\.xml)$#';

function removeTree($path)
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . '/' . $entry);
        }
    }
    rmdir($path);
}

function copyTree($from, $to, $skip, $relative = '')
{
    if (!is_dir($to)) {
        mkdir($to, 0755, true);
    }
    foreach (scandir($from) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $rel = ltrim($relative . '/' . $entry, '/');
        if (preg_match($skip, $rel)) {
            continue;
        }
        if (is_dir($from . '/' . $entry)) {
            copyTree($from . '/' . $entry, $to . '/' . $entry, $skip, $rel);
        } else {
            copy($from . '/' . $entry, $to . '/' . $entry);
        }
    }
}

function addIndexFiles($dir, $content)
{
    if (!file_exists($dir . '/index.php')) {
        file_put_contents($dir . '/index.php', $content);
    }
    foreach (scandir($dir) as $entry) {
        if ($entry !== '.' && $entry !== '..' && is_dir($dir . '/' . $entry)) {
            addIndexFiles($dir . '/' . $entry, $content);
        }
    }
}

function filesOf($dir, $prefix = '')
{
    $files = [];
    $entries = scandir($dir);
    sort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($dir . '/' . $entry)) {
            $files = array_merge($files, filesOf($dir . '/' . $entry, $prefix . $entry . '/'));
        } else {
            $files[] = $prefix . $entry;
        }
    }

    return $files;
}

removeTree($target);
if (file_exists($zipFile)) {
    unlink($zipFile);
}
copyTree($source, $target, $skip);

passthru('cd ' . escapeshellarg($target) . ' && composer dump-autoload --optimize --no-dev --no-interaction 2>&1', $code);
if ($code !== 0) {
    fwrite(STDERR, "composer dump-autoload failed\n");
    exit(1);
}
addIndexFiles($target, $indexTemplate);

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "cannot create $zipFile\n");
    exit(1);
}
$zip->addEmptyDir('smstunnel');
foreach (filesOf($target) as $file) {
    $zip->addFile($target . '/' . $file, 'smstunnel/' . $file);
}
$zip->close();

$count = count(filesOf($target));
printf("Built %s (%d files, %d bytes)\n", 'build/smstunnel.zip', $count, filesize($zipFile));
