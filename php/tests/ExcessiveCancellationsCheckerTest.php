<?php

/**
 * Note that this file cannot be modified.
 * If you would like to add your own unit tests, please put these in a separate test file.
 */

declare(strict_types=1);

namespace CodeScreen\Tests;

use CodeScreen\ExcessiveCancellationsChecker;
use PHPUnit\Framework\TestCase;

class ExcessiveCancellationsCheckerTest extends TestCase
{
    private ExcessiveCancellationsChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new ExcessiveCancellationsChecker('./data/trades.csv');
    }

    public function testGeneratesTheListOfCompaniesInvolvedInExcessiveCancelling(): void
    {
        $companiesList = $this->checker->companiesInvolvedInExcessiveCancellations();

        $this->assertEquals(['Ape accountants', 'Cauldron cooking'], $companiesList);
    }
}
