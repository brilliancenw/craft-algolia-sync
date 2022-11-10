<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * Syncing elements with Algolia using their API
 *
 * @link      https://www.brilliancenw.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\jobs;

use brilliance\algoliasync\AlgoliaSync;

use Craft;
use craft\queue\BaseJob;

use Algolia\AlgoliaSearch\SearchClient;

/**
 * AlgoliaSyncTask job
 *
 * Jobs are run in separate process via a Queue of pending jobs. This allows
 * you to spin lengthy processing off into a separate PHP process that does not
 * block the main process.
 *
 * You can use it like this:
 *
 * use brilliance\algoliasync\jobs\AlgoliaSyncTask as AlgoliaSyncTaskJob;
 *
 * $queue = Craft::$app->getQueue();
 * $jobId = $queue->push(new AlgoliaSyncTaskJob([
 *     'description' => Craft::t('algolia-sync', 'This overrides the default description'),
 *     'someAttribute' => 'someValue',
 * ]));
 *
 * The key/value pairs that you pass in to the job will set the public properties
 * for that object. Thus whatever you set 'someAttribute' to will cause the
 * public property $someAttribute to be set in the job.
 *
 * Passing in 'description' is optional, and only if you want to override the default
 * description.
 *
 * More info: https://github.com/yiisoft/yii2-queue
 *
 * @author    Mark Middleton
 * @package   AlgoliaSync
 * @since     1.0.0
 */
class AlgoliaSyncTask extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * Some attribute
     *
     * @var string
     */
    public $algoliaIndex = [];
    public $algoliaFunction = ''; // delete or insert
    public $algoliaObjectID = 0;
    public $algoliaRecord = [];
    public $algoliaMessage = 'Algolia Sync Task';

    // Public Methods
    // =========================================================================

    /**
     * When the Queue is ready to run your job, it will call this method.
     * You don't need any steps or any other special logic handling, just do the
     * jobs that needs to be done here.
     *
     * More info: https://github.com/yiisoft/yii2-queue
     */
    public function execute($queue)
    {

        $client = SearchClient::create(
            AlgoliaSync::$plugin->settings->getAlgoliaApp(),
            AlgoliaSync::$plugin->settings->getAlgoliaAdmin()
        );

        foreach ($this->algoliaIndex AS $index) {

            $this->description = $this->algoliaMessage;

            $clientIndex = $client->initIndex($index);

            SWITCH ($this->algoliaFunction) {
                CASE 'insert':
                    $clientIndex->saveObject($this->algoliaRecord)->wait();
                    break;

                CASE 'delete':
                    $clientIndex->deleteObject($this->algoliaObjectID);
                    break;
            }

            // todo implement craft categorized logs

        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
     * @return string The default task description
     */
    protected function defaultDescription()
    {
        return Craft::t('algolia-sync', 'Algolia Sync Task');
    }
}
