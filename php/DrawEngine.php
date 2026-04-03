<?php

/**
 * DrawEngine - Matches partners into teams, groups by skill, creates match draws
 */
class DrawEngine
{
    private array $players;
    private array $teams = [];
    private int   $teamCounter = 1;

    public function __construct(array $players)
    {
        $this->players = $players;
    }

    /**
     * Main entry point. Returns ['teams' => [...], 'draws' => [...]]
     */
    public function generateDraw(array $options): array
    {
        $rounds      = $options['rounds']      ?? 3;
        $skillBands  = $options['skill_bands'] ?? 3;
        $format      = $options['format']      ?? 'round_robin';
        $courts      = $options['courts']      ?? 4;

        $this->teams = $this->buildTeams();

        if (empty($this->teams)) {
            throw new Exception('Not enough players to form any teams. Need at least 2 players.');
        }

        $divisions = $this->groupBySkill($skillBands);
        $draws     = $this->buildDraw($divisions, $rounds, $format, $courts);

        return [
            'teams' => $this->teams,
            'draws' => $draws,
        ];
    }

    // -----------------------------------------------------------------------
    // TEAM BUILDING
    // -----------------------------------------------------------------------

    private function buildTeams(): array
    {
        $teams      = [];
        $paired     = [];
        $unmatched  = [];

        // First pass: form explicitly named partner pairs
        foreach ($this->players as $i => $player) {
            if (in_array($i, $paired)) continue;
            if (!$player['partner_matched'])  {
                $unmatched[] = $i;
                continue;
            }

            // Find the partner
            $partnerIdx = $this->findPlayerIndex($player['partner']);
            if ($partnerIdx === null || in_array($partnerIdx, $paired)) {
                $unmatched[] = $i;
                continue;
            }

            $partner = $this->players[$partnerIdx];
            $teams[] = $this->makeTeam($player, $partner);
            $paired[] = $i;
            $paired[] = $partnerIdx;
        }

        // Second pass: pair remaining players by similar skill
        $remaining = array_map(fn($i) => $this->players[$i], $unmatched);
        usort($remaining, fn($a, $b) => $b['skill'] <=> $a['skill']);

        while (count($remaining) >= 2) {
            $p1 = array_shift($remaining);
            // Find closest skill partner
            $bestIdx  = 0;
            $bestDiff = PHP_INT_MAX;
            foreach ($remaining as $j => $p) {
                $diff = abs($p1['skill'] - $p['skill']);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestIdx  = $j;
                }
            }
            $p2 = array_splice($remaining, $bestIdx, 1)[0];
            $teams[] = $this->makeTeam($p1, $p2, false);
        }

        // If odd player out, form a trio (add to last team's note, or solo bye)
        if (!empty($remaining)) {
            $solo = $remaining[0];
            if (!empty($teams)) {
                $teams[count($teams) - 1]['note'] = 'Includes bye player: ' . $solo['name'];
            } else {
                // Only one player — create a solo team
                $teams[] = [
                    'id'             => $this->teamCounter++,
                    'player1'        => $solo['name'],
                    'player2'        => '— BYE —',
                    'skill1'         => $solo['skill'],
                    'skill2'         => 0,
                    'combined_skill' => $solo['skill'],
                    'avg_skill'      => $solo['skill'],
                    'explicit_pair'  => false,
                    'note'           => 'Solo player - awaiting partner',
                ];
            }
        }

