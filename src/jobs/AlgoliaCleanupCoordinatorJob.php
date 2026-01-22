<?php

namespace brilliance\algoliasync\jobs;

use brilliance\algoliasync\AlgoliaSync;
use Craft;
use craft\queue\BaseJob;

/**
 * Coordinator job that queues one job per Algolia index
 *
 * This job finishes very quickly - it just creates one AlgoliaCleanupIndexJob
 * for each configured index. Each index job will then browse its index and
 * create batch jobs to process records.
 */
class AlgoliaCleanupCoordinatorJob extends BaseJob
{
    /**
     * How many records to check per batch job
     * @var int
     */
    public int $batchSize = 100;

    public function execute($queue): void
    {
        // Get all configured element types and their indexes
        $elementsToCheck = AlgoliaSync::$plugin->algoliaSyncService->getConfiguredElementsForCleanup();

        if (empty($elementsToCheck)) {
            Craft::info('No configured elements to check for cleanup', 'algolia-sync');
            return;
        }

        // Queue one job per index
        foreach ($elementsToCheck as $elementConfig) {
            AlgoliaSync::$plugin->pushToQueue(new AlgoliaCleanupIndexJob([
                'indexName' => $elementConfig['index'],
                'elementType' => $elementConfig['type'],
                'sectionId' => $elementConfig['sectionId'],
                'label' => $elementConfig['label'],
                'batchSize' => $this->batchSize,
            ]));
        }

        $indexCount = count($elementsToCheck);
        Craft::info("Queued {$indexCount} index cleanup jobs", 'algolia-sync');
    }

    protected function defaultDescription(): string
    {
        return Craft::t('algolia-sync', 'Coordinating Algolia cleanup - queueing jobs for each index');
    }
}
