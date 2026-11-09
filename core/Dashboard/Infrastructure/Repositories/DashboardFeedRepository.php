<?php

declare(strict_types=1);

namespace Core\Dashboard\Infrastructure\Repositories;

use App\Models\Activity;
use App\Models\Deal;
use Core\Dashboard\Domain\Contracts\DashboardFeedContract;
use Core\Dashboard\Domain\DataObjects\ActivityFeedEntry;
use Core\Dashboard\Domain\DataObjects\OverdueActivityEntry;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Eloquent-backed read for the dashboard's activity feed.
 *
 * Reads the activities table, which the Activity module owns, so it is kept out
 * of that module's repository: this one answers a reporting question rather
 * than a list the activity pages show.
 *
 * @implements DashboardFeedContract
 */
final class DashboardFeedRepository implements DashboardFeedContract
{
    /**
     * Returns the outstanding activities, soonest due first.
     *
     * @param int $limit How many rows to return at most.
     *
     * @return array<int, ActivityFeedEntry> Empty when nothing is outstanding.
     */
    public function getUpcomingActivities(int $limit): array
    {
        $activities = Activity::query()
            ->with(['user:id,name', 'trackable'])
            ->whereNull('completed_at')
            // An activity without a due date is not "next" anything, so it is
            // left off the feed rather than sorted to the top of it.
            ->whereNotNull('due_date')
            // The id tie-breaks rows sharing a due date. Without it the order
            // of equal keys is undefined, so two activities due the same day
            // could swap places between two loads of the page.
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        return $activities
            ->map(fn(Activity $activity) => new ActivityFeedEntry(
                id: (int) $activity->id,
                type: (string) $activity->type,
                subject: (string) $activity->subject,
                dueDate: $this->toDateTimeString($activity->due_date),
                completedAt: $this->toDateTimeString($activity->completed_at),
                userId: $activity->user_id === null ? null : (int) $activity->user_id,
                userName: $activity->user?->name,
                trackableType: $activity->trackable_type === null
                    ? null
                    : (string) $activity->trackable_type,
                trackableId: (int) $activity->trackable_id,
                trackableName: $this->trackableLabel($activity),
            ))
            ->all();
    }

    /**
     * Returns the outstanding activities whose due date has already passed,
     * most overdue first.
     *
     * Only rows with a due date can be late, so the null check that keeps them
     * off the upcoming feed also keeps them off this one. The window is one
     * day: an activity due today has not been missed yet.
     *
     * @param int $limit How many rows to return at most.
     *
     * @return array<int, OverdueActivityEntry> Empty when nothing is overdue.
     */
    public function getOverdueActivities(int $limit): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $activities = Activity::query()
            ->with(['user:id,name', 'trackable'])
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->where('due_date', '<', $now->format('Y-m-d H:i:s'))
            // Most overdue first, which is the opposite of the upcoming feed:
            // the row that has been waiting longest is the one to chase.
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        return $activities
            ->map(fn(Activity $activity) => new OverdueActivityEntry(
                id: (int) $activity->id,
                type: (string) $activity->type,
                subject: (string) $activity->subject,
                dueDate: $this->toDateTimeString($activity->due_date),
                daysOverdue: $this->daysOverdue($activity->due_date, $now),
                userId: $activity->user_id === null ? null : (int) $activity->user_id,
                userName: $activity->user?->name,
                trackableType: $activity->trackable_type === null
                    ? null
                    : (string) $activity->trackable_type,
                trackableId: (int) $activity->trackable_id,
                trackableName: $this->trackableLabel($activity),
            ))
            ->all();
    }

    /**
     * How many whole days past its due date an activity is.
     *
     * Counted in whole days rather than hours so "1 day overdue" means the
     * calendar day has turned, and never reads as zero for something that is
     * visibly late.
     *
     * @param mixed             $dueDate
     * @param DateTimeImmutable $now
     *
     * @return int
     */
    private function daysOverdue(mixed $dueDate, DateTimeImmutable $now): int
    {
        if ($dueDate === null) {
            return 0;
        }

        $due = $dueDate instanceof \DateTimeInterface
            ? DateTimeImmutable::createFromInterface($dueDate)
            : new DateTimeImmutable((string) $dueDate, new DateTimeZone('UTC'));

        return (int) $due->diff($now)->days;
    }

    /**
     * The label of the record an activity was logged against.
     *
     * The relation is polymorphic and the tracked models do not all name
     * themselves the same way: a lead, contact or company carries `name`, while
     * a deal carries `title`. The list of models is stated here rather than
     * read off the aliases, because which models are trackable is a fact about
     * the domain, not about the string in the column.
     *
     * @param Activity $activity
     *
     * @return string|null Null when the tracked row is gone or carries no label.
     */
    private function trackableLabel(Activity $activity): ?string
    {
        $trackable = $activity->trackable;

        if ($trackable === null) {
            return null;
        }

        $column = $trackable instanceof Deal ? 'title' : 'name';
        $label = $trackable->getAttribute($column);

        return $label === null ? null : (string) $label;
    }

    /**
     * The stored timestamp as a UTC wall-clock string. The casts already turn
     * these columns into date objects, so only their string form is left to
     * take before the mapper formats them.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function toDateTimeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->toDateTimeString();
    }
}
