<?php declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
const EXCLUDED = [
    ".git",
    ".idea",
    "releases",
    "make_release.php",
    "update_version.sh"
];

/**
 * This script helps me to produce a winx86 & linux release zip files and push them to github.
 * Usage Example: `$ php make_release.php v0.0.1`
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
function main(array $argv): int
{
    if (count($argv) === 1) {
        fwrite(STDERR, "Provide a release name/tag" . PHP_EOL);
        return 1;
    }
    if (count($argv) > 2) {
        fwrite(STDERR, "Expected exactly 1 argument" . PHP_EOL);
        return 1;
    }
    // release name
    $rname = $argv[1];
    if ($rname[0] !== 'v')
        $rname = 'v' . $rname;
    echo "Making : $rname" . PHP_EOL;
    echo "optimizing autoloader" . PHP_EOL;
    system("composer install --no-dev", $code);
    $code != 0 && die("exited with code $code");
    system("composer dump-autoload", $code);
    $code != 0 && die("exited with code $code");
    try {
        // filter unwanted entries using git
        $entries = array_diff(scandir('.'), ['.', '..']);
        $entries = array_filter($entries, function ($entry) {
            if ($entry === "vendor") return true;
            if (in_array($entry, EXCLUDED)) return false;
            exec("git check-ignore " . $entry, $o, $code);
            // check-ignore returns 1 if the path is not ignored
            if ($code === 1) return true;
            return false;
        });
        system("box compile", $code);
        $code != 0 && die("exited with code $code");
        $box_conf = json_decode(file_get_contents("box.json"), true);
        if (!file_exists($box_conf["output"])) throw new Exception("phar not found");
        $winx64_target = make_release("standalone-winx64", $rname, $entries + ["bin"]);
//        $winx86_target = make_release("standalone-winx86", $rname, $entries);
//        $linux_target = make_release("linux", $rname, $entries);

        echo "Are you sure you want to push these assets to github?(yes/no): ";
        $handle = fopen("php://stdin", "r");
        $line = strtolower(trim(fgets($handle)));
        if ($line !== 'yes' && $line !== 'y') {
            echo "ABORTING!\n";
            return 2;
        }
        fclose($handle);
        echo "\n";

        // use github cli to create release, generate notes and upload the zip release.
        echo "Pushing: $rname\r\n";
        system("gh release create " . quote($rname) . " --generate-notes " . quote($box_conf["output"]) . " " . quote($winx64_target), $code);
        if ($code !== 0) {
            throw new Exception("Failed to create release with githubCLI, exit code $code");
        }
//        @unlink($winx86_target);
//        @unlink($linux_target);
        @unlink($box_conf["output"]);
        @unlink($winx64_target);
    } catch (Exception $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
        return 1;
    }

    return 0;
}

/**
 * @return string path to zip file.
 * @throws Exception
 */
function make_release(string $name, string $rname, array $entries): string
{
    echo "Generating release : $name" . PHP_EOL;
    $release_container = normalize(dirname(__FILE__), "/releases/scraper-$name-$rname");
    $release_app_src = normalize("$release_container/scraper");
    $release_zip_output = normalize(dirname(__FILE__), "/releases/scraper-$name-$rname.zip");

    if (file_exists($release_container))
        throw new Exception("Release $rname already exists: $release_container");

    if (file_exists($release_zip_output))
        throw new Exception("Release zip already exists: $release_zip_output");

    if (!mkdir(normalize($release_app_src), 0777, true))
        throw new Exception("Failed to create release folder: $release_app_src");

    // try make release or remove the create release folder
    try {
        // copy current version to release.
        foreach ($entries as $entry) {
            if (!copyfs(realpath($entry), normalize($release_app_src, basename($entry)))) {
                throw new Exception("Failed to copy entry: " . $entry);
            }
        }
        // copy stubs
        $stub_dir = normalize("releases/stubs-$name");
        if (!file_exists($stub_dir)) {
            throw new Exception("Stubs folder not found: " . $stub_dir);
        }

        $stubs = array_diff(scandir($stub_dir), ['.', '..', '.gitignore']);
        foreach ($stubs as $stub) {
            if (!copyfs(realpath(normalize($stub_dir, $stub)), normalize($release_container, basename($stub)))) {
                throw new Exception("Failed to copy stub entry: " . $stub);
            }
        }

        // compress the release
        if (!zip_folder($release_container, $release_zip_output, $error)) {
            throw new Exception("could not compress release $release_container: $error");
        } else {
            // remove temp folder.
            rmfs($release_container);
        }
        $target = realpath($release_zip_output);
        if (!$target)
            throw new Exception("$name zip not found");
        return $target;
    } catch (Exception $e) {
        rmfs($release_container);
        @unlink($release_zip_output);
        throw $e;
    }
}

function quote(string $str): string
{
    return "\"" . $str . "\"";
}

/**
 * copy file system entry (recursively)
 */
function copyfs($source, $dest): bool
{
    if (is_link($source)) return symlink(readlink($source), $dest);
    if (is_file($source)) return copy($source, $dest);
    if (!is_dir($dest)) mkdir($dest);
    $dir = dir($source);
    while (false !== $entry = $dir->read()) {
        if ($entry == '.' || $entry == '..') continue;
        if (!copyfs("$source/$entry", "$dest/$entry")) return false;
    }
    $dir->close();
    return true;
}

/**
 * remove file system entry(recursively)
 */
function rmfs($entry): bool
{
    if (is_file($entry)) return @unlink($entry);
    else {
        if (substr($entry, strlen($entry) - 1, 1) != DIRECTORY_SEPARATOR) $entry .= DIRECTORY_SEPARATOR;
        foreach (glob("$entry{,.}*[!.]*", GLOB_MARK | GLOB_BRACE) as $file) if (!rmfs($file)) return false;
        return @rmdir($entry);
    }
}

/** @noinspection PhpComposerExtensionStubsInspection */
function zip_folder($source, $outfile, &$error): bool
{
    $dir = opendir($source);
    if (!$dir) return false;
    $rootPath = realpath($source);
    $zip = new ZipArchive();
    $zip->open($outfile, ZipArchive::CREATE);
    /** @var SplFileInfo[] $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = str_replace('\\', '/', substr($filePath, strlen(dirname($rootPath)) + 1));
            $zip->addFile($filePath, $relativePath);
        }
    }
    try {
        if (!$zip->close()) return false;
    } catch (Exception $e) {
        $error = $e->getMessage();
        return false;
    }
    return true;
}

/**
 * Make path cross platform
 * @author Eboubaker Bekkouche <eboubakkar@gmail.com>
 */
function normalize(string ...$paths): string
{
    $paths = array_map(fn($v) => trim($v, " \\/"), $paths);
    return str_replace(["\\", "/"], DIRECTORY_SEPARATOR, implode("/", $paths));
}

exit(main($argv));
