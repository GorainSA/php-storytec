# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a coding exercise ("Excessive-Trade-Cancelling"). The task is to implement
`ExcessiveCancellationsChecker` in [php/src/ExcessiveCancellationsChecker.php](php/src/ExcessiveCancellationsChecker.php),
which parses `php/data/trades.csv` (trade messages: time, company name, order type
`D`=new order or `F`=cancel, quantity) and determines which companies are engaged in
excessive cancelling.

A company is excessive-cancelling if, in any 60-second window, the ratio of
cumulative cancel quantity to cumulative order quantity exceeds 1/3. Malformed CSV
lines must be skipped, not fatal.

Required public API (do not rename):
- `companiesInvolvedInExcessiveCancellations(): array` — companies engaged in excessive cancelling
- `totalNumberOfWellBehavedCompanies(): int` — count of companies that are not

Full problem spec is in [README.md](README.md).

## Commands

All commands run from the `php/` directory.

```
composer install   # install dependencies (PHPUnit ^10.5)
composer test       # run the full test suite (alias for `phpunit`)
vendor/bin/phpunit --filter testGeneratesTheListOfCompaniesInvolvedInExcessiveCancelling
```

## Coding Standards

- Follow PSR-12 formatting conventions (4-space indentation, brace placement, naming).
- Use `declare(strict_types=1)` and typed properties, parameters, and return types on all code, matching the existing style in [php/src/ExcessiveCancellationsChecker.php](php/src/ExcessiveCancellationsChecker.php).

## Constraints

- Do not modify [php/data/trades.csv](php/data/trades.csv) or [php/tests/ExcessiveCancellationsCheckerTest.php](php/tests/ExcessiveCancellationsCheckerTest.php). Add any extra tests in a separate file under `php/tests/`.
- [php/composer.json](php/composer.json) may only be edited to add third-party dependencies; do not change the PHPUnit version.
- Solution must be compatible with PHP >= 8.2 (repo uses `declare(strict_types=1)` and constructor property promotion).
- PSR-4 autoloading: `CodeScreen\` -> `src/`, `CodeScreen\Tests\` -> `tests/`.
