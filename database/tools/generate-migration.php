<?php
/**
 * Migration Generator
 * LPG Delivery System v2
 *
 * Produces a timestamped, dependency-ordered SQL migration file for a table by
 * reading its exact DDL from the live database. Generating rather than
 * hand-writing guarantees the migration reproduces the real structure (types,
 * defaults, enums, indexes, foreign keys, collation) instead of drifting from it.
 *
 * Usage (from the project root):
 *   php database/tools/generate-migration.php --table=users --purpose="Baseline..."
 *   php database/tools/generate-migration.php --table=orders --alter="ADD COLUMN ..."
 *
 * Options:
 *   --table=NAME     Table to capture (required).
 *   --purpose=TEXT   One-line description written into the file header.
 *   --alter=TEXT     Optional column change appended after the CREATE/ALTER
 *                    statement, for migrations that modify an existing table.
 *   --alter-only     Emit only the ALTER block (no CREATE TABLE).
 *   --dry-run        Print the generated SQL to stdout, write nothing.
 *
 * The output filename embeds the authoring date, time, and table name so a
 * migration can be identified and ordered without opening it.
 */

$config = require __DIR__ . '/../../config/database.php';

// The host's PHP timezone is irrelevant here: migration filenames and the
// "Created" header must use the same clock the application does, or the
// recorded order will not match the order things actually happened in.
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}
date_default_timezone_set(APP_TIMEZONE);

$options = [
    'table'    => null,
    'purpose'  => null,
    'alter'    => null,
    'alter-only' => false,
    'dry-run'  => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $key = $m[1];
        if ($key === 'alter-only') {
            $options[$key] = true;
        } else {
            $options[$key] = isset($m[2]) ? trim($m[2]) : true;
        }
    }
}

if (empty($options['table'])) {
    fwrite(STDERR, "Error: --table=<name> is required.\n");
    exit(1);
}

$table = (string)$options['table'];

// Identifiers cannot be bound as parameters, and quoting with PDO::quote()
// yields a string literal. Validate the name instead so it can be wrapped in
// backticks safely - this also stops a crafted --table= value reaching SHOW.
if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
    fwrite(STDERR, "Error: --table must contain only letters, digits and underscores.\n");
    exit(1);
}
$tableIdent = '`' . $table . '`';
$purpose = (string)($options['purpose'] ?? "Schema migration for the `{$table}` table.");
$alter = $options['alter'] !== null ? (string)$options['alter'] : null;
$alterOnly = (bool)$options['alter-only'];
$dryRun = (bool)$options['dry-run'];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['dbname'], $config['charset']),
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Resolve the exact DDL straight from the server
// ---------------------------------------------------------------------------
// SHOW CREATE TABLE works on both MariaDB and MySQL. The information_schema
// CREATE_TABLE column only exists in MySQL 8.0+, so it is not portable.
$tableStmt = $pdo->query('SHOW FULL TABLES WHERE Table_Type = ' . $pdo->quote('BASE TABLE'));
$exists = false;
foreach ($tableStmt->fetchAll(PDO::FETCH_NUM) as $row) {
    if ($row[0] === $table) { $exists = true; break; }
}
if (!$exists) {
    fwrite(STDERR, "Error: table `{$table}` does not exist in `{$config['dbname']}`.\n");
    exit(1);
}

$createStmt = $pdo->query('SHOW CREATE TABLE ' . $tableIdent);
$createRow = $createStmt->fetch(PDO::FETCH_NUM);
$ddl = (string)$createRow[1];

$metaStmt = $pdo->prepare(
    'SELECT TABLE_COLLATION, ENGINE, AUTO_INCREMENT
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
);
$metaStmt->execute([$config['dbname'], $table]);
$meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// MariaDB reports `CREATE TABLE`; MySQL uses `CREATE TABLE IF NOT EXISTS`
// semantics differently, so normalise the statement keyword ourselves.
$ddl = preg_replace('/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $ddl, 1);

// A production table must not inherit this environment's AUTO_INCREMENT
// watermark - it would silently skip IDs.
$ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl, 1);

// Re-indent the server's single-line body so the closing paren starts a line.
// Anchoring on `) ENGINE=` keeps this from matching the parenthesis that ends a
// decimal or enum column definition earlier in the statement.
$ddl = preg_replace('/\s*\)\s*ENGINE=/', "\n) ENGINE=", $ddl, 1);

$ddl = rtrim(trim($ddl), ';') . ';';

