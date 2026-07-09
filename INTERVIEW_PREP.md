# Interview Prep — Excessive Cancellations Checker

Talking-points doc. Goal: be able to explain the file layout and defend every
design decision out loud, including the ones that are genuinely debatable.
For the formal audit trail, see `DECISIONS.md` — this file is the spoken
version of it, plus follow-up questions you should expect.

---

## 1. The 30-second summary

> "The task is: read a CSV of trade messages (new order or cancel), group
> them by company, and for each company check whether there's ever a
> 60-second rolling window where cancelled quantity is more than a third of
> total quantity. If so, that company is 'excessive.'
>
> I implemented it as a per-company **sliding window** (two-pointer) scan —
> O(n) per company, one pass, no re-scanning. Malformed lines are skipped,
> not fatal. Results are memoized so the two public methods don't
> re-parse the file twice."

That's the elevator pitch. Everything below is backup for "why," not "what."

---

## 2. File layout — what's where and why

```
php-storytec/
├── README.md                  # Original problem spec — DO NOT MODIFY
├── DECISIONS.md               # Formal audit trail of every interpretation call
├── CLAUDE.md                  # Project constraints for AI-assisted work
├── INTERVIEW_PREP.md          # This file
└── php/
    ├── composer.json          # Deps: PHPUnit ^10.5 only, PSR-4 autoload
    ├── data/trades.csv        # Input fixture — DO NOT MODIFY
    ├── src/
    │   ├── ExcessiveCancellationsChecker.php   # Public API + orchestration
    │   └── TradeEvent.php                      # Value object for one parsed line
    └── tests/
        ├── ExcessiveCancellationsCheckerTest.php          # LOCKED grader test
        └── ExcessiveCancellationsCheckerEdgeCasesTest.php # My own tests
```

**Why this shape, if asked:**

- **`ExcessiveCancellationsChecker.php` is the only public entry point** —
  matches the required API exactly (`companiesInvolvedInExcessiveCancellations()`,
  `totalNumberOfWellBehavedCompanies()`), everything else is private
  implementation detail (`parseTradesByCompany`, `parseLine`, `parseTimestamp`,
  `isExcessiveCancelling`).
- **`TradeEvent` is a separate, tiny readonly class** rather than inlining
  fields into arrays. It was actually added *after* a self-review pass —
  originally events were raw tuples like `[$timestamp, $type, $quantity]`.
  That's a "magic index" smell: `$event[0]` doesn't tell a reader what it
  is. A one-line value object (`$event->timestamp`) costs almost nothing
  and removes an entire class of "which index was quantity again?" bugs.
- **Two test files, not one** — `ExcessiveCancellationsCheckerTest.php` is
  explicitly locked by the exercise rules (can't be touched, asserts against
  the real `trades.csv`). All of *my* tests — edge cases, boundary
  conditions, malformed-input handling — live in a second file so the
  separation of "given" vs "added" tests is unambiguous to a grader.
- **`DECISIONS.md` exists because the spec is genuinely underspecified in
  places** (see §4). Rather than silently picking an interpretation, I
  wrote down every judgment call, the reasoning, and — critically — how to
  flip it if a grader disagrees. That's the single thing I'd point to as
  the most "senior" habit in this solution: making tacit decisions explicit
  and reversible instead of hoping nobody asks.

---

## 3. The algorithm, explained simply

For each company, independently:

1. Walk its events **in time order** (two pointers: `i` moves forward one
   event at a time; `windowStart` also only moves forward, never resets).
2. Maintain running totals `orderQty` and `cancelQty` for "everything
   currently in the window."
3. Before evaluating the ratio at event `i`, expire anything from the front
   of the window that's now more than 60 seconds older than event `i`
   (`while events[windowStart].timestamp <= current.timestamp - 60`).
4. Check the ratio at every step, not just at the end — "excessive" means
   *any* 60-second window crosses the threshold, so you can't just look at
   the final totals.
5. Because `windowStart` only ever moves forward and each event is added
   and removed from the running totals exactly once, this is **O(n) per
   company**, not O(n²) (no repeated re-summing of the window).

**Why per-company instead of one global pass:** the ratio is defined per
company, and windows for different companies are independent — interleaving
them into one pass would just mean re-deriving per-company state anyway, so
grouping first (`array<string, TradeEvent[]>`) is simpler and the grouping
itself is O(n).

---

## 4. Every design decision, as Q&A

### Q: Why is the window `(t-60, t]` and not `[t-60, t]`?
The spec's own worked example (Bank of Mars) only makes sense under one
reading. It explicitly excludes an order that is *exactly* 60 seconds before
the flagged cancel — if that boundary were inclusive, the example's numbers
would come out differently. So: an event exactly 60s in the past has already
left the window. I verified this by hand-tracing the full worked example
against the code before trusting it, and locked it in with two dedicated
tests (`...ExactlySixtySecondsApartFallOutsideWindow` /
`...FiftyNineSecondsApartFallInsideWindow`).

