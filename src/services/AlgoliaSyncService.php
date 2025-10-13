<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * Syncing elements with Algolia using their API
 *
 * @link      https://www.brilliancenw.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\services;

use brilliance\algoliasync\AlgoliaSync;

use Craft;
use craft\elements\Asset;
use craft\elements\Tag;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\Category;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\MoneyHelper;
use craft\helpers\ElementHelper;
use craft\elements\db\ElementQuery;
use craft\db\Query;

use brilliance\algoliasync\events\beforeAlgoliaSyncEvent;

use brilliance\algoliasync\jobs\AlgoliaSyncTask;

use Algolia\AlgoliaSearch\Api\SearchClient;
use craft\helpers\FileHelper;


/**
 * AlgoliaSyncService Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Mark Middleton
 * @package   AlgoliaSync
 * @since     1.0.0
 *
 * @property-read array[] $algoliaSupportedElements
 */
class AlgoliaSyncService extends Component
{
    // Public Methods
    // =========================================================================

    const EVENT_BEFORE_ALGOLIA_SYNC = 'beforeAlgoliaSyncEvent';

    /**
     *     AlgoliaSync::$plugin->algoliaSyncService->generateSecuredApiKey()
     *
     * @return string
     */

    public function generateSecuredApiKey($filterCompany=null): string
    {
        $algoliaConfig = [];
        $validUntil = time() + (60 * 60 * 24);
        $algoliaConfig['validUntil'] = $validUntil;

        if (isset($filterCompany) && $filterCompany > 0) {
            $algoliaConfig['filters'] = 'company_'.$filterCompany;
        }

        $public_key = SearchClient::generateSecuredApiKey(
            AlgoliaSync::$plugin->settings->getAlgoliaSearch(),
            $algoliaConfig
        );

        return isset($public_key) ? $public_key : '';
    }


    public function updateAllElements($elementType, $sectionId) {
        // Loop through each site to support multi-site
        $allSites = Craft::$app->getSites()->getAllSites();
        foreach ($allSites as $site) {
            switch ($elementType) {

                case 'entry':
                    $entries = Entry::find()
                        ->sectionId($sectionId)
                        ->siteId($site->id)
                        ->all();
                    foreach ($entries as $entry) {
                        AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($entry);
                    }
                    break;

                case 'category':
                    $categories = Category::find()
                        ->groupId($sectionId)
                        ->siteId($site->id)
                        ->all();
                    foreach ($categories as $category) {
                        AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($category);
                    }
                    break;

                case 'asset':
                    $assets = Asset::find()
                        ->volume($sectionId)
                        ->all();
                    foreach ($assets as $asset) {
                        // assets are global; prepare will filter by enabled sites
                        AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($asset);
                    }
                    break;

                case 'user':
                    $allUsers = User::find()
                        ->groupId($sectionId)
                        ->all();
                    foreach ($allUsers as $user) {
                        AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($user);
                    }
                    break;

                case 'tag':
                    $allTags = Tag::find()
                        ->groupId($sectionId)
                        ->siteId($site->id)
                        ->all();
                    foreach ($allTags as $tag) {
                        AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($tag);
                    }
                    break;

                case 'product':
                    $products = \craft\commerce\elements\Product::find()
                        ->typeId($sectionId)
                        ->siteId($site->id)
                        ->all();
                    foreach ($products as $product) {
                        print "this is a product";
                            exit;
                        AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($product);
                    }
                    break;
            }
        }
    }


    // is this element one that is configured to be synced with Algolia?
    public function algoliaElementSynced($element): bool
    {
        $elementInfo = AlgoliaSync::$plugin->algoliaSyncService->getEventElementInfo($element);
        $algoliaSettings = AlgoliaSync::$plugin->getSettings();

        $className = get_class($element);

        AlgoliaSync::$plugin->algoliaSyncService->logger("trying to sync a ".$className, basename(__FILE__) , __LINE__);

        if (isset($elementInfo['enabled']) && empty($elementInfo['enabled'])) {
            AlgoliaSync::$plugin->algoliaSyncService->logger("This item is not enabled", basename(__FILE__) , __LINE__);

            // at this point, we should remove the product from the index - we don't know if it was already there...
            $algoliaIndex = AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaIndex($element);
            $objectID = $this->generateObjectID($element);

            $queue = Craft::$app->getQueue();
            $queue->push(new AlgoliaSyncTask([
                'algoliaIndex' => $algoliaIndex,
                'algoliaFunction' => 'delete',
                'algoliaObjectID' => $objectID,
                'algoliaRecord' => [],
                'algoliaMessage' => "Item is not enabled, confirming it's removed from Algolia"
            ]));

            return false;
        }

        SWITCH ($className) {
            CASE 'craft\elements\Entry':
            CASE 'craft\elements\Category':
            CASE 'craft\elements\Asset':
                if (  isset($elementInfo['sectionId'][0]) && isset($algoliaSettings['algoliaElements'][$elementInfo['type']][$elementInfo['sectionId'][0]]['sync'])
                    &&
                    $algoliaSettings['algoliaElements'][$elementInfo['type']][$elementInfo['sectionId'][0]]['sync'] == 1
                )
                {
                    return true;
                }
                return false;

            CASE 'craft\elements\User':
                if (count($elementInfo['sectionId']) > 0) {
                    $userGroups = $elementInfo['sectionId'];

                    $syncedGroups = $algoliaSettings['algoliaElements']['user'];

                    foreach ($userGroups AS $userGroup) {
                        if (isset($syncedGroups[$userGroup]) && $syncedGroups[$userGroup]['sync'] == 1) {
                            return true;
                        }
                    }
                }

                break;
            CASE 'craft\commerce\elements\Product':

                // the product query requires the results of a Variant query (can't just use the Variant itself)
                // $thisVariantQuery = \craft\commerce\elements\Variant::find()->id($element->id);
                // $myProduct = craft\commerce\elements\Product::find()->hasVariant($thisVariantQuery)->one();

                AlgoliaSync::$plugin->algoliaSyncService->logger("Product Title: ".$element->title.", Product ID: ".$element->id, basename(__FILE__) , __LINE__);

                if (
                    isset($algoliaSettings['algoliaElements'][$elementInfo['type']][$elementInfo['sectionId'][0]]['sync'])
                    &&
                    $algoliaSettings['algoliaElements'][$elementInfo['type']][$elementInfo['sectionId'][0]]['sync'] == 1
                )
                {
                    return true;
                }
                return false;
        }

        return false;
    }


    public function algoliaSyncRecord($action, $recordUpdate, $queueMessage = '') {

        if (isset($recordUpdate['processAlgoliaSync']) && $recordUpdate['processAlgoliaSync'] === false) {
            // do not process this record
            $message = "[".$action."] Record skipped\n";
        }
        else {
            $message = "[".$action."] Algolia Sync record to queue with the following data:\n";
            $message .= print_r($recordUpdate['index'], true);
            $message .= print_r($recordUpdate, true);

            $queue = Craft::$app->getQueue();
            $queue->push(new AlgoliaSyncTask([
                'algoliaIndex' => $recordUpdate['index'],
                'algoliaFunction' => $action,
                'algoliaObjectID' => $recordUpdate['attributes']['objectID'],
                'algoliaRecord' => $recordUpdate['attributes'],
                'queueMessage' => $queueMessage
            ]));
        }
        AlgoliaSync::$plugin->algoliaSyncService->logger($message, basename(__FILE__) , __LINE__);
    }

    public function algoliaResetIndex($index) {

        // todo : flesh out the index reset
//        $queue = Craft::$app->getQueue();
//        $queue->push(new AlgoliaResetTask([
//            'algoliaIndex' => $index
//        ]));

    }

