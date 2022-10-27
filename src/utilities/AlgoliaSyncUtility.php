<?php
/**
 * hoopla audit plugin for Craft CMS 3.x
 *
 * audit system for the hoopla platform
 *
 * @link      http://www.hooplaglobal.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\utilities;

use brilliance\algoliasync;

use brilliance\algoliasync\models\Settings;
use brilliance\algoliasync\assetbundles\AlgoliaSync\AlgoliaSyncAsset;

use Craft;
use craft\base\Utility;



/**
 * hoopla audit Utility
 *
 * Utility is the base class for classes representing Control Panel utilities.
 *
 * https://craftcms.com/docs/plugins/utilities
 *
 * @author    Mark Middleton
 * @package   HooplaAudit
 * @since     1.0.0
 */
class AlgoliaSyncUtility extends Utility
{
    // Static
    // =========================================================================

    /**
     * Returns the display name of this utility.
     *
     * @return string The display name of this utility.
     */
    public static function displayName(): string
    {
        return Craft::t('algolia-sync', 'Algolia Sync Utility');
    }

    /**
     * Returns the utility’s unique identifier.
     *
     * The ID should be in `kebab-case`, as it will be visible in the URL (`admin/utilities/the-handle`).
     *
     * @return string
     */
    public static function id(): string
    {
        return 'algolia-sync-utility';
    }

    /**
     * Returns the path to the utility's SVG icon.
     *
     * @return string|null The path to the utility SVG icon
     */
    public static function iconPath()
    {
        return Craft::getAlias("@brilliance/algoliasync/assetbundles/algoliasync/dist/img/AlgoliaSync-icon.svg");
    }

    /**
     * Returns the number that should be shown in the utility’s nav item badge.
     *
     * If `0` is returned, no badge will be shown
     *
     * @return int
     */
    public static function badgeCount(): int
    {
        return 0;
    }

    /**
     * Returns the utility's content HTML.
     *
     * @return string
     */
    public static function contentHtml(): string
    {
        $utilityData = array();

        $algoliaSettings = algoliasync\AlgoliaSync::$plugin->getSettings();

        if (is_array($algoliaSettings['algoliaSections'])) {

            foreach ($algoliaSettings['algoliaSections'] AS $typeId) {

                $entryType = Craft::$app->sections->getSectionById($typeId);

                if (isset($entryType->id)) {
                    $utilityData['Entry Types'][] = array(
                        'id' => $entryType->id,
                        'type' => 'entry',
                        'name' => $entryType->name);
                }
            }
        }
        if (is_array($algoliaSettings['algoliaCategories'])) {
            foreach ($algoliaSettings['algoliaCategories'] AS $categoryGroup) {
                $categoryGroup = Craft::$app->categories->getGroupById($categoryGroup);
                $utilityData['Categories'][] = array(
                    'id' => $categoryGroup->id,
                    'type' => 'category',
                    'name' => $categoryGroup->name);
            }
        }

        if (is_array($algoliaSettings['algoliaUserGroupList'])) {

            foreach ($algoliaSettings['algoliaUserGroupList'] AS $userGroup) {
                $memberGroups = Craft::$app->userGroups->getGroupById($userGroup);
                $utilityData['Member Groups'][] = array(
                    'id' => $memberGroups->id,
                    'type' => 'user',
                    'name' => $memberGroups->name);
            }
        }

        return Craft::$app->getView()->renderTemplate(
            'algolia-sync/_components/utilities/AlgoliaSyncUtility_content',
            [
                'utilityData' => $utilityData
            ]
        );
    }
}
