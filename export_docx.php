<?php
/**
 * export_docx.php — Pickledraw Word Document Export
 *
 * Reads draw data from the PHP session, serialises it to JSON,
 * pipes it through generate_docx.js (Node.js / docx library),
 * and streams the resulting .docx to the browser.
 */
session_start();

$teams = $_SESSION['teams'] ?? [];
$draws = $_SESSION['draws'] ?? [];

if (empty($draws)) {
    header('Location: index.php');
    exit;
}

// ── Build the payload ─────────────────────────────────────────────────────────
$payload = json_encode([
    'title'       => 'Pickledraw Tournament Draw',
    'generatedAt' => date('c'),
    'teams'       => array_values($teams),
    'draws'       => $draws,
], JSON_UNESCAPED_UNICODE);

// ── Locate the generator script ───────────────────────────────────────────────
$scriptPath = __DIR__ . '/js/generate_docx.js';

if (!file_exists($scriptPath)) {
    http_response_code(500);
    echo 'Error: generate_docx.js not found at ' . htmlspecialchars($scriptPath);
    exit;
}

// ── Find node binary ──────────────────────────────────────────────────────────
$nodeBin = trim(shell_exec('which node 2>/dev/null') ?: shell_exec('which node18 2>/dev/null') ?: '');

if (empty($nodeBin)) {
    http_response_code(500);
    echo 'Error: Node.js is not available on this server. Please install Node.js to use Word export.';
    exit;
}

// ── Run the generator ─────────────────────────────────────────────────────────
$descriptors = [
    0 => ['pipe', 'r'],  // stdin  — we write JSON here
    1 => ['pipe', 'w'],  // stdout — we read .docx bytes from here
    2 => ['pipe', 'w'],  // stderr — capture errors
];

// Set NODE_PATH so the script can find the globally-installed docx module
$nodeModulePath = trim(shell_exec($nodeBin . ' -e "console.log(require(\'path\').dirname(process.execPath) + \'/../lib/node_modules\')" 2>/dev/null') ?: '');
$env = array_merge($_ENV, [
    'NODE_PATH' => $nodeModulePath ?: '/usr/lib/node_modules:/home/claude/.npm-global/lib/node_modules',
]);

$process = proc_open(
    escapeshellarg($nodeBin) . ' ' . escapeshellarg($scriptPath),
    $descriptors,
    $pipes,
    null,
    $env
);

if (!is_resource($process)) {
    http_response_code(500);
    echo 'Error: Could not start Node.js process.';
    exit;
}

// Write JSON payload to stdin and close
fwrite($pipes[0], $payload);
fclose($pipes[0]);

// Read all output and errors
$docxBytes = stream_get_contents($pipes[1]);
$errorOut  = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);

if ($exitCode !== 0 || empty($docxBytes)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "Word export failed (exit code $exitCode).\n\n";
    echo "Error output:\n$errorOut\n";
    echo "\nMake sure Node.js and the 'docx' npm package are installed:\n";
    echo "  npm install -g docx\n";
    exit;
}

// ── Stream .docx to browser ───────────────────────────────────────────────────
$filename = 'pickledraw_draw_' . date('Ymd_His') . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($docxBytes));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

echo $docxBytes;
exit;
