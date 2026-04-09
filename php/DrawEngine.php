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

    // -----------------------------------------------------------------------
    // TEAM BUILDING
    //
    // Two-partner logic:
    //   - A player with two resolved partners gets TWO team entries:
    //       team slot 1 = player + partner1
    //       team slot 2 = player + partner2
    //   - In round-robin draw, odd rounds use slot-1 teams,
    //     even rounds use slot-2 teams (swapped for affected players).
    //   - If a slot-2 team is not available for a given round, slot-1 is used.
    //   - Players with NO resolved partners are auto-paired by skill.
    // -----------------------------------------------------------------------

    private function buildTeams(): array
    {
        $teams  = [];
        $paired = []; // player indices placed in at least one primary (slot-1) team

        // --- Pass 1: confirmed primary pairs ---
        foreach ($this->players as $i => $player) {
            if (in_array($i, $paired)) continue;
            if (!$player['partner_matched']) continue;

            $partnerIdx = $player['manual_partner']
                ?? $this->findPlayerIndex($player['partner_resolved'] ?? $player['partner'] ?? '');

            if ($partnerIdx === null || $partnerIdx === $i || in_array($partnerIdx, $paired)) continue;

            $teams[]  = $this->makeTeam($player, $this->players[$partnerIdx], true, 1);
            $paired[] = $i;
            $paired[] = $partnerIdx;
        }

        // --- Pass 2: secondary partner teams (slot 2) ---
        // A player can appear in both a slot-1 and a slot-2 team.
        $paired2 = [];
        foreach ($this->players as $i => $player) {
            if (!($player['partner2_matched'] ?? false)) continue;
            if (in_array($i, $paired2)) continue;

            $p2Idx = $player['manual_partner2']
                ?? $this->findPlayerIndex($player['partner2_resolved'] ?? $player['partner2'] ?? '');

            if ($p2Idx === null || $p2Idx === $i || in_array($p2Idx, $paired2)) continue;

            $teams[]   = $this->makeTeam($player, $this->players[$p2Idx], true, 2);
            $paired2[] = $i;
            $paired2[] = $p2Idx;
        }

        // --- Pass 3: auto-pair remaining unpaired players by closest skill ---
        $remaining = [];
        foreach ($this->players as $i => $p) {
            if (!in_array($i, $paired)) $remaining[$i] = $p;
        }
        uasort($remaining, fn($a, $b) => $b['skill'] <=> $a['skill']);
        $remaining = array_values($remaining);

        while (count($remaining) >= 2) {
            $p1 = array_shift($remaining);
            $bestIdx = 0; $bestDiff = PHP_FLOAT_MAX;
            foreach ($remaining as $j => $p) {
                $d = abs($p1['skill'] - $p['skill']);
                if ($d < $bestDiff) { $bestDiff = $d; $bestIdx = $j; }
            }
            $p2 = array_splice($remaining, $bestIdx, 1)[0];
            $teams[] = $this->makeTeam($p1, $p2, false, 1);
        }

        // Odd player out → bye
        if (!empty($remaining)) {
            $solo = $remaining[0];
            if (!empty($teams)) {
                $teams[count($teams) - 1]['note'] = 'Bye: ' . $solo['name'];
            } else {
                $teams[] = [
                    'id'             => $this->teamCounter++,
                    'player1'        => $solo['name'],
                    'player2'        => '— BYE —',
                    'skill1'         => $solo['skill'],
                    'skill2'         => 0,
                    'combined_skill' => $solo['skill'],
                    'avg_skill'      => $solo['skill'],
                    'combined_dupr'  => null,
                    'explicit_pair'  => false,
                    'partner_slot'   => 1,
                    'note'           => 'Solo player awaiting partner',
                ];
            }
        }

        return $teams;
    }

    private function makeTeam(array $p1, array $p2, bool $explicit = true, int $partnerSlot = 1): array
    {
        $avg  = round(($p1['skill'] + $p2['skill']) / 2, 2);
        $d1   = isset($p1['dupr'])  && $p1['dupr']  !== null ? (float)$p1['dupr']  : null;
        $d2   = isset($p2['dupr'])  && $p2['dupr']  !== null ? (float)$p2['dupr']  : null;
        $cDupr = ($d1 !== null && $d2 !== null) ? round($d1 + $d2, 2) : null;

        return [
            'id'             => $this->teamCounter++,
            'player1'        => $p1['name'],
            'player2'        => $p2['name'],
            'skill1'         => $p1['skill'],
            'skill2'         => $p2['skill'],
            'combined_skill' => round($p1['skill'] + $p2['skill'], 2),
            'avg_skill'      => $avg,
            'skill_raw1'     => $p1['skill_raw'] ?? (string)$p1['skill'],
            'skill_raw2'     => $p2['skill_raw'] ?? (string)$p2['skill'],
            'dupr1'          => $d1,
            'dupr2'          => $d2,
            'combined_dupr'  => $cDupr,
            'explicit_pair'  => $explicit,
            'partner_slot'   => $partnerSlot,
            'note'           => '',
        ];
    }

    private function findPlayerIndex(string $name): ?int
    {
        if ($name === '') return null;
        $nl = strtolower($name);
        foreach ($this->players as $i => $p) {
            if (strtolower($p['name']) === $nl) return $i;
        }
        return null;
    }


    // -----------------------------------------------------------------------
    // SKILL DIVISION GROUPING
    // Fixed pickleball bands: Under 2.5 | 2.5–2.99 | 3.0–3.49 | 3.5–3.99 | 4.0+
    // When numBands === 1 all teams are placed in a single division.
    // -----------------------------------------------------------------------

    private function groupBySkill(int $numBands): array
    {
        if (empty($this->teams)) return [];

        if ($numBands <= 1) {
            return ['All Teams' => $this->teams];
        }

        $fixedBands = [
            'Division A (4.0+)'       => fn(float $s) => $s >= 4.0,
            'Division B (3.5–3.99)'   => fn(float $s) => $s >= 3.5 && $s < 4.0,
            'Division C (3.0–3.49)'   => fn(float $s) => $s >= 3.0 && $s < 3.5,
            'Division D (2.5–2.99)'   => fn(float $s) => $s >= 2.5 && $s < 3.0,
            'Division E (Under 2.5)'  => fn(float $s) => $s < 2.5,
        ];

        if ($numBands >= 5) {
            $divisions = [];
            foreach ($fixedBands as $label => $test) {
                foreach ($this->teams as $team) {
                    if ($test($team['avg_skill'])) $divisions[$label][] = $team;
                }
            }
            return array_filter($divisions);
        }

        $skills   = array_column($this->teams, 'avg_skill');
        $min      = min($skills);
        $max      = max($skills);

        if ($max === $min) return ['Division A' => $this->teams];

        $bandSize   = ($max - $min) / $numBands;
        $divLetters = range('A', 'Z');
        $divisions  = [];

        foreach ($this->teams as $team) {
            $bandIdx = min((int)floor(($team['avg_skill'] - $min) / $bandSize), $numBands - 1);
            $divIdx  = ($numBands - 1) - $bandIdx;
            $divName = 'Division ' . ($divLetters[$divIdx] ?? ($divIdx + 1));
            $divisions[$divName][] = $team;
        }

        ksort($divisions);
        return $divisions;
    }

    // -----------------------------------------------------------------------
    // DRAW GENERATION
    //
    // Cross-division pairing:
    //   When a division has an odd number of slot-1 teams, the team with skill
    //   closest to the boundary borrows the nearest team from an adjacent
    //   division to make the count even.
    //
    // Bye/Singles with court:
    //   When the total number of slot-1 teams (after cross-division pairing) is
    //   still odd, one team sits out each round.  They are assigned a real court
    //   and displayed as "Bye or Singles".  A deficit counter ensures the bye
    //   rotates as evenly as possible across all teams.
    // -----------------------------------------------------------------------

    private function buildDraw(array $divisions, int $rounds, string $format, int $courts): array
    {
        $draws        = [];
        $courtCounter = 1;

        if ($format !== 'round_robin') {
            foreach ($divisions as $divName => $teams) {
                $teams = $this->sortByDuprThenSkill($teams);
                $draws[$divName] = $this->makeDivisionEntry($teams);
                if (count($teams) < 2) {
                    $draws[$divName]['rounds'][1] = [['note' => 'Only one team — awaiting more players.']];
                    continue;
                }
                $draws[$divName]['rounds'] = $format === 'elimination'
                    ? $this->buildElimination($teams, $courts, $courtCounter)
                    : $this->buildPools($teams, $rounds, $courts, $courtCounter);
                $courtCounter += $courts;
            }
            return $draws;
        }

        // ── Round Robin ───────────────────────────────────────────────────────

        if (count($divisions) === 1) {
            // Single division — simple path
            $divName   = array_key_first($divisions);
            $allTeams  = $this->sortByDuprThenSkill($divisions[$divName]);
            $baseTeams = array_values(array_filter($allTeams, fn($t) => ($t['partner_slot'] ?? 1) === 1));
            $altTeams  = array_values(array_filter($allTeams, fn($t) => ($t['partner_slot'] ?? 1) === 2));

            $draws[$divName] = $this->makeDivisionEntry($allTeams);
            $draws[$divName]['rounds'] = $this->buildRoundRobin(
                $baseTeams, $altTeams, $rounds, $courts, $courtCounter
            );
        } else {
            // Multiple divisions — cross-pair odd divisions
            $divNames  = array_keys($divisions);
            $divArrays = array_values($divisions);
            $numDivs   = count($divArrays);

            for ($d = 0; $d < $numDivs; $d++) {
                $divTeams  = $this->sortByDuprThenSkill($divArrays[$d]);
                $baseTeams = array_values(array_filter($divTeams, fn($t) => ($t['partner_slot'] ?? 1) === 1));
                $altTeams  = array_values(array_filter($divTeams, fn($t) => ($t['partner_slot'] ?? 1) === 2));

                if (count($baseTeams) % 2 !== 0) {
                    $borrowed = $this->borrowTeamFromAdjacent($baseTeams, $divArrays, $d, $numDivs);
                    if ($borrowed !== null) {
                        $borrowed['_borrowed'] = true;
                        $baseTeams[] = $borrowed;
                    }
                }

                $draws[$divNames[$d]] = $this->makeDivisionEntry(array_values($divArrays[$d]));
                $draws[$divNames[$d]]['rounds'] = $this->buildRoundRobin(
                    $baseTeams, $altTeams, $rounds, $courts, $courtCounter
                );
                $courtCounter += $courts;
            }
        }

        return $draws;
    }

    private function sortByDuprThenSkill(array $teams): array
    {
        usort($teams, function ($a, $b) {
            $aD = $a['combined_dupr'] ?? null;
            $bD = $b['combined_dupr'] ?? null;
            if ($aD !== null && $bD !== null) return $bD <=> $aD;
            if ($aD !== null) return -1;
            if ($bD !== null) return 1;
            return ($b['avg_skill'] ?? 0) <=> ($a['avg_skill'] ?? 0);
        });
        return $teams;
    }

    private function makeDivisionEntry(array $teams): array
    {
        $base    = array_filter($teams, fn($t) => ($t['partner_slot'] ?? 1) === 1);
        $skills  = array_column(array_values($base), 'avg_skill');
        $duprs   = array_filter(array_column(array_values($base), 'combined_dupr'), fn($v) => $v !== null);
        return [
            'teams'     => array_values($teams),
            'avg_skill' => count($skills) ? round(array_sum($skills) / count($skills), 2) : 0,
            'dupr_avg'  => count($duprs)  ? round(array_sum($duprs)  / count($duprs),  2) : null,
            'rounds'    => [],
        ];
    }

    private function borrowTeamFromAdjacent(array $baseTeams, array $divArrays, int $divIdx, int $numDivs): ?array
    {
        $boundary   = min(array_column($baseTeams, 'avg_skill'));
        $candidates = [];

        if ($divIdx + 1 < $numDivs) {
            foreach ($divArrays[$divIdx + 1] as $t) {
                if (($t['partner_slot'] ?? 1) === 1) {
                    $candidates[] = ['team' => $t, 'gap' => abs($t['avg_skill'] - $boundary)];
                }
            }
        }
        if (empty($candidates) && $divIdx - 1 >= 0) {
            $boundary = max(array_column($baseTeams, 'avg_skill'));
            foreach ($divArrays[$divIdx - 1] as $t) {
                if (($t['partner_slot'] ?? 1) === 1) {
                    $candidates[] = ['team' => $t, 'gap' => abs($t['avg_skill'] - $boundary)];
                }
            }
        }

        if (empty($candidates)) return null;
        usort($candidates, fn($a, $b) => $a['gap'] <=> $b['gap']);
        return $candidates[0]['team'];
    }

    /**
     * Round Robin — circle method with bye-deficit rotation and alt-partner support.
     *
     * Bye rotation:
     *   When $baseTeams count is odd, one team sits out each round.
     *   The team selected is the one with the highest bye_deficit (most
     *   overdue for a bye). Playing increments deficit; sitting out decrements
     *   it, keeping the bye distribution as even as possible.
     *   The sitting-out team is assigned a real court and shown as "Bye or Singles".
     *
     * Alt-partner alternation:
     *   Even rounds prefer slot-2 teams where available.
     *   IMPORTANT: A slot-2 swap is only applied if neither player in the alt team
     *   is already committed to another match in the same round. This prevents a
     *   player with two partners from appearing in two games simultaneously.
     */
    private function buildRoundRobin(
        array $baseTeams, array $altTeams, int $requestedRounds, int $courts, int $courtOffset
    ): array {
        if (empty($baseTeams)) return [];

        $lookup = [];
        foreach (array_merge($baseTeams, $altTeams) as $t) {
            $lookup[$t['id']] = $t;
        }

        $slot2ByPlayer = [];
        foreach ($altTeams as $t) {
            $slot2ByPlayer[$t['player1']] = $t['id'];
            $slot2ByPlayer[$t['player2']] = $t['id'];
        }

        $ids    = array_column($baseTeams, 'id');
        $n      = count($ids);
        $isOdd  = $n % 2 !== 0;

        // Total rounds in a full round-robin: n for odd, n-1 for even
        $totalRounds = $isOdd ? $n : $n - 1;
        $rounds      = min($requestedRounds, $totalRounds);

        // Bye deficit: higher = more overdue for a bye round
        $byeDeficit = array_fill_keys($ids, 0.0);

        $roundsOut = [];

        for ($r = 0; $r < $rounds; $r++) {
            $roundNum = $r + 1;
            $useAlt   = ($roundNum % 2 === 0) && !empty($altTeams);
            $matches  = [];
            $courtNum = $courtOffset;

            // Determine base pairs for this round
            if ($isOdd) {
                arsort($byeDeficit);
                $byeId     = (int)array_key_first($byeDeficit);
                $activeIds = array_values(array_filter($ids, fn($id) => $id !== $byeId));
                $pairs     = $this->circleMethodPairs($activeIds, $r, $n);
            } else {
                $byeId = null;
                $pairs = $this->circleMethodPairs($ids, $r, $n);
            }

            // ── Phase 1: resolve which team (slot-1 or slot-2) plays each pair ────
            // We must determine ALL team assignments first before committing, so that
            // we can detect and prevent a player appearing in two matches.

            // Build the set of players committed by slot-1 assignments
            $committedPlayers = []; // player_name => true

            // First pass: record all slot-1 players for this round
            foreach ($pairs as [$id1, $id2]) {
                $t1 = $lookup[$id1] ?? null;
                $t2 = $lookup[$id2] ?? null;
                if (!$t1 || !$t2) continue;
                $committedPlayers[$t1['player1']] = true;
                $committedPlayers[$t1['player2']] = true;
                $committedPlayers[$t2['player1']] = true;
                $committedPlayers[$t2['player2']] = true;
            }
            // Also add the bye player as committed
            if ($isOdd && $byeId !== null) {
                $byeTeam = $lookup[$byeId] ?? null;
                if ($byeTeam) {
                    $committedPlayers[$byeTeam['player1']] = true;
                    $committedPlayers[$byeTeam['player2']] = true;
                }
            }

            // Second pass: resolve alt swaps, checking for conflicts
            // When we swap a team to its alt, we temporarily "free" the slot-1 players
            // and "commit" the alt players — but only if the alt players aren't
            // already committed to a different match.
            $resolvedPairs = [];
            // Track which players are already locked into a resolved match
            $lockedPlayers = [];

            foreach ($pairs as [$id1, $id2]) {
                $t1 = $lookup[$id1] ?? null;
                $t2 = $lookup[$id2] ?? null;
                if (!$t1 || !$t2) continue;

                $altLabel = '';

                if ($useAlt) {
                    // Try alt for team1 — only if alt players are not already locked
                    $a1 = $this->swapToAlt($t1, $slot2ByPlayer, $lookup);
                    if ($a1 !== null) {
                        if (!isset($lockedPlayers[$a1['player1']]) &&
                            !isset($lockedPlayers[$a1['player2']])) {
                            $t1 = $a1;
                            $altLabel = '2nd partner';
                        }
                        // else: keep slot-1 to avoid duplicate
                    }

                    // Try alt for team2 — only if alt players are not already locked
                    $a2 = $this->swapToAlt($t2, $slot2ByPlayer, $lookup);
                    if ($a2 !== null) {
                        if (!isset($lockedPlayers[$a2['player1']]) &&
                            !isset($lockedPlayers[$a2['player2']]) &&
                            // Also ensure alt-2 players don't clash with the (possibly already-swapped) t1
                            $a2['player1'] !== $t1['player1'] && $a2['player1'] !== $t1['player2'] &&
                            $a2['player2'] !== $t1['player1'] && $a2['player2'] !== $t1['player2']) {
                            $t2 = $a2;
                            $altLabel = $altLabel ? 'Alt partners' : '2nd partner';
                        }
                    }
                }

                // Lock both resolved players for the rest of this round
                $lockedPlayers[$t1['player1']] = true;
                $lockedPlayers[$t1['player2']] = true;
                $lockedPlayers[$t2['player1']] = true;
                $lockedPlayers[$t2['player2']] = true;

                $resolvedPairs[] = [$t1, $t2, $altLabel];
            }

            // ── Phase 2: emit matches ─────────────────────────────────────────────
            foreach ($resolvedPairs as [$t1, $t2, $altLabel]) {
                $matches[] = [
                    'team1_id'    => $t1['id'],
                    'team1'       => $t1['player1'] . ' / ' . $t1['player2'],
                    'team2_id'    => $t2['id'],
                    'team2'       => $t2['player1'] . ' / ' . $t2['player2'],
                    'court'       => (($courtNum - $courtOffset) % $courts) + $courtOffset,
                    'alt_partner' => $altLabel,
                    'is_bye'      => false,
                ];
                $courtNum++;

                if ($isOdd) {
                    $byeDeficit[$t1['id']] = ($byeDeficit[$t1['id']] ?? 0) + 1;
                    $byeDeficit[$t2['id']] = ($byeDeficit[$t2['id']] ?? 0) + 1;
                }
            }

            // Bye team gets a court
            if ($isOdd && $byeId !== null) {
                $byeTeam = $lookup[$byeId] ?? null;
                if ($byeTeam) {
                    $matches[] = [
                        'team1_id'    => $byeTeam['id'],
                        'team1'       => $byeTeam['player1'] . ' / ' . $byeTeam['player2'],
                        'team2_id'    => 'BYE',
                        'team2'       => '',
                        'court'       => (($courtNum - $courtOffset) % $courts) + $courtOffset,
                        'alt_partner' => '',
                        'is_bye'      => true,
                    ];
                }
                $byeDeficit[$byeId] = ($byeDeficit[$byeId] ?? 0) - 1;
            }

            $roundsOut[$roundNum] = $matches;
        }

        return $roundsOut;
    }

    /**
     * Generate pairs for round index $r using the circle method.
     * $n is the total count of IDs (must be even).
     */
    private function circleMethodPairs(array $ids, int $r, int $n): array
    {
        $nLocal = count($ids);
        if ($nLocal < 2 || $nLocal % 2 !== 0) return [];

        $denominator = max($n - 1, 1);
        $rotation    = $r % $denominator;

        $circle = array_slice($ids, 1);
        for ($rot = 0; $rot < $rotation; $rot++) {
            array_unshift($circle, array_pop($circle));
        }

        $pairs = [[$ids[0], $circle[0]]];
        for ($i = 1; $i <= ($nLocal / 2) - 1; $i++) {
            $pairs[] = [$circle[$i], $circle[$nLocal - 1 - $i]];
        }
        return $pairs;
    }

    /**
     * If any player in $team has a slot-2 alternate, return the alternate team.
     */
    private function swapToAlt(array $team, array $slot2ByPlayer, array $lookup): ?array
    {
        foreach (['player1', 'player2'] as $key) {
            $name = $team[$key] ?? '';
            if (isset($slot2ByPlayer[$name]) && isset($lookup[$slot2ByPlayer[$name]])) {
                return $lookup[$slot2ByPlayer[$name]];
            }
        }
        return null;
    }

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
