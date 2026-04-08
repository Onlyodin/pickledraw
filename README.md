# Pickledraw
This project is for a website to create a training or practice draw for tournament preparation sessions.


## How it works
You upload a list of players, or a CSV file containing a list of players and their partners, confirm the partners and skill levels, and then the application will attempt to create a draw with similarly skilled teams.

---

## Requirements

| Requirement | Notes |
|-------------|-------|
| PHP 7.4+ | PHP 8.x recommended |
| PHP `ZipArchive` extension | Standard on all major hosts; required for Word export |
| Apache or Nginx | Any web server running PHP; `php -S` also works for local dev |,

---

## File Structure

```
pickledraw/
├── index.php           # Main 3-step UI (import → configure → draw)
├── export.php          # CSV download of the generated draw
├── export_docx.php     # Word Doc download of the generated draw
├── css/
│   └── style.css       # Stylesheet (dark green pickleball theme)
├── js/
│   └── app.js          # Client-side: drag-drop, sample data, interactions
└── php/
    ├── CSVParser.php   # Parses Eventbrite-style attendee CSV exports
    └── DrawEngine.php  # Team pairing, skill grouping, match generation
```

---
## Step 1 — Import Registration Data
 
### CSV Column Format
 
Pickledraw is designed around Eventbrite attendee exports but handles many column naming variations from Google Forms and custom spreadsheets.
 
**Critical columns** (must be present in some recognisable form):
 
| Column | Description |
|--------|-------------|
| `Attendee First Name` | Player's first name |
| `Attendee Last Name` | Player's last name |
| `Name of partner(s)` | Partner's full name — up to two partners |
| `I am registered to play in a tournament at this level.` | Skill / division level |
 
**Optional columns** (used for display if present):
 
| Column | Description |
|--------|-------------|
| `Date Created` | Order creation date |
| `Order ID` | Eventbrite order reference |
| `Attendee ID` | Individual attendee identifier |
| `Status` | Attending / Cancelled / Refunded etc. |
| `Ticket Class` | e.g. Mixed Doubles, Men's Doubles |
| `Checked in` | Yes / No |
| `Please enter your DUPR…` | Individual DUPR rating |
 
### Column Name Flexibility
 
The parser uses three-pass matching (exact → strip trailing punctuation → keyword substring) so it handles real-world variations automatically. Examples of recognised phrasings:
 
**Partner column:**
- `Name of partner(s)` · `Name of partner/partners - full name`
- `Please name your partner/partners (2 maximum)` · `Your partner` · `Doubles partner`
 
**Division/skill column:**
- `I am registered to play in a tournament at this level.` (period or colon)
- `Select the DUPR that you are registered in, or plan to register in for a tournament`
- `Tournament level` · `Tournament division` · `Division` · `Level`
 
**DUPR column:**
- `DUPR` · `DUPR rating` · `My DUPR`
- `Please enter your DUPR (or best guess if you don't have a DUPR)`
 
The parser also strips UTF-8 BOM, non-breaking spaces, and zero-width characters that some export tools add to headers.
 
### Skill Level Parsing
 
| Input | Interpreted as |
|-------|---------------|
| `3.5`, `4.0`, `2.5` | Used as-is |
| `3.5 - Intermediate`, `4.0 Open` | Leading number extracted |
| `Under 2.5`, `Below 3.0`, `< 3.0` | Mapped just below the threshold |
| `Beginner` | 2.5 |
| `Intermediate` | 3.5 |
| `Advanced` | 4.0 |
| `Open` | 4.5 |
 
### Column Detection Panel
 
After importing, a collapsible **"Column mapping detected"** summary appears. Each critical column shows ✓ (detected, with column number) or ✗ (not found), and a warning banner lists any missing critical columns.
 
### Excluded Attendees
 
Rows with status `Cancelled`, `Refunded`, `Not Attending`, `Deleted`, or `Void` are automatically skipped.
 
---
 
## Step 1 — Managing Players After Import
 
### Partner Matching
 
- **One-way declaration is sufficient** — if Player A names Player B, both are marked as paired even if B left the field blank.
- **Two-partner support** — separate names with a comma, semicolon, ` and `, or ` & `. In the draw, a player with two partners alternates: Partner 1 on odd rounds, Partner 2 on even rounds.
- Unresolved partners show a colour-coded dropdown: choose a specific partner or leave as **⟳ Auto-pair by skill**.
- Confirmed pairs show a **✕ unlink** button to separate them without restarting.
- Selecting a partner **mirrors** the selection on the partner's row automatically.
 
### Manual Player Management
 
- **+ Add Player** — modal dialog with First Name, Last Name, Tournament Division, DUPR, Partner 1, Partner 2. The partner fields offer autocomplete from existing attendees.
- **🗑 Delete** — removes a player with an animated row fade. Also unlinks their confirmed partner. The draw is cleared so it must be regenerated.
 
