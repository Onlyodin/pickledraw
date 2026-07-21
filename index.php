<?php
session_start();
require_once 'php/DrawEngine.php';
require_once 'php/CSVParser.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function skillBandDisplay(string $band): string {
    return match($band) {
        'band-4p'  => '4.0+',
        'band-35'  => '3.5–3.99',
        'band-30'  => '3.0–3.49',
        'band-25'  => '2.5–2.99',
        'band-u25' => 'Under 2.5',
        default    => $band,
    };
}

function skillBandMidpoint(string $band): float {
    return match($band) {
        'band-4p'  => 4.5,
        'band-35'  => 3.75,
        'band-30'  => 3.25,
        'band-25'  => 2.75,
        'band-u25' => 2.0,
        default    => 3.25,
    };
}

function findPartnerIndexInData(array $parsedData, string $name): ?int {
    if ($name === '') return null;
    foreach ($parsedData as $i => $p) {
        if (strcasecmp($p['name'], $name) === 0) return $i;
    }
    return null;
}

$error      = '';
$teams      = [];
$draws      = [];
$parsedData = null;
$warnings   = [];

// ─────────────────────────────────────────────────────────────────────────────
//  POST HANDLERS
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 1. PARSE CSV ──────────────────────────────────────────────────────────
    if ($action === 'parse') {
        $rawData = '';
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $rawData = file_get_contents($_FILES['csv_file']['tmp_name']);
        } elseif (!empty($_POST['csv_text'])) {
            $rawData = trim($_POST['csv_text']);
        }

        if (empty($rawData)) {
            $error = 'Please upload a CSV file or paste delimited data.';
        } else {
            try {
                $parser     = new CSVParser();
                $parsedData = $parser->parse($rawData, $_POST['delimiter'] ?? 'auto');
                $_SESSION['parsed_data']  = $parsedData;
                $_SESSION['column_map']   = $parser->getLastColumnMap();
                $_SESSION['draws']        = [];
                $_SESSION['teams']        = [];
            } catch (Exception $e) {
                $error = 'Import error: ' . $e->getMessage();
            }
        }
    }

    // ── 2. SAVE MANUAL PARTNER OVERRIDES, DIVISION OVERRIDES & DUPR ─────────
    if ($action === 'save_pairings') {
        $parsedData = $_SESSION['parsed_data'] ?? null;

        if ($parsedData) {
            // Ensure partner2 fields exist on all records (backward compat)
            foreach ($parsedData as &$p) {
                $p['partner2']          ??= null;
                $p['partner2_matched']  ??= false;
                $p['partner2_resolved'] ??= null;
                $p['manual_partner2']   ??= null;
            }
            unset($p);

            // ── Division overrides ─────────────────────────────────────────
            $divisionOverrides = $_POST['player_division'] ?? [];
            foreach ($divisionOverrides as $playerIdx => $divVal) {
                $playerIdx = (int)$playerIdx;
                if (!isset($parsedData[$playerIdx])) continue;
                $divVal = trim($divVal);
                if ($divVal !== '') {
                    $parsedData[$playerIdx]['skill_band']      = $divVal;
                    $parsedData[$playerIdx]['skill']           = skillBandMidpoint($divVal);
                    $parsedData[$playerIdx]['skill_raw']       = skillBandDisplay($divVal);
                    $parsedData[$playerIdx]['division_manual'] = true;
                }
            }

            // ── DUPR overrides ─────────────────────────────────────────────
            $duprValues = $_POST['player_dupr'] ?? [];
            foreach ($duprValues as $playerIdx => $dupr) {
                $playerIdx = (int)$playerIdx;
                if (!isset($parsedData[$playerIdx])) continue;
                $dupr = trim($dupr);
                if ($dupr === '' || is_numeric($dupr)) {
                    $parsedData[$playerIdx]['dupr'] = $dupr === '' ? null : (float)$dupr;
                }
            }

            // ── Partner overrides ──────────────────────────────────────────
            foreach ($parsedData as &$p) {
                if (!$p['partner_matched']) {
                    $p['manual_partner'] = null;
                }
            }
            unset($p);

            $overrides = $_POST['manual_partner'] ?? [];
            foreach ($overrides as $playerIdx => $partnerIdx) {
                $playerIdx = (int)$playerIdx;
                if (!isset($parsedData[$playerIdx])) continue;

                if ($partnerIdx === 'auto' || $partnerIdx === '') {
                    $parsedData[$playerIdx]['manual_partner']   = null;
                    $parsedData[$playerIdx]['partner_matched']  = false;
                    $parsedData[$playerIdx]['partner_resolved'] = null;
                } else {
                    $partnerIdx = (int)$partnerIdx;
                    if (!isset($parsedData[$partnerIdx])) continue;

                    $parsedData[$playerIdx]['manual_partner']   = $partnerIdx;
                    $parsedData[$playerIdx]['partner_matched']  = true;
                    $parsedData[$playerIdx]['partner_resolved'] = $parsedData[$partnerIdx]['name'];

                    $parsedData[$partnerIdx]['manual_partner']   = $playerIdx;
                    $parsedData[$partnerIdx]['partner_matched']  = true;
                    $parsedData[$partnerIdx]['partner_resolved'] = $parsedData[$playerIdx]['name'];
                }
            }

            // ── Partner 2 overrides ────────────────────────────────────────
            $overrides2 = $_POST['manual_partner2'] ?? [];
            foreach ($overrides2 as $playerIdx => $partner2Idx) {
                $playerIdx = (int)$playerIdx;
                if (!isset($parsedData[$playerIdx])) continue;

                if ($partner2Idx === 'auto' || $partner2Idx === '') {
                    $parsedData[$playerIdx]['manual_partner2']   = null;
                    $parsedData[$playerIdx]['partner2_matched']  = false;
                    $parsedData[$playerIdx]['partner2_resolved'] = null;
                } else {
                    $partner2Idx = (int)$partner2Idx;
                    if (!isset($parsedData[$partner2Idx])) continue;

                    $parsedData[$playerIdx]['manual_partner2']   = $partner2Idx;
                    $parsedData[$playerIdx]['partner2_matched']  = true;
                    $parsedData[$playerIdx]['partner2_resolved'] = $parsedData[$partner2Idx]['name'];

                    // Mirror onto partner2 into their best available slot
                    if (!$parsedData[$partner2Idx]['partner_matched']) {
                        $parsedData[$partner2Idx]['manual_partner']   = $playerIdx;
                        $parsedData[$partner2Idx]['partner_matched']  = true;
                        $parsedData[$partner2Idx]['partner_resolved'] = $parsedData[$playerIdx]['name'];
                    } elseif (!($parsedData[$partner2Idx]['partner2_matched'] ?? false)) {
                        $parsedData[$partner2Idx]['manual_partner2']   = $playerIdx;
                        $parsedData[$partner2Idx]['partner2_matched']  = true;
                        $parsedData[$partner2Idx]['partner2_resolved'] = $parsedData[$playerIdx]['name'];
                    }
                }
            }

            $_SESSION['parsed_data']         = $parsedData;
            $_SESSION['scroll_to_configure'] = true;
        }

        // PRG: redirect to avoid re-POST on browser refresh
        header('Location: index.php');
        exit;
    }

    // ── 2b. AJAX: save a single field (division or DUPR) ─────────────────────
    // Called via fetch() on each field change — keeps the main form payload small
    if ($action === 'save_field') {
        header('Content-Type: application/json');
        $parsedData = $_SESSION['parsed_data'] ?? null;
        if (!$parsedData) { echo json_encode(['ok' => false, 'error' => 'No session data']); exit; }

        $idx   = (int)($_POST['idx']   ?? -1);
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');

        if (!isset($parsedData[$idx])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid player index']);
            exit;
        }

        if ($field === 'division' && $value !== '') {
            $parsedData[$idx]['skill_band']      = $value;
            $parsedData[$idx]['skill']           = skillBandMidpoint($value);
            $parsedData[$idx]['skill_raw']       = skillBandDisplay($value);
            $parsedData[$idx]['division_manual'] = true;
        } elseif ($field === 'dupr') {
            $parsedData[$idx]['dupr'] = ($value !== '' && is_numeric($value)) ? (float)$value : null;
        } else {
            echo json_encode(['ok' => false, 'error' => 'Unknown field']);
            exit;
        }

        $_SESSION['parsed_data'] = $parsedData;
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── 3. GENERATE DRAW ─────────────────────────────────────────────────────
    if ($action === 'generate') {
        $parsedData = $_SESSION['parsed_data'] ?? null;

        if (empty($parsedData)) {
            $error = 'No player data found. Please import a CSV first.';
        } else {
            try {
                $engine = new DrawEngine($parsedData);
                $result = $engine->generateDraw([
                    'rounds'      => (int)($_POST['rounds'] ?? 8),
                    'skill_bands' => (int)($_POST['skill_bands'] ?? 1),
                    'format'      => $_POST['format'] ?? 'round_robin',
                    'courts'      => (int)($_POST['courts'] ?? 11),
                ]);
                $teams = $result['teams'];
                $draws = $result['draws'];
                $_SESSION['teams'] = $teams;
                $_SESSION['draws'] = $draws;
            } catch (Exception $e) {
                $error = 'Draw error: ' . $e->getMessage();
            }
        }
    }

    // ── 4. ADD PLAYER MANUALLY ────────────────────────────────────────────────
    if ($action === 'add_player') {
        $parsedData = $_SESSION['parsed_data'] ?? [];

        $firstName = trim($_POST['new_first_name'] ?? '');
        $lastName  = trim($_POST['new_last_name']  ?? '');
        $band      = trim($_POST['new_division']   ?? 'band-30');
        $dupr      = trim($_POST['new_dupr']       ?? '');
        $partner   = trim($_POST['new_partner']    ?? '');
        $partner2  = trim($_POST['new_partner2']   ?? '');

        if ($firstName === '' && $lastName === '') {
            $error = 'Please enter at least a first or last name.';
        } else {
            $fullName = trim("$firstName $lastName");

            $newPlayer = [
                'name'              => $fullName,
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'skill'             => skillBandMidpoint($band),
                'skill_raw'         => skillBandDisplay($band),
                'skill_band'        => $band,
                'partner'           => $partner ?: null,
                'partner_matched'   => false,
                'partner_resolved'  => null,
                'manual_partner'    => null,
                'partner2'          => $partner2 ?: null,
                'partner2_matched'  => false,
                'partner2_resolved' => null,
                'manual_partner2'   => null,
                'division_manual'   => true,
                'dupr'              => ($dupr !== '' && is_numeric($dupr)) ? (float)$dupr : null,
                'attendee_id'       => '',
                'order_id'          => '',
                'ticket_class'      => '',
                'checked_in'        => false,
                'status'            => 'Manual',
                'team_id'           => null,
            ];

            $newIdx = count($parsedData);

            // Try to match primary partner
            if ($partner !== '') {
                foreach ($parsedData as $j => $p) {
                    if (strcasecmp($p['name'], $partner) === 0) {
                        $newPlayer['partner_matched']  = true;
                        $newPlayer['partner_resolved'] = $p['name'];
                        $parsedData[$j]['partner_matched']  = true;
                        $parsedData[$j]['partner_resolved'] = $fullName;
                        $parsedData[$j]['manual_partner']   = $newIdx;
                        break;
                    }
                }
            }

            // Try to match secondary partner
            if ($partner2 !== '') {
                foreach ($parsedData as $j => $p) {
                    if (strcasecmp($p['name'], $partner2) === 0) {
                        $newPlayer['partner2_matched']  = true;
                        $newPlayer['partner2_resolved'] = $p['name'];
                        if (!$parsedData[$j]['partner_matched']) {
                            $parsedData[$j]['partner_matched']  = true;
                            $parsedData[$j]['partner_resolved'] = $fullName;
                            $parsedData[$j]['manual_partner']   = $newIdx;
                        }
                        break;
                    }
                }
            }

            $parsedData[] = $newPlayer;

            $_SESSION['parsed_data'] = $parsedData;
            $_SESSION['draws']       = [];
            $_SESSION['teams']       = [];
        }
    }

    // ── 5. DELETE PLAYER ─────────────────────────────────────────────────────
    if ($action === 'delete_player') {
        $parsedData = $_SESSION['parsed_data'] ?? [];
        $delIdx     = (int)($_POST['del_idx'] ?? -1);

        if (isset($parsedData[$delIdx])) {
            $deleted = $parsedData[$delIdx];

            // Unlink their partner if they had one
            if ($deleted['partner_matched']) {
                foreach ($parsedData as $j => &$p) {
                    if ($j === $delIdx) continue;
                    if (
                        (isset($p['manual_partner']) && $p['manual_partner'] === $delIdx) ||
                        strcasecmp($p['partner_resolved'] ?? '', $deleted['name']) === 0 ||
                        strcasecmp($p['name'], $deleted['partner_resolved'] ?? '') === 0
                    ) {
                        $p['partner_matched']  = false;
                        $p['partner_resolved'] = null;
                        $p['manual_partner']   = null;
                    }
                }
                unset($p);
            }

            // Remove the player — use array_values to reindex
            unset($parsedData[$delIdx]);
            $parsedData = array_values($parsedData);

            // Re-index any manual_partner references (they store array indices)
            foreach ($parsedData as $j => &$p) {
                if (isset($p['manual_partner']) && $p['manual_partner'] !== null) {
                    if ($p['manual_partner'] > $delIdx) {
                        $p['manual_partner']--;
                    } elseif ($p['manual_partner'] === $delIdx) {
                        $p['manual_partner']   = null;
                        $p['partner_matched']  = false;
                        $p['partner_resolved'] = null;
                    }
                }
            }
            unset($p);

            $_SESSION['parsed_data'] = $parsedData;
            $_SESSION['draws']       = [];
            $_SESSION['teams']       = [];
        }
    }

} // end if POST

