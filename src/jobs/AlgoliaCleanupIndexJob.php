<?php

namespace brilliance\algoliasync\jobs;

use brilliance\algoliasync\AlgoliaSync;
use Craft;
use craft\queue\BaseJob;

/**
 * Job that browses a single Algolia index and queues batch processing jobs
 *
 * This job browses one index and creates batch jobs for processing records
 * in manageable chunks.
 */
class AlgoliaCleanupIndexJob extends BaseJob
{
    /**
     * The Algolia index name to process
     * @var string
     */
    public string $indexName = '';

    /**
     * The element type (entry, category, asset, user, product)
     * @var string
     */
    public string $elementType = '';

    /**
     * The section/group/volume/type ID
     * @var int
     */
    public int $sectionId = 0;

    /**
     * Human-readable label for progress messages
     * @var string
     */
    public string $label = '';

    /**
     * How many records to check per batch job
     * @var int
     */
    public int $batchSize = 100;

    public function execute($queue): void
    {
        $settings = AlgoliaSync::$plugin->getSettings();
        $algoliaAppId = $settings->getAlgoliaApp();
        $algoliaApiKey = $settings->getAlgoliaAdmin();

        if (!$algoliaAppId || !$algoliaApiKey) {
            throw new \Exception('Algolia API credentials not configured');
        }

        $client = \Algolia\AlgoliaSearch\Api\SearchClient::create($algoliaAppId, $algoliaApiKey);

        $batch = [];
        $browseParams = ['attributesToRetrieve' => ['objectID']];
        $totalProcessed = 0;

        try {
            foreach ($client->browseObjects($this->indexName, $browseParams) as $hit) {
                if (!isset($hit['objectID'])) {
                    continue;
                }

                $batch[] = $hit['objectID'];
                $totalProcessed++;

                // Queue a batch job when we reach the batch size
                if (count($batch) >= $this->batchSize) {
                    $this->queueBatchJob($batch);
                    $batch = [];
                }
            }

            // Queue remaining records
            if (!empty($batch)) {
                $this->queueBatchJob($batch);
            }

            Craft::info("Queued cleanup jobs for {$totalProcessed} records in {$this->label}", 'algolia-sync');

        } catch (\Throwable $e) {
            // Index doesn't exist or other error - log and continue
            Craft::warning("Skipping index {$this->indexName}: " . $e->getMessage(), 'algolia-sync');
        }
    }

    /**
     * Queue a batch job to process a set of objectIDs
     */
    protected function queueBatchJob(array $objectIDs): void
    {
        Craft::$app->getQueue()->push(new AlgoliaCleanupBatchJob([
            'objectIDs' => $objectIDs,
            'indexName' => $this->indexName,
            'elementType' => $this->elementType,
            'sectionId' => $this->sectionId,
            'label' => $this->label,
        ]));
    }

    protected function defaultDescription(): string
    {
        return Craft::t('algolia-sync', 'Browsing {label} index and queueing batch jobs', [
            'label' => $this->label
        ]);
    }
}
