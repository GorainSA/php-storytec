# Excessive-Trade-Cancelling

## Explanation
`data/trades.csv` is a large CSV file that contains a list of trade messages, one per line in the following format:

`Time of trade message, CompanyName, Order Type - New order (D) or Cancel (F), Quantity`

The lines are time ordered although **two or more lines may have the same time**.
Company names will not contain any commas. Ignore any lines which are not properly formatted and **continue to process** the rest of the file.

### Here are some example lines: 

| Time | Company name | Order Type | Quantity |
| ----------- | ----------- | ----------- | ----------- |
| 2015-02-28 07:58:14 | Bank of Mars | D | 140 |
| 2015-02-28 08:00:13 | Bank of Mars | D | 500 |
| 2015-02-28 08:00:14 | Bank of Mars | D | 200 |
| 2015-02-28 08:01:13 | Bank of Mars | F | 200 |
| 2015-02-28 08:04:29 | Joe traders | D | 110 |
| 2015-02-28 08:05:22 | Joe traders | F | 11 |
| 2015-02-28 08:05:25 | Joe traders | D | 70 |

If, in any given **60 second** period and for a given company, the ratio of the cumulative quantity of cancels to cumulative quantity of orders is greater than **1/3** then the company is engaged in excessive cancelling.

### Consider the above lines:
- During the period 08:00:14 to 08:01:13 `Bank of Mars` made 400 new orders and cancels,
of which 200 were cancels. This is 50% and is excessive cancelling.
- First line `2015-02-28 07:58:14,Bank of Mars,D,140` is just one event in any 60 seconds interval, because nothing more happend at +-60 seconds.
That means that at this interval `Bank of Mars` is not engaged in excessive cancelling.
- `Joe traders` did not engage in excessive cancelling.

## Your Task

Implement an `ExcessiveCancellationsChecker` class that accepts a filepath to a CSV file (example file is `data/trades.csv`), parses it, and provides the following two methods:

- **companiesInvolvedInExcessiveCancellations**: should return an array/list of companies that are engaged in excessive cancelling
- **totalNumberOfWellBehavedCompanies**: should return the number of companies that are not engaged in excessive cancelling

You are free to add your own helper methods to the class, but the above two are **required**.

## Getting Started

In [php/src/ExcessiveCancellationsChecker.php](php/src/ExcessiveCancellationsChecker.php) you will find a `ExcessiveCancellationsChecker` class with 2 methods to implement.

## Tests

Run `composer install` to install all dependencies and then run `composer test` to run the unit tests. These should all pass if your solution has been implemented correctly.

The unit tests in [php/tests/ExcessiveCancellationsCheckerTest.php](php/tests/ExcessiveCancellationsCheckerTest.php) should pass if the methods in [php/src/ExcessiveCancellationsChecker.php](php/src/ExcessiveCancellationsChecker.php) are implemented correctly. You are welcome to add more tests in a separate file.

## Requirements

- The [php/data/trades.csv](php/data/trades.csv) file and the [php/tests/ExcessiveCancellationsCheckerTest.php](php/tests/ExcessiveCancellationsCheckerTest.php) file should not be modified.
- The [php/composer.json](php/composer.json) file should only be modified in order to add any third-party dependencies required for your solution. The PHPUnit version should not be changed.
- Your solution must use/be compatible with PHP `8.2` or later.
- You may add third-party dependencies as needed.

##

Good luck!
