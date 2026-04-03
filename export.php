<?php
session_start();

$teams = $_SESSION['teams'] ?? [];
$draws = $_SESSION['draws'] ?? [];

if (empty($draws)) {
    header('Location: index.php');
    exit;
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="tournament_draw_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');

// Teams sheet
fputcsv($out, ['TEAMS']);
fputcsv($out, ['Team ID', 'Player 1', 'Player 2', 'Combined Skill', 'Avg Skill', 'Explicit Pair?']);
foreach ($teams as $t) {
    fputcsv($out, [
        $t['id'],
        $t['player1'],
        $t['player2'],
        $t['combined_skill'],
        $t['avg_skill'],
        $t['explicit_pair'] ? 'Yes' : 'No (auto-paired)',
    ]);
}

fputcsv($out, []);

// Draws
foreach ($draws as $divName => $divData) {
    fputcsv($out, [strtoupper($divName), 'Avg Skill: ' . $divData['avg_skill']]);
    fputcsv($out, ['Round', 'Court', 'Team 1 ID', 'Team 1', 'Team 2 ID', 'Team 2', 'Score']);

    foreach ($divData['rounds'] as $round => $matches) {
        foreach ($matches as $match) {
            if (isset($match['note'])) {
                fputcsv($out, [$round, '', '', $match['note'], '', '']);
            } else {
                fputcsv($out, [
                    $round,
                    $match['court'] ?? '',
                    $match['team1_id'] ?? '',
                    $match['team1'] ?? '',
                    $match['team2_id'] ?? '',
                    $match['team2'] ?? '',
                    '', // score blank
                ]);
            }
        }
    }
    fputcsv($out, []);
}

fclose($out);
