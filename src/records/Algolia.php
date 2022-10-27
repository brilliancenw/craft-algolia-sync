<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * testing
 *
 * @link      http://www.hooplaglobal.com
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace markmiddleton\algoliasync\records;

use markmiddleton\algoliasync\AlgoliaSync;

use Craft;
use craft\db\ActiveRecord;

/**
 * @author    Mark Middleton
 * @package   AlgoliaSync
 * @since     1.0.0
 */
class Algolia extends ActiveRecord
{
    // Public Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%algoliasync_algolia}}';
    }
}