// ── Load from session ─────────────────────────────────────────────────────────
if (empty($teams) && !empty($_SESSION['teams'])) {
    $teams = $_SESSION['teams'];
    $draws = $_SESSION['draws'] ?? [];
}
if (empty($parsedData) && !empty($_SESSION['parsed_data'])) {
    $parsedData = $_SESSION['parsed_data'];
}
$columnMap = $_SESSION['column_map'] ?? [];

// Consume the scroll-to-configure flag (one-shot)
$scrollToConfigure = !empty($_SESSION['scroll_to_configure']);
unset($_SESSION['scroll_to_configure']);

// ── Counts ────────────────────────────────────────────────────────────────────
$matchedCount   = $parsedData ? count(array_filter($parsedData, fn($p) => $p['partner_matched']))  : 0;
$unmatchedCount = $parsedData ? count(array_filter($parsedData, fn($p) => !$p['partner_matched'])) : 0;

// Build list of all attendees for the partner dropdowns
// Only players not yet explicitly matched are available as options for any given player
$allAttendees = $parsedData ? array_map(fn($i, $p) => ['idx' => $i, 'name' => $p['name'], 'skill_raw' => $p['skill_raw'], 'partner_matched' => $p['partner_matched']], array_keys($parsedData), $parsedData) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pickledraw — Pickleball Tournament Draw Generator</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="bg-grid"></div>

