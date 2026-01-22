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
use craft\commerce\elements\Product;
use yii\queue\RetryableJobInterface;

/**
 * AlgoliaBulkLoadTask
 *
 * Breaks a full set of elements into smaller chunks and enqueues each chunk
 * for processing, preventing timeout issues when sync large data sets.
 *
 * @package AlgoliaSync
 */
class AlgoliaBulkLoadTask extends BaseJob implements RetryableJobInterface
{
    // Public Properties
    // =========================================================================

    /**
     * The element type and its associated section/group ID to bulk load.
     * Example: ['entry', 5] or ['product', 12]
     * @var array|string
     */
    public string|array $loadRecordType = [];

    /**
     * Maximum number of items per chunk
     * @var int
     */
    public int $standardLimit = 100;

    // Public Methods
    // =========================================================================

    /**
     * Returns how many seconds this job can run for.
     */
    public function getTtr()
    {
        return AlgoliaSync::$plugin->getSettings()->queueTtr
            ?? Craft::$app->getQueue()->ttr;
    }

    /**
     * Whether this job can retry on failure, and how many times.
     */
    public function canRetry($attempt, $error): bool
    {
        $maxAttempts = AlgoliaSync::$plugin->getSettings()->queueMaxRetry
            ?? Craft::$app->getQueue()->attempts;
        return $attempt < $maxAttempts;
    }

    /**
     * Executes the bulk load by slicing the full element set into chunks
     * and enqueuing an AlgoliaChunkLoadTask for each chunk.
     */
    public function execute($queue): void
    {
        list($elementType, $sectionId) = $this->loadRecordType;

        // Build a base query across all sites
        switch ($elementType) {
            case 'entry':
                $query = Entry::find()->sectionId($sectionId)->site('*');
                break;

            case 'product':
                $query = Product::find()->typeId($sectionId)->site('*');
                break;

            case 'category':
                $query = Category::find()->groupId($sectionId)->site('*');
                break;

            case 'user':
                $query = User::find()->groupId($sectionId)->site('*');
                break;

            default:
                Craft::error("Unknown element type: {$elementType}", __METHOD__);
                return;
        }

        // Total count of distinct elements
        $totalCount = $query->count();
        AlgoliaSync::$plugin->algoliaSyncService->logger(
            "Bulk load: found {$totalCount} {$elementType}(s) for ID {$sectionId}",
            basename(__FILE__), __LINE__
        );

        // Enqueue chunked jobs
        for ($offset = 0; $offset < $totalCount; $offset += $this->standardLimit) {
            $start = $offset + 1;
            $end   = min($offset + $this->standardLimit, $totalCount);
            $desc  = Craft::t(
                'algolia-sync',
                'Queueing chunks of {type} records {start}–{end} for Algolia sync',
                [
                    'type'  => ucfirst($elementType),
                    'start' => $start,
                    'end'   => $end,
                ]
            );

            AlgoliaSync::$plugin->pushToQueue(new AlgoliaChunkLoadTask([
                'description'    => $desc,
                'loadRecordType' => $this->loadRecordType,
                'limit'          => $this->standardLimit,
                'offset'         => $offset,
            ]));
        }
    }

    /**
     * Fallback description if none provided
     */
    protected function defaultDescription(): string
    {
        return Craft::t('algolia-sync', 'Algolia Bulk Loader');
    }

    /**
     * Show a concise description in the CP
     */
    public function getDescription(): string
    {
        return $this->description ?: $this->defaultDescription();
    }
}
