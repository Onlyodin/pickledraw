# Pickledraw
This project is for a website to create a training or practice draw for tournament preparation sessions.


## How it works
You upload a list of players, or a CSV file containing a list of players and their partners, confirm the partners and skill levels, and then the application will attempt to create a draw with similarly skilled teams.

---

## Requirements

- PHP 7.4+ (PHP 8.x recommended)
- A web server (Apache, Nginx) or PHP's built-in server

---

## File Structure

```
pickledraw/
├── index.php           # Main 3-step UI (import → configure → draw)
├── export.php          # CSV download of the generated draw
├── css/
│   └── style.css       # Stylesheet (dark green pickleball theme)
├── js/
│   └── app.js          # Client-side: drag-drop, sample data, interactions
└── php/
    ├── CSVParser.php   # Parses Eventbrite-style attendee CSV exports
    └── DrawEngine.php  # Team pairing, skill grouping, match generation
```

---

## CSV Format

Pickledraw expects the following columns (standard Eventbrite attendee export):

| Column | Description |
|--------|-------------|
| `Date Created` | Order creation date |
| `Order ID` | Eventbrite order reference |
| `Purchaser User ID` | Purchaser's user ID |
| `Attendee ID` | Individual attendee ID |
| `Attendee First Name` | **Required** — player's first name |
| `Attendee Last Name` | **Required** — player's last name |
| `Status` | Attending / Not Attending / Cancelled etc. |
| `Ticket Class` | e.g. Mixed Doubles, Men's Doubles, Women's Doubles |
| `Ticket Class Price` | Ticket price |
| `Is Guest` | Yes / No |
| `Checked in` | Yes / No |
| `Checked in Date` | Date/time of check-in |
| `Name of partner(s)` | **Required** — full name of their doubles partner |
| `I am registered to play in a tournament at this level.` | **Required** — skill rating (e.g. `3.5`, `4.0`, `3.5 - Intermediate`) |

**Notes:**
- Columns can be in any order — Pickledraw maps them by name.
- The header row is always expected.
- Attendees with status `Cancelled`, `Refunded`, `Not Attending`, or `Deleted` are automatically excluded.
- Partner matching is **one-way sufficient**: if Player A names Player B as partner, both are paired even if B left the field blank.
- Players without a matched partner are **auto-paired** with someone of the closest skill level.

---

## Tournament Formats

| Format              | Description                                         |
|---------------------|-----------------------------------------------------|
| **Round Robin**     | Every team plays every other team in the division   |
| **Single Elim**     | Bracket-style, losers are eliminated                |
| **Pool Play+Finals**| Snake-seeded pools, then semi-finals and a final    |

---

## Skill Divisions

Teams are grouped into divisions (A, B, C…) based on average player skill. Highest skill = Division A.

| Bands | Divisions |
|-------|-----------|
| 1 | All teams in one draw |
| 2 | A (top 50%), B (bottom 50%) |
| 3 | A, B, C |
| 4 | A, B, C, D |
| 5 | Five bands — open/competitive format |

---

## Export & Print

After generating a draw:

- **⬇ Export CSV** — downloads the full draw as a UTF-8 CSV (Excel-compatible), including the team list and all rounds with a blank Score column for manual entry.
- **🖨 Print** — print-optimised stylesheet hides the import form, leaving only the draw output.

---