<?php if (isset($_GET['reset'])) {
    session_destroy();
    header('Location: index.php');
    exit;
} ?>

<header class="site-header">
    <div class="header-inner">
        <div class="logo">
            <span class="logo-icon">🥒</span>
            <span class="logo-text">PICKLEDRAW</span>
            <span class="logo-sub">Tournament Draw Generator</span>
        </div>
        <nav class="header-nav">
            <?php if (!empty($parsedData)): ?>
                <a href="?reset=1" class="btn-ghost">↺ Start Over</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="main-content">

<!-- ═══════════════════════════════════════════════════════════════════════
     STEP 1 — IMPORT
═══════════════════════════════════════════════════════════════════════ -->
<section class="step-section <?= !empty($parsedData) ? 'step-done' : 'step-active' ?>" id="step-input">
    <div class="step-header">
        <span class="step-num">01</span>
        <div>
            <h2 class="step-title">Import Registration Data</h2>
            <p class="step-desc">Upload your Eventbrite attendee CSV export or paste the data directly</p>
        </div>
        <?php if (!empty($parsedData)): ?>
            <span class="step-badge">✓ <?= count($parsedData) ?> attendees loaded</span>
        <?php endif; ?>
    </div>

    <?php if (empty($parsedData)): ?>
    <!-- ── Upload / paste form ── -->
    <form method="POST" enctype="multipart/form-data" class="input-form">
        <input type="hidden" name="action" value="parse">

        <div class="form-grid">
            <div class="form-col">
                <label class="form-label">Upload CSV File</label>
                <div class="file-drop-zone" id="dropZone">
                    <input type="file" name="csv_file" id="csvFile" accept=".csv,.txt" class="file-input">
                    <div class="file-drop-content">
                        <span class="file-icon">⬆</span>
                        <span class="file-text">Drop file here or <strong>click to browse</strong></span>
                        <span class="file-hint">CSV or TXT — standard Eventbrite export format</span>
                    </div>
                </div>
            </div>

            <div class="form-divider"><span>OR</span></div>

            <div class="form-col">
                <label class="form-label">Paste CSV Data</label>
                <textarea name="csv_text" class="data-textarea"
                    placeholder="Date Created,Order ID,Purchaser User ID,Attendee ID,Attendee First Name,Attendee Last Name,Status,Ticket Class,Ticket Class Price,Is Guest,Checked in,Checked in Date,Name of partner(s),I am registered to play in a tournament at this level.