    // todo : this was a poorly implemented solution to preventing specific field content from synced with Algolia.
    //  Instead, implement a mechanism to choose specific fields to sync (or not to sync)
    public function stopwordPass($fieldHandle, $stopword = '') {
        $stopword = trim($stopword);
        if ($stopword === '') {
            return true;
        }
        else {
            $stopword = strtolower($stopword);
            if (strpos($fieldHandle, $stopword) === 0) {
                return false;
            }
        }
        return true;
    }

    public function getCategoryTree($element, $fieldHandle, $parentCategory=null, $level=0) {
        $level++;
        /*
(
    [id] => 37
    [fieldLayoutId] => 6
    [uid] => 39811dea-f5f8-4e41-80d4-931e1068b78a
    [enabled] => 1
    [archived] => 0
    [dateCreated] => 2024-01-18 20:33:40
    [dateUpdated] => 2024-01-18 20:33:40
    [siteSettingsId] => 37
    [slug] => parent-1
    [siteId] => 1
    [uri] => categories/parent-1
    [enabledForSite] => 1
    [canonicalId] =>
    [dateLastMerged] =>
    [groupId] => 1
    [contentId] => 36
    [title] => Parent #1
    [field_numberField_vqmpwqxj] =>
    [field_textField_abzobzqy] =>
    [root] => 1
    [lft] => 2
    [rgt] => 7
    [level] => 1
    [structureId] => 1
)
         */

        // if this is the top level, treat it as such.
        if ($parentCategory === null) {
            $cats = $element->$fieldHandle
                ->level($level)
                ->all();
        }
        else {
            $cats = $element->$fieldHandle
                ->descendantOf($parentCategory->id)
                ->descendantDist(1)
                ->level($level)
                ->all();

        }

        if (count($cats) == 0) {
            return null;
        }
        else {

            foreach ($cats AS $cat) {
                $thisLittlePiece = [];
                $thisLittlePiece['title'] = $cat->title;
                $thisLittlePiece['children'] = $this->getCategoryTree($element, $fieldHandle, $cat, $level);

                $thisBranch[] = $thisLittlePiece;
            }
        }

        return $thisBranch;
    }


