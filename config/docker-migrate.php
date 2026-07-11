<?php
/**
 * Pinakes Docker migration runner.
 *
 * Runs on every container boot (after the install step). Makes `docker compose
 * pull && up -d` a COMPLETE upgrade path: until this existed, pulling a newer
 * image shipped new code but never applied pending DB migrations — only the
 * in-app updater ran them, so image-pull upgrades silently accumulated schema
 * drift (e.g. 0.7.30 → 0.7.31 without `libri.search_index`).
 *
 * Zero reimplementation: it drives the REAL \App\Support\Updater::runMigrations()
 * from the app codebase, so file selection (version_compare window), statement
 * splitting (DELIMITER-aware), the ignorable-error idempotency list, the
 * `migrations` bookkeeping table and the trigger re-apply are all byte-identical
 * to what the in-app updater does.
 *
 * Schema-version tracking: system_settings ('system', 'docker_schema_version').
 * The DB is the only storage that reliably survives container recreation AND
 * volume-less setups, and it travels with dump/restore.
 *
 * from-version resolution (first match wins):
 *   1. PINAKES_MIGRATE_FROM env override (operator escape hatch);
 *   2. the stamp written by a previous run of this script (or by
 *      headless-install.php at fresh-install time);
 *   3. newest version recorded in the `migrations` table (in-app update ran);
 *   4. newest successful to_version in `update_logs` (in-app update ran);
 *   5. fallback '0.7.21': the first published Docker image was 0.7.22, and
 *      every core migration after 0.7.21 (0.7.25-rc.1, 0.7.26, 0.7.31) is
 *      guarded/idempotent, so re-applying them on a schema that already has
 *      them is a no-op. This heals legacy image-pull installs automatically.
 *      If you imported a PRE-0.7.22 database dump into Docker, set
 *      PINAKES_MIGRATE_FROM to its real version once.
 *
 * Failure policy: log loudly and let the container boot anyway (a library
 * locked out entirely is worse than one page misbehaving). Set
 * PINAKES_MIGRATE_STRICT=1 to make a failed migration abort the boot.
 *
 * Exit codes: 0 = up to date / migrated / non-strict failure; 1 = hard failure
 * (strict mode or cannot reach DB / read version.json).
 */

declare(strict_types=1);

$baseDir = '/var/www/html';

function out(string $msg): void { fwrite(STDOUT, '[docker-migrate] ' . $msg . "\n"); }
function warn(string $msg): void { fwrite(STDERR, '[docker-migrate] WARNING: ' . $msg . "\n"); }

$strict = in_array(strtolower((string) getenv('PINAKES_MIGRATE_STRICT')), ['1', 'true', 'yes', 'on'], true);

/**
 * Hard failure. The boot must NEVER be blocked by this runner unless the
 * operator opted into strict mode: a transient DB outage (DB_SOCKET setups
 * skip the entrypoint's wait loop entirely) or an unreadable version.json
 * would otherwise crash-loop a previously healthy installed instance. The
 * run is simply retried on the next boot.
 */
function bail(string $msg): void
{
    global $strict;
    warn($msg);
    warn('skipping the migration run for this boot; it will be retried on the next one.');
    if ($strict) {
        fwrite(STDERR, "[docker-migrate] ERROR: PINAKES_MIGRATE_STRICT=1 — aborting container start.\n");
        exit(1);
    }
    exit(0);
}

// ── Target version = the image's version.json ─────────────────────────────
$versionFile = $baseDir . '/version.json';
$versionData = is_file($versionFile) ? json_decode((string) file_get_contents($versionFile), true) : null;
$toVersion = is_array($versionData) ? (string) ($versionData['version'] ?? '') : '';
if ($toVersion === '') {
    bail("cannot read app version from {$versionFile}");
}

// ── App bootstrap (autoloader + __() helper used by Updater messages) ─────
require $baseDir . '/vendor/autoload.php';
require $baseDir . '/app/helpers.php';

// ── DB coordinates: prefer the app's own .env (it may be bind-mounted by ──
// the operator with coordinates that differ from the container env), fall
// back to the container environment with the compose defaults.
$envFileVals = [];
$envFilePath = $baseDir . '/.env';
if (is_readable($envFilePath)) {
    foreach (@file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $envFileVals[$k] = $v;
    }
}
$cfg = static function (string $key, string $default) use ($envFileVals): string {
    $v = $envFileVals[$key] ?? '';
    if ($v !== '') {
        return $v;
    }
    $e = getenv($key);
    return ($e !== false && $e !== '') ? $e : $default;
};

$dbHost   = $cfg('DB_HOST', 'db');
$dbPort   = (int) $cfg('DB_PORT', '3306');
$dbName   = $cfg('DB_NAME', 'pinakes');
$dbUser   = $cfg('DB_USER', 'pinakes');
$dbPass   = $cfg('DB_PASS', $cfg('DB_PASSWORD', ''));
$dbSocket = $cfg('DB_SOCKET', '');

mysqli_report(MYSQLI_REPORT_OFF);
$db = $dbSocket !== ''
    ? @new mysqli(null, $dbUser, $dbPass, $dbName, 0, $dbSocket)
    : @new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if ($db->connect_errno) {
    bail('cannot connect to database: ' . $db->connect_error);
}
$db->set_charset('utf8mb4');