2024-01-15,ORD001,USR001,ATT001,Alice,Thompson,Attending,Mixed Doubles,45.00,No,No,,Bob Clarke,3.5
..."></textarea>
            </div>
        </div>

        <div class="form-options">
            <div class="option-group">
                <label class="form-label">Delimiter</label>
                <select name="delimiter" class="form-select">
                    <option value="auto">Auto-detect</option>
                    <option value=",">Comma (,)</option>
                    <option value=";">Semicolon (;)</option>
                    <option value="\t">Tab</option>
                    <option value="|">Pipe (|)</option>
                </select>
            </div>

            <div class="option-group format-hint">
                <label class="form-label">Expected Column Format</label>
                <code class="format-code">Date Created · Order ID · Purchaser User ID · Attendee ID · Attendee First Name · Attendee Last Name · Status · Ticket Class · Ticket Class Price · Is Guest · Checked in · Checked in Date · Name of partner(s) · I am registered to play in a tournament at this level.</code>
                <p class="hint-text">Columns can be in any order. Cancelled / refunded attendees are automatically excluded.</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="sample-section">
            <button type="button" class="btn-ghost" onclick="loadSample()">Load Sample Data</button>
        </div>

        <button type="submit" class="btn-primary">Import Players →</button>
    </form>

    <?php else: /* ── Parsed data preview with pairing overrides ── */ ?>

    <div class="import-stats">
        <div class="stat-pill stat-green">✓ <?= $matchedCount ?> players paired</div>
        <?php if ($unmatchedCount > 0): ?>
            <div class="stat-pill stat-amber">⚠ <?= $unmatchedCount ?> need pairing assignment</div>
        <?php endif; ?>
        <div class="stat-pill stat-blue"><?= count($parsedData) ?> total attendees</div>
    </div>

    <?php
    // Column detection summary — show warnings for any critical columns not found
    $criticalCols = [
        'first_name'  => 'Attendee First Name',
        'last_name'   => 'Attendee Last Name',
        'skill_level' => 'Tournament Division',
        'partner'     => 'Name of partner(s)',
    ];
    $missingCols = [];
    foreach ($criticalCols as $key => $label) {
        if (!isset($columnMap[$key])) {
            $missingCols[] = $label;
        }
    }
    ?>
    <?php if (!empty($missingCols)): ?>
    <div class="alert alert-warn col-warn">
        <strong>⚠ Some columns were not detected:</strong>
        <?= implode(', ', array_map('htmlspecialchars', $missingCols)) ?>.
        Values for these fields will be blank or defaulted. Check your CSV headers match the expected format.
    </div>
    <?php endif; ?>

    <?php if (!empty($columnMap)): ?>
    <details class="col-debug">
        <summary>Column mapping detected (<?= count($columnMap) ?> columns)</summary>
        <div class="col-debug-grid">
            <?php foreach ($criticalCols as $key => $label): ?>
            <div class="col-debug-item <?= isset($columnMap[$key]) ? 'col-ok' : 'col-missing' ?>">
                <?= isset($columnMap[$key]) ? '✓' : '✗' ?>
                <span><?= htmlspecialchars($label) ?></span>
                <?php if (isset($columnMap[$key])): ?>
                    <span class="col-idx">col <?= $columnMap[$key] + 1 ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <form method="POST" class="pairing-form" id="pairingForm">
        <input type="hidden" name="action" value="save_pairings">

        <div class="parsed-preview">
            <table class="data-table" id="attendeeTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Attendee</th>
                        <th>Tournament Division</th>
                        <th>DUPR Rating</th>
                        <th>Partner Declared</th>
                        <th>Pairing</th>
                        <th class="th-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parsedData as $i => $player): ?>
                    <tr class="<?= $player['partner_matched'] ? 'row-matched' : 'row-unmatched' ?>"
                        data-player-idx="<?= $i ?>"
                        data-player-name="<?= htmlspecialchars($player['name'], ENT_QUOTES) ?>"
                        <?= ($player['status'] ?? '') === 'Manual' ? 'data-manual="1"' : '' ?>>

                        <td class="td-num"><?= $i + 1 ?></td>

                        <td>
                            <strong><?= htmlspecialchars($player['name']) ?></strong>
                            <?php if ($player['attendee_id']): ?>
                                <span class="meta-id">#<?= htmlspecialchars($player['attendee_id']) ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="td-division">
                            <select class="division-select division-<?= $player['skill_band'] ?>"
                                    data-player-idx="<?= $i ?>"
                                    onchange="onDivisionChange(this)">
                                <option value="band-u25" <?= $player['skill_band'] === 'band-u25' ? 'selected' : '' ?>>Under 2.5</option>
                                <option value="band-25"  <?= $player['skill_band'] === 'band-25'  ? 'selected' : '' ?>>2.5 – 2.99</option>
                                <option value="band-30"  <?= $player['skill_band'] === 'band-30'  ? 'selected' : '' ?>>3.0 – 3.49</option>
                                <option value="band-35"  <?= $player['skill_band'] === 'band-35'  ? 'selected' : '' ?>>3.5 – 3.99</option>
                                <option value="band-4p"  <?= $player['skill_band'] === 'band-4p'  ? 'selected' : '' ?>>4.0+</option>
                            </select>
                            <span class="division-src" title="From CSV: <?= htmlspecialchars($player['skill_raw']) ?>">
                                <?= isset($player['division_manual']) && $player['division_manual'] ? '✎' : '↑CSV' ?>
                            </span>
                        </td>

                        <td class="td-dupr">
                            <input type="text"
                                   class="dupr-input"
                                   data-player-idx="<?= $i ?>"
                                   value="<?= htmlspecialchars($player['dupr'] ?? '') ?>"
                                   placeholder="e.g. 3.421"
                                   maxlength="7"
                                   title="Enter individual DUPR rating (e.g. 3.421)"
                                   onchange="onDuprChange(this)">
                        </td>

                        <td class="td-partner">
                            <?php
                            $p1 = $player['partner'] ?? null;
                            $p2 = $player['partner2'] ?? null;
                            if ($p1) echo htmlspecialchars($p1);
                            if ($p1 && $p2) echo '<br><span class="partner2-label">+ </span>';
                            if ($p2) echo '<span class="partner2-label">' . htmlspecialchars($p2) . '</span>';
                            if (!$p1 && !$p2) echo '—';
                            ?>
                        </td>

                        <td class="td-pairing">
                            <?php
                            // ── Partner 1 ──────────────────────────────────
                            $p1Resolved = $player['partner_resolved'] ?? null;
                            $p1Idx      = $p1Resolved ? findPartnerIndexInData($parsedData, $p1Resolved) : null;
                            ?>
                            <div class="pairing-slot" data-slot="1">
                                <span class="pairing-slot-label">P1</span>
                                <?php if ($player['partner_matched'] && $p1Resolved): ?>
                                    <div class="pairing-confirmed">
                                        <span class="status-dot matched"></span>
                                        <span class="pair-label"><?= htmlspecialchars($p1Resolved) ?></span>
                                        <button type="button" class="btn-unlink"
                                            onclick="unlinkPlayerSlot(<?= $i ?>, 1)"
                                            title="Remove primary pairing">✕</button>
                                    </div>
                                    <input type="hidden"
                                        name="manual_partner[<?= $i ?>]"
                                        id="partner_input_<?= $i ?>"
                                        value="<?= $p1Idx ?? 'auto' ?>">
                                <?php else: ?>
                                    <div class="pairing-select-wrap">
                                        <span class="status-dot unmatched"></span>
                                        <select name="manual_partner[<?= $i ?>]"
                                                id="partner_select_<?= $i ?>"
                                                class="partner-select"
                                                data-player-idx="<?= $i ?>"
                                                data-slot="1"
                                                onchange="onPartnerChange(this)">
                                            <option value="auto">⟳ Auto-pair</option>
                                            <optgroup label="── Select partner 1 ──">
                                            <?php foreach ($parsedData as $j => $other): ?>
                                                <?php if ($j === $i) continue; ?>
                                                <option value="<?= $j ?>"
                                                    <?= (isset($player['manual_partner']) && $player['manual_partner'] === $j) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($other['name']) ?>
                                                    (<?= htmlspecialchars($other['skill_raw']) ?>)
                                                    <?= $other['partner_matched'] ? ' ✓' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            // ── Partner 2 ──────────────────────────────────
                            $p2Resolved = $player['partner2_resolved'] ?? null;
                            $p2Idx      = $p2Resolved ? findPartnerIndexInData($parsedData, $p2Resolved) : null;
                            ?>
                            <div class="pairing-slot pairing-slot-2" data-slot="2">
                                <span class="pairing-slot-label p2-label">P2</span>
                                <?php if (($player['partner2_matched'] ?? false) && $p2Resolved): ?>
                                    <div class="pairing-confirmed">
                                        <span class="status-dot matched"></span>
                                        <span class="pair-label"><?= htmlspecialchars($p2Resolved) ?></span>
                                        <button type="button" class="btn-unlink"
                                            onclick="unlinkPlayerSlot(<?= $i ?>, 2)"
                                            title="Remove secondary pairing">✕</button>
                                    </div>
                                    <input type="hidden"
                                        name="manual_partner2[<?= $i ?>]"
                                        id="partner2_input_<?= $i ?>"
                                        value="<?= $p2Idx ?? 'auto' ?>">
                                <?php else: ?>
                                    <div class="pairing-select-wrap">
                                        <span class="status-dot" style="background:rgba(91,143,255,0.5)"></span>
                                        <select name="manual_partner2[<?= $i ?>]"
                                                id="partner2_select_<?= $i ?>"
                                                class="partner-select partner-select-2"
                                                data-player-idx="<?= $i ?>"
                                                data-slot="2"
                                                onchange="onPartnerChange(this)">
                                            <option value="auto">— No 2nd partner</option>
                                            <optgroup label="── Select partner 2 ──">
                                            <?php foreach ($parsedData as $j => $other): ?>
                                                <?php if ($j === $i) continue; ?>
                                                <option value="<?= $j ?>"
                                                    <?= (isset($player['manual_partner2']) && $player['manual_partner2'] === $j) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($other['name']) ?>
                                                    (<?= htmlspecialchars($other['skill_raw']) ?>)
                                                    <?= ($other['partner2_matched'] ?? false) ? ' ✓' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="td-actions">
                            <button type="button"
                                    class="btn-delete-player"
                                    title="Remove <?= htmlspecialchars($player['name'], ENT_QUOTES) ?>"
                                    onclick="confirmDeletePlayer(<?= $i ?>, '<?= htmlspecialchars(addslashes($player['name']), ENT_QUOTES) ?>')">
                                🗑
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="pairing-actions">
            <a href="?reset=1" class="btn-ghost btn-sm">← Re-import</a>
            <button type="button" class="btn-add-player" onclick="openAddPlayerModal()">+ Add Player</button>
            <button type="submit" class="btn-primary">Confirm Pairings &amp; Continue →</button>
        </div>
    </form>

    <!-- Hidden form for deleting a player (separate POST from the main pairing form) -->
    <form id="deletePlayerForm" method="POST" style="display:none">
        <input type="hidden" name="action" value="delete_player">
        <input type="hidden" name="del_idx" id="deletePlayerIdx" value="">
    </form>
    <?php endif; ?>