    public function convertToLevels($array, &$categories, $level = 0, $prefix = '') {
        if (!is_array($array)) {
            return;
        }

        foreach ($array as $element) {
            if (isset($element['title'])) {
                $currentTitle = $level === 0 ? $element['title'] : $prefix . ' > ' . $element['title'];

                // Add the current title to the appropriate level
                $categories['lvl' . $level][] = $currentTitle;

                // If children are present, go one level deeper
                if (isset($element['children']) && is_array($element['children'])) {
                    $this->convertToLevels($element['children'], $categories, $level + 1, $currentTitle);
                }
            }
        }
    }
    public function getFieldData($element, $field, $fieldHandle): mixed
    {
        if (get_class($field) === 'craft\ckeditor\Field') {
            $fieldType = 'ckeditor';
        }
        else {
            $fieldTypeArray = explode('\\', get_class($field));
            $fieldType = strtolower(array_pop($fieldTypeArray));
        }

        switch ($fieldType) {
            case 'ckeditor':
                return $element->$fieldHandle;

            case 'plaintext':
                $checkValue = $element->$fieldHandle;
                if (is_numeric($checkValue)) {
                    return (float)$element->$fieldHandle;
                }
                return $element->$fieldHandle;

            case 'categories':

                // flat categories
                $categories = $element->$fieldHandle->all();
                $flatCats = [];
                foreach ($categories AS $cat) {
                    $flatCats[] = $cat->title;
                }

                // nested categories
                $nestedCategories = $this->getCategoryTree($element,$fieldHandle);

                $levelCategories = [];
                $this->convertToLevels($nestedCategories, $levelCategories);

                return array(
                    'type' => $fieldType,
                    'flat'   => $flatCats,
                    'nested'    => $levelCategories
                );


            case 'entries':
            case 'tags':
            case 'users':
                $allRecords = $element->$fieldHandle->all();

                $titlesArray = [];
                $idsArray = [];

                foreach ($allRecords AS $thisRecord) {
                    if ($fieldType == 'users') {
                        $titlesArray[] = $thisRecord->username;
                    }
                    else {
                        $titlesArray[] = $thisRecord->title;
                    }
                    $idsArray[] = $thisRecord->id;
                }
                return array(
                    'type' => $fieldType,
                    'ids'   => $idsArray,
                    'titles'    => $titlesArray
                );

            case 'number':
                return (float)$element->$fieldHandle;

            case 'lightswitch':
                return (bool)$element->$fieldHandle;

            case 'multiselect':
            case 'checkboxes':
                $storedOptions = [];
                $fieldOptions = $element->$fieldHandle->getOptions();
                if ($fieldOptions) {
                    foreach ($fieldOptions AS $option) {
                        if ($option->selected) {
                            $storedOptions[] = $option->label;
                        }
                    }
                }
                return $storedOptions;
            case 'dropdown':
                return $element->$fieldHandle->label;
            case 'radiobuttons':
                return $element->$fieldHandle->value;
            case 'date':
                if ($element->$fieldHandle) {
                    return $element->$fieldHandle->getTimestamp();
                }
                else {
                    return null;
                }

            case 'assets':
                if ($element->$fieldHandle->count() > 1) {
                    $assetArray = [];
                    $allAssets = $element->$fieldHandle->all();
                    foreach ($allAssets AS $allAsset) {
                        $assetArray[] = $allAsset->url;
                    }
                    return $assetArray;
                }
                else {
                    $thisAsset = $element->$fieldHandle->one();
                    if ($thisAsset) {
                        return $thisAsset->url;
                    }
                    else {
                        return null;
                    }
                }

            case 'color':
                if ($element->$fieldHandle) {
                    return $element->$fieldHandle->getHex();
                }
                break;
            case 'email':
            case 'url':
                return $element->$fieldHandle;

            // TODO: Add support for other fields,
            // with maps being at the top of the list$fields = $element->getFieldLayout()->getFields();
            // to support location searches in Algolia
            // support for "Maps" (formerly "Simple Maps")
            // https://plugins.craftcms.com/simplemap
            case 'mapfield':

                $mapInfo = [];

                $mapInfo['type'] = 'mapfield';
                $mapInfo['lat'] = $element->$fieldHandle->lat;
                $mapInfo['lng'] = $element->$fieldHandle->lng;
                $mapInfo['zoom'] = $element->$fieldHandle->zoom;
                $mapInfo['address'] = $element->$fieldHandle->address;
                $mapInfo['what3words'] = $element->$fieldHandle->what3words;
                $mapInfo['parts'] = [];
                $mapInfo['parts']['number'] = $element->$fieldHandle->number;
                $mapInfo['parts']['address'] = $element->$fieldHandle->address;
                $mapInfo['parts']['city'] = $element->$fieldHandle->city;
                $mapInfo['parts']['postcode'] = $element->$fieldHandle->postcode;
                $mapInfo['parts']['county'] = $element->$fieldHandle->county;
                $mapInfo['parts']['state'] = $element->$fieldHandle->state;
                $mapInfo['parts']['country'] = $element->$fieldHandle->country;
                $mapInfo['parts']['planet'] = $element->$fieldHandle->planet;
                $mapInfo['parts']['system'] = $element->$fieldHandle->system;
                $mapInfo['parts']['arm'] = $element->$fieldHandle->arm;
                $mapInfo['parts']['galaxy'] = $element->$fieldHandle->galaxy;
                $mapInfo['parts']['group'] = $element->$fieldHandle->group;
                $mapInfo['parts']['cluster'] = $element->$fieldHandle->cluster;
                $mapInfo['parts']['supercluster'] = $element->$fieldHandle->supercluster;

                return $mapInfo;
        }
        return null;
    }

//    public function prepareAlgoliaSyncElement($element, $action = 'save', $algoliaMessage = '') {
//
//        $elementInfo = AlgoliaSync::$plugin->algoliaSyncService->getEventElementInfo($element);
//        $elementTypeSlug = $elementInfo['type'];
//
//        AlgoliaSync::$plugin->algoliaSyncService->logger("This type of item has been saved:: ".$elementTypeSlug, basename(__FILE__) , __LINE__);
//        // do we update this type of element?
//        $recordUpdate = array();
//
//        $okayToSync = AlgoliaSync::$plugin->algoliaSyncService->algoliaElementSynced($element);
//
//        if ($okayToSync) {
//            AlgoliaSync::$plugin->algoliaSyncService->logger("We are going to sync", basename(__FILE__) , __LINE__);
//
//            // what type of element
//            // user, entry, category
//            $recordUpdate['attributes'] = array();
//
//            if ($action == 'delete') {
//                $algoliaAction = 'delete';
//            } else {
//                if ($element->enabled) {
//                    $algoliaAction = 'insert';
//                } else {
//                    $algoliaAction = 'delete';
//                }
//            }
//            // get the attributes of the entity
//            $recordUpdate['attributes']['objectID'] = $element->id.'-'.$element->siteId;
//            $recordUpdate['attributes']['message'] = (int)$element->id;
//
//            // Get the list of enabled sites
//            $enabledSitesIds = [];
//            $enabledSiteHandles = [];
//
//            $allSites = Craft::$app->getSites()->getAllSites();
//
//            foreach ($allSites as $site) {
//                // Query the database directly for the element's enabled status in this site
//                $isEnabled = (new Query())
//                    ->select(['enabled'])
//                    ->from(['{{%elements_sites}}'])
//                    ->where(['elementId' => $element->id, 'siteId' => $site->id])
//                    ->scalar();
//
//                if ($isEnabled == 1) {
//                    $enabledSitesIds[] = $site->id;
//                    $enabledSiteHandles[] = $site->handle;
//                }
//            }
//
//            $recordUpdate['attributes']['siteIds'] = $enabledSitesIds;
//            $recordUpdate['attributes']['siteHandles'] = $enabledSiteHandles;
//
//            if (isset($element->slug)) {
//                $recordUpdate['attributes']['slug'] = $element->slug;
//            }
//            if (isset($element->authorId)) {
//                $recordUpdate['attributes']['authorId'] = (int)$element->authorId;
//            }
//            if (isset($element->postDate)) {
//                $recordUpdate['attributes']['postDate'] = (int)$element->postDate->getTimestamp();
//            }
//
//            if (!empty($element->product)) {
//                $fields = $element->product->getFieldLayout()->getCustomFields();
//            } else {
//                $fields = $element->getFieldLayout()->getCustomFields();
//            }
//
//            $arrayFieldTypes = array('entries','tags','users');
//
//            foreach ($fields AS $field) {
//                $fieldHandle = $field->handle;
//
//                // send this off to a function to extract the specific information
//                // based on what type of field it is (asset, text, varchar, etc...)
//                $fieldName = AlgoliaSync::$plugin->algoliaSyncService->sanitizeFieldName($field->name);
//
//                $rawData = AlgoliaSync::$plugin->algoliaSyncService->getFieldData($element, $field, $fieldHandle);
//
//                if ($rawData instanceof \craft\ckeditor\data\FieldData) {
//                    $recordUpdate['attributes'][$fieldName] = $rawData->getRawContent();;
//                    }
//                elseif (isset($rawData['type']) && in_array($rawData['type'], $arrayFieldTypes)) {
//                    $recordUpdate['attributes'][$fieldName] = $rawData['titles'];
//                    $idsFieldName = $fieldName.'Ids';
//                    $recordUpdate['attributes'][$idsFieldName] = $rawData['ids'];
//                }
//                elseif (isset($rawData['type']) && $rawData['type'] == 'mapfield') {
//                    $recordUpdate['attributes'][$fieldName] = $rawData;
//                    $recordUpdate['attributes'][$fieldName.'_address'] = $rawData['address'];
//                    $recordUpdate['attributes'][$fieldName.'_lat'] = $rawData['lat'];
//                    $recordUpdate['attributes'][$fieldName.'_lng'] = $rawData['lng'];
//                    $recordUpdate['attributes'][$fieldName.'_zoom']['zoom'] = $rawData['zoom'];
//                    if (!empty($rawData['lat']) && !empty($rawData['lng'])) {
//                        // https://www.algolia.com/doc/guides/managing-results/refine-results/geolocation/#enabling-geo-search-by-adding-geolocation-data-to-records
//                        // inject a _geoloc into Algolia
//                        // this doesn't take into account if there are multiple _geoloc...
//                        // that will be more complex to resolve
//                        $recordUpdate['attributes']['_geoloc'] = [];
//                        $recordUpdate['attributes']['_geoloc']['lat'] = $rawData['lat'];
//                        $recordUpdate['attributes']['_geoloc']['lng'] = $rawData['lng'];
//                    }
//                }
//                elseif (isset($rawData['type']) && $rawData['type'] == 'categories') {
//
//                    $recordUpdate['attributes'][$fieldName] = $rawData['flat'];
//                    $nestedName = $fieldName."_hx";
//                    $recordUpdate['attributes'][$nestedName] = $rawData['nested'];
//
//                }
//                else {
//                    $recordUpdate['attributes'][$fieldName] = $rawData;
//                }
//
//                $fieldTypeLong = get_class($field);
//                $fieldTypeArray = explode('\\', $fieldTypeLong);
//                $fieldType = strtolower(array_pop($fieldTypeArray));
//
//                // for the date field, create a few versions of the date
//                // todo : add in a config for custom date format to be added
//                if ($fieldType == 'date') {
//                    // get the friendly date
//                    $friendlyName = $fieldName . "_friendly";
//                    $friendlyDate = date('n/j/Y', $rawData);
//                    $recordUpdate['attributes'][$friendlyName] = $friendlyDate;
//
//                    // get the previous midnight of the current date (unix timestamp)
//                    $midnightName = $fieldName . "_midnight";
//                    $midnightTimestamp = mktime(0, 0, 0, date('n', $rawData), date('j', $rawData), date('Y', $rawData));
//                    $recordUpdate['attributes'][$midnightName] = $midnightTimestamp;
//                }
//            }
//
//            $recordUpdate['index'] = AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaIndex($element);
//
//            switch ($elementTypeSlug) {
//                case 'category':
//                case 'entry':
//                case 'asset':
//                case 'tag':
//
//                    $recordUpdate['elementType'] = ucwords($elementTypeSlug);
//                    $recordUpdate['handle'] = $elementInfo['sectionHandle'];
//                    $recordUpdate['attributes']['title'] = $element->title;
//                    break;
//
//                case 'user':
//                    $recordUpdate['elementType'] = 'User';
//                    $recordUpdate['handle'] = $elementInfo['sectionHandle'];
//                    $recordUpdate['attributes']['title'] = $element->username;
//                    $recordUpdate['attributes']['firstname'] = $element->firstName;
//                    $recordUpdate['attributes']['lastname'] = $element->lastName;
//                    $recordUpdate['attributes']['email'] = $element->email;
//                    $userGroups = $element->getGroups();
//                    $groupList = [];
//                    foreach ($userGroups AS $group) {
//                        $groupList[] = $group->handle;
//                    }
//                    $recordUpdate['attributes']['userGroups'] = $groupList;
//                    break;
//
//                case 'product':
//
//                    $defaultVariant = $element->defaultVariant;
//
//                    if (isset($defaultVariant) &&  isset($defaultVariant->onSale)) {
//                        $salePrice = (float)$defaultVariant->salePrice;
//                        $onSale = true;
//                    }
//                    else {
//                        $salePrice = null;
//                        $onSale = false;
//                    }
//
//                    AlgoliaSync::$plugin->algoliaSyncService->logger("Product is being loaded", basename(__FILE__) , __LINE__);
//
//                    // get the basic product info
//                    $recordUpdate['elementType'] = ucwords($elementTypeSlug);
//                    $recordUpdate['handle'] = $elementInfo['sectionHandle'][0] ?? null;
//                    $recordUpdate['attributes']['title'] = $element->title ?? null;
//
//                    if (isset($element->id)) {
//                        $recordUpdate['attributes']['productId'] = $element->id;
//                    }
//                    if (isset($element->typeId)) {
//                        $recordUpdate['attributes']['typeId'] = $element->typeId;
//                    }
//                    if (isset($element->taxCategoryId)) {
//                        $recordUpdate['attributes']['taxCategoryId'] = $element->taxCategoryId;
//                    }
//                    if (isset($element->shippingCategoryId)) {
//                        $recordUpdate['attributes']['shippingCategoryId'] = $element->shippingCategoryId;
//                    }
//                    if (isset($element->defaultSku)) {
//                        $recordUpdate['attributes']['defaultSku'] = $element->defaultSku;
//                    }
//                    if (isset($element->availableForPurchase)) {
//                        $recordUpdate['attributes']['availableForPurchase'] = (bool)$element->availableForPurchase;
//                    }
//                    if (isset($element->defaultVariantId)) {
//                        $recordUpdate['attributes']['defaultVariantId'] = (int)$element->defaultVariantId;
//                    }
//                    if (isset($element->defaultPrice)) {
//                        $recordUpdate['attributes']['defaultPrice'] = (float)$element->defaultPrice;
//                    }
//                    if (isset($element->defaultWidth)) {
//                        $recordUpdate['attributes']['defaultWidth'] = $element->defaultWidth;
//                    }
//                    if (isset($element->defaultHeight)) {
//                        $recordUpdate['attributes']['defaultHeight'] = $element->defaultHeight;
//                    }
//                    if (isset($element->defaultLength)) {
//                        $recordUpdate['attributes']['defaultLength'] = $element->defaultLength;
//                    }
//                    if (isset($element->defaultWeight)) {
//                        $recordUpdate['attributes']['defaultWeight'] = $element->defaultWeight;
//                    }
//                    if (isset($element->taxCategory)) {
//                        $recordUpdate['attributes']['taxCategory'] = $element->taxCategory;
//                    }
//
//
//                    if (isset($elementInfo['productTypeName'])) {
//                        $recordUpdate['attributes']['ProductType'] = $elementInfo['productTypeName'];
//                    }
//
//                    if (isset($onSale)) {
//                        $recordUpdate['attributes']['onSale'] = $onSale; // Assuming $onSale is already a boolean or correct type
//                    }
//                    if (isset($salePrice)) {
//                        $recordUpdate['attributes']['salePrice'] = $salePrice; // Assuming $salePrice is already a number/string or correct type
//                    }
//
//                    // now load all variants
//                    $getAllVariants = \craft\commerce\elements\Variant::find()->productId($element->id);
//
//                    $recordUpdate['attributes']['variants'] = [];
//
//                    foreach ($getAllVariants AS $variantDetails) {
//
//                        $variantInfo = [];
//
//                        $variantInfo['title'] = $variantDetails->title ?? null;
//
//                        $variantInfo['variantId'] = $variantDetails->id ?? null;
//
//                        if (isset($variantDetails->productId)) {
//                            $variantInfo['productId'] = (int)$variantDetails->productId;
//                        }
//                        if (isset($variantDetails->isDefault)) {
//                            $variantInfo['isDefault'] = (bool)$variantDetails->isDefault;
//                        }
//                        if (isset($variantDetails->price)) {
//                            $variantInfo['price'] = (float)$variantDetails->price;
//                        }
//                        if (isset($variantDetails->sortOrder)) {
//                            $variantInfo['sortOrder'] = (int)$variantDetails->sortOrder;
//                        }
//                        if (isset($variantDetails->width)) {
//                            $variantInfo['width'] = (float)$variantDetails->width;
//                        }
//                        if (isset($variantDetails->height)) {
//                            $variantInfo['height'] = (float)$variantDetails->height;
//                        }
//                        if (isset($variantDetails->length)) {
//                            $variantInfo['length'] = (float)$variantDetails->length;
//                        }
//                        if (isset($variantDetails->stock)) {
//                            $variantInfo['stock'] = (int)$variantDetails->stock;
//                        }
//                        if (isset($variantDetails->weight)) {
//                            $variantInfo['weight'] = (float)$variantDetails->weight;
//                        }
//                        if (isset($variantDetails->hasUnlimitedStock)) {
//                            $variantInfo['hasUnlimitedStock'] = (bool)$variantDetails->hasUnlimitedStock;
//                        }
//                        if (isset($variantDetails->minQty)) {
//                            $variantInfo['minQty'] = (int)$variantDetails->minQty;
//                        }
//                        if (isset($variantDetails->maxQty)) {
//                            // Note: maxQty might be 0 or null when there's no maximum.
//                            // isset() handles the null case. If 0 is a meaningful value you want,
//                            // this check is still correct as 0 is considered "set".
//                            $variantInfo['maxQty'] = (int)$variantDetails->maxQty;
//                        }
//
//                        // nest each variant under the product info
//                        $recordUpdate['attributes']['variants'][] = $variantInfo;
//
//                    }
//
//                    break;
//
//            }
//
//            // Fire event for tracking before the sync event.
//            $event = new beforeAlgoliaSyncEvent([
//                'recordElement' => $element,
//                'recordUpdate' => $recordUpdate
//            ]);
//
//            $this->trigger(self::EVENT_BEFORE_ALGOLIA_SYNC, $event);
//            $recordUpdate = $event->recordUpdate;
//
//            // $recordUpdate['elementType']
//            AlgoliaSync::$plugin->algoliaSyncService->algoliaSyncRecord($algoliaAction, $recordUpdate, $algoliaMessage);
//        }
//        else {
//            AlgoliaSync::$plugin->algoliaSyncService->logger("Not okay to sync...", basename(__FILE__) , __LINE__);
//        }
//    }


