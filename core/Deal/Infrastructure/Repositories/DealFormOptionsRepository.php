<?php

declare(strict_types=1);

namespace Core\Deal\Infrastructure\Repositories;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DealStage;
use App\Models\Pipeline;
use Core\Deal\Application\Contracts\DealFormOptionsContract;
use Core\Deal\Domain\DataObjects\DealFormOptions;

/**
 * Eloquent-backed reads for the rows the deal form offers. Reads tables owned
 * by other modules, so it is deliberately kept out of the deal repository: the
 * deal repository owns the deals table, and this one only reads lookups.
 *
 * @implements DealFormOptionsContract
 */
final class DealFormOptionsRepository implements DealFormOptionsContract
{
    /**
     * Returns the rows the deal form offers for each of its pickers.
     *
     * Every list is narrowed to the columns a picker shows, so the form never
     * loads a company or contact record it only prints a name from.
     *
     * @return DealFormOptions
     */
    public function getFormOptions(): DealFormOptions
    {
        return new DealFormOptions(
            pipelines: Pipeline::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn(Pipeline $pipeline) => $pipeline->getAttributes())
                ->all(),
            // The stages carry their pipeline, so the form can narrow the list
            // to the pipeline the user picked.
            stages: DealStage::query()
                ->with('pipeline:id,name')
                ->orderBy('pipeline_id')
                ->orderBy('order_position')
                ->get(['id', 'pipeline_id', 'name'])
                ->map(fn(DealStage $stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'pipeline_id' => $stage->pipeline_id,
                    'pipeline_name' => $stage->pipeline?->name ?? '',
                ])
                ->all(),
            companies: Company::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn(Company $company) => $company->getAttributes())
                ->all(),
            contacts: Contact::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn(Contact $contact) => $contact->getAttributes())
                ->all(),
        );
    }
}
