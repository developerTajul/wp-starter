<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Enums;

enum LeadStatus: string
{
    case NEW = 'new';
    case CONTACTED = 'contacted';
    case QUALIFIED = 'qualified';
    case CONVERTED = 'converted';
    case LOST = 'lost';

    /**
     * The pipeline order the progress bar walks, from first touch to won.
     *
     * Lost is deliberately absent: it is an exit, not a stage on the way, so it
     * has no position in the progress sequence.
     *
     * @var list<self>
     */
    public const PIPELINE = [
        self::NEW,
        self::CONTACTED,
        self::QUALIFIED,
        self::CONVERTED,
    ];

    /**
     * The key the presentation layer translates. The wording lives in
     * resources/lang/<locale>/leads.php under the same path, so the domain
     * never carries a display string and a new locale needs no code change.
     *
     * @return string
     */
    public function translationKey(): string
    {
        return match ($this) {
            self::NEW => 'leads.status.new',
            self::CONTACTED => 'leads.status.contacted',
            self::QUALIFIED => 'leads.status.qualified',
            self::CONVERTED => 'leads.status.converted',
            self::LOST => 'leads.status.lost',
        };
    }

    /**
     * Tells whether this status sits on the pipeline itself.
     *
     * @return bool
     */
    public function isOnPipeline(): bool
    {
        return in_array($this, self::PIPELINE, true);
    }

    /**
     * The pipeline stage this status represents, counting from one. A status
     * that leaves the pipeline reports the stage after the last one, so it
     * never reads as unfinished progress.
     *
     * @return int
     */
    public function pipelinePosition(): int
    {
        $position = array_search($this, self::PIPELINE, true);

        if ($position === false) {
            return count(self::PIPELINE) + 1;
        }

        return $position + 1;
    }

    /**
     * How far along the pipeline the lead is, as a whole percentage.
     *
     * @return int
     */
    public function pipelineProgress(): int
    {
        if (! $this->isOnPipeline()) {
            return 0;
        }

        return (int) round($this->pipelinePosition() / count(self::PIPELINE) * 100);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