    public function prepareAlgoliaSyncElement($element, $action = 'save', $algoliaMessage = '')
    {
        // Gather element info and short-circuit if not synced
        $elementInfo = $this->getEventElementInfo($element);
        $type = $elementInfo['type'];

        $this->logger("Preparing sync for {$type} ID {$element->id}", __FILE__, __LINE__);

        if (!$this->algoliaElementSynced($element)) {
            $this->logger("Element not configured for sync: {$element->id}", __FILE__, __LINE__);
            return;
        }

        // Determine Algolia action
        // Check if element is expired
        $isExpired = false;
        if (isset($element->expiryDate) && $element->expiryDate instanceof \DateTime) {
            $now = new \DateTime();
            $isExpired = ($element->expiryDate < $now);
        }

        $isDelete = ($action === 'delete' || !$element->enabled || $isExpired);
        $algoliaAction = $isDelete ? 'delete' : 'insert';
        $algoliaActionTitle = $isDelete ? 'Deleting' : 'Inserting';
        $algoliaIndexHandle = $this->getAlgoliaIndex($element)[0];

        // Build base payload
        $recordTemplate = [
            'attributes' => [],
            'index' => $this->getAlgoliaIndex($element),
            'elementType' => ucwords($type),
            'handle' => $elementInfo['sectionHandle'],
        ];
        $recordTemplate['attributes']['message'] = (int)$element->id;
        $recordTemplate['attributes']['slug'] = $element->slug ?? null;
        $recordTemplate['attributes']['postDate'] = isset($element->postDate)
            ? (int)$element->postDate->getTimestamp()
            : null;

        // Gather custom field values
        if ($type === 'product') {

            $defaultVariant = $element->defaultVariant;

            if (isset($defaultVariant) &&  isset($defaultVariant->onSale)) {
                $salePrice = (float)$defaultVariant->salePrice;
                $onSale = true;
            }
            else {
                $salePrice = null;
                $onSale = false;
            }

            AlgoliaSync::$plugin->algoliaSyncService->logger("Product is being loaded", basename(__FILE__) , __LINE__);

            // get the basic product info
            $recordTemplate['elementType'] = ucwords($type);
            $recordTemplate['handle'] = $elementInfo['sectionHandle'][0] ?? null;
            $recordTemplate['attributes']['title'] = $element->title ?? null;

            if (isset($element->id)) {
                $recordTemplate['attributes']['productId'] = $element->id;
            }
            if (isset($element->typeId)) {
                $recordTemplate['attributes']['typeId'] = $element->typeId;
            }
            if (isset($element->taxCategoryId)) {
                $recordTemplate['attributes']['taxCategoryId'] = $element->taxCategoryId;
            }
            if (isset($element->shippingCategoryId)) {
                $recordTemplate['attributes']['shippingCategoryId'] = $element->shippingCategoryId;
            }
            if (isset($element->defaultSku)) {
                $recordTemplate['attributes']['defaultSku'] = $element->defaultSku;
            }
            if (isset($element->availableForPurchase)) {
                $recordTemplate['attributes']['availableForPurchase'] = (bool)$element->availableForPurchase;
            }
            if (isset($element->defaultVariantId)) {
                $recordTemplate['attributes']['defaultVariantId'] = (int)$element->defaultVariantId;
            }
            if (isset($element->defaultPrice)) {
                $recordTemplate['attributes']['defaultPrice'] = (float)$element->defaultPrice;
            }
            if (isset($element->defaultWidth)) {
                $recordTemplate['attributes']['defaultWidth'] = $element->defaultWidth;
            }
            if (isset($element->defaultHeight)) {
                $recordTemplate['attributes']['defaultHeight'] = $element->defaultHeight;
            }
            if (isset($element->defaultLength)) {
                $recordTemplate['attributes']['defaultLength'] = $element->defaultLength;
            }
            if (isset($element->defaultWeight)) {
                $recordTemplate['attributes']['defaultWeight'] = $element->defaultWeight;
            }
            if (isset($element->taxCategory)) {
                $recordTemplate['attributes']['taxCategory'] = $element->taxCategory;
            }
            if (isset($elementInfo['productTypeName'])) {
                $recordTemplate['attributes']['ProductType'] = $elementInfo['productTypeName'];
            }
            if (isset($onSale)) {
                $recordTemplate['attributes']['onSale'] = $onSale; // Assuming $onSale is already a boolean or correct type
            }
            if (isset($salePrice)) {
                $recordTemplate['attributes']['salePrice'] = $salePrice; // Assuming $salePrice is already a number/string or correct type
            }

            // now load all variants
            $getAllVariants = \craft\commerce\elements\Variant::find()->productId($element->id);

            $recordTemplate['attributes']['variants'] = [];

            foreach ($getAllVariants AS $variantDetails) {

                $variantInfo = [];

                $variantInfo['title'] = $variantDetails->title ?? null;

                $variantInfo['variantId'] = $variantDetails->id ?? null;

                if (isset($variantDetails->productId)) {
                    $variantInfo['productId'] = (int)$variantDetails->productId;
                }
                if (isset($variantDetails->isDefault)) {
                    $variantInfo['isDefault'] = (bool)$variantDetails->isDefault;
                }
                if (isset($variantDetails->price)) {
                    $variantInfo['price'] = (float)$variantDetails->price;
                }
                if (isset($variantDetails->sortOrder)) {
                    $variantInfo['sortOrder'] = (int)$variantDetails->sortOrder;
                }
                if (isset($variantDetails->width)) {
                    $variantInfo['width'] = (float)$variantDetails->width;
                }
                if (isset($variantDetails->height)) {
                    $variantInfo['height'] = (float)$variantDetails->height;
                }
                if (isset($variantDetails->length)) {
                    $variantInfo['length'] = (float)$variantDetails->length;
                }
                if (isset($variantDetails->stock)) {
                    $variantInfo['stock'] = (int)$variantDetails->stock;
                }
                if (isset($variantDetails->weight)) {
                    $variantInfo['weight'] = (float)$variantDetails->weight;
                }
                if (isset($variantDetails->hasUnlimitedStock)) {
                    $variantInfo['hasUnlimitedStock'] = (bool)$variantDetails->hasUnlimitedStock;
                }
                if (isset($variantDetails->minQty)) {
                    $variantInfo['minQty'] = (int)$variantDetails->minQty;
                }
                if (isset($variantDetails->maxQty)) {
                    // Note: maxQty might be 0 or null when there's no maximum.
                    // isset() handles the null case. If 0 is a meaningful value you want,
                    // this check is still correct as 0 is considered "set".
                    $variantInfo['maxQty'] = (int)$variantDetails->maxQty;
                }
                // nest each variant under the product info
                $recordTemplate['attributes']['variants'][] = $variantInfo;
            }
            $fields = $element->getFieldLayout()->getCustomFields();
        } else {

            $fields = $element->getFieldLayout()->getCustomFields();
        }
        $arrayFieldTypes = ['entries', 'tags', 'users'];

        foreach ($fields as $field) {
            $handle = $field->handle;
            $name = $this->sanitizeFieldName($field->name);
            $raw = $this->getFieldData($element, $field, $handle);

            if ($raw instanceof \craft\ckeditor\data\FieldData) {
                $recordTemplate['attributes'][$name] = $raw->getRawContent();
            } elseif (isset($raw['type']) && in_array($raw['type'], $arrayFieldTypes)) {
                $recordTemplate['attributes'][$name] = $raw['titles'];
                $recordTemplate['attributes'][$name . 'Ids'] = $raw['ids'];
            } elseif (isset($raw['type']) && $raw['type'] === 'mapfield') {
                $recordTemplate['attributes'][$name] = $raw;
                $recordTemplate['attributes'][$name . '_address'] = $raw['address'];
                $recordTemplate['attributes'][$name . '_lat'] = $raw['lat'];
                $recordTemplate['attributes'][$name . '_lng'] = $raw['lng'];
                $recordTemplate['attributes'][$name . '_zoom'] = $raw['zoom'];
                if (!empty($raw['lat']) && !empty($raw['lng'])) {
                    $recordTemplate['attributes']['_geoloc'] = [
                        'lat' => $raw['lat'],
                        'lng' => $raw['lng'],
                    ];
                }
            } elseif (isset($raw['type']) && $raw['type'] === 'categories') {
                $recordTemplate['attributes'][$name] = $raw['flat'];
                $recordTemplate['attributes'][$name . '_hx'] = $raw['nested'];
            } else {
                $recordTemplate['attributes'][$name] = $raw;
            }

            // Friendly date formats
            $classParts = explode('\\', get_class($field));
            $fieldType = strtolower(end($classParts));
            if ($fieldType === 'date') {
                $ts = $raw;
                $recordTemplate['attributes'][$name . '_friendly'] = date('n/j/Y', $ts);
                $recordTemplate['attributes'][$name . '_midnight'] = mktime(
                    0, 0, 0,
                    date('n', $ts),
                    date('j', $ts),
                    date('Y', $ts)
                );
            }
        }

        // Determine which sites to sync
        // Only sync the current site when bulk‑loading or saving
        if ($action === 'bulk' || $action === 'save') {
            $siteId    = $element->siteId;
            $siteModel = Craft::$app->getSites()->getSiteById($siteId);
            $enabledSites = [
                $siteId => [
                    'handle'   => $siteModel->handle,
                    'language' => $siteModel->language,
                ],
            ];
        } else {
            // For delete or other actions, process ALL sites
            // When deleting/disabling, the element may not be "enabled" in Craft anymore,
            // but it may still exist in Algolia and needs to be removed
            $enabledSites = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $enabledSites[$site->id] = [
                    'handle'   => $site->handle,
                    'language' => $site->language,
                ];
            }
        }