### Q: Why integer cross-multiplication instead of `cancelQty / total > 1/3`?
Floating point division of two integers can introduce rounding error right
at the boundary you care about most — exactly 1/3. `cancelQty * 3 > total`
is mathematically identical for the ratio check but uses only integer
arithmetic, so there's no float representation issue and the "strictly
greater than, not equal to" requirement is exact, not approximate.

### Q: Why does a lone cancel with no prior order not count as excessive?
**This is the one decision I'd flag as genuinely debatable if asked.**
The spec never states this case — it only says a lone *order* isn't
excessive (trivially true, zero cancels). It says nothing about a lone
*cancel* with no order in its window, where the ratio is undefined (0
orders, positive cancels).

I chose to require **at least 2 events in a window** before evaluating the
ratio at all — a single message isn't really a "trading period" to judge,
it might just mean the matching order happened slightly outside this
window. The counter-argument: read completely literally, "cumulative
cancels / cumulative orders" with 0 orders and >0 cancels is arguably the
*most* obviously excessive case (infinite ratio), not an edge case to
suppress.

I discussed this trade-off explicitly and decided to keep the current
(suppress) behavior, but the fix is a one-line change if a grader's
expectations disagree — I called that out directly in `DECISIONS.md` #3
specifically so it wouldn't look like an oversight if it comes up.

### Q: What counts as a malformed line, and why?
A line is skipped (not fatal) if: it doesn't split into exactly 4 fields;
company name is empty; order type isn't exactly `D` or `F` (a stray leading
space like `" D"` is rejected — intentional, real data shouldn't have that);
quantity isn't all digits (rejects negatives and non-numeric junk via
`ctype_digit`); or the timestamp doesn't round-trip exactly through
`Y-m-d H:i:s` (rejects impossible dates like `2015-02-30`, not just
unparsable strings — `DateTime::createFromFormat` is surprisingly lenient
about overflow by default, so the round-trip check is what actually catches
it).

### Q: Why parse timestamps in a fixed UTC timezone?
So window-length arithmetic (`timestamp - 60`) can never be distorted by a
DST transition depending on where the test suite happens to run. Using the
runtime's default timezone would make the same input behave differently on
different machines — a subtle, hard-to-reproduce bug.

### Q: Why memoize?
Both public methods need the same per-company analysis. Without caching,
calling both means parsing and analyzing the file twice for no reason.
`analyzeIfNeeded()` runs once, and a dedicated test
(`testResultsAreMemoizedAndConsistentRegardlessOfCallOrder`) proves the
two methods can be called in either order without drift.

### Q: What changed in the post-review hardening pass, and why?
Three things, none changing observable behavior for any previously-passing
test:
- Extracted `TradeEvent` (see §2).
- Added a **defensive per-company sort by timestamp** before running the
  window. The spec guarantees the whole file is globally time-ordered, so
  this should be a no-op in practice — but the sliding window's pointer
  only ever moves forward, so if that assumption were ever violated for one
  company (e.g. a hand-edited fixture), it would previously corrupt results
  *silently*. A cheap `usort` turns a silent-corruption risk into a
  guaranteed-correct-or-visibly-different outcome for near-zero cost.
- Suppressed `fopen()`'s native PHP warning with `@`, since a failed open is
  already converted into a `RuntimeException` immediately after — the raw
  warning was just noise (and was tripping PHPUnit's risky-test detector
  once I added a test for the missing-file path).

---

## 5. Anticipated follow-up questions

**"What's the time and space complexity?"**
O(n) time overall (one pass to group by company, one pass per company for
the window scan, and totals across companies still sum to n). O(n) space —
the full file is parsed into memory upfront rather than streamed
window-by-window across companies.

**"How would this change at real scale (e.g. gigabytes of trades)?"**
The per-company grouping step currently loads everything into memory
before analysis. At real scale I'd want to stream: since the file is
globally time-ordered, you could process it in one pass while keeping only
a bounded per-company window (using a `SplQueue` or trimmed array per
company) rather than materializing the entire per-company event list
first. That changes peak memory from O(n) to roughly O(active companies ×
window size).

**"How would you test this differently with more time?"**
Property-based/fuzz testing on the parser (random malformed permutations)
and a synthetic large-file generator to check performance characteristics,
plus a mutation-testing pass (e.g. Infection) to check the edge-case suite
actually kills mutants around the ratio boundary and window boundary, not
just happens to pass.

**"Why not use `str_getcsv` instead of `explode(',', $line)`?"**
The spec guarantees company names contain no commas, so there's no
quoting/escaping to handle — `explode` is faster and simpler, and using
`str_getcsv` would just be defending against a case the input format
explicitly rules out.

**"What would you refactor if this class grew?"**
Split responsibilities: a `TradeLineParser` (I/O + line validation) and a
`CancellationWindowAnalyzer` (pure algorithm, testable with in-memory
`TradeEvent[]` with no file I/O at all). At the current size, one class is
still easy to read end-to-end, so I didn't force the split prematurely.
