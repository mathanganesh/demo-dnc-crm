<?php
/**
 * receiver.php — DNC Chits HR Attendance Receiver (Verification Phase)
 *
 * Simple standalone PHP file. No framework needed.
 * Receives attendance batches from att-sync.php and saves to log files.
 *
 * Host this on any PHP server temporarily:
 *   Option A: Windows machine itself  → php -S 0.0.0.0:8080 receiver.php
 *   Option B: Any web server with PHP → upload this file
 *
 * API_URL in att-sync.php should point here:
 *   http://localhost:8080/receiver.php  (if running on same Windows machine)
 *   http://your-server.com/receiver.php
 */

define('SYNC_KEY', 'dnc-att-sync-2026');
define('LOG_DIR',  '/var/www/html/att_logs');

// ── Diagnostic: open http://35.207.250.39/receiver.php in browser to check ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'status'     => 'receiver online',
        'log_dir'    => LOG_DIR,
        'dir_exists' => is_dir(LOG_DIR),
        'writable'   => is_writable(LOG_DIR),
        'files'      => is_dir(LOG_DIR) ? scandir(LOG_DIR) : [],
        'php_file'   => __FILE__,
    ], JSON_PRETTY_PRINT);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────

// Show all PHP errors in response (remove after debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Catch fatal errors and return as JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error' => 'PHP Fatal Error',
            'message' => $err['message'],
            'file'    => $err['file'],
            'line'    => $err['line'],
        ]);
    }
});

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => get_class($e),
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit(1);
});

// Validate API key
$incomingKey = $_SERVER['HTTP_X_ATT_SYNC_KEY'] ?? '';
if ($incomingKey !== SYNC_KEY) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Create log directory if not exists
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Parse incoming JSON
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['records'])) {
    http_response_code(422);
    die(json_encode(['error' => 'Invalid payload']));
}

$sourceTable = $data['source_table'] ?? 'unknown';
$records     = $data['records'];
$receivedAt  = date('Y-m-d H:i:s');
$inserted    = 0;
$duplicates  = 0;

// ── 1. Save raw JSON batch (one file per table per day) ─────────────────────
$batchLogFile = LOG_DIR . '/batch_' . $sourceTable . '_' . date('Ymd') . '.log';
$batchLine    = json_encode([
    'received_at'  => $receivedAt,
    'source_table' => $sourceTable,
    'count'        => count($records),
    'records'      => $records,
]) . PHP_EOL;
file_put_contents($batchLogFile, $batchLine, FILE_APPEND);

// ── 2. Save to master CSV (easy to open in Excel) ──────────────────────────
$csvFile    = LOG_DIR . '/attendance_all.csv';
$addHeader  = !file_exists($csvFile);
$csvHandle  = fopen($csvFile, 'a');

if ($addHeader) {
    fputcsv($csvHandle, ['received_at', 'source_table', 'device_log_id', 'log_date', 'employee_code', 'direction']);
}

// Track seen device_log_ids to detect duplicates within this session
$seenFile = LOG_DIR . '/seen_ids.json';
$seen     = file_exists($seenFile) ? json_decode(file_get_contents($seenFile), true) : [];
if (!is_array($seen)) $seen = [];

foreach ($records as $r) {
    $key = $sourceTable . '_' . $r['device_log_id'];
    if (isset($seen[$key])) {
        $duplicates++;
        continue;
    }
    $seen[$key] = 1;
    fputcsv($csvHandle, [
        $receivedAt,
        $sourceTable,
        $r['device_log_id'],
        $r['log_date'],
        $r['employee_code'],
        $r['direction'],
    ]);
    $inserted++;
}
fclose($csvHandle);
file_put_contents($seenFile, json_encode($seen));

// ── 3. Save summary log ────────────────────────────────────────────────────
$summaryFile = LOG_DIR . '/sync_summary.log';
$summaryLine = "[{$receivedAt}] table={$sourceTable}  received=" . count($records) .
               "  inserted={$inserted}  duplicates={$duplicates}" . PHP_EOL;
file_put_contents($summaryFile, $summaryLine, FILE_APPEND);

// ── 4. Respond ────────────────────────────────────────────────────────────
header('Content-Type: application/json');
echo json_encode([
    'status'     => 'ok',
    'received'   => count($records),
    'inserted'   => $inserted,
    'duplicates' => $duplicates,
    'table'      => $sourceTable,
]);