</section>


<!-- ═══════════════════════════════════════════════════════════════════════
     STEP 2 — CONFIGURE DRAW
═══════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($parsedData)): ?>
<section class="step-section <?= !empty($draws) ? 'step-done' : 'step-active' ?>" id="step-settings">
    <div class="step-header">
        <span class="step-num">02</span>
        <div>
            <h2 class="step-title">Configure Draw</h2>
            <p class="step-desc">Choose format, rounds, courts, and skill divisions</p>
        </div>
    </div>

    <form method="POST" class="settings-form">
        <input type="hidden" name="action" value="generate">

        <div class="settings-grid">
            <div class="setting-card">
                <label class="form-label">Format</label>
                <select name="format" class="form-select">
                    <option value="round_robin">Round Robin</option>
                    <option value="elimination">Single Elimination</option>
                    <option value="pools">Pool Play + Finals</option>
                </select>
            </div>
            <div class="setting-card">
                <label class="form-label">Rounds</label>
                <input type="number" name="rounds" value="8" min="1" max="20" class="form-input">
            </div>
            <div class="setting-card">
                <label class="form-label">Courts Available</label>
                <input type="number" name="courts" value="11" min="1" max="30" class="form-input">
            </div>
            <div class="setting-card">
                <label class="form-label">Skill Divisions</label>
                <select name="skill_bands" class="form-select">
                    <option value="1" selected>1 — All play together</option>
                    <option value="2">2 — A / B</option>
                    <option value="3">3 — A / B / C</option>
                    <option value="4">4 — A / B / C / D</option>
                    <option value="5">5 — By pickleball rating</option>
                </select>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <button type="submit" class="btn-primary">Generate Draw →</button>
    </form>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════
     STEP 3 — DRAW OUTPUT
