<?php
declare(strict_types=1);
/** Public, read-only static asset manifest. Does not bootstrap the game or use MySQL. */

const DN_CLIENT_PROTOCOL = 1;
const DN_CLIENT_CACHE_TTL = 30;
const DN_CLIENT_FULL_HASH_TTL = 300;
const DN_CLIENT_CACHE_PREFIX = "<?php exit; ?>\n";

function dn_client_mimes(): array {
    return ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
        'webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml','ico'=>'image/x-icon',
        'ogg'=>'audio/ogg','mp3'=>'audio/mpeg','wav'=>'audio/wav','woff'=>'font/woff',
        'woff2'=>'font/woff2','ttf'=>'font/ttf','css'=>'text/css',
        'js'=>'application/javascript','json'=>'application/json'];
}

function dn_client_path_allowed(string $path): bool {
    if ($path === '' || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) return false;
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
    }
    if ($path !== 'favicon.png' && !str_starts_with($path, 'assets/') && !str_starts_with($path, 'html/static/')) return false;
    return isset(dn_client_mimes()[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
}

function dn_client_revision(array $assets): string {
    ksort($assets, SORT_STRING);
    $hash = hash_init('sha256');
    foreach ($assets as $path => $row) {
        hash_update($hash, $path."\0".$row['sha256']."\0".$row['size']."\0".$row['mime']."\n");
    }
    return hash_final($hash);
}

function dn_client_version(string $root): string {
    try {
        if (is_file($root.'/config/version.php')) {
            $version = require $root.'/config/version.php';
            if (is_string($version) && preg_match('/^[A-Za-z0-9.+_-]{1,64}$/', $version)) return $version;
        }
        if (is_file($root.'/config/app.php')) {
            $config = require $root.'/config/app.php';
            if (is_array($config) && is_string($config['asset_version'] ?? null)) return $config['asset_version'];
        }
    } catch (Throwable $ignored) { /* An unavailable release label must not expose server errors. */ }
    return 'unknown';
}

function dn_client_read_cache(string $file): ?array {
    if (is_link($file) || !is_file($file)) return null;
    $raw = @file_get_contents($file);
    if (!is_string($raw) || !str_starts_with($raw, DN_CLIENT_CACHE_PREFIX)) return null;
    $cache = json_decode(substr($raw, strlen(DN_CLIENT_CACHE_PREFIX)), true);
    if (!is_array($cache) || !is_int($cache['scanned_at'] ?? null) || !is_int($cache['full_hashed_at'] ?? null) || !is_array($cache['metadata'] ?? null)) return null;
    $manifest = $cache['manifest'] ?? null;
    if (!is_array($manifest) || ($manifest['protocol'] ?? null) !== DN_CLIENT_PROTOCOL || ($manifest['game'] ?? null) !== 'Digimon NXT'
        || !is_string($manifest['game_version'] ?? null) || !is_array($manifest['assets'] ?? null)
        || !is_string($manifest['revision'] ?? null) || count($manifest['assets']) !== count($cache['metadata'])) return null;
    foreach ($manifest['assets'] as $path => $row) {
        if (!is_string($path) || !dn_client_path_allowed($path) || !is_array($row)
            || !is_string($row['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $row['sha256'])
            || !is_int($row['size'] ?? null) || $row['size'] < 0
            || ($row['mime'] ?? null) !== dn_client_mimes()[strtolower(pathinfo($path, PATHINFO_EXTENSION))]) return null;
        $meta = $cache['metadata'][$path] ?? null;
        if (!is_array($meta) || ($meta['size'] ?? null) !== $row['size'] || !is_int($meta['mtime'] ?? null) || !is_int($meta['ctime'] ?? null)) return null;
    }
    if (!hash_equals(dn_client_revision($manifest['assets']), $manifest['revision'])) return null;
    return $cache;
}

function dn_client_collect(string $root): array {
    $files = [];
    foreach (['assets', 'html/static'] as $relative) {
        $folder = $root.'/'.$relative;
        if (!is_dir($folder) || is_link($folder)) continue;
        // FOLLOW_SYMLINKS is deliberately absent. Links to files are rejected below too.
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink()) continue;
            $real = $entry->getRealPath();
            if ($real === false || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root).'/')) continue;
            $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            if (dn_client_path_allowed($path)) $files[$path] = $entry->getPathname();
        }
    }
    if (is_file($root.'/favicon.png') && !is_link($root.'/favicon.png')) $files['favicon.png'] = $root.'/favicon.png';
    ksort($files, SORT_STRING);
    return $files;
}

