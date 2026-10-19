# Changelog

All notable changes to this project are documented here. Earlier releases are
available from the git tags (`v1.0.1`, …).

## [1.0.2] - 2026-10-10

Baseline: **DuckPHP 1.4.1** (`dvaknheo/duckphp: ^1.4.1`, including the
`DuckPhp\HttpServer\HttpServer` component), `phpunit/php-code-coverage ^9.2.32`.

### Added

- **`--jsonl[=FILE]` / `--jsonl-detail=none|uncovered|full` / `--jsonl-no-timestamp` / `--format=jsonl`**
  — a line-oriented report (one JSON object per line) for diffing and batch tooling;
  bare `--jsonl` writes `<report dir>/report.jsonl`.
- **`duckcoverage_exclude` option and `exclude($paths)` method** — keep directories or
  files out of collection and out of the HTML report, `report.json` and `report.jsonl`.
  Relative entries resolve against `duckcoverage_path_src` (a trailing `/` marks a
  directory); absolute paths and `*` / `?` globs are accepted too.
- **`--flag=<value>` / `getFlag()` / `X-DuckCoverage-Flag`** — carry a free-form value into
  the application (honoured only while `duckcoverage_enable` is on, so an incoming header
  cannot influence an application with the tool switched off).
- **Test-list macros** — `#PHASE_BEGIN`, `#PHASE_END`, `#CURRENT_PHASE`, `#INCLUDE_CALL`,
  `#INCLUDE_CHILD`, `#BUSINESS`, `#MODEL`, `#ACTION`, `#ADMIN_LOGIN/LOGOUT/CLEAN` and
  `#USER_LOGIN/LOGOUT/CLEAN`; `explainMarco()` is now public, `TestListerHelper` ships
  ready-made callbacks, and `TestListWithAuthBase` dispatches per login / logout / clean.
- **`report.json`** next to the HTML report: per-file lines, functions, classes, traits,
  uncovered / TODO / signature lines, and totals.
- **`logException()` and `DuckCoverage.exception.log`** — `CALL` failures are logged and
  playback continues instead of aborting the run.
- **`RUN` executes in a new process** (`proc_open`); the child collects and dumps its own
  coverage, `duckcoverage_run_entry` selects the CLI entry script, and the previous
  in-process behaviour is the fallback when no entry can be resolved.
- **`--fail-on-empty`** — exit code `2` when no dump was merged (default stays `0`).
- `watchingGetName()` is public.

### Changed

- Definition/signature lines are no longer counted as executable, so declaration-only
  lines stop showing up as “not executed”.
- `--report` empties its output directory first and prints `html report:` / `json report:`.
- Empty results are reported loudly (a warning record in the machine-readable output and a
  prominent console message) instead of being silently swallowed.

### Fixed

- `Prepare()` registers `DuckCoverage` as a shared class **before** resolving the instance:
  sub-application phases no longer create a second, uninited instance (which made
  `getFlag()` / `watchingGetName()` return empty inside sub-application listers).
- Excluded paths now take effect at the right time (after the application options are
  merged), are pruned from already-collected dumps, and are blacklisted in the
  php-code-coverage `Filter` (recursively for directories) so the HTML report matches
  `report.json`.
- Various report / JSONL edge cases: empty dumps, 0/0 percentages, and `sig` handling.
