<?php

declare(strict_types=1);

namespace Core\Deal\Application\Contracts;

/**
 * Supplies the pipeline and stage a deal starts in when nobody chose them.
 *
 * A conversion creates a deal without a human picking a pipeline, so the Deal
 * module answers that question itself rather than letting the caller read its
 * tables (P7). The matching adapter sits in this module's Infrastructure.
 */
interface DealDefaultOptionsContract
{
    /**
     * The pipeline a new deal belongs to.
     *
     * The pipeline table marks a default row, so this returns that row rather
     * than the first by id. Null means no pipeline exists, which is a broken
     * install rather than a choice the caller may make.
     *
     * @return int|null
     */
    public function getDefaultPipelineId(): ?int;

    /**
     * The stage a freshly created deal opens in, looked up by name inside the
     * given pipeline.
     *
     * The opening stage is named, not numbered: the deal's position in the
     * pipeline is business wording, and matching the name keeps this working
     * when a pipeline is reordered.
     *
     * @param int    $pipelineId The pipeline the stage must belong to.
     * @param string $stageName  The stage name to find, compared case-insensitively.
     *
     * @return int|null Null when that pipeline carries no such stage.
     */
    public function findStageIdByName(int $pipelineId, string $stageName): ?int;

    /**
     * The name of a stage, or null when no stage carries the id.
     *
     * The stage table belongs to the Pipeline module, so the deal module reads
     * it here rather than through its own read port (P7). A deal carries its
     * stage as an ID, but the rule about which stages close a deal is stated in
     * names, so a caller needs the name to apply it.
     *
     * @param int $dealStageId
     *
     * @return string|null
     */
    public function findStageNameById(int $dealStageId): ?string;
}
