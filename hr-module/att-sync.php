<?php
/**
 * att-sync.php — DNC Chits HR Attendance Sync Agent
 *
 * Reads punch records from eTimeTrackLite SQL Server (etimetracklite1)
 * and POSTs them to the receiver (Phase 1: receiver.php / Phase 2: Laravel API).
 *
 * Usage:
 *   c:\php\php.exe att-sync.php           ← live mode (default, for Task Scheduler)
 *   c:\php\php.exe att-sync.php live      ← same as above
 *   c:\php\php.exe att-sync.php history   ← one-time historical pull (all tables 2018→now)
 *
 * Files created in same folder as this script:
 *   state.json      — tracks last synced DeviceLogId per table (enables resume)
 *   att-sync.log    — activity log
 */

// ─── CONFIG — edit these before running ───────────────────────────────────────

define('DB_HOST',     'localhost');
define('DB_PORT',     1433);
define('DB_NAME',     'etimetracklite1');
define('DB_USER',     'dncerpreader');
define('DB_PASS',     'AttSync@2026!');

// Phase 1: local receiver (run receiver.php on same machine: php -S 0.0.0.0:8080 receiver.php)
define('API_URL',     'http://35.207.250.39/receiver.php');
define('API_KEY',     'dnc-att-sync-2026');

// Phase 2 (switch these after verification):
// define('API_URL',  'https://yourdomain.com/api/hr/attendance/sync');
// define('API_KEY',  '<HR_ATT_SYNC_KEY from .env>');

// Records per POST — keep at 200 (each call takes < 3s, no timeout risk)
define('BATCH_SIZE',  200);

// History mode: first year/month to start from
define('HISTORY_FROM_YEAR',  2018);
define('HISTORY_FROM_MONTH', 1);

// Pause between batches in history mode (milliseconds) — prevents overwhelming receiver
define('HISTORY_BATCH_SLEEP_MS', 300);

define('STATE_FILE',  __DIR__ . DIRECTORY_SEPARATOR . 'state.json');
define('LOG_FILE',    __DIR__ . DIRECTORY_SEPARATOR . 'att-sync.log');

// ─── END CONFIG ───────────────────────────────────────────────────────────────

// ── Helpers ───────────────────────────────────────────────────────────────────