═══════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($draws)): ?>
<section class="step-section step-active" id="step-draw">
    <div class="step-header">
        <span class="step-num">03</span>
        <div>
            <h2 class="step-title">Tournament Draw</h2>
            <p class="step-desc"><?= count($teams) ?> teams<?= count($draws) > 1 ? ' across ' . count($draws) . ' divisions' : '' ?></p>
        </div>
        <div class="draw-actions">
            <button onclick="window.print()" class="btn-ghost">🖨 Print</button>
            <a href="export.php" class="btn-ghost">⬇ Export CSV</a>
            <a href="export_docx.php" class="btn-ghost btn-word">📄 Export Word</a>
        </div>
    </div>

    <?php $singleDivision = count($draws) === 1; ?>
    <?php foreach ($draws as $divName => $divData): ?>
    <div class="division-block">
        <?php if (!$singleDivision): ?>
        <div class="division-header">
            <span class="division-badge"><?= htmlspecialchars($divName) ?></span>
            <span class="division-info">
                <?= count($divData['teams']) ?> teams · Avg <?= $divData['avg_skill'] ?>
                <?php if (!empty($divData['dupr_avg'])): ?>
                    · Avg DUPR <?= $divData['dupr_avg'] ?>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <div class="teams-row">
            <?php foreach ($divData['teams'] as $team): ?>
            <div class="team-chip">
                <span class="team-names"><?= htmlspecialchars($team['player1']) ?> &amp; <?= htmlspecialchars($team['player2']) ?></span>
                <?php if ($team['combined_dupr'] !== null): ?>
                    <span class="team-dupr" title="Combined DUPR">DUPR <?= $team['combined_dupr'] ?></span>
                <?php else: ?>
                    <span class="team-skill">⚡<?= $team['combined_skill'] ?></span>
                <?php endif; ?>
                <?php if ($team['explicit_pair']): ?>
                    <span class="pair-tag">✓ paired</span>
                <?php endif; ?>
                <?php if (($team['partner_slot'] ?? 1) === 2): ?>
                    <span class="alt-pair-tag">2nd partner</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($divData['rounds'] as $roundNum => $matches): ?>
        <div class="round-block">
            <h4 class="round-title">Round <?= $roundNum ?></h4>
            <div class="matches-grid">
                <?php foreach ($matches as $match): ?>
                <div class="match-card <?= !empty($match['is_bye']) ? 'match-card-bye' : '' ?>">
                    <?php if (isset($match['note'])): ?>
                        <div class="match-note"><?= htmlspecialchars($match['note']) ?></div>
                    <?php elseif (!empty($match['is_bye'])): ?>
                    <div class="match-court">
                        <input type="text"
                               class="court-input"
                               value="Court <?= (int)$match['court'] ?>"
                               data-default="Court <?= (int)$match['court'] ?>"
                               aria-label="Court number">
                    </div>
                    <div class="match-teams">
                        <div class="match-team home" style="flex:1">
                            <span class="match-team-name"><?= htmlspecialchars($match['team1']) ?></span>
                        </div>
                    </div>
                    <div class="bye-label">Bye or Singles</div>
                    <?php else: ?>
                    <div class="match-court">
                        <input type="text"
                               class="court-input"
                               value="Court <?= (int)$match['court'] ?>"
                               data-default="Court <?= (int)$match['court'] ?>"
                               aria-label="Court number">
                        <?php if (!empty($match['pool'])): ?>
                            <span class="pool-tag"><?= htmlspecialchars($match['pool']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($match['alt_partner'])): ?>
                            <span class="alt-round-tag">↕ <?= htmlspecialchars($match['alt_partner']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="match-teams">
                        <div class="match-team home">
                            <span class="match-team-name"><?= htmlspecialchars($match['team1']) ?></span>
                        </div>
                        <div class="match-vs">VS</div>
                        <div class="match-team away">
                            <span class="match-team-name"><?= htmlspecialchars($match['team2']) ?></span>
                        </div>
                    </div>
                    <div class="match-score">
                        <input type="text" placeholder="Score" class="score-input">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

</main>

<!-- ═══════════════════════════════════════════════════════════════════════
     ADD PLAYER MODAL
═══════════════════════════════════════════════════════════════════════ -->
<div id="addPlayerModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Player Manually</h3>
            <button type="button" class="modal-close" onclick="closeAddPlayerModal()" aria-label="Close">✕</button>
        </div>

        <form method="POST" class="modal-form" id="addPlayerForm">
            <input type="hidden" name="action" value="add_player">

            <div class="modal-row">
                <div class="modal-field">
                    <label class="form-label" for="new_first_name">First Name</label>
                    <input type="text" id="new_first_name" name="new_first_name"
                           class="form-input" placeholder="e.g. Jane" autocomplete="off">
                </div>
                <div class="modal-field">
                    <label class="form-label" for="new_last_name">Last Name</label>
                    <input type="text" id="new_last_name" name="new_last_name"
                           class="form-input" placeholder="e.g. Smith" autocomplete="off">
                </div>
            </div>

            <div class="modal-row">
                <div class="modal-field">
                    <label class="form-label" for="new_division">Tournament Division</label>
                    <select id="new_division" name="new_division" class="form-select" onchange="syncModalDivision(this)">
                        <option value="band-u25">Under 2.5</option>
                        <option value="band-25">2.5 – 2.99</option>
                        <option value="band-30" selected>3.0 – 3.49</option>
                        <option value="band-35">3.5 – 3.99</option>
                        <option value="band-4p">4.0+</option>
                    </select>
                </div>
                <div class="modal-field">
                    <label class="form-label" for="new_dupr">DUPR Rating <span class="label-optional">(optional)</span></label>
                    <input type="text" id="new_dupr" name="new_dupr"
                           class="form-input" placeholder="e.g. 3.42"
                           maxlength="8" autocomplete="off">
                </div>
            </div>

            <div class="modal-row">
                <div class="modal-field modal-field-full">
                    <label class="form-label" for="new_partner">Partner 1 Name <span class="label-optional">(optional — must match an existing player)</span></label>
                    <input type="text" id="new_partner" name="new_partner"
                           class="form-input" placeholder="e.g. John Doe"
                           list="existingPlayersList" autocomplete="off">
                    <datalist id="existingPlayersList">
                        <?php foreach ($parsedData as $p): ?>
                            <option value="<?= htmlspecialchars($p['name']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div class="modal-row">
                <div class="modal-field modal-field-full">
                    <label class="form-label" for="new_partner2">Partner 2 Name <span class="label-optional">(optional — for players alternating between two partners)</span></label>
                    <input type="text" id="new_partner2" name="new_partner2"
                           class="form-input" placeholder="e.g. Jane Smith"
                           list="existingPlayersList2" autocomplete="off">
                    <datalist id="existingPlayersList2">
                        <?php foreach ($parsedData as $p): ?>
                            <option value="<?= htmlspecialchars($p['name']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div id="addPlayerError" class="alert alert-error" style="display:none"></div>

            <div class="modal-actions">
                <button type="button" class="btn-ghost" onclick="closeAddPlayerModal()">Cancel</button>
                <button type="submit" class="btn-primary">Add Player</button>
            </div>
        </form>
    </div>
</div>

<footer class="site-footer">
    <p>🥒 Pickledraw · Pickleball Tournament Draw Generator · <?= date('Y') ?></p>
</footer>

<!-- Attendee data for JS partner select logic -->
<?php if (!empty($parsedData)): ?>
<script>
const ATTENDEES = <?= json_encode(array_map(fn($i, $p) => [
    'idx'             => $i,
    'name'            => $p['name'],
    'skill_raw'       => $p['skill_raw'],
    'skill_band'      => $p['skill_band'],
    'dupr'            => $p['dupr'] ?? null,
    'partner_matched' => $p['partner_matched'],
    'has_partner2'    => !empty($p['partner2']),
], array_keys($parsedData), $parsedData), JSON_HEX_TAG) ?>;
const SCROLL_TO_CONFIGURE = <?= $scrollToConfigure ? 'true' : 'false' ?>;
</script>
<?php endif; ?>

<script src="js/app.js"></script>
</body>
</html>
