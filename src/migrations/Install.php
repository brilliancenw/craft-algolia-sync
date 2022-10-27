<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * testing
 *
 * @link      http://www.hooplaglobal.com
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\migrations;

use brilliance\algoliasync\AlgoliaSync;

use Craft;
use craft\config\DbConfig;
use craft\db\Migration;

/**
 * @author    Mark Middleton
 * @package   AlgoliaSync
 * @since     1.0.0
 */
class Install extends Migration
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The database driver to use
     */
    public $driver;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $this->driver = Craft::$app->getConfig()->getDb()->driver;
        if ($this->createTables()) {
            $this->createIndexes();
            $this->addForeignKeys();
            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
            $this->insertDefaultData();
        }

        return true;
    }

   /**
     * @inheritdoc
     */
    public function safeDown()
    {
        $this->driver = Craft::$app->getConfig()->getDb()->driver;
        $this->removeTables();

        return true;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @return bool
     */
    protected function createTables()
    {
        $tablesCreated = false;

        $tableSchema = Craft::$app->db->schema->getTableSchema('{{%algoliasync_algolia}}');
        if ($tableSchema === null) {
            $tablesCreated = true;
            $this->createTable(
                '{{%algoliasync_algolia}}',
                [
                    'id' => $this->primaryKey(),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                    'siteId' => $this->integer()->notNull(),

                    'entityType' => $this->string(255)->notNull()->defaultValue(''),
                    'entityTypeHandle' => $this->string(255)->notNull()->defaultValue(''),
                    'entityId' => $this->string(255)->notNull()->defaultValue(''),
                    'algoliaTransactionId' => $this->string(255)->notNull()->defaultValue('')
                ]
            );
        }

        return $tablesCreated;
    }

    /**
     * @return void
     */
    protected function createIndexes()
    {
        $this->createIndex(
            $this->db->getIndexName(
                '{{%algoliasync_algolia}}',
                'entityType',
                true
            ),
            '{{%algoliasync_algolia}}',
            'entityType',
            true
        );
        $this->createIndex(
            $this->db->getIndexName(
                '{{%algoliasync_algolia}}',
                'entityTypeHandle',
                true
            ),
            '{{%algoliasync_algolia}}',
            'entityTypeHandle',
            true
        );
        $this->createIndex(
            $this->db->getIndexName(
                '{{%algoliasync_algolia}}',
                'entityId',
                true
            ),
            '{{%algoliasync_algolia}}',
            'entityId',
            true
        );
        $this->createIndex(
            $this->db->getIndexName(
                '{{%algoliasync_algolia}}',
                'algoliaTransactionId',
                true
            ),
            '{{%algoliasync_algolia}}',
            'algoliaTransactionId',
            true
        );
        // Additional commands depending on the db driver
        switch ($this->driver) {
            case DbConfig::DRIVER_MYSQL:
                break;
            case DbConfig::DRIVER_PGSQL:
                break;
        }
    }

    /**
     * @return void
     */
    protected function addForeignKeys()
    {
        $this->addForeignKey(
            $this->db->getForeignKeyName('{{%algoliasync_algolia}}', 'siteId'),
            '{{%algoliasync_algolia}}',
            'siteId',
            '{{%sites}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    /**
     * @return void
     */
    protected function insertDefaultData()
    {
    }

    /**
     * @return void
     */
    protected function removeTables()
    {
        $this->dropTableIfExists('{{%algoliasync_algolia}}');
    }
}