function logMsg(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

function loadState(): array
{
    if (!file_exists(STATE_FILE)) return [];
    $d = json_decode(file_get_contents(STATE_FILE), true);
    return is_array($d) ? $d : [];
}

function saveState(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

function connectDb(): PDO
{
    $dsn = sprintf(
        'sqlsrv:Server=%s,%d;Database=%s;TrustServerCertificate=1',
        DB_HOST, DB_PORT, DB_NAME
    );
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 30,
    ]);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_CATALOG = ? AND TABLE_NAME = ?"
    );
    $stmt->execute([DB_NAME, $table]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Fetch up to BATCH_SIZE records from a monthly table where DeviceLogId > $afterId.
 * Returns array of rows, or empty array if table doesn't exist.
 */
function fetchBatch(PDO $pdo, string $table, int $afterId): array
{
    if (!tableExists($pdo, $table)) {
        return [];
    }
    // Direction column is empty in eTimeTrackLite — actual in/out value is stored in C1
    $sql = "SELECT DeviceLogId, LogDate, UserId, C1 AS Direction
            FROM [{$table}]
            WHERE DeviceLogId > :afterId
              AND C1 IN ('in', 'out')
              AND UserId IS NOT NULL
              AND LTRIM(RTRIM(CAST(UserId AS NVARCHAR(50)))) != ''
            ORDER BY DeviceLogId
            OFFSET 0 ROWS FETCH NEXT :batchSize ROWS ONLY";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':afterId',    $afterId,    PDO::PARAM_INT);
    $stmt->bindValue(':batchSize',  BATCH_SIZE,  PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Format raw DB rows into the payload shape receiver/Laravel expects.
 */
function formatRecords(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        // sqlsrv driver returns LogDate as a PHP DateTime object
        if ($r['LogDate'] instanceof DateTime) {
            $logDate = $r['LogDate']->format('Y-m-d H:i:s');
        } else {
            $logDate = date('Y-m-d H:i:s', strtotime((string)$r['LogDate']));
        }
        $out[] = [
            'device_log_id' => (int)$r['DeviceLogId'],
            'log_date'      => $logDate,
            'employee_code' => trim((string)$r['UserId']),
            'direction'     => strtolower(trim((string)$r['Direction'])),
        ];
    }
    return $out;
}

/**
 * POST one batch to the receiver/API. Returns response array.
 * Throws RuntimeException on failure.
 */
function postBatch(string $table, array $records): array
{
    $payload = json_encode(['source_table' => $table, 'records' => $records]);

    $ch = curl_init(API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Att-Sync-Key: ' . API_KEY,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)        throw new RuntimeException("cURL error: {$curlErr}");
    if ($httpCode !== 200) throw new RuntimeException("API HTTP {$httpCode}: {$response}");

    return json_decode($response, true) ?? [];
}

/**
 * Returns the table name for a given month/year.
 * eTimeTrackLite uses: DeviceLogs_6_2026 (no zero-padding on month)
 */
function tableName(int $month, int $year): string
{
    return "DeviceLogs_{$month}_{$year}";
}

/**
 * Returns ordered list of all monthly table names from HISTORY_FROM to now.
 */
function allTableNames(): array
{
    $tables = [];
    $curYear  = (int)date('Y');
    $curMonth = (int)date('n');

    for ($y = HISTORY_FROM_YEAR; $y <= $curYear; $y++) {
        $startM = ($y === HISTORY_FROM_YEAR) ? HISTORY_FROM_MONTH : 1;
        $endM   = ($y === $curYear)          ? $curMonth          : 12;
        for ($m = $startM; $m <= $endM; $m++) {
            $tables[] = tableName($m, $y);
        }
    }
    return $tables;
}

// ── Sync one table fully (all pending batches) ────────────────────────────────

function syncTable(PDO $pdo, string $table, array &$state): void
{
    $lastId  = (int)($state[$table] ?? 0);
    $batches = 0;
    $total   = 0;

    while (true) {
        $rows = fetchBatch($pdo, $table, $lastId);

        if (empty($rows)) {
            if ($batches === 0) {
                logMsg("  {$table}: no new records (last id={$lastId})");
            }
            break;
        }

        $records = formatRecords($rows);
        $result  = postBatch($table, $records);
        $maxId   = max(array_column($rows, 'DeviceLogId'));

        // Advance state only after successful POST
        $state[$table] = $maxId;
        saveState($state);

        $lastId  = $maxId;
        $batches++;
        $total  += count($rows);

        $ins  = $result['inserted']   ?? '?';
        $dup  = $result['duplicates'] ?? '?';
        logMsg("  {$table} batch#{$batches}: sent=" . count($rows) .
               "  inserted={$ins}  dup={$dup}  lastId={$lastId}");

        // If we got fewer rows than batch size, this table is fully synced
        if (count($rows) < BATCH_SIZE) {
            break;
        }

        // Pause between batches in history mode to avoid overwhelming the receiver
        if (HISTORY_BATCH_SLEEP_MS > 0) {
            usleep(HISTORY_BATCH_SLEEP_MS * 1000);
        }
    }

    if ($total > 0) {
        logMsg("  {$table}: DONE — {$total} records in {$batches} batches");
    }
}

// ── MAIN ──────────────────────────────────────────────────────────────────────

$mode = strtolower(trim($argv[1] ?? 'live'));
if (!in_array($mode, ['live', 'history'])) {
    echo "Usage: php att-sync.php [live|history]\n";
    exit(1);
}

logMsg("=== att-sync START mode={$mode} ===");

try {
    $pdo   = connectDb();
    $state = loadState();

    if ($mode === 'live') {
        // ── Live mode: only current month ─────────────────────────────────────
        $table = tableName((int)date('n'), (int)date('Y'));
        logMsg("Live mode → {$table}");
        syncTable($pdo, $table, $state);

    } else {
        // ── History mode: all tables from 2018 to now ─────────────────────────
        $tables = allTableNames();
        logMsg("History mode → " . count($tables) . " tables to process");

        foreach ($tables as $i => $table) {
            $progress = ($i + 1) . '/' . count($tables);
            logMsg("[{$progress}] {$table}");

            if (!tableExists($pdo, $table)) {
                logMsg("  → table does not exist, skipping");
                continue;
            }

            syncTable($pdo, $table, $state);
        }

        logMsg("History mode complete.");
    }

} catch (Exception $e) {
    logMsg('ERROR: ' . $e->getMessage());
    // State was saved after each successful batch — next run resumes from last good point
    exit(1);
}

logMsg("=== att-sync END ===");
exit(0);
