<?php

declare(strict_types=1);

namespace Core\Deal\Application\Contracts;

use Core\Deal\Domain\DataObjects\DealFormOptions;

/**
 * Read side of the port that fills the deal form's pickers.
 *
 * The form has to offer a pipeline, a stage, a company and a contact to choose
 * from. Those rows belong to other modules' tables, so the read sits behind its
 * own port rather than being reached for directly from the delivery layer.
 */
interface DealFormOptionsContract
{
    /**
     * Returns the rows the deal form offers for each of its pickers.
     *
     * @return DealFormOptions
     */
    public function getFormOptions(): DealFormOptions;
}
