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
use yii\queue\RetryableJobInterface;

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
 *
 * @property-read mixed $ttr
 */
class AlgoliaBulkLoadTask extends BaseJob implements RetryableJobInterface
{
    // Public Properties
    // =========================================================================


    /**
     * Some attribute
     *
     * @var string
     */
    public $loadRecordType = [];
    public $standardLimit = 100;

    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getTtr()
    {
        return AlgoliaSync::$plugin->getSettings()->queueTtr ?? AlgoliaSync::getInstance()->queue->ttr;
    }

    /**
     * @inheritDoc
     */
    public function canRetry($attempt, $error)
    {
        $attempts = AlgoliaSync::$plugin->getSettings()->queueMaxRetry ?? AlgoliaSync::getInstance()->queue->attempts;
        return $attempt < $attempts;
    }

    /**
     * When the Queue is ready to run your job, it will call this method.
     * You don't need any steps or any other special logic handling, just do the
     * jobs that needs to be done here.
     *
     * More info: https://github.com/yiisoft/yii2-queue
     */
    public function execute($queue)
    {
        // $algoliaSettings = AlgoliaSync::$plugin->getSettings();

        // as an example, you would receive one of the rows below
        //[0] => [entry][1]
        //[1] => [entry][6]
        //[2] => [entry][4]
        //[3] => [entry][5]
        //[4] => [user][1]

        AlgoliaSync::$plugin->algoliaSyncService->logger("Executing the Queue", basename(__FILE__) , __LINE__);

        list($elementType,$sectionId) = $this->loadRecordType;

        AlgoliaSync::$plugin->algoliaSyncService->logger("Loading the type of '".$elementType."' with ID #".$sectionId, basename(__FILE__) , __LINE__);

        SWITCH ($elementType) {
            CASE 'entry':

                // loading too many causes a timeout and memory issue...
                // breaking these updates into 100 record chunks

                $entryCount = Entry::find()->sectionId($sectionId)->count();

                $queue = Craft::$app->getQueue();

                for ($x=0; $x<$entryCount; $x=$x+$this->standardLimit) {
                    $queue->push(new AlgoliaChunkLoadTask([
                        'description' => Craft::t('algolia-sync', 'Queueing a chunk of records to process start ('.$x.') limit ('.$this->standardLimit.')'),
                        'loadRecordType' => $this->loadRecordType,
                        'limit' => 100,
                        'offset' => $x,
                        'elementType' => $elementType
                    ]));
                }

            break;

            CASE 'variant':

                // loading too many causes a timeout and memory issue...
                // breaking these updates into 100 record chunks

                $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

                if ($commercePlugin) {

                    $variantCount = \craft\commerce\elements\Variant::find()->typeId($sectionId)->count();

                    $queue = Craft::$app->getQueue();

                    for ($x=0; $x<$variantCount; $x=$x+$this->standardLimit) {

                        $queue->push(new AlgoliaChunkLoadTask([
                            'description' => Craft::t('algolia-sync', 'Queueing a chunk of records to process start ('.$x.') limit ('.$this->standardLimit.')'),
                            'loadRecordType' => $this->loadRecordType,
                            'limit' => 100,
                            'offset' => $x,
                            'elementType' => $elementType
                        ]));

                        }
                    }
                break;

            CASE 'category':

                $categories = Category::find()->groupId($sectionId)->all();

                $categoryCount = count($categories);

                $currentCategoryNumber = 0;

                // this is probably a poor assumption to think that the quantity of categories
                // won't time out (like entries or users)
                // todo : break this into smaller chunks like entries and users
                foreach ($categories AS $cat) {
                    $progress = $currentCategoryNumber / $categoryCount;
                    $this->setProgress($queue, $progress);
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($cat);
                    $currentCategoryNumber++;
                }
            break;

            CASE 'user':

                $userCount = User::find()->groupId($sectionId)->count();

                $queue = Craft::$app->getQueue();

                for ($x=0; $x<$userCount; $x=$x+$this->standardLimit) {
                    print_r('count: '.$x);

                    $progress = $x / $this->standardLimit;
                    $this->setProgress($queue, $progress);

                    $chunkEnd = $x + $this->standardLimit;

                    $queue->push(new AlgoliaChunkLoadTask([
                        'description' => Craft::t('algolia-sync', 'Queueing records '.$x.' through '.$chunkEnd.' of a total '.$userCount.' users'),
                        'loadRecordType' => $this->loadRecordType,
                        'limit' => $this->standardLimit,
                        'offset' => $x,
                        'elementType' => $elementType
                    ]));
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
    protected function defaultDescription()
    {
        return Craft::t('algolia-sync', 'Algolia Sync');
    }
}
