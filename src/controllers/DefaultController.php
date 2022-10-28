<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * Syncing elements with Algolia using their API
 *
 * @link      https://www.brilliancenw.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\controllers;

use brilliance\algoliasync\AlgoliaSync;

use Craft;
use craft\web\Controller;

use craft\elements\Entry;

use brilliance\algoliasync\jobs\AlgoliaBulkLoadTask;
use craft\helpers\UrlHelper;


/**
 * Default Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Mark Middleton
 * @package   AlgoliaSync
 * @since     1.0.0
 */
class DefaultController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected int|bool|array $allowAnonymous = [];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's index action URL,
     * e.g.: actions/algolia-sync/default
     *
     * @return mixed
     */
    public function actionIndex(): mixed
    {
        $result = array();
        $algoliaSettings = AlgoliaSync::$plugin->getSettings();

        if (is_array($algoliaSettings['algoliaSections'])) {
            $result[] = '<h4>Entry Types</h4>';
            $result[] = '<ul>';
            foreach ($algoliaSettings['algoliaSections'] AS $typeId) {
                $entryType = Craft::$app->sections->getSectionById($typeId);

                $result[] = '<li><a href="'.UrlHelper::actionUrl(('algolia-sync/default/load-records?elementType=entry&elementTypeId='.$entryType->id)).'">'.$entryType->name."</a>";
            }
            $result[] = '</ul>';
        }
        if (is_array($algoliaSettings['algoliaCategories'])) {
            $result[] = '<h4>Category Groups</h4>';
            $result[] = '<ul>';
            foreach ($algoliaSettings['algoliaCategories'] AS $categoryGroup) {
                $categoryGroup = Craft::$app->categories->getGroupById($categoryGroup);
                $result[] = '<li><a href="'.UrlHelper::actionUrl(('algolia-sync/default/load-records?elementType=category&elementTypeId='.$categoryGroup->id)).'">'.$categoryGroup->name."</a>";
            }
            $result[] = '</ul>';
        }

        if (is_array($algoliaSettings['algoliaUserGroupList'])) {
            $result[] = '<h4>User Groups</h4>';
            $result[] = '<ul>';
            foreach ($algoliaSettings['algoliaUserGroupList'] AS $userGroup) {
                $memberGroups = Craft::$app->userGroups->getGroupById($userGroup);
                $result[] = '<li><a href="'.UrlHelper::actionUrl(('actions/algolia-sync/default/load-records?elementType=user&elementTypeId='.$memberGroups->id)).'">'.$memberGroups->name."</a>";
            }
            $result[] = '</ul>';
        }
        return implode($result);
    }

    /**
     * e.g.: actions/algolia-sync/default/load-records
     *
     * @return mixed
     */
    public function actionLoadRecords()
    {
        $loadRecordTypes = Craft::$app->request->post('loadRecords');

        $queue = Craft::$app->getQueue();

        foreach ($loadRecordTypes AS $loadRecordType) {

            $loadRecordArray = explode('|', $loadRecordType);

            $jobId = $queue->push(new AlgoliaBulkLoadTask([
                'description' => Craft::t('algolia-sync', 'Queueing Up Bulk Records to sync into Algolia (Type: '.$loadRecordArray[0].', ID: '.$loadRecordArray[1].')'),
                'loadRecordType' => $loadRecordArray,
            ]));
        }

        // todo : fix the method return
        print "These Entities have been queued to sync with Algolia";
        exit;


        // Craft::$app->getResponse()->redirect($utilitiesUrl);

    }
}
