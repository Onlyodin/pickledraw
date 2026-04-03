<?php
session_start();
require_once 'php/DrawEngine.php';
require_once 'php/CSVParser.php';

$error = '';
$success = '';
$teams = [];
$draws = [];
$parsedData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $parser = new CSVParser();

    if (isset($_POST['action']) && $_POST['action'] === 'parse') {
        // Handle CSV upload or pasted data
        $rawData = '';

        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $rawData = file_get_contents($_FILES['csv_file']['tmp_name']);
        } elseif (!empty($_POST['csv_text'])) {
            $rawData = trim($_POST['csv_text']);
        }

        $delimiter = $_POST['delimiter'] ?? 'auto';

        if (empty($rawData)) {
            $error = 'Please upload a CSV file or paste delimited data.';
        } else {
            try {
                $parsedData = $parser->parse($rawData, $delimiter);
                $_SESSION['parsed_data'] = $parsedData;
                $success = count($parsedData) . ' players parsed successfully.';
            } catch (Exception $e) {
                $error = 'Parse error: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'generate') {
        $parsedData = $_SESSION['parsed_data'] ?? null;

        if (empty($parsedData)) {
            $error = 'No player data found. Please parse data first.';
        } else {
            try {
                $engine = new DrawEngine($parsedData);
                $result = $engine->generateDraw([
                    'rounds'         => (int)($_POST['rounds'] ?? 3),
                    'skill_bands'    => (int)($_POST['skill_bands'] ?? 3),
                    'format'         => $_POST['format'] ?? 'round_robin',
                    'courts'         => (int)($_POST['courts'] ?? 4),
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
}

// Load from session if available
if (empty($teams) && !empty($_SESSION['teams'])) {
    $teams = $_SESSION['teams'];
    $draws = $_SESSION['draws'] ?? [];
}
if (empty($parsedData) && !empty($_SESSION['parsed_data'])) {
    $parsedData = $_SESSION['parsed_data'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Draw Generator</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="bg-grid"></div>

<header class="site-header">
    <div class="header-inner">
        <div class="logo">
            <span class="logo-icon">⬡</span>
            <span class="logo-text">DRAWMASTER</span>
            <span class="logo-sub">Tournament Engine</span>
        </div>
        <nav class="header-nav">
            <a href="?reset=1" class="btn-ghost">Reset</a>
        </nav>
    </div>
</header>

<?php if (isset($_GET['reset'])) {
    session_destroy();
    header('Location: index.php');
    exit;
} ?>

<main class="main-content">

    <!-- STEP 1: DATA INPUT -->
    <section class="step-section <?= !empty($parsedData) ? 'step-done' : 'step-active' ?>" id="step-input">
        <div class="step-header">
            <span class="step-num">01</span>
            <div>
                <h2 class="step-title">Import Player Data</h2>
                <p class="step-desc">Upload a CSV or paste delimited data containing player names, skill levels, and partner names</p>
            </div>
            <?php if (!empty($parsedData)): ?>
                <span class="step-badge">✓ <?= count($parsedData) ?> players loaded</span>
            <?php endif; ?>
        </div>

        <?php if (empty($parsedData)): ?>
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
                            <span class="file-hint">CSV, TXT — max 2MB</span>
                        </div>
                    </div>
                </div>

                <div class="form-divider"><span>OR</span></div>

                <div class="form-col">
                    <label class="form-label">Paste Delimited Data</label>
                    <textarea name="csv_text" class="data-textarea" placeholder="PlayerName, SkillLevel, PartnerName&#10;Alice Smith, 7, Bob Jones&#10;Bob Jones, 6, Alice Smith&#10;Carol White, 8, Dave Brown&#10;..."></textarea>
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
                    <label class="form-label">Expected Format</label>
                    <code class="format-code">PlayerName, SkillLevel (1-10), PartnerName</code>
                    <p class="hint-text">First row can be a header — it'll be auto-detected and skipped.</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="sample-section">
                <button type="button" class="btn-ghost" onclick="loadSample()">Load Sample Data</button>
            </div>

            <button type="submit" class="btn-primary">Parse Players →</button>
        </form>
        <?php else: ?>
        <div class="parsed-preview">
            <table class="data-table">
                <thead>
                    <tr><th>#</th><th>Player</th><th>Skill</th><th>Partner</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($parsedData, 0, 10) as $i => $player): ?>
                    <tr>
                        <td class="td-num"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($player['name']) ?></td>
                        <td><span class="skill-badge skill-<?= $player['skill_band'] ?>"><?= $player['skill'] ?></span></td>
                        <td><?= htmlspecialchars($player['partner'] ?? '—') ?></td>
                        <td><span class="status-dot <?= $player['partner_matched'] ? 'matched' : 'unmatched' ?>"></span><?= $player['partner_matched'] ? 'Paired' : 'Solo' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($parsedData) > 10): ?>
                    <tr><td colspan="5" class="td-more">+ <?= count($parsedData) - 10 ?> more players...</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <a href="?reset=1" class="btn-ghost btn-sm">← Re-import</a>
        </div>
        <?php endif; ?>
    </section>

    <!-- STEP 2: DRAW SETTINGS -->
    <?php if (!empty($parsedData)): ?>
    <section class="step-section <?= !empty($draws) ? 'step-done' : 'step-active' ?>" id="step-settings">
        <div class="step-header">
            <span class="step-num">02</span>
            <div>
                <h2 class="step-title">Configure Draw</h2>
                <p class="step-desc">Set the format, rounds and court count for the tournament</p>
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
                    <input type="number" name="rounds" value="3" min="1" max="10" class="form-input">
                </div>
                <div class="setting-card">
                    <label class="form-label">Courts Available</label>
                    <input type="number" name="courts" value="4" min="1" max="20" class="form-input">
                </div>
                <div class="setting-card">
                    <label class="form-label">Skill Bands</label>
                    <select name="skill_bands" class="form-select">
                        <option value="2">2 (A/B)</option>
                        <option value="3" selected>3 (A/B/C)</option>
                        <option value="4">4 (A/B/C/D)</option>
                        <option value="5">5 (Open)</option>
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

    <!-- STEP 3: DRAW OUTPUT -->
    <?php if (!empty($draws)): ?>
    <section class="step-section step-active" id="step-draw">
        <div class="step-header">
            <span class="step-num">03</span>
            <div>
                <h2 class="step-title">Tournament Draw</h2>
                <p class="step-desc"><?= count($teams) ?> teams across <?= count($draws) ?> division(s)</p>
            </div>
            <div class="draw-actions">
                <button onclick="window.print()" class="btn-ghost">🖨 Print</button>
                <a href="export.php" class="btn-ghost">⬇ Export CSV</a>
            </div>
        </div>

        <?php foreach ($draws as $divName => $divData): ?>
        <div class="division-block">
            <div class="division-header">
                <span class="division-badge"><?= htmlspecialchars($divName) ?></span>
                <span class="division-info"><?= count($divData['teams']) ?> teams · Skill avg <?= $divData['avg_skill'] ?></span>
            </div>

            <!-- Teams in this division -->
            <div class="teams-row">
                <?php foreach ($divData['teams'] as $team): ?>
                <div class="team-chip">
                    <span class="team-num"><?= $team['id'] ?></span>
                    <span class="team-names"><?= htmlspecialchars($team['player1']) ?> &amp; <?= htmlspecialchars($team['player2']) ?></span>
                    <span class="team-skill">⚡<?= $team['combined_skill'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Match schedule for this division -->
            <?php foreach ($divData['rounds'] as $roundNum => $matches): ?>
            <div class="round-block">
                <h4 class="round-title">Round <?= $roundNum ?></h4>
                <div class="matches-grid">
                    <?php foreach ($matches as $match): ?>
                    <div class="match-card">
                        <div class="match-court">Court <?= $match['court'] ?></div>
                        <div class="match-teams">
                            <div class="match-team home">
                                <span class="match-team-id"><?= $match['team1_id'] ?></span>
                                <span class="match-team-name"><?= htmlspecialchars($match['team1']) ?></span>
                            </div>
                            <div class="match-vs">VS</div>
                            <div class="match-team away">
                                <span class="match-team-id"><?= $match['team2_id'] ?></span>
                                <span class="match-team-name"><?= htmlspecialchars($match['team2']) ?></span>
                            </div>
                        </div>
                        <div class="match-score">
                            <input type="text" placeholder="Score" class="score-input">
                        </div>
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

<footer class="site-footer">
    <p>DrawMaster · Tournament Engine · <?= date('Y') ?></p>
</footer>

<script src="js/app.js"></script>
</body>
</html>
