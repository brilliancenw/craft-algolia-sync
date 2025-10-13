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
     * Queues cleanup jobs to check Algolia records and remove stale ones.
     * Removes records that are deleted, disabled, or expired in Craft.
     * Uses efficient batching with one job per index, then batch jobs for processing.
     *
     * Usage: ./craft algolia-sync/default/cleanup-stale-records
     */
    public function actionCleanupStaleRecords(): int
    {
        $this->stdout("Queueing Algolia stale record cleanup jobs...\n", Console::FG_YELLOW);

        try {
            $result = AlgoliaSync::$plugin->algoliaSyncService->cleanupStaleRecords();

            if ($result['queued']) {
                $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_GREEN);
                $this->stdout("Cleanup jobs queued successfully!\n", Console::FG_GREEN);
                $this->stdout(str_repeat('=', 60) . "\n\n", Console::FG_GREEN);

                $this->stdout($result['message'] . "\n\n");
                $this->stdout("Monitor queue progress:\n", Console::FG_CYAN);
                $this->stdout("  php craft queue/info\n");
                $this->stdout("  php craft queue/run\n\n");
            }

            return 0;

        } catch (Throwable $e) {
            $this->stderr("Error queueing cleanup: " . $e->getMessage() . "\n", Console::FG_RED);
            Craft::error("Error queueing Algolia cleanup: " . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return 1;
        }
    }
}
