<?php

declare(strict_types=1);

namespace CodeScreen;

/**
 * A single parsed, valid trade message for one company.
 */
final class TradeEvent
{
    public function __construct(
        public readonly int $timestamp,
        public readonly string $type,
        public readonly int $quantity,
    ) {
    }
}
