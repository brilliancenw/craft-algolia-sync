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

class AlgoliaChunkLoadTask extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * The element type and associated section/group ID (e.g. ['entry', 5]).
     * @var array|string
     */
    public string|array $loadRecordType = [];

    /** @var int Offset into list of IDs */
    public int $offset = 0;

    /** @var int Number of IDs to process per chunk */
    public int $limit = 100;

    // Public Methods
    // =========================================================================

    /**
     * Executes a chunk of element IDs, loading each model once and syncing per site.
     */
    public function execute($queue): void
    {
        Craft::info('Executing AlgoliaChunkLoadTask', __METHOD__);
        AlgoliaSync::$plugin->algoliaSyncService->logger(
            "Chunk task: offset={$this->offset}, limit={$this->limit}",
            basename(__FILE__), __LINE__
        );

        list($elementType, $sectionId) = $this->loadRecordType;
        $offset = $this->offset;
        $limit  = $this->limit;

        // Build a site-agnostic query for distinct IDs
        switch ($elementType) {
            case 'product':
                $query = Product::find()
                    ->typeId($sectionId)
                    ->siteId('*')
                    ->orderBy([])
                    ->offset($offset)
                    ->limit($limit);
                break;

            case 'entry':
                $query = Entry::find()
                    ->sectionId($sectionId)
                    ->siteId('*')
                    ->orderBy([])
                    ->offset($offset)
                    ->limit($limit);
                break;

            case 'category':
                $query = Category::find()
                    ->groupId($sectionId)
                    ->siteId('*')
                    ->orderBy([])
                    ->offset($offset)
                    ->limit($limit);
                break;

            case 'user':
                $query = User::find()
                    ->groupId($sectionId)
                    ->siteId('*')
                    ->orderBy([])
                    ->offset($offset)
                    ->limit($limit);
                break;

            default:
                Craft::error("Unknown elementType: {$elementType}", __METHOD__);
                return;
        }

        // Get distinct element IDs
        $elementIds = $query
            ->distinct()
            ->select(['elements.id'])
            ->column();

        $total = count($elementIds);
        AlgoliaSync::$plugin->algoliaSyncService->logger(
            "Found {$total} distinct {$elementType}(s) in this chunk", basename(__FILE__), __LINE__
        );

        // Process each ID once
        foreach ($elementIds as $index => $id) {
            $progress = $total > 0 ? ($index / $total) : 1;
            $this->setProgress($queue, $progress);

            // Load the model by ID across all sites
            switch ($elementType) {
                case 'product':
                    $model = Product::find()->id($id)->siteId('*')->one();
                    break;
                case 'entry':
                    $model = Entry::find()->id($id)->siteId('*')->one();
                    break;
                case 'category':
                    $model = Category::find()->id($id)->siteId('*')->one();
                    break;
                case 'user':
                    $model = User::find()->id($id)->siteId('*')->one();
                    break;
            }

            if (!empty($model)) {
                AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement(
                    $model,
                    'save',
                    null
                );
            }
        }
    }

    /**
     * Default description if none provided.
     */
    protected function defaultDescription(): string
    {
        list($elementType) = $this->loadRecordType;
        return Craft::t(
            'algolia-sync',
            'Sync {type} chunk',
            ['type' => ucfirst($elementType)]
        );
    }

    /**
     * Show slice range in the CP description.
     */
    public function getDescription(): string
    {
        list($elementType) = $this->loadRecordType;
        $start = $this->offset + 1;
        $end   = $this->offset + $this->limit;
        return Craft::t(
            'algolia-sync',
            'Sync {type} records {start}–{end}',
            ['type' => ucfirst($elementType), 'start' => $start, 'end' => $end]
        );
    }
}
