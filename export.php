<?php
session_start();

$teams = $_SESSION['teams'] ?? [];
$draws = $_SESSION['draws'] ?? [];

if (empty($draws)) {
    header('Location: index.php');
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="pickledraw_' . date('Ymd_His') . '.csv"');

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// ---- Teams sheet ----
fputcsv($out, ['PICKLEDRAW — TEAMS']);
fputcsv($out, ['Player 1', 'Player 2', 'Combined Skill', 'Avg Skill', 'Explicitly Paired?', 'Notes']);
foreach ($teams as $t) {
    fputcsv($out, [
        $t['player1'],
        $t['player2'],
        $t['combined_skill'],
        $t['avg_skill'],
        $t['explicit_pair'] ? 'Yes (named partners)' : 'No (auto-paired by skill)',
        $t['note'] ?? '',
    ]);
}

fputcsv($out, []);
fputcsv($out, []);

// ---- Draws sheet ----
fputcsv($out, ['PICKLEDRAW — MATCH DRAW']);
fputcsv($out, []);

foreach ($draws as $divName => $divData) {
    fputcsv($out, [strtoupper($divName), 'Average Skill: ' . $divData['avg_skill'], count($divData['teams']) . ' teams']);
    fputcsv($out, ['Round', 'Court', 'Pool / Stage', 'Team 1', 'Team 2', 'Score']);

    foreach ($divData['rounds'] as $round => $matches) {
        foreach ($matches as $match) {
            if (isset($match['note'])) {
                fputcsv($out, [$round, '', '', $match['note'], '', '']);
            } elseif (!empty($match['is_bye'])) {
                fputcsv($out, [
                    'Round ' . $round,
                    'Court ' . ($match['court'] ?? ''),
                    '',
                    ($match['team1'] ?? '') . ' — Bye or Singles',
                    '',
                    '',
                ]);
            } else {
                fputcsv($out, [
                    'Round ' . $round,
                    'Court ' . ($match['court'] ?? ''),
                    $match['pool'] ?? '',
                    $match['team1'] ?? '',
                    $match['team2'] ?? '',
                    '', // score — blank for manual entry
                ]);
            }
        }
    }

    fputcsv($out, []);
}

fclose($out);
