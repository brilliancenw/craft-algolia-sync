<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * Syncing elements with Algolia using their API
 *
 * @link      https://www.brilliancenw.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\variables;

use brilliance\algoliasync\AlgoliaSync;



use Craft;

/**
 * Algolia Sync Variable
 *
 * Craft allows plugins to provide their own template variables, accessible from
 * the {{ craft }} global variable (e.g. {{ craft.algoliaSync }}).
 *
 * https://craftcms.com/docs/plugins/variables
 *
 * @author    Mark Middleton
 * @package   AlgoliaSync
 * @since     1.0.0
 */
class AlgoliaSyncVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Whatever you want to output to a Twig template can go into a Variable method.
     * You can have as many variable functions as you want.  From any Twig template,
     * call it like this:
     *
     *     {{ craft.algoliaSync.exampleVariable }}
     *
     * Or, if your variable requires parameters from Twig:
     *
     *     {{ craft.algoliaSync.exampleVariable(twigValue) }}
     *
     * @param null $optional
     * @return string
     */
//    public function exampleVariable($optional = null)
//    {
//        $result = "And away we go to the Twig template...";
//        if ($optional) {
//            $result = "I'm feeling optional today...";
//        }
//        return $result;
//    }

    // {{ craft.algoliaSync.algoliaApiKey }}
    public function algoliaApiKey() {

        $isAdmin = Craft::$app->user->getIsAdmin();
        $globalAlgoliaAccess = Craft::$app->getUser()->getIdentity()->getFieldValue('globalAlgoliaAccess');

        if ($isAdmin || $globalAlgoliaAccess) {
            // return $algoliaSettings['algoliaSearch'];
            return AlgoliaSync::$plugin->algoliaSyncService->generateSecuredApiKey();
        }
        else {
             // do they have a stored API key?... if so, use it

            // generate new key and store it for this user
            // what is their associated company?

            $associatedCompany = Craft::$app->getUser()->getIdentity()->getFieldValue('associatedCompany');

            if (is_object($associatedCompany) && count($associatedCompany) > 0) {

                if (isset($associatedCompany[0]->id) AND $associatedCompany[0]->id > 0) {
                    $associatedCompanyId = $associatedCompany[0]->id;

                    // get the login to the algolia api, and generate a new key
                    return AlgoliaSync::$plugin->algoliaSyncService->generateSecuredApiKey($associatedCompanyId);

                }
                else {
                    return 'no-associated-company on line '.__LINE__;
                }
            }
            else {
                return 'no-associated-company on line '.__LINE__;
            }
        }
    }
}
