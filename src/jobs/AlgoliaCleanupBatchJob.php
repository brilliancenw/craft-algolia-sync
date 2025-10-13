<?php

namespace brilliance\algoliasync\jobs;

use brilliance\algoliasync\AlgoliaSync;
use Craft;
use craft\queue\BaseJob;
use craft\elements\Entry;
use craft\elements\Category;
use craft\elements\Asset;
use craft\elements\User;

/**
 * Batch job that checks a batch of Algolia records and takes appropriate action
 *
 * For each objectID in the batch:
 * - If element doesn't exist in Craft: Queue deletion from Algolia
 * - If element is disabled or expired: Resave element to trigger normal sync logic
 *   (resaving is safer than direct deletion as it respects site-specific rules)
 */
class AlgoliaCleanupBatchJob extends BaseJob
{
    /**
     * Array of Algolia objectIDs to check
     * @var array
     */
    public array $objectIDs = [];

    /**
     * The Algolia index name
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

    public function execute($queue): void
    {
        if (empty($this->objectIDs)) {
            return;
        }

        $total = count($this->objectIDs);
        $processed = 0;

        // Parse objectIDs to get element IDs
        $elementIds = [];
        $objectIDMap = []; // elementId => [objectIDs]

        foreach ($this->objectIDs as $objectID) {
            // Parse objectID to get element ID (e.g., "123" or "123-1")
            $parts = explode('-', $objectID);
            $elementId = (int)$parts[0];

            if (!isset($objectIDMap[$elementId])) {
                $objectIDMap[$elementId] = [];
            }
            $objectIDMap[$elementId][] = $objectID;
            $elementIds[] = $elementId;
        }

        $elementIds = array_unique($elementIds);

        // Query Craft for these elements
        $elements = $this->queryElements($elementIds);

        // Check each objectID
        foreach ($objectIDMap as $elementId => $objectIDs) {
            $element = $elements[$elementId] ?? null;

            foreach ($objectIDs as $objectID) {
                $processed++;
                $this->setProgress($queue, $processed / $total);

                if (!$element) {
                    // Element doesn't exist in Craft - queue deletion
                    $this->queueDeletion($objectID, 'deleted');
                } elseif (!$element->enabled) {
                    // Element is disabled - resave to let sync logic handle it
                    $this->queueResave($element, $objectID, 'disabled');
                } elseif (isset($element->expiryDate) && $element->expiryDate instanceof \DateTime) {
                    $now = new \DateTime();
                    if ($element->expiryDate < $now) {
                        // Element is expired - resave to let sync logic handle it
                        $this->queueResave($element, $objectID, 'expired');
                    }
                }
                // If enabled and not expired, nothing to do - record is valid
            }
        }
    }

    /**
     * Query Craft elements by their IDs
     *
     * @param array $elementIds
     * @return array Indexed by element ID
     */
    protected function queryElements(array $elementIds): array
    {
        $elements = [];

        switch ($this->elementType) {
            case 'entry':
                // Use siteId('*') to get elements from all sites, then use unique() to get one per ID
                $elements = Entry::find()
                    ->id($elementIds)
                    ->sectionId($this->sectionId)
                    ->siteId('*')
                    ->status(null) // Include all statuses
                    ->unique()
                    ->all();
                break;

            case 'category':
                $elements = Category::find()
                    ->id($elementIds)
                    ->groupId($this->sectionId)
                    ->siteId('*')
                    ->status(null)
                    ->unique()
                    ->all();
                break;

            case 'asset':
                $elements = Asset::find()
                    ->id($elementIds)
                    ->volumeId($this->sectionId)
                    ->siteId('*')
                    ->status(null)
                    ->unique()
                    ->all();
                break;

            case 'user':
                $elements = User::find()
                    ->id($elementIds)
                    ->groupId($this->sectionId)
                    ->status(null)
                    ->all();
                break;

            case 'product':
                if (class_exists('craft\\commerce\\elements\\Product')) {
                    $elements = \craft\commerce\elements\Product::find()
                        ->id($elementIds)
                        ->typeId($this->sectionId)
                        ->siteId('*')
                        ->status(null)
                        ->unique()
                        ->all();
                } else {
                    $elements = [];
                }
                break;

            default:
                $elements = [];
        }

        // Index by element ID
        $indexed = [];
        foreach ($elements as $element) {
            $indexed[$element->id] = $element;
        }

        return $indexed;
    }

    /**
     * Queue a deletion job for an objectID that no longer exists in Craft
     */
    protected function queueDeletion(string $objectID, string $reason): void
    {
        Craft::$app->getQueue()->push(new AlgoliaSyncTask([
            'algoliaIndex' => [$this->indexName],
            'algoliaFunction' => 'delete',
            'algoliaObjectID' => $objectID,
            'algoliaRecord' => [],
            'queueMessage' => "Cleanup: Deleting {$objectID} from {$this->label} (reason: {$reason})"
        ]));
    }

    /**
     * Resave an element to trigger normal sync logic
     * This is safer than direct deletion as it respects all site-specific rules
     */
    protected function queueResave($element, string $objectID, string $reason): void
    {
        // Trigger a resave which will let the normal sync logic handle the element
        // If it's truly expired/disabled, it will be removed from Algolia
        // If it should stay (e.g., different site rules), it will be updated properly

        Craft::info("Cleanup: Resaving element {$element->id} (objectID: {$objectID}, reason: {$reason})", 'algolia-sync');

        try {
            // Resave the element to trigger the normal save event
            // Use false for the second parameter to skip validation (element might be in invalid state)
            Craft::$app->getElements()->saveElement($element, false);
        } catch (\Throwable $e) {
            Craft::error("Failed to resave element {$element->id}: {$e->getMessage()}", 'algolia-sync');
        }
    }

    protected function defaultDescription(): string
    {
        $count = count($this->objectIDs);
        return Craft::t('algolia-sync', "Checking {count} records in {label}", [
            'count' => $count,
            'label' => $this->label
        ]);
    }
}
