<?php

namespace brilliance\algoliasync\jobs;

use brilliance\algoliasync\AlgoliaSync;
use Craft;
use craft\queue\BaseJob;
use Algolia\AlgoliaSearch\Api\SearchClient;

class AlgoliaSyncTask extends BaseJob
{
    public array $algoliaIndex = [];
    public string $algoliaFunction = ''; // delete or insert
    public int $algoliaObjectID = 0;
    public array $algoliaRecord = [];
    public string $algoliaMessage = 'Algolia Sync Task';

    public function execute($queue): void
    {
        Craft::info("Executing the Queue", "algolia-sync");

        $client = SearchClient::create(
            AlgoliaSync::$plugin->settings->getAlgoliaApp(),
            AlgoliaSync::$plugin->settings->getAlgoliaAdmin()
        );

        foreach ($this->algoliaIndex as $indexName) {
            $this->description = $this->algoliaMessage;

            switch ($this->algoliaFunction) {
                case 'insert':
                    // In v4, you pass the index name directly in saveObject
                    $saveResp = $client->saveObject($indexName, $this->algoliaRecord);

                    // Wait for the indexing task to complete
                    $client->waitForTask($indexName, $saveResp['taskID']);
                    break;

                case 'delete':
                    // In v4, you pass the index name directly in deleteObject
                    $deleteResp = $client->deleteObject($indexName, $this->algoliaObjectID);

                    // Wait for the delete task to complete
                    $client->waitForTask($indexName, $deleteResp['taskID']);
                    break;
            }
        }
    }

    protected function defaultDescription(): string
    {
        return Craft::t('algolia-sync', 'Algolia Sync Task');
    }
}
