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
├── index.php           # Main application entry point
├── export.php          # CSV export handler
├── css/
│   └── style.css       # Stylesheet
├── js/
│   └── app.js          # Client-side interactivity
└── php/
    ├── CSVParser.php   # Parses delimited player data
    └── DrawEngine.php  # Team matching + draw generation
```

---

## CSV / Data Format

**Minimum required columns** (in any order, with or without a header row):

| Column       | Description                                  | Example          |
|--------------|----------------------------------------------|------------------|
| Player Name  | Full name of the player                      | `Alice Thompson` |
| Skill Level  | Numeric rating from 1 (beginner) to 10 (pro) | `8.5`            |
| Partner Name | Exact name of their doubles partner          | `Ben Clarke`     |

**Example CSV:**
```csv
Player Name,Skill,Partner
Alice Thompson,8.5,Ben Clarke
Ben Clarke,8,Alice Thompson
Carol Wright,7.5,Dan Fisher
...
```

**Notes:**
- Header row is auto-detected and can be skipped.
- Supported delimiters: comma, semicolon, tab, pipe — auto-detected or selectable.
- Partner pairing is bidirectional: both players must name each other as partner to be explicitly paired.
- Players without a matched partner are automatically paired with someone of similar skill.
- Odd players out receive a BYE note.

---

## Tournament Formats

| Format              | Description                                         |
|---------------------|-----------------------------------------------------|
| **Round Robin**     | Every team plays every other team in the division   |
| **Single Elim**     | Bracket-style, losers are eliminated                |
| **Pool Play+Finals**| Snake-seeded pools, then semi-finals and a final    |

---

## Skill Divisions

Teams are grouped into divisions (A, B, C…) based on the average skill of both players. The number of bands (2–5) is configurable.

| Band Setting | Divisions                       |
|--------------|---------------------------------|
| 2            | A (high), B (low)               |
| 3            | A, B, C                         |
| 4            | A, B, C, D                      |
| 5            | Open / unrestricted banding     |

---

## Exporting

After generating a draw, click **⬇ Export CSV** to download the complete draw as a spreadsheet-ready CSV, including:
- Team list with skill ratings and pairing status
- Full match schedule per division and round
- Empty score column for manual entry

---

## Printing

Click **🖨 Print** — the input forms and navigation are hidden in the print stylesheet, leaving only the draw results.

---