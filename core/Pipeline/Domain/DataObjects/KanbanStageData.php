<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\DataObjects;

/**
 * Read model for one Kanban column: a stage together with the deals sitting in
 * it. The column's derived values are computed here, so no layer above has to
 * total a stage's deals itself.
 */
final readonly class KanbanStageData
{
    /**
     * @param array<int, KanbanDealData> $deals
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $orderPosition,
        public int $probability,
        public array $deals = [],
    ) {}

    /**
     * How many deals sit in this column.
     *
     * @return int
     */
    public function dealsCount(): int
    {
        return count($this->deals);
    }

    /**
     * The combined amount of the deals in this column. Rounded to the two
     * decimals an amount column carries, so summing decimal strings cannot
     * leave float noise on the wire.
     *
     * @return float
     */
    public function totalValue(): float
    {
        $total = 0.0;

        foreach ($this->deals as $deal) {
            $total += (float) $deal->amount;
        }

        return round($total, 2);
    }
}
