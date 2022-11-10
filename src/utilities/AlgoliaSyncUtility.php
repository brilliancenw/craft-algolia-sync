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

use brilliance\algoliasync\AlgoliaSync;

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
        return Craft::getAlias("@brilliance/algoliasync/icon.svg");
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
        $supportedElements = AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaSupportedElements();
        return Craft::$app->view->renderTemplate(
            'algolia-sync/_components/utilities/AlgoliaSyncUtility_content',
            [
                'settings' => AlgoliaSync::$plugin->getSettings(),
                'supportedElementsConfig' => $supportedElements
            ]
        );
    }
}