### Tournament Division Override
 
Each player's division can be changed via an inline dropdown (Under 2.5 · 2.5–2.99 · 3.0–3.49 · 3.5–3.99 · 4.0+). A `↑CSV` indicator shows when the value came from the file; it changes to `✎` when manually overridden. Overrides persist through the session.
 
### DUPR Rating
 
A free-text field per player for individual DUPR. When both players in a confirmed pair have DUPR entered, their **combined DUPR** is displayed on team chips in the draw and used for seeding within a division.
 
---
 
## Step 2 — Configure Draw
 
| Setting | Default | Options |
|---------|---------|---------|
| Format | Round Robin | Round Robin / Single Elimination / Pool Play + Finals |
| Rounds | 8 | 1–20 |
| Courts Available | 11 | 1–30 |
| Skill Divisions | 1 — All play together | 1 / 2 / 3 / 4 / 5 |
 
### Skill Division Bands
 
| Setting | Behaviour |
|---------|-----------|
| **1** (default) | All teams in one draw — no division headers displayed |
| 2 | Two divisions split by skill midpoint |
| 3–4 | Dynamic equal-range bands |
| 5 | Fixed pickleball bands: A (4.0+), B (3.5–3.99), C (3.0–3.49), D (2.5–2.99), E (Under 2.5) |
 
---
 
## Step 3 — Draw Output
 
### Round Robin Scheduling
 
Matches are generated using the **circle method**, which produces a complete round-robin schedule with no repeat matchups. Teams are seeded by combined DUPR (descending) where available, then by average skill.
 
### No Division Headers (Single Division)
 
When all teams play together (1 division), the division badge and stat bar are hidden and you see the team chips and round schedule directly.
 
### Cross-Division Pairing
 
If a division has an **odd number of teams**, the boundary team is paired with the most similarly-skilled team from the adjacent division. This prevents a team going without a match due to an odd division size.
 
### Bye or Singles
 
When the **total number of teams is odd** after any cross-division pairing, one team sits out per round. They:
- Are assigned a **real court number** so it isn't wasted
- Appear as a distinct **"Bye or Singles"** card (muted, dashed border)
- Are **rotated evenly** — a deficit counter increments for every team that plays and decrements when they sit out, so the bye is distributed as fairly as possible across all rounds
 
### Two-Partner Alternation
 
Players with two named partners play with Partner 1 on odd rounds and Partner 2 on even rounds. Match cards are tagged `↕ 2nd partner` or `↕ Alt partners` when alternates are in play. Slot-2 teams appear in the teams row with a `2nd partner` badge.
 
---
 
## Export & Print
 
| Option | Description |
|--------|-------------|
| **🖨 Print** | Print-optimised stylesheet — import form, controls, and dropdowns are hidden |
| **⬇ Export CSV** | UTF-8 CSV with BOM (Excel-compatible). Includes the team list and all rounds with a blank Score column, pool/stage labels, and alt-partner notation |
| **📄 Export Word** | `.docx` download generated entirely in PHP via `ZipArchive`. No Node.js, no Composer packages. Includes cover page, registered teams table, and per-division match schedule with alternating row shading and page breaks between divisions |
 
### Word Export — Server Requirements
 
Only PHP's standard `ZipArchive` extension is required (enabled by default on Fedora, Ubuntu, Debian, and most cPanel/Plesk hosts). If it is somehow disabled, `export_docx.php` returns a plain-text error message explaining how to enable it.
 
---
 
## Architecture Notes
 
### CSVParser.php
 
- Strips UTF-8 BOM and invisible Unicode characters from headers and cell values before matching
- Three-pass header resolution: exact → strip trailing `.:/!?` → substring keyword
- `parsePartnerNames()` splits the partner field into up to two names on comma/semicolon/` and `/` & `
- `resolvePartners()` — one-way matching sufficient; includes first/last name reversal and substring fallback
- `parseSkillLevel()` — handles bare numbers, `Under X` phrases, and text-only labels
 
### DrawEngine.php
 
- `buildTeams()` — Pass 1: named primary partners (slot 1); Pass 2: named secondary partners (slot 2); Pass 3: auto-pair remaining by closest skill
- `groupBySkill()` — routes into 1–5 divisions; `numBands ≤ 1` returns a single `All Teams` pool
- `buildDraw()` — single-division fast path vs. multi-division with cross-pairing
- `borrowTeamFromAdjacent()` — finds the closest-skill team in an adjacent division when a division has an odd count
- `buildRoundRobin()` — circle method; odd-team bye rotation via deficit counter; alt-partner swapping on even rounds
- `circleMethodPairs()` — stateless pair generation for any round index, avoids re-simulating prior rotations


---