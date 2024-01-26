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

use brilliance\algoliasync\events\beforeAlgoliaSyncEvent;

use brilliance\algoliasync\jobs\AlgoliaSyncTask;

use Algolia\AlgoliaSearch\SearchClient;
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

    public function generateSecuredApiKey($filterCompany=null)
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

        SWITCH ($elementType) {
            CASE 'entry':
                $entries = Entry::find()->sectionId($sectionId)->all();
                foreach ($entries AS $entry) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($entry);
                }
                break;

            CASE 'category':
                $categories = Category::find()->groupId($sectionId)->all();
                foreach ($categories AS $category) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($category);
                }
                break;

            CASE 'asset':
                $assets = Asset::find()->volume($sectionId)->all();
                foreach ($assets AS $asset) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($asset);
                }
                break;

            CASE 'user':
                $allUsers = User::find()->groupId($sectionId)->all();
                foreach ($allUsers AS $user) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($user);
                }
                break;

            CASE 'tag':
                $allTags = Tag::find()->groupId($sectionId)->all();
                foreach ($allTags AS $tag) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($tag);
                }
                break;
            CASE 'product':
                $allProductTypes = \craft\commerce\elements\Product::find()->typeId($sectionId)->all();

                foreach ($allProductTypes AS $productType) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($productType);
                }
                break;

        }
    }


    // is this element one that is configured to be synced with Algolia?
    public function algoliaElementSynced($element)
    {
        $elementInfo = AlgoliaSync::$plugin->algoliaSyncService->getEventElementInfo($element);
        $algoliaSettings = AlgoliaSync::$plugin->getSettings();

        $className = get_class($element);

        AlgoliaSync::$plugin->algoliaSyncService->logger("trying to sync a ".$className, basename(__FILE__) , __LINE__);

        if (isset($elementInfo['enabled']) && empty($elementInfo['enabled'])) {
            AlgoliaSync::$plugin->algoliaSyncService->logger("This item is not enabled", basename(__FILE__) , __LINE__);

            // at this point, we should remove the product from the index - we don't know if it was already there...
            $algoliaIndex = AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaIndex($element);

            $queue = Craft::$app->getQueue();
            $queue->push(new AlgoliaSyncTask([
                'algoliaIndex' => $algoliaIndex,
                'algoliaFunction' => 'delete',
                'algoliaObjectID' => $element->id,
                'algoliaRecord' => [],
                'algoliaMessage' => "Item is not enabled, confirming it's removed from Algolia"
            ]));

            return false;
        }

        SWITCH ($className) {
            CASE 'craft\elements\Entry':
            CASE 'craft\elements\Category':
            CASE 'craft\elements\Asset':
                if (    isset($algoliaSettings['algoliaElements'][$elementInfo['type']][$elementInfo['sectionId'][0]]['sync'])
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
                'algoliaMessage' => $queueMessage
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
    public function getFieldData($element, $field, $fieldHandle)
    {
        $fieldTypeArray = explode('\\', get_class($field));
        $fieldType = strtolower(array_pop($fieldTypeArray));

        switch ($fieldType) {
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

    public function prepareAlgoliaSyncElement($element, $action = 'save', $algoliaMessage = '') {

        $elementInfo = AlgoliaSync::$plugin->algoliaSyncService->getEventElementInfo($element);
        $elementTypeSlug = $elementInfo['type'];

        AlgoliaSync::$plugin->algoliaSyncService->logger("This type of item has been saved:: ".$elementTypeSlug, basename(__FILE__) , __LINE__);
        // do we update this type of element?
        $recordUpdate = array();

        $okayToSync = AlgoliaSync::$plugin->algoliaSyncService->algoliaElementSynced($element);

        if ($okayToSync) {
            AlgoliaSync::$plugin->algoliaSyncService->logger("We are going to sync", basename(__FILE__) , __LINE__);

            // what type of element
            // user, entry, category
            $recordUpdate['attributes'] = array();

            if ($action == 'delete') {
                $algoliaAction = 'delete';
            } else {
                if ($element->enabled) {
                    $algoliaAction = 'insert';
                } else {
                    $algoliaAction = 'delete';
                }
            }
            // get the attributes of the entity
            $recordUpdate['attributes']['objectID'] = (int)$element->id;
            $recordUpdate['attributes']['message'] = (int)$element->id;

            if (isset($element->slug)) {
                $recordUpdate['attributes']['slug'] = $element->slug;
            }
            if (isset($element->authorId)) {
                $recordUpdate['attributes']['authorId'] = (int)$element->authorId;
            }
            if (isset($element->postDate)) {
                $recordUpdate['attributes']['postDate'] = (int)$element->postDate->getTimestamp();
            }

            if (!empty($element->product)) {
                $fields = $element->product->getFieldLayout()->getFields();
            }
            else {
                $fields = $element->getFieldLayout()->getFields();
            }

            $arrayFieldTypes = array('entries','tags','users');

            foreach ($fields AS $field) {
                $fieldHandle = $field->handle;

                // send this off to a function to extract the specific information
                // based on what type of field it is (asset, text, varchar, etc...)
                $fieldName = AlgoliaSync::$plugin->algoliaSyncService->sanitizeFieldName($field->name);

                $rawData = AlgoliaSync::$plugin->algoliaSyncService->getFieldData($element, $field, $fieldHandle);

                if (isset($rawData['type']) && in_array($rawData['type'],$arrayFieldTypes)) {
                    $recordUpdate['attributes'][$fieldName] = $rawData['titles'];
                    $idsFieldName = $fieldName.'Ids';
                    $recordUpdate['attributes'][$idsFieldName] = $rawData['ids'];
                }
                elseif (isset($rawData['type']) && $rawData['type'] == 'mapfield') {
                    $recordUpdate['attributes'][$fieldName] = $rawData;
                    $recordUpdate['attributes'][$fieldName.'_address'] = $rawData['address'];
                    $recordUpdate['attributes'][$fieldName.'_lat'] = $rawData['lat'];
                    $recordUpdate['attributes'][$fieldName.'_lng'] = $rawData['lng'];
                    $recordUpdate['attributes'][$fieldName.'_zoom']['zoom'] = $rawData['zoom'];
                    if (!empty($rawData['lat']) && !empty($rawData['lng'])) {
                        // https://www.algolia.com/doc/guides/managing-results/refine-results/geolocation/#enabling-geo-search-by-adding-geolocation-data-to-records
                        // inject a _geoloc into Algolia
                        // this doesn't take into account if there are multiple _geoloc...
                        // that will be more complex to resolve
                        $recordUpdate['attributes']['_geoloc'] = [];
                        $recordUpdate['attributes']['_geoloc']['lat'] = $rawData['lat'];
                        $recordUpdate['attributes']['_geoloc']['lng'] = $rawData['lng'];
                    }
                }
                elseif (isset($rawData['type']) && $rawData['type'] == 'categories') {

                    $recordUpdate['attributes'][$fieldName] = $rawData['flat'];
                    $nestedName = $fieldName."_hx";
                    $recordUpdate['attributes'][$nestedName] = $rawData['nested'];

                }
                else {
                    $recordUpdate['attributes'][$fieldName] = $rawData;
                }

                $fieldTypeLong = get_class($field);
                $fieldTypeArray = explode('\\', $fieldTypeLong);
                $fieldType = strtolower(array_pop($fieldTypeArray));

                // for the date field, create a few versions of the date
                // todo : add in a config for custom date format to be added
                if ($fieldType == 'date') {
                    // get the friendly date
                    $friendlyName = $fieldName . "_friendly";
                    $friendlyDate = date('n/j/Y', $rawData);
                    $recordUpdate['attributes'][$friendlyName] = $friendlyDate;

                    // get the previous midnight of the current date (unix timestamp)
                    $midnightName = $fieldName . "_midnight";
                    $midnightTimestamp = mktime(0, 0, 0, date('n', $rawData), date('j', $rawData), date('Y', $rawData));
                    $recordUpdate['attributes'][$midnightName] = $midnightTimestamp;
                }
            }

            $recordUpdate['index'] = AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaIndex($element);

            switch ($elementTypeSlug) {
                case 'category':
                case 'entry':
                case 'asset':
                case 'tag':

                    $recordUpdate['elementType'] = ucwords($elementTypeSlug);
                    $recordUpdate['handle'] = $elementInfo['sectionHandle'];
                    $recordUpdate['attributes']['title'] = $element->title;
                    break;

                case 'user':
                    $recordUpdate['elementType'] = 'User';
                    $recordUpdate['handle'] = $elementInfo['sectionHandle'];
                    $recordUpdate['attributes']['title'] = $element->username;
                    $recordUpdate['attributes']['firstname'] = $element->firstName;
                    $recordUpdate['attributes']['lastname'] = $element->lastName;
                    $recordUpdate['attributes']['email'] = $element->email;
                    $userGroups = $element->getGroups();
                    $groupList = [];
                    foreach ($userGroups AS $group) {
                        $groupList[] = $group->handle;
                    }
                    $recordUpdate['attributes']['userGroups'] = $groupList;
                    break;

                case 'product':

                    AlgoliaSync::$plugin->algoliaSyncService->logger("Product is being loaded", basename(__FILE__) , __LINE__);

                    // get the basic product info
                    $recordUpdate['elementType'] = ucwords($elementTypeSlug);
                    $recordUpdate['handle'] = $elementInfo['sectionHandle'][0];
                    $recordUpdate['attributes']['title'] = $element->title;
                    $recordUpdate['attributes']['productId'] = $element->id;
                    $recordUpdate['attributes']['typeId'] = $element->typeId;
                    $recordUpdate['attributes']['taxCategoryId'] = $element->taxCategoryId;
                    $recordUpdate['attributes']['shippingCategoryId'] = $element->shippingCategoryId;
                    $recordUpdate['attributes']['defaultSku'] = $element->defaultSku;
                    $recordUpdate['attributes']['ProductType'] = $elementInfo['productTypeName'];
                    $recordUpdate['attributes']['availableForPurchase'] = (bool)$element->availableForPurchase;
                    $recordUpdate['attributes']['defaultVariantId'] = (int)$element->defaultVariantId;
                    $recordUpdate['attributes']['defaultPrice'] = (float)$element->defaultPrice;
                    $recordUpdate['attributes']['defaultWidth'] = $element->defaultWidth;
                    $recordUpdate['attributes']['defaultHeight'] = $element->defaultHeight;
                    $recordUpdate['attributes']['defaultLength'] = $element->defaultLength;
                    $recordUpdate['attributes']['defaultWeight'] = $element->defaultWeight;
                    $recordUpdate['attributes']['taxCategory'] = $element->taxCategory;

                    // now load all variants
                    $getAllVariants = \craft\commerce\elements\Variant::find()->productId($element->id);

                    $recordUpdate['attributes']['variants'] = [];

                    foreach ($getAllVariants AS $variantDetails) {

                        $variantInfo = [];
                        $variantInfo['title'] = $variantDetails->title;
                        $variantInfo['productId'] = (int)$variantDetails->productId;
                        $variantInfo['isDefault'] = (bool)$variantDetails->isDefault;
                        $variantInfo['price'] = (float)$variantDetails->price;
                        $variantInfo['sortOrder'] = (int)$variantDetails->sortOrder;
                        $variantInfo['width'] = (float)$variantDetails->width;
                        $variantInfo['height'] = (float)$variantDetails->height;
                        $variantInfo['length'] = (float)$variantDetails->length;
                        $variantInfo['stock'] = (int)$variantDetails->stock;
                        $variantInfo['weight'] = (float)$variantDetails->weight;
                        $variantInfo['hasUnlimitedStock'] = (bool)$variantDetails->hasUnlimitedStock;
                        $variantInfo['minQty'] = (int)$variantDetails->minQty;
                        $variantInfo['minQty'] = (int)$variantDetails->minQty;

                        // nest each variant under the product info
                        $recordUpdate['attributes']['variants'][] = $variantInfo;

                    }

                    break;

            }

            // Fire event for tracking before the sync event.
            $event = new beforeAlgoliaSyncEvent([
                'recordElement' => $element,
                'recordUpdate' => $recordUpdate
            ]);

            $this->trigger(self::EVENT_BEFORE_ALGOLIA_SYNC, $event);
            $recordUpdate = $event->recordUpdate;

            AlgoliaSync::$plugin->algoliaSyncService->algoliaSyncRecord($algoliaAction, $recordUpdate, $algoliaMessage);
        }
        else {
            AlgoliaSync::$plugin->algoliaSyncService->logger("Not okay to sync...", basename(__FILE__) , __LINE__);
        }
    }

    public function sanitizeFieldName($fieldName) {
        $fieldName = preg_replace("/[^A-Za-z0-9 ]/", '', $fieldName);
        return str_replace(' ', '_', $fieldName);
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
                    $info['sectionHandle'][] = Craft::$app->sections->getSectionById($element->sectionId)->handle;
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
                        $elementData['index'] = AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaIndex($element);
                        $elementData['attributes'] = [];
                        $elementData['attributes']['objectID'] = $element->id;

                        AlgoliaSync::$plugin->algoliaSyncService->algoliaSyncRecord('delete', $elementData);
                    }
                }
                break;
        }
        return $info;
    }

    public function getAlgoliaIndex($element)
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
    public function getAlgoliaSupportedElements() {
        $env = $this->getEnvironment();

        // all Channel Sections
        $entriesConfig = array();
        $allSections = Craft::$app->sections->getAllSections();
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

    public function getEnvironment() {
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
    public function logger($message, $filename, $linenumber) {
        if (Craft::$app->getConfig()->general->devMode) {
            $file = Craft::getAlias('@storage/logs/algolia-sync.log');
            $log = date('Y-m-d H:i:s').' ['.$filename.':'.$linenumber.'] '.$message."\n";
            FileHelper::writeToFile($file, $log, ['append' => true]);
        }
    }
}
