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
     * Array of Algolia records to check (each contains objectID, sectionId, type)
     * @var array
     */
    public array $objectIDs = []; // Actually contains full record data, not just IDs

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

        // Parse records to get element IDs, site IDs, and section info from Algolia
        // Map: "elementId-siteId-sectionId" => [records]
        $elementMap = [];

        foreach ($this->objectIDs as $record) {
            // Handle both old format (string objectID) and new format (array with metadata)
            if (is_string($record)) {
                $objectID = $record;
                $algoliaType = null;
                $algoliaSectionId = null;
            } else {
                $objectID = $record['objectID'];
                $algoliaType = $record['type'] ?? null;
                $algoliaSectionId = $record['sectionId'] ?? null;
            }

            // Parse objectID to get element ID and site ID (e.g., "123" or "123-1")
            $parts = explode('-', $objectID);
            $elementId = (int)$parts[0];
            $siteId = isset($parts[1]) ? (int)$parts[1] : null;

            // Use section ID from Algolia record if available, otherwise use job's sectionId
            $sectionId = $algoliaSectionId ?? $this->sectionId;
            $elementType = $algoliaType ?? $this->elementType;

            // Create a unique key
            $key = "{$elementId}-" . ($siteId ?? 'default') . "-{$sectionId}";

            if (!isset($elementMap[$key])) {
                $elementMap[$key] = [
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                    'sectionId' => $sectionId,
                    'elementType' => $elementType,
                    'objectIDs' => []
                ];
            }
            $elementMap[$key]['objectIDs'][] = $objectID;
        }

        // Check each element in its specific site context
        foreach ($elementMap as $key => $data) {
            $elementId = $data['elementId'];
            $siteId = $data['siteId'];
            $sectionId = $data['sectionId'];
            $elementType = $data['elementType'];
            $objectIDs = $data['objectIDs'];

            // Query element for this specific site and section
            $element = $this->queryElement($elementId, $siteId, $sectionId, $elementType);

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
     * Query a single Craft element by ID for a specific site and section
     *
     * @param int $elementId
     * @param int|null $siteId Site ID, or null for default site
     * @param int $sectionId Section/group/volume/type ID
     * @param string $elementType Element type (entry, category, asset, user, product)
     * @return \craft\base\ElementInterface|null
     */
    protected function queryElement(int $elementId, ?int $siteId, int $sectionId, string $elementType)
    {
        // If no siteId specified, use the primary site
        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        }

        $query = null;

        switch ($elementType) {
            case 'entry':
                $query = Entry::find()
                    ->id($elementId)
                    ->sectionId($sectionId)
                    ->siteId($siteId)
                    ->status(null); // Include all statuses
                break;

            case 'category':
                $query = Category::find()
                    ->id($elementId)
                    ->groupId($sectionId)
                    ->siteId($siteId)
                    ->status(null);
                break;

            case 'asset':
                $query = Asset::find()
                    ->id($elementId)
                    ->volumeId($sectionId)
                    ->siteId($siteId)
                    ->status(null);
                break;

            case 'user':
                // Users don't have site-specific versions
                $query = User::find()
                    ->id($elementId)
                    ->groupId($sectionId)
                    ->status(null);
                break;

            case 'product':
                if (class_exists('craft\\commerce\\elements\\Product')) {
                    $query = \craft\commerce\elements\Product::find()
                        ->id($elementId)
                        ->typeId($sectionId)
                        ->siteId($siteId)
                        ->status(null);
                }
                break;
        }

        return $query ? $query->one() : null;
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
