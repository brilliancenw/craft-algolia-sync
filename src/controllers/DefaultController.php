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
use craft\web\Response;


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

    // Public Methods
    // =========================================================================

    /**
     * actions/algolia-sync/default
     *
     * @return mixed
     */
    public function actionIndex(): craft\web\Response
    {
        return new Response();
    }

    /**
     * e.g.: actions/algolia-sync/default/load-records
     *
     * @return mixed
     */
    public function actionLoadRecords()
    {
        $this->requirePostRequest(); // Optional but recommended for safety

        $loadRecordTypes = Craft::$app->request->post('loadRecords');

        if (!$loadRecordTypes || !is_array($loadRecordTypes)) {
            Craft::$app->getSession()->setFlash('yourVariable', 'No record types were selected.');
            return $this->redirectToPostedUrl();
        }

        $queue = Craft::$app->getQueue();
        $queuedCount = 0;

        foreach ($loadRecordTypes as $loadRecordType) {
            $loadRecordArray = explode('|', $loadRecordType);
            if (count($loadRecordArray) !== 2) {
                continue;
            }

            $messageString = 'Queueing Up Bulk Records to sync into Algolia (Type: ' . $loadRecordArray[0] . ', ID: ' . $loadRecordArray[1] . ')';

            $queue->push(new AlgoliaBulkLoadTask([
                'description' => Craft::t('algolia-sync', $messageString),
                'loadRecordType' => $loadRecordArray,
            ]));

            $queuedCount++;
        }

        // Show success message in the CP (it will appear on the next request)
        Craft::$app->getSession()->setFlash('yourVariable', "{$queuedCount} record types have been queued for Algolia sync.");

        // Redirect back to the CP utility page or wherever the form was submitted from
        return $this->redirectToPostedUrl();
    }

}