function dn_client_scan(string $root, string $version, ?array $previous): array {
    $assets = []; $metadata = []; $mimes = dn_client_mimes();
    // Windows ctime means creation time. A bounded full refresh also detects files
    // deployed with unchanged size/timestamps and edits inside one clock second.
    $fullHash = $previous === null || ($previous['full_hashed_at'] ?? 0) > time()
        || time() - ($previous['full_hashed_at'] ?? 0) >= DN_CLIENT_FULL_HASH_TTL;
    clearstatcache();
    foreach (dn_client_collect($root) as $path => $file) {
        $stat = @stat($file);
        if ($stat === false) continue; // A deployment can remove a file between enumeration and stat.
        $meta = ['size'=>(int)$stat['size'], 'mtime'=>(int)$stat['mtime'], 'ctime'=>(int)$stat['ctime']];
        $prior = $previous['metadata'][$path] ?? null;
        $digest = !$fullHash && $prior === $meta ? ($previous['manifest']['assets'][$path]['sha256'] ?? null) : null;
        if ($digest === null) $digest = @hash_file('sha256', $file);
        if (!is_string($digest)) continue;
        $assets[$path] = ['sha256'=>$digest, 'size'=>$meta['size'], 'mime'=>$mimes[strtolower(pathinfo($path, PATHINFO_EXTENSION))]];
        $metadata[$path] = $meta;
    }
    return ['scanned_at'=>time(), 'full_hashed_at'=>$fullHash ? time() : $previous['full_hashed_at'], 'metadata'=>$metadata, 'manifest'=>[
        'protocol'=>DN_CLIENT_PROTOCOL, 'game'=>'Digimon NXT', 'game_version'=>$version,
        'revision'=>dn_client_revision($assets), 'assets'=>$assets]];
}

function dn_client_cache_directory(string $root): ?string {
    $candidates = [$root.'/storage/client-assets', rtrim(sys_get_temp_dir(), '/\\').'/digimon-nxt-client-assets-'.hash('sha256', $root)];
    foreach ($candidates as $path) {
        if (is_link($path) || is_link(dirname($path))) continue;
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) continue;
        if (is_writable($path)) return $path;
    }
    return null;
}

function dn_client_manifest(string $root): array {
    $version = dn_client_version($root);
    $directory = dn_client_cache_directory($root);
    if ($directory === null) return dn_client_scan($root, $version, null)['manifest'];
    $file = $directory.'/manifest-cache.php';
    $cache = dn_client_read_cache($file);
    if ($cache !== null && $cache['scanned_at'] <= time() && time() - $cache['scanned_at'] < DN_CLIENT_CACHE_TTL
        && $cache['manifest']['game_version'] === $version) return $cache['manifest'];
    $lockPath = $directory.'/manifest.lock';
    $lock = is_link($lockPath) ? false : @fopen($lockPath, 'c');
    if ($lock === false) return dn_client_scan($root, $version, $cache)['manifest'];
    try {
        if (!flock($lock, LOCK_EX)) return dn_client_scan($root, $version, $cache)['manifest'];
        $latest = dn_client_read_cache($file);
        if ($latest !== null && $latest['scanned_at'] <= time() && time() - $latest['scanned_at'] < DN_CLIENT_CACHE_TTL
            && $latest['manifest']['game_version'] === $version) return $latest['manifest'];
        $cache = dn_client_scan($root, $version, $latest ?? $cache);
        $json = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = @tempnam($directory, 'manifest-');
        if ($temporary !== false) {
            @chmod($temporary, 0600);
            $payload = DN_CLIENT_CACHE_PREFIX.$json;
            if (@file_put_contents($temporary, $payload, LOCK_EX) === strlen($payload)) @rename($temporary, $file);
            if (is_file($temporary)) @unlink($temporary);
        }
        return $cache['manifest'];
    } finally {
        @flock($lock, LOCK_UN); fclose($lock);
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD'); http_response_code(405);
    echo '{"error":"Use GET or HEAD for the asset manifest."}'; exit;
}
try {
    $root = realpath(__DIR__);
    if ($root === false) throw new RuntimeException('Unavailable asset root.');
    $manifest = dn_client_manifest($root);
    $etag = '"'.$manifest['revision'].'"';
    header('ETag: '.$etag);
    foreach (explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '') as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '*' || $candidate === $etag || $candidate === 'W/'.$etag) {
            http_response_code(304); exit;
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        // Preserve the dictionary type even on a server with no bundled static files.
        $manifest['assets'] = (object)$manifest['assets'];
        echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    http_response_code(503); header('Retry-After: 30');
    echo '{"error":"Asset manifest is temporarily unavailable. The game can still load assets normally."}';
}
