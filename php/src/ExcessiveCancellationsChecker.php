<?php

declare(strict_types=1);

namespace CodeScreen;

class ExcessiveCancellationsChecker
{
    private const WINDOW_SECONDS = 60;
    private const RATIO_NUMERATOR = 1;
    private const RATIO_DENOMINATOR = 3;
    private const DATE_FORMAT = 'Y-m-d H:i:s';
    private const ORDER_TYPE_NEW = 'D';
    private const ORDER_TYPE_CANCEL = 'F';

    /** @var array<string, bool>|null */
    private ?array $companyExcessiveMap = null;

    /*
     * We provide a path to a file when initiating the class.
     * You have to use it in your methods to solve the task.
     */
    public function __construct(
        private readonly string $filePath,
    ) {
    }

    /**
     * Returns the list of companies that are involved in excessive cancelling.
     *
     * @return string[]
     */
    public function companiesInvolvedInExcessiveCancellations(): array
    {
        $this->analyzeIfNeeded();

        $excessive = array_keys(array_filter($this->companyExcessiveMap));
        sort($excessive);

        return $excessive;
    }

    /**
     * Returns the total number of companies that are not involved in any excessive cancelling.
     */
    public function totalNumberOfWellBehavedCompanies(): int
    {
        $this->analyzeIfNeeded();

        $excessiveCount = count(array_filter($this->companyExcessiveMap));

        return count($this->companyExcessiveMap) - $excessiveCount;
    }

    private function analyzeIfNeeded(): void
    {
        if ($this->companyExcessiveMap !== null) {
            return;
        }

        $eventsByCompany = $this->parseTradesByCompany();

        $this->companyExcessiveMap = [];
        foreach ($eventsByCompany as $company => $events) {
            // Defensive: the README guarantees the file is globally time-ordered,
            // so each company's events should already be in order. A stable sort
            // here is cheap insurance against that assumption ever being violated
            // (e.g. a hand-edited fixture) — it would otherwise silently corrupt
            // the sliding window, whose pointer only ever moves forward.
            usort($events, static fn (TradeEvent $a, TradeEvent $b): int => $a->timestamp <=> $b->timestamp);
            $this->companyExcessiveMap[$company] = $this->isExcessiveCancelling($events);
        }
    }

    /**
     * @return array<string, list<TradeEvent>>
     */
    private function parseTradesByCompany(): array
    {
        $eventsByCompany = [];

        // Suppressed: fopen() emits a PHP-level warning on failure in addition
        // to returning false. We convert that failure into our own
        // RuntimeException below, so the native warning would just be noise
        // (and trips PHPUnit's "risky test" warning detection).
        $handle = @fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open trades file: %s', $this->filePath));
        }

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                continue;
            }

            [$company, $event] = $parsed;
            $eventsByCompany[$company][] = $event;
        }

        fclose($handle);

        return $eventsByCompany;
    }

    /**
     * @return array{0: string, 1: TradeEvent}|null
     */
    private function parseLine(string $line): ?array
    {
        $fields = explode(',', $line);
        if (count($fields) !== 4) {
            return null;
        }

        [$timeStr, $company, $type, $quantityStr] = $fields;

        if ($company === '') {
            return null;
        }

        if ($type !== self::ORDER_TYPE_NEW && $type !== self::ORDER_TYPE_CANCEL) {
            return null;
        }

        if (!ctype_digit($quantityStr)) {
            return null;
        }

        $timestamp = $this->parseTimestamp($timeStr);
        if ($timestamp === null) {
            return null;
        }

        return [$company, new TradeEvent($timestamp, $type, (int) $quantityStr)];
    }

    private function parseTimestamp(string $timeStr): ?int
    {
        // Fixed to UTC so window calculations don't shift with the runtime's
        // default timezone (e.g. a DST transition would otherwise distort them).
        $dateTime = \DateTime::createFromFormat(self::DATE_FORMAT, $timeStr, new \DateTimeZone('UTC'));
        if ($dateTime === false || $dateTime->format(self::DATE_FORMAT) !== $timeStr) {
            return null;
        }

        return $dateTime->getTimestamp();
    }

    /**
     * @param list<TradeEvent> $events
     */
    private function isExcessiveCancelling(array $events): bool
    {
        $windowStart = 0;
        $orderQty = 0;
        $cancelQty = 0;
        $count = count($events);

        for ($i = 0; $i < $count; $i++) {
            $current = $events[$i];

            if ($current->type === self::ORDER_TYPE_NEW) {
                $orderQty += $current->quantity;
            } else {
                $cancelQty += $current->quantity;
            }

            // Window is (t-60, t], open at the lower bound: an event exactly 60s
            // before the current one has already left the period. See DECISIONS.md #1.
            while ($events[$windowStart]->timestamp <= $current->timestamp - self::WINDOW_SECONDS) {
                $expired = $events[$windowStart];
                if ($expired->type === self::ORDER_TYPE_NEW) {
                    $orderQty -= $expired->quantity;
                } else {
                    $cancelQty -= $expired->quantity;
                }
                $windowStart++;
            }

            $windowEventCount = $i - $windowStart + 1;
            $total = $orderQty + $cancelQty;

            // A single isolated message (e.g. a lone cancel with no prior order in
            // its window) is 100% one type by definition but isn't a real trading
            // "period" to judge a ratio over — require at least 2 events in the
            // window before evaluating excessiveness. This is an interpretation
            // call, not something the README states outright — see DECISIONS.md #3.
            if (
                $windowEventCount > 1
                && $total > 0
                // Cross-multiplied instead of dividing, so the "> 1/3" check is exact
                // and never affected by floating-point rounding. See DECISIONS.md #2.
                && $cancelQty * self::RATIO_DENOMINATOR > $total * self::RATIO_NUMERATOR
            ) {
                return true;
            }
        }

        return false;
    }
}