        return $teams;
    }

    private function makeTeam(array $p1, array $p2, bool $explicit = true): array
    {
        return [
            'id'             => $this->teamCounter++,
            'player1'        => $p1['name'],
            'player2'        => $p2['name'],
            'skill1'         => $p1['skill'],
            'skill2'         => $p2['skill'],
            'combined_skill' => round($p1['skill'] + $p2['skill'], 1),
            'avg_skill'      => round(($p1['skill'] + $p2['skill']) / 2, 1),
            'explicit_pair'  => $explicit,
            'note'           => '',
        ];
    }

    private function findPlayerIndex(string $name): ?int
    {
        $nameLower = strtolower($name);
        foreach ($this->players as $i => $p) {
            if (strtolower($p['name']) === $nameLower) return $i;
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // SKILL DIVISION GROUPING
    // -----------------------------------------------------------------------

    private function groupBySkill(int $numBands): array
    {
        if (empty($this->teams)) return [];

        $skills = array_column($this->teams, 'avg_skill');
        $min    = min($skills);
        $max    = max($skills);

        if ($max === $min) {
            // All same skill - one division
            return ['Division A' => $this->teams];
        }

        $range     = $max - $min;
        $bandSize  = $range / $numBands;
        $divLetters = range('A', 'Z');

        $divisions = [];
        foreach ($this->teams as $team) {
            $bandIdx = (int) floor(($team['avg_skill'] - $min) / $bandSize);
            $bandIdx = min($bandIdx, $numBands - 1); // cap at top band

            // Reverse: highest skill = Division A
            $divIdx  = ($numBands - 1) - $bandIdx;
            $divName = 'Division ' . ($divLetters[$divIdx] ?? $divIdx + 1);

            $divisions[$divName][] = $team;
        }

        ksort($divisions);
        return $divisions;
    }

    // -----------------------------------------------------------------------
    // DRAW GENERATION
    // -----------------------------------------------------------------------

    private function buildDraw(array $divisions, int $rounds, string $format, int $courts): array
    {
        $draws = [];
        $courtCounter = 1;

        foreach ($divisions as $divName => $teams) {
            $draws[$divName] = [
                'teams'     => $teams,
                'avg_skill' => round(array_sum(array_column($teams, 'avg_skill')) / count($teams), 1),
                'rounds'    => [],
            ];

            if (count($teams) < 2) {
                $draws[$divName]['rounds'][1] = [['note' => 'Only one team in this division — awaiting more players.']];
                continue;
            }

            switch ($format) {
                case 'elimination':
                    $draws[$divName]['rounds'] = $this->buildElimination($teams, $courts, $courtCounter);
                    break;
                case 'pools':
                    $draws[$divName]['rounds'] = $this->buildPools($teams, $rounds, $courts, $courtCounter);
                    break;
                default:
                    $draws[$divName]['rounds'] = $this->buildRoundRobin($teams, $rounds, $courts, $courtCounter);
            }

            $courtCounter += $courts;
        }

        return $draws;
    }

    /**
     * Round robin using circle method algorithm.
     * Ensures every team plays every other team, minimising repeat matchups.
     */
    private function buildRoundRobin(array $teams, int $requestedRounds, int $courts, int $courtOffset): array
    {
        $n = count($teams);
        // Ensure even number (add bye if needed)
        $hasBye = false;
        if ($n % 2 !== 0) {
            $teams[] = ['id' => 0, 'player1' => 'BYE', 'player2' => '', 'avg_skill' => 0];
            $n++;
            $hasBye = true;
        }

        $totalRounds = $n - 1;
        $rounds      = min($requestedRounds, $totalRounds);

        $ids    = array_column($teams, 'id');
        $lookup = array_combine($ids, $teams);

        $roundsOut = [];

        // Circle method: fix team[0], rotate the rest
        $circle = array_slice($ids, 1);

        for ($r = 0; $r < $rounds; $r++) {
            $matches = [];
            $courtNum = $courtOffset;

            $pairs = [[$ids[0], $circle[0]]];
            for ($i = 1; $i <= ($n / 2) - 1; $i++) {
                $pairs[] = [$circle[$i], $circle[$n - 1 - $i]];
            }

            foreach ($pairs as $pair) {
                [$id1, $id2] = $pair;
                $t1 = $lookup[$id1] ?? null;
                $t2 = $lookup[$id2] ?? null;

                // Skip bye matches
                if (!$t1 || !$t2) continue;
                if ($id1 === 0 || $id2 === 0) continue;

                $matches[] = [
                    'team1_id' => $t1['id'],
                    'team1'    => $t1['player1'] . ' / ' . $t1['player2'],
                    'team2_id' => $t2['id'],
                    'team2'    => $t2['player1'] . ' / ' . $t2['player2'],
                    'court'    => (($courtNum - 1) % $courts) + $courtOffset,
                ];
                $courtNum++;
            }

            $roundsOut[$r + 1] = $matches;

            // Rotate circle
            array_unshift($circle, array_pop($circle));
        }

        return $roundsOut;
    }

    /**
     * Single elimination bracket.
     */
    private function buildElimination(array $teams, int $courts, int $courtOffset): array
    {
        // Seed teams by skill descending
        usort($teams, fn($a, $b) => $b['avg_skill'] <=> $a['avg_skill']);

        $rounds  = [];
        $bracket = $teams;
        $round   = 1;

        while (count($bracket) > 1) {
            $matches = [];
            $next    = [];
            $court   = $courtOffset;

            for ($i = 0; $i < count($bracket); $i += 2) {
                $t1 = $bracket[$i];
                $t2 = $bracket[$i + 1] ?? null;

                if (!$t2) {
                    // Bye
                    $next[] = $t1;
                    continue;
                }

                $matches[] = [
                    'team1_id' => $t1['id'],
                    'team1'    => $t1['player1'] . ' / ' . $t1['player2'],
                    'team2_id' => $t2['id'],
                    'team2'    => $t2['player1'] . ' / ' . $t2['player2'],
                    'court'    => (($court - 1) % $courts) + $courtOffset,
                ];
                $court++;

                // Placeholder winner for next round
                $next[] = ['id' => "W{$round}_{$i}", 'player1' => "Winner R{$round} Match " . (intdiv($i, 2) + 1), 'player2' => '', 'avg_skill' => 0];
            }

            $rounds[$round] = $matches;
            $bracket = $next;
            $round++;
        }

        return $rounds;
    }

    /**
     * Pool play: split into pools, play within pools, then finals.
     */
    private function buildPools(array $teams, int $rounds, int $courts, int $courtOffset): array
    {
        // Split into 2 pools using snake seeding
        usort($teams, fn($a, $b) => $b['avg_skill'] <=> $a['avg_skill']);

        $poolA = [];
        $poolB = [];
        foreach ($teams as $i => $team) {
            if ($i % 4 < 2) {
                $poolA[] = $team;
            } else {
                $poolB[] = $team;
            }
        }

        $roundsOut = [];

        // Pool A matches
        $poolARounds = $this->buildRoundRobin($poolA, $rounds, intdiv($courts, 2), $courtOffset);
        foreach ($poolARounds as $r => $matches) {
            foreach ($matches as &$m) {
                $m['pool'] = 'Pool A';
            }
            $roundsOut[$r] = array_merge($roundsOut[$r] ?? [], $matches);
        }

        // Pool B matches (offset courts)
        $poolBRounds = $this->buildRoundRobin($poolB, $rounds, intdiv($courts, 2), $courtOffset + intdiv($courts, 2));
        foreach ($poolBRounds as $r => $matches) {
            foreach ($matches as &$m) {
                $m['pool'] = 'Pool B';
            }
            $roundsOut[$r] = array_merge($roundsOut[$r] ?? [], $matches);
        }

        // Finals round
        $finalRound = ($rounds + 1);
        $roundsOut[$finalRound] = [
            [
                'team1_id' => 'A1',
                'team1'    => '1st Pool A',
                'team2_id' => 'B2',
                'team2'    => '2nd Pool B',
                'court'    => $courtOffset,
                'pool'     => 'Semi-Final 1',
            ],
            [
                'team1_id' => 'B1',
                'team1'    => '1st Pool B',
                'team2_id' => 'A2',
                'team2'    => '2nd Pool A',
                'court'    => $courtOffset + 1,
                'pool'     => 'Semi-Final 2',
            ],
        ];

        $roundsOut[$finalRound + 1] = [
            [
                'team1_id' => 'SF1W',
                'team1'    => 'Winner Semi 1',
                'team2_id' => 'SF2W',
                'team2'    => 'Winner Semi 2',
                'court'    => $courtOffset,
                'pool'     => 'Final',
            ],
        ];

        return $roundsOut;
    }
}
