<?php
/**
 * Algolia Sync plugin for Craft CMS 5.x
 *
 * Syncing elements with Algolia using their API
 *
 * @link      https://www.brilliancenw.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\console\controllers;

use brilliance\algoliasync\AlgoliaSync;
use Craft;
use craft\helpers\Console;
use yii\console\Controller;
use Throwable;

/**
 * Algolia Sync Commands
 *
 * ./craft algolia-sync/default/cleanup-stale-records
 *
 * @author    Brilliance Northwest LLC
 * @package   AlgoliaSync
 * @since     2.0.0
 */
class DefaultController extends Controller
{
    /**
     * Checks all records in configured Algolia indexes and removes stale ones.
     * Removes records that are deleted, disabled, or expired in Craft.
     * Uses efficient batching to avoid overwhelming the queue.
     *
     * Usage: ./craft algolia-sync/default/cleanup-stale-records
     */
    public function actionCleanupStaleRecords(): int
    {
        $this->stdout("Starting Algolia stale record cleanup...\n", Console::FG_YELLOW);

        try {
            $result = AlgoliaSync::$plugin->algoliaSyncService->cleanupStaleRecords();

            $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_GREEN);
            $this->stdout("Cleanup complete!\n", Console::FG_GREEN);
            $this->stdout("Total records checked: {$result['totalChecked']}\n");
            $this->stdout("Total stale records queued for deletion: {$result['totalStale']}\n");

            if (!empty($result['results'])) {
                $this->stdout("\nDetails by index:\n", Console::FG_CYAN);
                foreach ($result['results'] as $indexResult) {
                    if (isset($indexResult['error']) && $indexResult['error']) {
                        $this->stdout("  {$indexResult['label']}: ", Console::FG_CYAN);
                        $this->stdout("SKIPPED - {$indexResult['error']}\n", Console::FG_GREY);
                    } else {
                        $staleColor = $indexResult['stale'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN;
                        $this->stdout("  {$indexResult['label']}: ", Console::FG_CYAN);
                        $this->stdout("{$indexResult['checked']} checked, ");
                        $this->stdout("{$indexResult['stale']} stale\n", $staleColor);
                    }
                }
            }

            $this->stdout(str_repeat('=', 60) . "\n", Console::FG_GREEN);

            return 0;

        } catch (Throwable $e) {
            $this->stderr("Error during cleanup: " . $e->getMessage() . "\n", Console::FG_RED);
            Craft::error("Error during Algolia cleanup: " . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return 1;
        }
    }
}
