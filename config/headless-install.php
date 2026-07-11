<?php
/**
 * Pinakes headless installer for Docker.
 *
 * Drives the REAL installer (installer/classes/Installer.php) so there is zero
 * drift from the canonical wizard — we only orchestrate its public methods,
 * we never re-implement schema/seed SQL.
 *
 * Behaviour:
 *   - Idempotent: exits 0 immediately if already installed (.installed marker).
 *   - Creates the database if missing (the Installer connects WITH a dbname,
 *     so the DB must exist first).
 *   - Imports schema + locale data + triggers + optimisation indexes, seeds
 *     default settings, installs bundled plugins.
 *   - If ADMIN_EMAIL and ADMIN_PASSWORD are provided -> creates the admin and
 *     writes the .installed lock (fully headless, no wizard).
 *   - Otherwise -> leaves the DB pre-filled but does NOT lock, so the operator
 *     lands in the web wizard with only the admin-user step remaining.
 *
 * Exit codes: 0 = installed or already-installed or wizard-fallback prepared;
 *             1 = hard failure.
 */

declare(strict_types=1);

$baseDir = '/var/www/html';

function out(string $msg): void { fwrite(STDOUT, '[headless-install] ' . $msg . "\n"); }
function fail(string $msg): void { fwrite(STDERR, '[headless-install] ERROR: ' . $msg . "\n"); exit(1); }

/**
 * Record the schema version a fresh install starts at, so docker-migrate.php
 * (entrypoint step 4b) knows exactly which migrations are pending after a
 * future image-pull upgrade instead of falling back to its legacy baseline.
 * Best-effort: a missing stamp only costs a few idempotent re-runs later.
 */