// ---------------------------------------------------------------------------
// Work out which tables this one depends on, so the header can warn the reader
// ---------------------------------------------------------------------------
$fkStmt = $pdo->prepare(
    'SELECT kcu.TABLE_NAME AS child, kcu.REFERENCED_TABLE_NAME AS parent
       FROM information_schema.KEY_COLUMN_USAGE kcu
      WHERE kcu.TABLE_SCHEMA = ?
        AND kcu.TABLE_NAME = ?
        AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
      ORDER BY kcu.REFERENCED_TABLE_NAME'
);
$fkStmt->execute([$config['dbname'], $table]);
$parents = array_values(array_unique(array_column($fkStmt->fetchAll(PDO::FETCH_ASSOC), 'parent')));

$dependsOn = empty($parents)
    ? '(none)'
    : implode(', ', array_map(fn($p) => "`{$p}`", $parents));

// ---------------------------------------------------------------------------
// Emit the file
// ---------------------------------------------------------------------------
$now        = new DateTimeImmutable('now');
$stamp      = $now->format('Y-m-d_His');
$verb       = $alterOnly ? 'alter' : 'create';
$dir        = __DIR__ . '/../migrations';
$purpose    = str_replace("'", "''", $purpose);

// A migration must never sort before one that already exists, otherwise it
// would look already-applied and get skipped. Two problems to avoid:
//
//   1. A burst of tables generated in the same second all collide on one
//      timestamp and then sort alphabetically, so `orders` could land before
//      the `users` and `products` it depends on.
//   2. The clock here may already lag behind migrations created earlier, so
//      "now" alone is not a safe lower bound.
//
// So: never go below the newest timestamp already present in the folder.
// Parse every .sql name in the folder rather than globbing a wildcard
// pattern: the date portion is "YYYY-MM-DD" (10 chars), so an ?-count pattern
// is easy to get wrong and silently matches nothing.
$existing = glob($dir . '/*.sql') ?: [];
$newest = null;
foreach ($existing as $file) {
    if (preg_match('/(\d{4}-\d{2}-\d{2}_\d{6})_/', basename($file), $m)) {
        if ($newest === null || $m[1] > $newest) {
            $newest = $m[1];
        }
    }
}

if ($newest !== null && $stamp <= $newest) {
    $now = DateTimeImmutable::createFromFormat(
        'Y-m-d_His',
        $newest,
        new DateTimeZone(APP_TIMEZONE)
    )->modify('+1 second');
    $stamp = $now->format('Y-m-d_His');
}

$filename = sprintf('%s_%s_%s_table.sql', $stamp, $verb, $table);
$path     = $dir . '/' . $filename;

$humanTime = $now->format('Y-m-d H:i:s T');

$ledger = sprintf(
    "INSERT IGNORE INTO `schema_migrations` (`migration`, `table_name`, `description`)\nVALUES ('%s', '%s', '%s');",
    $filename,
    $table,
    $purpose
);

$body = $alterOnly
    ? "ALTER TABLE `{$table}`\n    {$alter};"
    : $ddl;

$sql = <<<SQL
-- =============================================================================
-- Migration : {$filename}
-- Created   : {$humanTime}
-- Database  : {$config['dbname']}
-- Table     : {$table}
-- Depends on: {$dependsOn}
-- Purpose   : {$purpose}
-- -----------------------------------------------------------------------------
-- HOW TO APPLY
--   mysql -u <user> -p {$config['dbname']} < {$filename}
--   ...or paste the whole file into phpMyAdmin > SQL.
--
-- Apply the files in this folder in filename order; the timestamps sort them
-- into dependency order. Confirm what is already applied with:
--   SELECT * FROM schema_migrations ORDER BY migration;
--
-- Re-running this file is safe: the DDL uses IF NOT EXISTS and the ledger
-- insert uses INSERT IGNORE.
-- =============================================================================


{$body}

{$ledger}

SQL;

if ($dryRun) {
    echo $sql;
    exit(0);
}

if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Error: could not create {$dir}\n");
    exit(1);
}

if (file_put_contents($path, $sql) === false) {
    fwrite(STDERR, "Error: could not write {$path}\n");
    exit(1);
}

echo "Created: database/migrations/{$filename}\n";
echo "Table:   {$table} (engine {$meta['ENGINE']}, collation {$meta['TABLE_COLLATION']})\n";
echo "Depends: {$dependsOn}\n";