// ── Cross-replica mutex: scaled/rolling deployments share one DB — only ───
// one container may run migrations at a time. If another replica holds the
// lock, skip: by the time this replica boots again the stamp is current.
$lockRow = $db->query("SELECT GET_LOCK('pinakes_docker_migrate', 300)");
$gotLock = $lockRow !== false && ($lockRow->fetch_row()[0] ?? '0') === '1';
if (!$gotLock) {
    warn('another container is running migrations (GET_LOCK timeout) — skipping this boot.');
    exit(0);
}

/** Newest version (by version_compare) from a single-column result set. */
function newestVersion(mysqli $db, string $sql): ?string
{
    $res = @$db->query($sql);
    if ($res === false) {
        return null;
    }
    $best = null;
    while ($row = $res->fetch_row()) {
        $v = trim((string) $row[0]);
        if ($v === '') {
            continue;
        }
        if ($best === null || version_compare($v, $best, '>')) {
            $best = $v;
        }
    }
    $res->free();
    return $best;
}

function readStamp(mysqli $db): ?string
{
    $res = @$db->query(
        "SELECT setting_value FROM system_settings
          WHERE category = 'system' AND setting_key = 'docker_schema_version' LIMIT 1"
    );
    if ($res === false) {
        return null;
    }
    $row = $res->fetch_row();
    $res->free();
    $v = $row !== null ? trim((string) $row[0]) : '';
    return $v !== '' ? $v : null;
}

function writeStamp(mysqli $db, string $version): void
{
    $stmt = $db->prepare(
        "INSERT INTO system_settings (category, setting_key, setting_value, description)
         VALUES ('system', 'docker_schema_version', ?, 'Schema version applied by the Docker migration runner')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    if ($stmt === false) {
        warn('could not prepare schema-version stamp: ' . $db->error);
        return;
    }
    $stmt->bind_param('s', $version);
    if (!$stmt->execute()) {
        warn('could not write schema-version stamp: ' . $db->error);
    }
    $stmt->close();
}

// ── Resolve from-version ───────────────────────────────────────────────────
$fromVersion = null;
$fromSource  = '';

$override = trim((string) getenv('PINAKES_MIGRATE_FROM'));
if ($override !== '') {
    $fromVersion = $override;
    $fromSource  = 'PINAKES_MIGRATE_FROM override';
}

if ($fromVersion === null) {
    $stamp = readStamp($db);
    if ($stamp !== null) {
        $fromVersion = $stamp;
        $fromSource  = 'schema-version stamp';
    }
}

if ($fromVersion === null) {
    $v = newestVersion($db, 'SELECT version FROM migrations');
    if ($v !== null) {
        $fromVersion = $v;
        $fromSource  = 'migrations table';
    }
}

if ($fromVersion === null) {
    // The app's Updater writes status='completed' (ENUM also allows
    // 'started'/'failed'/'rolled_back'); 'success' kept for belt-and-braces.
    $v = newestVersion($db, "SELECT to_version FROM update_logs WHERE status IN ('completed', 'success')");
    if ($v !== null) {
        $fromVersion = $v;
        $fromSource  = 'update_logs table';
    }
}

if ($fromVersion === null) {
    // First published Docker image was 0.7.22; every migration after 0.7.21 is
    // idempotent, so this baseline heals legacy image-pull installs safely.
    $fromVersion = '0.7.21';
    $fromSource  = 'legacy baseline (no recorded schema version)';
    out('no recorded schema version — assuming >= 0.7.22 (first Docker image).');
    out('If this database was imported from a pre-0.7.22 install, re-run once with');
    out('PINAKES_MIGRATE_FROM=<that version> to apply the older migrations too.');
}

out("schema version: {$fromVersion} ({$fromSource}); image version: {$toVersion}");

if (version_compare($fromVersion, $toVersion, '>=')) {
    if (version_compare($fromVersion, $toVersion, '>')) {
        warn("recorded schema version {$fromVersion} is NEWER than the image ({$toVersion}) — downgrade detected, leaving the schema alone.");
    } else {
        out('schema is up to date — nothing to do.');
    }
    exit(0);
}

// ── Run the REAL migration runner ──────────────────────────────────────────
out("applying pending migrations ({$fromVersion} → {$toVersion})…");
try {
    $updater = new \App\Support\Updater($db);
    $result = $updater->runMigrations($fromVersion, $toVersion);
} catch (\Throwable $e) {
    $result = ['success' => false, 'executed' => [], 'error' => $e->getMessage()];
}

if (!empty($result['executed'])) {
    foreach ($result['executed'] as $file) {
        out("  applied: {$file}");
    }
} else {
    out('  no migration files in this version window.');
}

if (!($result['success'] ?? false)) {
    $err = (string) ($result['error'] ?? 'unknown error');
    warn('migration run FAILED: ' . $err);
    warn('the schema version stamp was NOT advanced; the run will be retried on the next boot.');
    warn('fix the underlying problem, or re-run manually:');
    warn('  docker exec <app-container> php /usr/local/lib/pinakes/docker-migrate.php');
    if ($strict) {
        fwrite(STDERR, "[docker-migrate] ERROR: PINAKES_MIGRATE_STRICT=1 — aborting container start.\n");
        exit(1);
    }
    exit(0);
}

writeStamp($db, $toVersion);
$db->query("SELECT RELEASE_LOCK('pinakes_docker_migrate')");
out("✓ schema is now at {$toVersion}.");
exit(0);