function stampSchemaVersion(string $baseDir): void
{
    try {
        $versionData = json_decode((string) @file_get_contents($baseDir . '/version.json'), true);
        $version = is_array($versionData) ? (string) ($versionData['version'] ?? '') : '';
        if ($version === '') {
            return;
        }
        $dbSocket = getenv('DB_SOCKET') ?: '';
        $dsn = ($dbSocket !== ''
            ? "mysql:unix_socket={$dbSocket}"
            : 'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (int) (getenv('DB_PORT') ?: 3306))
            . ';dbname=' . (getenv('DB_NAME') ?: 'pinakes') . ';charset=utf8mb4';
        $dbh = new PDO($dsn, getenv('DB_USER') ?: 'pinakes', getenv('DB_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $dbh->prepare(
            "INSERT INTO system_settings (category, setting_key, setting_value, description)
             VALUES ('system', 'docker_schema_version', ?, 'Schema version applied by the Docker migration runner')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$version]);
        out("Recorded schema version {$version} for the Docker migration runner.");
    } catch (\Throwable $e) {
        out('  schema-version stamp skipped (non-fatal): ' . $e->getMessage());
    }
}

require $baseDir . '/installer/classes/Installer.php';

$installer = new Installer($baseDir);

// 1) Idempotency guard.
if ($installer->isInstalled()) {
    out('Already installed (.installed present) — skipping.');
    exit(0);
}

// 2) Read DB config from the environment (the entrypoint already wrote .env).
$dbHost   = getenv('DB_HOST') ?: 'db';
$dbPort   = (int)(getenv('DB_PORT') ?: 3306);
$dbName   = getenv('DB_NAME') ?: 'pinakes';
$dbUser   = getenv('DB_USER') ?: 'pinakes';
$dbPass   = getenv('DB_PASS') ?: '';
$dbSocket = getenv('DB_SOCKET') ?: '';

// 3) Create the database if it doesn't exist (connect WITHOUT a dbname).
try {
    if ($dbSocket !== '') {
        $dsn = "mysql:unix_socket={$dbSocket};charset=utf8mb4";
    } else {
        $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
    }
    $root = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec(
        "CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $dbName) . "` " .
        "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    out("Database `{$dbName}` ready.");

    // Idempotency for container recreate / image upgrade: the .installed marker
    // lives in the (ephemeral) container layer, not a volume, so a normal
    // `docker compose up` after a `down` loses it while the db_data volume keeps
    // the database. Without this guard the re-run would reach createAdminUser()
    // and die on the utenti.email UNIQUE key (schema/data imports are
    // duplicate-tolerant, the admin INSERT is not). Detect an existing install
    // from the database itself: if utenti has rows, just re-write the lock and
    // skip the whole import.
    $alreadyInstalled = false;
    try {
        $hasUtenti = (int) $root->query(
            "SELECT COUNT(*) FROM information_schema.tables " .
            "WHERE table_schema = " . $root->quote($dbName) . " AND table_name = 'utenti'"
        )->fetchColumn();
        if ($hasUtenti > 0) {
            $appDsn = ($dbSocket !== ''
                ? "mysql:unix_socket={$dbSocket}"
                : "mysql:host={$dbHost};port={$dbPort}") . ";dbname={$dbName};charset=utf8mb4";
            $dbh = new PDO($appDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $alreadyInstalled = ((int) $dbh->query("SELECT COUNT(*) FROM utenti")->fetchColumn()) > 0;
            $dbh = null;
        }
    } catch (\Throwable $e) {
        // Inconclusive -> treat as not installed and let the canonical (and
        // duplicate-tolerant) import run.
    }
    $root = null;

    if ($alreadyInstalled) {
        out('Existing install detected in the database (utenti populated) —');
        out('re-locking and skipping import (container recreate / upgrade).');
        // The lock write MUST succeed: without .installed the vhost redirects
        // the whole (installed!) site to the wizard. Fail the boot loudly
        // instead — a disk-full container must not masquerade as uninstalled.
        if (!$installer->createLockFile()) {
            fail('Could not re-write the .installed lock (disk full / permissions?) — refusing to start.');
        }
        out('✓ Re-locked existing install.');
        exit(0);
    }
} catch (\Throwable $e) {
    fail('Could not create/verify database: ' . $e->getMessage());
}

// 4) Wizard fallback: without admin credentials the CANONICAL web wizard must
//    run the whole flow itself — and its database step refuses a non-empty DB,
//    so pre-importing schema/data here would wedge the wizard at step 2.
//    Leave the (created, empty) database alone; the vhost redirects every
//    request to /installer/ until the wizard writes the .installed lock.
$adminEmail = getenv('ADMIN_EMAIL') ?: '';
$adminPass  = getenv('ADMIN_PASSWORD') ?: '';
if ($adminEmail === '' || $adminPass === '') {
    out('ADMIN_EMAIL/ADMIN_PASSWORD not set — wizard mode: leaving the database');
    out('empty for the web wizard. Open the site and complete the install at /installer/.');
    exit(0);
}

// 5) Headless: load .env into the Installer's config and run the canonical steps.
try {
    $installer->loadEnvConfig();

    out('Importing schema…');
    $installer->importSchema();

    out('Importing locale data…');
    $installer->importData();

    try {
        out('Importing triggers…');
        $installer->importTriggers();
        foreach ($installer->getTriggerWarnings() as $w) {
            out('  trigger warning (non-fatal): ' . $w);
        }
    } catch (\Throwable $e) {
        out('  triggers skipped (non-fatal): ' . $e->getMessage());
    }

    try {
        out('Applying optimisation indexes…');
        $installer->importOptimizationIndexes();
    } catch (\Throwable $e) {
        out('  indexes skipped (non-fatal): ' . $e->getMessage());
    }

    out('Populating default settings…');
    $installer->populateDefaultSettings();

    out('Installing bundled plugins…');
    try {
        $installer->installPluginsFromZip();
    } catch (\Throwable $e) {
        out('  plugin install warning (non-fatal): ' . $e->getMessage());
    }
} catch (\Throwable $e) {
    fail('Install step failed: ' . $e->getMessage());
}

// 6) Admin user + lock (credentials guaranteed by the wizard-mode early exit).
$adminName = getenv('ADMIN_NAME') ?: 'Admin';
$adminSurn = getenv('ADMIN_SURNAME') ?: 'User';

try {
    out("Creating admin user {$adminEmail}…");
    $installer->createAdminUser($adminName, $adminSurn, $adminEmail, $adminPass);
} catch (\Throwable $e) {
    // Defence in depth: the early DB-already-installed guard normally
    // prevents reaching here on a re-run, but if the admin email already
    // exists (e.g. a half-finished prior run), treat a duplicate-key as
    // success rather than wedging the container in an unhealthy loop.
    $msg = $e->getMessage();
    if (stripos($msg, 'Duplicate entry') !== false || strpos($msg, '23000') !== false) {
        out('  admin already exists — keeping the existing one.');
    } else {
        fail('Admin creation failed: ' . $msg);
    }
}
if (!$installer->createLockFile()) {
    fail('Could not write .installed lock file.');
}
stampSchemaVersion($baseDir);
out('✓ Headless install complete — Pinakes is ready (no wizard needed).');
exit(0);
