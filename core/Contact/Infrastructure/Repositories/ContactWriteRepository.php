<?php

declare(strict_types=1);

namespace Core\Contact\Infrastructure\Repositories;

use App\Models\Contact;
use Core\Contact\Domain\Contracts\ContactRepositoryContract;
use Core\Contact\Domain\Entities\ContactEntity;
use Core\Contact\Domain\Exceptions\ContactDuplicateException;
use Core\Contact\Domain\ValueObjects\CompanyLink;
use Core\Contact\Infrastructure\Mappers\ContactEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Eloquent writes for contacts. Returns the domain entity, never a read model.
 *
 * @implements ContactRepositoryContract
 */
final class ContactWriteRepository extends BaseRepository implements ContactRepositoryContract
{
    /** SQLSTATE for an integrity constraint violation, the unique email index rejecting a duplicate. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /**
     * @param Contact             $model
     * @param ContactEntityMapper $mapper
     */
    public function __construct(
        Contact $model,
        private readonly ContactEntityMapper $mapper,
    ) {
        parent::__construct($model);
    }

    /**
     * Inserts the contact and attaches its companies in one transaction, so a
     * failure part-way leaves neither behind.
     *
     * @param ContactEntity $contact
     *
     * @return ContactEntity The stored contact, rebuilt from storage.
     *
     * @throws RuntimeException          When the contact row could not be written.
     * @throws ContactDuplicateException When the unique email index rejects the row.
     */
    public function create(ContactEntity $contact): ContactEntity
    {
        try {
            $contactModel = DB::transaction(function () use ($contact): Contact {
                $contactModel = $this->createRecord($this->mapper->toPersistenceArray($contact));

                $this->writeCompanyLinks($contactModel, $contact->companyLinks);

                return $contactModel;
            });
        } catch (QueryException $exception) {
            throw $this->translateWriteFailure($exception);
        }

        if (! $contactModel instanceof Contact) {
            throw new RuntimeException('The contact row could not be written.');
        }

        return $this->toEntityWithLinks($contactModel);
    }

    /**
     * Reads a contact while holding a row lock, so a concurrent write cannot
     * slip between the read and the save.
     *
     * @param int $contactId
     *
     * @return ContactEntity|null Null when no contact carries the id.
     */    public function findByIdForUpdate(int $contactId): ?ContactEntity
    {
        $contactModel = $this->findRecordForUpdate($contactId);

        if (! $contactModel instanceof Contact) {
            return null;
        }

        return $this->toEntityWithLinks($contactModel);
    }

    /**
     * Persists changes to an existing contact, including its company links.
     *
     * The row, its links and the read that follows share one transaction, so the
     * returned state is the committed state.
     *
     * @param ContactEntity $contact
     *
     * @return ContactEntity The stored contact, rebuilt from storage.
     *
     * @throws RuntimeException When the entity has no ID, or the row no longer exists.
     * @throws ContactDuplicateException When the unique email index rejects the row.
     */
    public function update(ContactEntity $contact): ContactEntity
    {
        if ($contact->id === null) {
            throw new RuntimeException('Cannot update a contact that has no ID.');
        }

        try {
            return DB::transaction(function () use ($contact): ContactEntity {
                $record = $this->findRecord($contact->id);

                if (! $record instanceof Contact) {
                    throw new RuntimeException(sprintf(
                        'Contact with ID %d no longer exists.',
                        $contact->id,
                    ));
                }

                $record->fill($this->mapper->toPersistenceArray($contact));
                $record->save();

                // The entity owns the link list and says whether that list is
                // the whole truth: a detach clears the stored links first, an
                // attach adds to them.
                $this->writeCompanyLinks(
                    contactModel: $record,
                    companyLinks: $contact->companyLinks,
                    replaceExisting: $contact->replacesStoredLinks,
                );

                // Read back inside the transaction, so a caller never sees the
                // state an earlier read would have given it.
                return $this->toEntityWithLinks($record->refresh());            });
        } catch (QueryException $exception) {
            throw $this->translateWriteFailure($exception);
        }
    }

    /**
     * Deletes a contact.
     *
     * @param int $contactId
     *
     * @return void
     *
     * @throws RuntimeException When the row could not be deleted.
     */
    public function delete(int $contactId): void
    {
        $deleted = $this->deleteRecord($contactId);

        if (! $deleted) {
            throw new RuntimeException(sprintf(
                'Contact with ID %d could not be deleted.',
                $contactId,
            ));
        }
    }

    /**
     * Writes the links the entity carries onto the pivot table.
     *
     * Applied additively: an existing link is refreshed in place, a new one added,
     * and a link the entity no longer carries is left alone. The entity decides
     * when that is not enough and the stored links are cleared first.
     *
     * @param Contact $contactModel
     * @param list<CompanyLink> $companyLinks
     * @param bool $replaceExisting Drop every stored link first.
     *
     * @return void
     */
    private function writeCompanyLinks(Contact $contactModel, array $companyLinks, bool $replaceExisting = false): void
    {
        if ($replaceExisting) {
            $contactModel->companies()->detach();
        }

        $pivotRows = [];

        foreach ($companyLinks as $companyLink) {
            $pivotRows[$companyLink->companyId] = $this->mapper->toPivotArray($companyLink);
        }

        if ($pivotRows === []) {
            return;
        }

        // The pivot is unique on (company_id, contact_id), so a second attach
        // would collide. syncWithoutDetaching() writes the same row and refreshes
        // the role on it when the link is already there.
        $contactModel->companies()->syncWithoutDetaching($pivotRows);
    }

    /**
     * Rebuilds the entity together with its attached companies.
     *
     * @param Contact $contactModel
     *
     * @return ContactEntity
     */
    private function toEntityWithLinks(Contact $contactModel): ContactEntity
    {
        return $this->mapper->toEntity(
            data: $contactModel->toArray(),
            companyRows: $this->toCompanyRows($contactModel),
        );
    }

    /**
     * Flattens the attached companies to plain rows, so the mapper need not know
     * how the pivot is read.
     *
     * @param Contact $contactModel
     *
     * @return array<int, array<string, mixed>>
     */
    private function toCompanyRows(Contact $contactModel): array
    {
        return array_values($contactModel->companies()
            ->get()
            ->map(fn($company) => [
                'id' => $company->id,
                'name' => $company->name,
                'phone' => $company->phone,
                'website' => $company->website,
                'industry' => $company->industry,
                'job_title' => $company->pivot?->job_title,
                'is_primary' => (bool) $company->pivot?->is_primary,
            ])
            ->all());
    }

    /**
     * Turns a failed write into the exception Core is allowed to see.
     *
     * A unique-index violation becomes the module's conflict exception. Any other
     * database error keeps its original type, so a caller never treats an outage
     * as "already exists".
     *
     * @param QueryException $exception
     *
     * @return \Throwable
     */
    private function translateWriteFailure(QueryException $exception): \Throwable
    {
        if ((string) $exception->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
            return new ContactDuplicateException();
        }

        return $exception;
    }
}
