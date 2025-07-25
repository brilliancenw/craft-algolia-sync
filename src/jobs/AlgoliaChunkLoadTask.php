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
     * [elementType, sectionOrGroupId]
     * e.g. ['entry', 5] or ['product', 12]
     * @var array|string
     */
    public string|array $loadRecordType = [];

    /** @var int Offset into the full list of IDs */
    public int $offset = 0;

    /** @var int How many IDs to process in this chunk */
    public int $limit = 100;

    // Public Methods
    // =========================================================================

    /**
     * Execute a chunk of element IDs, one model per ID, site‑fanout in service.
     */
    public function execute($queue): void
    {
        Craft::info("Executing AlgoliaChunkLoadTask", __METHOD__);
        AlgoliaSync::$plugin->algoliaSyncService->logger("Starting chunk: offset={$this->offset}, limit={$this->limit}", basename(__FILE__), __LINE__);

        list($elementType, $sectionId) = $this->loadRecordType;
        $offset = $this->offset;
        $limit  = $this->limit;

        switch ($elementType) {

            case 'product':
                // commerce products by type ID
                $query = Product::find()
                    ->typeId($sectionId)
                    ->siteId('*')
                    ->offset($offset)
                    ->limit($limit)
                    ->status('enabled');

                break;

            case 'entry':
                // Craft entries by section ID
                $query = Entry::find()
                    ->sectionId($sectionId)
                    ->siteId('*')
                    ->offset($offset)
                    ->limit($limit);

                break;

            case 'category':
                // categories by group ID
                $query = Category::find()
                    ->groupId($sectionId)
                    ->siteId('*')
                    ->offset($offset)
                    ->limit($limit);

                break;

            case 'user':
                // users by group ID
                $query = User::find()
                    ->groupId($sectionId)
                    ->siteId('*')
                    ->offset($offset)
                    ->limit($limit);

                break;

            default:
                Craft::error("Unknown elementType: {$elementType}", __METHOD__);
                return;
        }

        // Get a distinct list of element IDs
        $elementIds = $query
            ->distinct()
            ->select(['elements.id'])
            ->column();

        $total = count($elementIds);
        AlgoliaSync::$plugin->algoliaSyncService->logger("Found {$total} distinct {$elementType}(s) in this chunk", basename(__FILE__), __LINE__);

        // Process each ID once
        $processed = 0;
        foreach ($elementIds as $id) {
            $progress = $total > 0 ? ($processed / $total) : 1;
            $this->setProgress($queue, $progress);

            switch ($elementType) {
                case 'product':
                    $model = Product::find()->id($id)->one();
                    break;
                case 'entry':
                    $model = Entry::find()->id($id)->one();
                    break;
                case 'category':
                    $model = Category::find()->id($id)->one();
                    break;
                case 'user':
                    $model = User::find()->id($id)->one();
                    break;
                default:
                    $model = null;
            }

            if ($model) {
                AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($model, 'save', "Chunk Load Task processed ID: {$id}");
            }

            $processed++;
        }
    }

    /**
     * Use a custom description that shows exactly which slice we're syncing.
     */
    public function getDescription(): string
    {
        list($elementType) = $this->loadRecordType;
        $typeLabel = ucfirst($elementType);
        $start = $this->offset + 1;
        $end   = $this->offset + $this->limit;
        return Craft::t(
            'algolia-sync',
            'Sync {type} records {start}–{end} to Algolia',
            ['type' => $typeLabel, 'start' => $start, 'end' => $end]
        );
    }


}
