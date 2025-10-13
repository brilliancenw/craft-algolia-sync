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

        // Parse objectIDs to get element IDs and site IDs
        // Map: "elementId-siteId" => [objectIDs]
        $elementSiteMap = [];

        foreach ($this->objectIDs as $objectID) {
            // Parse objectID to get element ID and site ID (e.g., "123" or "123-1")
            $parts = explode('-', $objectID);
            $elementId = (int)$parts[0];
            $siteId = isset($parts[1]) ? (int)$parts[1] : null;

            // Create a key combining elementId and siteId
            $key = $siteId ? "{$elementId}-{$siteId}" : (string)$elementId;

            if (!isset($elementSiteMap[$key])) {
                $elementSiteMap[$key] = [
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                    'objectIDs' => []
                ];
            }
            $elementSiteMap[$key]['objectIDs'][] = $objectID;
        }

        // Check each element in its specific site context
        foreach ($elementSiteMap as $key => $data) {
            $elementId = $data['elementId'];
            $siteId = $data['siteId'];
            $objectIDs = $data['objectIDs'];

            // Query element for this specific site
            $element = $this->queryElement($elementId, $siteId);

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
     * Query a single Craft element by ID for a specific site
     *
     * @param int $elementId
     * @param int|null $siteId Site ID, or null for default site
     * @return \craft\base\ElementInterface|null
     */
    protected function queryElement(int $elementId, ?int $siteId)
    {
        // If no siteId specified, use the primary site
        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        }

        $query = null;

        switch ($this->elementType) {
            case 'entry':
                $query = Entry::find()
                    ->id($elementId)
                    ->sectionId($this->sectionId)
                    ->siteId($siteId)
                    ->status(null); // Include all statuses
                break;

            case 'category':
                $query = Category::find()
                    ->id($elementId)
                    ->groupId($this->sectionId)
                    ->siteId($siteId)
                    ->status(null);
                break;

            case 'asset':
                $query = Asset::find()
                    ->id($elementId)
                    ->volumeId($this->sectionId)
                    ->siteId($siteId)
                    ->status(null);
                break;

            case 'user':
                // Users don't have site-specific versions
                $query = User::find()
                    ->id($elementId)
                    ->groupId($this->sectionId)
                    ->status(null);
                break;

            case 'product':
                if (class_exists('craft\\commerce\\elements\\Product')) {
                    $query = \craft\commerce\elements\Product::find()
                        ->id($elementId)
                        ->typeId($this->sectionId)
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
