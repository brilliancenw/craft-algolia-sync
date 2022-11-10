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

use craft\elements\Entry;
use craft\elements\Category;
use craft\elements\User;

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
class AlgoliaChunkLoadTask extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * Some attribute
     *
     * @var string
     */
    public $loadRecordType = [];
    public int $offset = 0;
    public int $limit = 100;
    public string $elementType = '';

    // Public Methods
    // =========================================================================


    /**
     * When the Queue is ready to run your job, it will call this method.
     * You don't need any steps or any other special logic handling, just do the
     * jobs that needs to be done here.
     *
     * More info: https://github.com/yiisoft/yii2-queue
     */
    public function execute($queue): void
    {
        // $algoliaSettings = AlgoliaSync::$plugin->getSettings();

        // as an example, you would receive one of the rows below
        //[0] => [entry][1]
        //[1] => [entry][6]
        //[2] => [entry][4]
        //[3] => [entry][5]
        //[4] => [user][1]

        list($elementType,$sectionId) = $this->loadRecordType;
        $offsetCount = $this->offset;
        $limitCount = $this->limit;

        SWITCH ($elementType) {
            CASE 'entry':

                // loading too many causes a timeout and memory issue...
                // what if we run some smaller loaders to execute a little block at a time?
                // then we can run an infinite number!
                $entryCount = Entry::find()->sectionId($sectionId)->offset($offsetCount)->limit($limitCount)->count();

                if ($entryCount > 0) {
                    $entries = Entry::find()->sectionId($sectionId)->offset($offsetCount)->limit($limitCount)->all();

                    $recordCount = count($entries);
                    $currentLoopCount = 0;
                    foreach ($entries AS $entry) {
                        $progress = $currentLoopCount / $recordCount;
                        $this->setProgress($queue, $progress);
                        AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($entry);
                        $currentLoopCount++;
                    }
                }


                break;

            CASE 'category':

                $categories = Category::find()->groupId($sectionId)->all();
                $recordCount = count($categories);
                $currentLoopCount = 0;
                foreach ($categories AS $cat) {
                    $progress = $currentLoopCount / $recordCount;
                    $this->setProgress($queue, $progress);
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($cat);
                    $currentLoopCount++;
                }
                break;

            CASE 'user':
                $blockOfUsers = User::find()->groupId($sectionId)->offset($offsetCount)->limit($limitCount)->all();

                $recordCount = count($blockOfUsers);
                $currentLoopCount = 0;
                $duplicateCheck = [];

                foreach ($blockOfUsers AS $user) {
                    $userId = $user->id;
                    if (!in_array($userId, $duplicateCheck)) {
                        $duplicateCheck[] = $userId;
                        $progress = $currentLoopCount / $recordCount;
                        $this->setProgress($queue, $progress);
                        AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($user, 'save', 'Chunk Load Task Line: '.__LINE__);
                        $currentLoopCount++;
                    }
                }
                break;
            }
        }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        return Craft::t('algolia-sync', 'Algolia Chunked Task');
    }
}