        // Fire before-sync event
        $event = new beforeAlgoliaSyncEvent([
            'recordElement' => $element,
            'recordUpdate' => $recordTemplate,
        ]);
        $this->trigger(self::EVENT_BEFORE_ALGOLIA_SYNC, $event);
        $recordTemplate = $event->recordUpdate;

        // Queue a record per site
        foreach ($enabledSites as $siteId => $info) {
            $record = $recordTemplate;

            // Generate objectID using centralized helper
            $objectID = $this->generateObjectID($element, $siteId);

            $record['attributes']['objectID'] = $objectID;
            $record['attributes']['siteId'] = $siteId;
            $record['attributes']['siteHandle'] = $info['handle'];
            $record['attributes']['siteLanguage'] = $info['language'];

            $title = $element->title ?? ($element->username ?? 'N/A');
            $record['attributes']['title'] = $title;
            $max = 40;
            $short = mb_strlen($title) > $max ? mb_substr($title, 0, $max) . '...' : $title;

            $queueMessage = sprintf(
                'Algolia Sync: %s %s "%s (id: %s)", Site: "%s (id: %d)", Index "%s"',
                $algoliaActionTitle,
                $type,
                $short,
                $objectID,
                $info['handle'],
                $siteId,
                $algoliaIndexHandle
            );

            $this->algoliaSyncRecord($algoliaAction, $record, $queueMessage);

            // Cleanup: When deleting from default site, also delete the alternate format
            // This ensures old records are removed when the setting changes
            $defaultSiteId = Craft::$app->getSites()->getPrimarySite()->id;
            $isDefaultSite = ($siteId == $defaultSiteId);
            $appendSuffix = AlgoliaSync::$plugin->settings->appendSiteIdToDefaultSite;

            if ($algoliaAction === 'delete' && $isDefaultSite) {
                $alternateObjectID = $appendSuffix
                    ? (string)$element->id  // If we just deleted "123-1", also delete "123"
                    : "{$element->id}-{$siteId}";  // If we just deleted "123", also delete "123-1"

                $alternateRecord = $record;
                $alternateRecord['attributes']['objectID'] = $alternateObjectID;

                $alternateQueueMessage = sprintf(
                    'Algolia Sync: Cleanup - Deleting alternate format "%s" for %s, Site: "%s (id: %d)", Index "%s"',
                    $alternateObjectID,
                    $type,
                    $info['handle'],
                    $siteId,
                    $algoliaIndexHandle
                );

                $this->algoliaSyncRecord('delete', $alternateRecord, $alternateQueueMessage);
            }
        }
    }

    /**
     * Generate the Algolia objectID for an element based on site and settings
     *
     * @param Element $element The element to generate objectID for
     * @param int|null $siteId Optional site ID (uses element's siteId if not provided)
     * @return string The objectID (e.g., "123" or "123-1")
     */
    public function generateObjectID($element, ?int $siteId = null): string
    {
        $siteId = $siteId ?? $element->siteId;
        $defaultSiteId = Craft::$app->getSites()->getPrimarySite()->id;
        $isDefaultSite = ($siteId == $defaultSiteId);
        $appendSuffix = AlgoliaSync::$plugin->settings->appendSiteIdToDefaultSite;

        if ($isDefaultSite && !$appendSuffix) {
            return (string)$element->id;
        } else {
            return "{$element->id}-{$siteId}";
        }
    }

    public function sanitizeFieldName($fieldName) {
        $fieldName = preg_replace("/[^A-Za-z0-9 ]/", '', $fieldName);
        return str_replace(' ', '_', $fieldName);
    }

    public function getSyncedMemberGroups() {
        // this is used when deleting a user record
        // as Craft doesn't give us what groups they "used to be" in
        // when a record gets updated.  If they are not part of any group,
        // we need to actively delete them from any member group that is being synced

        $env = $this->getEnvironment();

        // user groups list
        $userGroups = Craft::$app->userGroups->getAllGroups();
        $userGroupsConfig = [];
        foreach ($userGroups AS $group) {
            $userGroupsConfig[$group->id] = $env.'_user_'.$group->handle;
        }

        $syncedGroups = [];

        $algoliaSettings = AlgoliaSync::$plugin->getSettings();

        if (isset($algoliaSettings->algoliaElements['user'])) {
            $syncedGroupsArray = $algoliaSettings->algoliaElements['user'];
            if (count($syncedGroupsArray) > 0) {
                foreach ($syncedGroupsArray AS $groupId => $groupData) {
                    if (!empty($groupData['sync'])) {
                        // does this have a custom index name?
                        $potentialIndexOverride = $groupData['customIndex'];

                        if (!empty($potentialIndexOverride)) {
                            // is that name an env variable?
                            $syncedGroups[] = App::parseEnv($potentialIndexOverride);
                        }
                        else {
                            // otherwise, use the default convention
                            if (isset($userGroupsConfig[$groupId])) {
                                $syncedGroups[] = $userGroupsConfig[$groupId];
                            }
                        }
                    }
                }
            }
        }
        return $syncedGroups;
    }

    public function getEventElementInfo($element, $processRecords = true) {

        $elementTypeSlugArray = explode("\\", get_class($element));

        $info = [];
        $info['type'] = strtolower(array_pop($elementTypeSlugArray));
        $info['id'] = $element->id;

        $info['sectionHandle'] = [];
        $info['sectionId'] = [];

        switch ($info['type']) {
            case 'product':
                $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

                if ($commercePlugin) {
                    $productType = $commercePlugin::getInstance()->getProductTypes()->getProductTypeById($element->typeId);
                    $info['sectionHandle'][] = $productType->handle;
                    $info['sectionId'][] = $element->typeId;
                    $info['productTypeId'] = $element->typeId;
                    $info['productTypeName'] = $productType->name;
                    $info['enabled'] = $element->enabled;
                }
                break;
            case 'asset':
                $info['sectionHandle'][] = $element->volume->handle;
                $info['sectionId'][] = $element->volume->id;
                break;
            case 'category':
                $info['sectionHandle'][] = Craft::$app->categories->getGroupById($element->groupId)->handle;
                $info['sectionId'][] = $element->groupId;
                break;
            case 'entry':
                if (!empty($element->sectionId)) {
                    $info['sectionHandle'][] = Craft::$app->entries->getSectionById($element->sectionId)->handle;
                    $info['sectionId'][] = $element->sectionId;
                }
                break;
            case 'tag':
                if (!empty($element->sectionId)) {
                    $info['sectionHandle'][] = Craft::$app->tags->getTagGroupById($element->sectionId)->handle;
                    $info['sectionId'][] = $element->sectionId;
                }
                break;
            case 'user':

                // this will get the current user's groups (may be multiple indexes to update)
                // lets only upsert records that match the configured groups in algolia sync
                $algoliaSettings = AlgoliaSync::$plugin->getSettings();

                if (!empty($algoliaSettings['algoliaElements']['user'])) {
                    $syncedGroups = $algoliaSettings['algoliaElements']['user'];

                    $userGroups = Craft::$app->userGroups->getGroupsByUserId($element->id);
                    $deleteFromAlgolia = true;

                    foreach ($userGroups AS $group) {
                        if (isset($syncedGroups[$group->id]) && $syncedGroups[$group->id]['sync'] == 1) {
                            $info['sectionHandle'][] = $group->handle;
                            $info['sectionId'][] = $group->id;
                            $deleteFromAlgolia = false;
                        }
                    }
                    // there doesn't seem to be a way to see what group the user WAS in,
                    // so we need to purge them from all groups they are NOT in now.
                    // check that this user is NOT in Algolia any more
                    // if their user group used to match, but it's been changed
                    // and they need to be removed...
                    // this is where we send a quick message to Algolia to purge out their record

                    if ($deleteFromAlgolia && $processRecords) {

                        $elementData = [];
                        $elementData['index'] = AlgoliaSync::$plugin->algoliaSyncService->getSyncedMemberGroups();
                        $elementData['attributes'] = [];
                        $elementData['attributes']['objectID'] = $this->generateObjectID($element);

                        AlgoliaSync::$plugin->algoliaSyncService->algoliaSyncRecord('delete', $elementData);
                    }
                }
                break;
        }
        return $info;
    }

    public function getAlgoliaIndex($element): array
    {
        $returnIndex = [];

        $allSettings = AlgoliaSync::$plugin->getSettings();
        $eventInfo = AlgoliaSync::$plugin->algoliaSyncService->getEventElementInfo($element, false);

        foreach ($eventInfo['sectionId'] as $sectionId) {
            $potentialIndexOverride = $allSettings['algoliaElements'][$eventInfo['type']][$sectionId]['customIndex'];

            if (!empty($potentialIndexOverride)) {
                $returnIndex[] = App::parseEnv($potentialIndexOverride);
                return $returnIndex;
            }
        }

        $env = $this->getEnvironment();
        foreach ($eventInfo['sectionHandle'] AS $handle) {
            $returnIndex[] = $env.'_'.$eventInfo['type'].'_'.$handle;
        }

        return $returnIndex;
    }

    // AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaSupportedElements()
    public function getAlgoliaSupportedElements(): array {
        $env = $this->getEnvironment();

        // all Channel Sections
        $entriesConfig = array();
        $allSections = Craft::$app->entries->allSections;
        foreach ($allSections as $section) {
            if (in_array($section->type, ['channel','structure'])) {
                $sectionIndex = 'section-'.$section->id;
                $entriesConfig[$sectionIndex] = array(
                    'default_index' => $env.'_section_'.$section->handle,
                    'label' => $section->name,
                    'handle' => $section->handle,
                    'value' => $section->id
                );
            }
        }

        // all Asset Volumes
        $volumes = Craft::$app->volumes->getAllVolumes();
        $volumesConfig = [];
        foreach ($volumes AS $volume) {
            $volumesConfig[] = array(
                'default_index' => $env.'_volume_'.$volume->handle,
                'label' => $volume->name,
                'handle' => $volume->handle,
                'value' => $volume->id
            );
        }

        // all Category Groups
        $catGroups = Craft::$app->categories->getAllGroups();
        $categoriesConfig = [];
        foreach ($catGroups AS $group) {
            $categoriesConfig[] = array(
                'default_index' => $env.'_category_'.$group->handle,
                'label' => $group->name,
                'handle' => $group->handle,
                'value' => $group->id
            );
        }

        // $tagGroupsConfig
        $tagGroups = Craft::$app->tags->getAllTagGroups();
        $tagGroupsConfig = [];
        foreach ($tagGroups AS $tagGroup) {
            $tagGroupsConfig[] = array(
                'default_index' => $env.'_tag_'.$tagGroup->handle,
                'label' => $tagGroup->name,
                'handle' => $tagGroup->handle,
                'value' => $tagGroup->id
            );
        }

        // We are not supporting Global Sets until I find a use case that I can build towards
        // this would only create a single record in Algolia, which defeats the whole point of search
        // please let us know if you have a specific use case and we can add in support to meet the need

        // user groups list
        $userGroups = Craft::$app->userGroups->getAllGroups();
        $userGroupsConfig = [];
        foreach ($userGroups AS $group) {
            $userGroupsConfig[] = array(
                'default_index' => $env.'_user_'.$group->handle,
                'label' => $group->name,
                'handle' => $group->handle,
                'value' => $group->id
            );
        }

        $returnArray = [];

        $returnArray[] =    ['label' => 'Entries',          'handle' => 'entry',    'data' => $entriesConfig];
        $returnArray[] =    ['label' => 'Asset Volumes',    'handle' => 'asset',    'data' => $volumesConfig];
        $returnArray[] =    ['label' => 'Categories',       'handle' => 'category', 'data' => $categoriesConfig];
        $returnArray[] =    ['label' => 'User Groups',      'handle' => 'user',     'data' => $userGroupsConfig];
        $returnArray[] =    ['label' => 'Tag Groups',       'handle' => 'tag',      'data' => $tagGroupsConfig];

        $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

        if ($commercePlugin) {

            $productTypes = $commercePlugin::getInstance()->getProductTypes()->getAllProductTypes();;

            $productTypesConfig = [];
            foreach ($productTypes AS $productType) {
                $productTypesConfig[] = array(
                    'default_index' => $env.'_product_'.$productType->handle,
                    'label' => $productType->name,
                    'handle' => $productType->handle,
                    'value' => $productType->id
                );
            }
            $returnArray[] =     [
                'label' => 'Commerce Product Types',
                'handle' => 'product',
                'data' => $productTypesConfig
            ];
        }

        return $returnArray;

    }

    public function getEnvironment(): string {
        if (getenv('ENVIRONMENT')) {
            return getenv('ENVIRONMENT');
        }
        else if (getenv('CRAFT_ENVIRONMENT')) {
            return getenv('CRAFT_ENVIRONMENT');
        }
        else {
            return 'site';
        }
    }

    // AlgoliaSync::$plugin->algoliaSyncService->logger($message, __FILE__, __LINE__)
    public function logger($message, $filename, $linenumber):void {
        if (Craft::$app->getConfig()->general->devMode) {
            $file = Craft::getAlias('@storage/logs/algolia-sync.log');
            $log = date('Y-m-d H:i:s').' ['.$filename.':'.$linenumber.'] '.$message."\n";
            FileHelper::writeToFile($file, $log, ['append' => true]);
        }
    }

    /**
     * Clean up stale records in Algolia (deleted, disabled, or expired elements)
     * Returns statistics about what was found
     *
     * @return array ['totalChecked' => int, 'totalStale' => int, 'results' => array]
     */
    public function cleanupStaleRecords(): array
    {
        $settings = AlgoliaSync::$plugin->getSettings();
        $algoliaAppId = $settings->getAlgoliaApp();
        $algoliaApiKey = $settings->getAlgoliaAdmin();

        if (!$algoliaAppId || !$algoliaApiKey) {
            throw new \Exception('Algolia API credentials not configured');
        }

        $client = \Algolia\AlgoliaSearch\Api\SearchClient::create($algoliaAppId, $algoliaApiKey);

        // Get all configured element types and their indexes
        $elementsToCheck = $this->getConfiguredElementsForCleanup();

        if (empty($elementsToCheck)) {
            return ['totalChecked' => 0, 'totalStale' => 0, 'results' => []];
        }

        $totalChecked = 0;
        $totalStale = 0;
        $results = [];

        foreach ($elementsToCheck as $elementConfig) {
            try {
                $result = $this->checkIndexForStaleRecords(
                    $client,
                    $elementConfig['index'],
                    $elementConfig['type'],
                    $elementConfig['sectionId']
                );

                $totalChecked += $result['checked'];
                $totalStale += $result['stale'];

                $results[] = [
                    'type' => $elementConfig['type'],
                    'label' => $elementConfig['label'],
                    'index' => $elementConfig['index'],
                    'checked' => $result['checked'],
                    'stale' => $result['stale'],
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                // Index doesn't exist or other error - log and continue
                Craft::warning("Skipping index {$elementConfig['index']}: " . $e->getMessage(), __METHOD__);

                $errorMessage = $e->getMessage();
                // Make "does not exist" errors clearer by adding "in Algolia"
                if (strpos($errorMessage, 'does not exist') !== false) {
                    $errorMessage = str_replace('does not exist', 'does not exist in Algolia', $errorMessage);
                }

                $results[] = [
                    'type' => $elementConfig['type'],
                    'label' => $elementConfig['label'],
                    'index' => $elementConfig['index'],
                    'checked' => 0,
                    'stale' => 0,
                    'error' => $errorMessage,
                ];
            }
        }

        return [
            'totalChecked' => $totalChecked,
            'totalStale' => $totalStale,
            'results' => $results,
        ];
    }

    /**
     * Check a single Algolia index for stale records
     *
     * @param \Algolia\AlgoliaSearch\Api\SearchClient $client
     * @param string $indexName
     * @param string $elementType
     * @param int $sectionId
     * @return array ['checked' => int, 'stale' => int]
     */
    protected function checkIndexForStaleRecords($client, string $indexName, string $elementType, int $sectionId): array
    {
        $checked = 0;
        $stale = 0;
        $batch = [];
        $batchSize = 100;

        // Browse all records in the index
        $browseParams = ['attributesToRetrieve' => ['objectID']];

        foreach ($client->browseObjects($indexName, $browseParams) as $hit) {
            if (!isset($hit['objectID'])) {
                continue;
            }

            $checked++;
            $objectID = $hit['objectID'];

            // Parse objectID to get element ID and site ID
            $parts = explode('-', $objectID);
            $elementId = (int)$parts[0];
            $siteId = isset($parts[1]) ? (int)$parts[1] : null;

            $batch[] = [
                'objectID' => $objectID,
                'elementId' => $elementId,
                'siteId' => $siteId,
            ];

            // Process batch when it reaches the size limit
            if (count($batch) >= $batchSize) {
                $stale += $this->processBatchForCleanup($batch, $elementType, $sectionId, $indexName);
                $batch = [];
            }
        }

        // Process remaining batch
        if (!empty($batch)) {
            $stale += $this->processBatchForCleanup($batch, $elementType, $sectionId, $indexName);
        }

        return ['checked' => $checked, 'stale' => $stale];
    }

    /**
     * Process a batch of Algolia records and queue deletions for stale ones
     *
     * @param array $batch
     * @param string $elementType
     * @param int $sectionId
     * @param string $indexName
     * @return int Number of stale records found
     */
    protected function processBatchForCleanup(array $batch, string $elementType, int $sectionId, string $indexName): int
    {
        $elementIds = array_column($batch, 'elementId');
        $staleCount = 0;

        // Query Craft for these elements (all statuses)
        $elements = $this->queryElementsForCleanup($elementType, $sectionId, $elementIds);

        // Check each record in the batch
        foreach ($batch as $record) {
            $elementId = $record['elementId'];
            $objectID = $record['objectID'];
            $element = $elements[$elementId] ?? null;

            $shouldDelete = false;
            $reason = '';

            if (!$element) {
                $shouldDelete = true;
                $reason = 'deleted';
            } elseif (!$element->enabled) {
                $shouldDelete = true;
                $reason = 'disabled';
            } elseif (isset($element->expiryDate) && $element->expiryDate instanceof \DateTime) {
                $now = new \DateTime();
                if ($element->expiryDate < $now) {
                    $shouldDelete = true;
                    $reason = 'expired';
                }
            }

            if ($shouldDelete) {
                $queue = Craft::$app->getQueue();
                $queue->push(new \brilliance\algoliasync\jobs\AlgoliaSyncTask([
                    'algoliaIndex' => [$indexName],
                    'algoliaFunction' => 'delete',
                    'algoliaObjectID' => $objectID,
                    'algoliaRecord' => [],
                    'queueMessage' => "Cleanup: Deleting stale record {$objectID} (reason: {$reason})"
                ]));
                $staleCount++;
            }
        }

        return $staleCount;
    }

    /**
     * Query Craft elements by type and IDs for cleanup
     *
     * @param string $elementType
     * @param int $sectionId
     * @param array $elementIds
     * @return array Indexed by element ID
     */
    protected function queryElementsForCleanup(string $elementType, int $sectionId, array $elementIds): array
    {
        $query = null;

        switch ($elementType) {
            case 'entry':
                $query = Entry::find()
                    ->id($elementIds)
                    ->sectionId($sectionId)
                    ->status(null)
                    ->indexBy('id');
                break;

            case 'category':
                $query = Category::find()
                    ->id($elementIds)
                    ->groupId($sectionId)
                    ->status(null)
                    ->indexBy('id');
                break;

            case 'asset':
                $query = Asset::find()
                    ->id($elementIds)
                    ->volume($sectionId)
                    ->status(null)
                    ->indexBy('id');
                break;

            case 'user':
                $query = User::find()
                    ->id($elementIds)
                    ->groupId($sectionId)
                    ->status(null)
                    ->indexBy('id');
                break;

            case 'product':
                if (class_exists('craft\commerce\elements\Product')) {
                    $query = \craft\commerce\elements\Product::find()
                        ->id($elementIds)
                        ->typeId($sectionId)
                        ->status(null)
                        ->indexBy('id');
                }
                break;
        }

        return $query ? $query->all() : [];
    }

    /**
     * Get all configured elements that should be checked for cleanup
     *
     * @return array
     */
    protected function getConfiguredElementsForCleanup(): array
    {
        $settings = AlgoliaSync::$plugin->getSettings();
        $elements = [];

        if (empty($settings->algoliaElements)) {
            return [];
        }

        foreach ($settings->algoliaElements as $type => $configs) {
            foreach ($configs as $sectionId => $config) {
                if (empty($config['sync'])) {
                    continue;
                }

                $indexName = $config['customIndex'] ?? null;
                if (empty($indexName)) {
                    // Generate default index name
                    $env = $this->getEnvironment();
                    $handle = $this->getSectionHandleForCleanup($type, $sectionId);
                    $indexName = "{$env}_{$type}_{$handle}";
                }

                $elements[] = [
                    'type' => $type,
                    'sectionId' => $sectionId,
                    'index' => App::parseEnv($indexName),
                    'label' => $this->getSectionLabelForCleanup($type, $sectionId),
                ];
            }
        }

        return $elements;
    }

    /**
     * Get section handle by type and ID for cleanup
     *
     * @param string $type
     * @param int $sectionId
     * @return string
     */
    protected function getSectionHandleForCleanup(string $type, int $sectionId): string
    {
        switch ($type) {
            case 'entry':
                $section = Craft::$app->entries->getSectionById($sectionId);
                return $section ? $section->handle : 'unknown';
            case 'category':
                $group = Craft::$app->categories->getGroupById($sectionId);
                return $group ? $group->handle : 'unknown';
            case 'asset':
                $volume = Craft::$app->volumes->getVolumeById($sectionId);
                return $volume ? $volume->handle : 'unknown';
            case 'user':
                $group = Craft::$app->userGroups->getGroupById($sectionId);
                return $group ? $group->handle : 'unknown';
            case 'product':
                if (Craft::$app->plugins->isPluginEnabled('commerce')) {
                    $productType = \craft\commerce\Plugin::getInstance()->getProductTypes()->getProductTypeById($sectionId);
                    return $productType ? $productType->handle : 'unknown';
                }
                break;
        }
        return 'unknown';
    }

    /**
     * Get section label by type and ID for cleanup
     *
     * @param string $type
     * @param int $sectionId
     * @return string
     */
    protected function getSectionLabelForCleanup(string $type, int $sectionId): string
    {
        switch ($type) {
            case 'entry':
                $section = Craft::$app->entries->getSectionById($sectionId);
                return $section ? $section->name : 'Unknown Section';
            case 'category':
                $group = Craft::$app->categories->getGroupById($sectionId);
                return $group ? $group->name : 'Unknown Group';
            case 'asset':
                $volume = Craft::$app->volumes->getVolumeById($sectionId);
                return $volume ? $volume->name : 'Unknown Volume';
            case 'user':
                $group = Craft::$app->userGroups->getGroupById($sectionId);
                return $group ? $group->name : 'Unknown Group';
            case 'product':
                if (Craft::$app->plugins->isPluginEnabled('commerce')) {
                    $productType = \craft\commerce\Plugin::getInstance()->getProductTypes()->getProductTypeById($sectionId);
                    return $productType ? $productType->name : 'Unknown Type';
                }
                break;
        }
        return 'Unknown';
    }
}
