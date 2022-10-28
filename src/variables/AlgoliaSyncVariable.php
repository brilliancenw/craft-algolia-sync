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
     * Used for front-end javascript keys for accessing the index
     *
     *     {{ craft.algoliaSync.algoliaApiKey }}
     *
     * @param null $optional
     * @return string
     */

    public function algoliaApiKey(): string
    {

        $isAdmin = Craft::$app->user->getIsAdmin();
        $globalAlgoliaAccess = Craft::$app->getUser()->getIdentity()->getFieldValue('globalAlgoliaAccess');

        if ($isAdmin || $globalAlgoliaAccess) {
            return AlgoliaSync::$plugin->algoliaSyncService->generateSecuredApiKey();
        }
    }
}
