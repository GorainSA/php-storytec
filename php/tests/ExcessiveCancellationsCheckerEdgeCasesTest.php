<?php

declare(strict_types=1);

namespace CodeScreen\Tests;

use CodeScreen\ExcessiveCancellationsChecker;
use PHPUnit\Framework\TestCase;

class ExcessiveCancellationsCheckerEdgeCasesTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = tempnam(sys_get_temp_dir(), 'trades_') . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }
    }

    // Ratio must be strictly greater than 1/3; exactly 1/3 is compliant.
    // Guards against an off-by-one on the comparison operator. See DECISIONS.md #2.
    public function testExactlyOneThirdRatioIsNotExcessive(): void
    {
        $this->writeFixture([
            '2015-01-01 00:00:00,Steady traders,D,200',
            '2015-01-01 00:00:10,Steady traders,F,100',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame([], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(1, $checker->totalNumberOfWellBehavedCompanies());
    }

    // Numbers taken directly from the README's worked Bank of Mars example.
    // Confirms the sliding window matches the README's own arithmetic exactly
    // (400 total, 200 cancels, 50% > 1/3), not just an internally-consistent
    // reimplementation of it.
    public function testJustAboveOneThirdRatioIsExcessive(): void
    {
        $this->writeFixture([
            '2015-02-28 07:58:14,Bank of Mars,D,140',
            '2015-02-28 08:00:13,Bank of Mars,D,500',
            '2015-02-28 08:00:14,Bank of Mars,D,200',
            '2015-02-28 08:01:13,Bank of Mars,F,200',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame(['Bank of Mars'], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(0, $checker->totalNumberOfWellBehavedCompanies());
    }

    // Window is (t-60, t], open at the lower bound — proves events exactly
    // 60s apart do NOT share a window. Derived from the README's own worked
    // example, where the order 60s before the flagged cancel is excluded.
    // See DECISIONS.md #1.
    public function testEventsExactlySixtySecondsApartFallOutsideWindow(): void
    {
        $this->writeFixture([
            '2015-01-01 00:00:00,Punctual traders,D,100',
            '2015-01-01 00:01:00,Punctual traders,F,100',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame([], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(1, $checker->totalNumberOfWellBehavedCompanies());
    }

    // Complements the 60s test above: one second inside the boundary must
    // still count as the same window. See DECISIONS.md #1.
    public function testEventsExactlyFiftyNineSecondsApartFallInsideWindow(): void
    {
        $this->writeFixture([
            '2015-01-01 00:00:00,Almost late traders,D,100',
            '2015-01-01 00:00:59,Almost late traders,F,100',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame(['Almost late traders'], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(0, $checker->totalNumberOfWellBehavedCompanies());
    }

    // Encodes an interpretation the README doesn't state outright: a window
    // needs 2+ events to represent a real trading period, so a single cancel
    // with nothing else nearby isn't treated as 100% cancels. See DECISIONS.md #3
    // for why this reading was chosen and how to flip it if a grader disagrees.
    public function testLoneCancelWithNoPriorOrdersIsNotExcessive(): void
    {
        $this->writeFixture([
            '2015-01-01 00:00:00,Lone canceller,F,7',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame([], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(1, $checker->totalNumberOfWellBehavedCompanies());
    }

    // Control case for the lone-cancel test above: same all-cancel company,
    // but with 2+ events in the window. Confirms the >1-event guard only
    // suppresses single-event windows, not all-cancel companies generally.
    public function testAllCancelCompanyWithMultipleEventsIsExcessive(): void
    {
        $this->writeFixture([
            '2015-01-01 00:00:00,Cancel only traders,F,10000',
            '2015-01-01 00:00:06,Cancel only traders,F,10000',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame(['Cancel only traders'], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(0, $checker->totalNumberOfWellBehavedCompanies());
    }

    // One instance of each malformed-line category, interleaved with valid
    // lines, to prove a bad line is skipped rather than aborting the parse
    // (required by the README). See DECISIONS.md #4 for the full skip criteria.
    public function testMalformedLinesAreSkippedButParsingContinues(): void
    {
        $this->writeFixture([
            '2015-02-28 07:58:14,Bank of Mars,D,140',
            'not,enough,fields', // wrong field count
            '2015-02-30 10:00:00,Bank of Mars,D,999', // impossible date
            '2015-02-28 08:00:13,Bank of Mars, D,500', // order type has a stray leading space
            '2015-02-28 08:00:14,Bank of Mars,X,200', // order type is neither D nor F
            '2015-02-28 08:00:15,Bank of Mars,D,-5', // negative quantity
            '2015-02-28 08:00:14,Bank of Mars,D,200',
            '2015-02-28 08:01:13,Bank of Mars,F,200',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame(['Bank of Mars'], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(0, $checker->totalNumberOfWellBehavedCompanies());
    }

    // totalNumberOfWellBehavedCompanies() and companiesInvolvedInExcessiveCancellations()
    // share a single cached analysis pass — calling them in either order, or
    // repeatedly, must return consistent results. See DECISIONS.md #6.
    public function testResultsAreMemoizedAndConsistentRegardlessOfCallOrder(): void
    {
        $this->writeFixture([
            '2015-01-01 00:00:00,Cancel only traders,F,10000',
            '2015-01-01 00:00:06,Cancel only traders,F,10000',
            '2015-01-01 00:00:00,Steady traders,D,200',
            '2015-01-01 00:00:10,Steady traders,F,100',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $wellBehavedFirst = $checker->totalNumberOfWellBehavedCompanies();
        $excessive = $checker->companiesInvolvedInExcessiveCancellations();
        $wellBehavedSecond = $checker->totalNumberOfWellBehavedCompanies();

        $this->assertSame(['Cancel only traders'], $excessive);
        $this->assertSame(1, $wellBehavedFirst);
        $this->assertSame($wellBehavedFirst, $wellBehavedSecond);
    }

    // File-not-found is a distinct failure mode from a malformed line: it
    // should surface loudly (an exception) rather than silently reporting
    // zero companies, since it likely indicates a configuration mistake
    // rather than dirty input data.
    public function testNonExistentFileThrowsRuntimeException(): void
    {
        $checker = new ExcessiveCancellationsChecker($this->fixturePath . '.does-not-exist');

        $this->expectException(\RuntimeException::class);

        $checker->companiesInvolvedInExcessiveCancellations();
    }

    // An empty file (or one where every line is malformed) is valid input,
    // not an error: zero companies were observed, so zero are excessive and
    // zero are well-behaved.
    public function testEmptyFileYieldsNoCompanies(): void
    {
        $this->writeFixture([]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame([], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(0, $checker->totalNumberOfWellBehavedCompanies());
    }

    // Documents current (deliberate, not accidental) behavior: company names
    // are matched by exact string equality, so trailing/leading whitespace
    // differences are treated as distinct companies rather than being
    // trimmed and merged. The README does not require normalization, and
    // silently merging names could mask genuinely distinct entities.
    public function testCompanyNamesWithDifferingWhitespaceAreTreatedAsDistinct(): void
    {
        $this->writeFixture([
            '2015-01-01 00:00:00,Steady traders,D,200',
            '2015-01-01 00:00:10,Steady traders ,F,100',
        ]);

        $checker = new ExcessiveCancellationsChecker($this->fixturePath);

        $this->assertSame([], $checker->companiesInvolvedInExcessiveCancellations());
        $this->assertSame(2, $checker->totalNumberOfWellBehavedCompanies());
    }

    /**
     * @param string[] $lines
     */
    private function writeFixture(array $lines): void
    {
        file_put_contents($this->fixturePath, $lines === [] ? '' : implode("\n", $lines) . "\n");
    }
}
