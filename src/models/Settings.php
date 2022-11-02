<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * Syncing elements with Algolia using their API
 *
 * @link      https://www.brilliancenw.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\models;

use brilliance\algoliasync\AlgoliaSync;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * AlgoliaSync Settings Model
 *
 * This is a model used to define the plugin's settings.
 *
 * Models are containers for data. Just about every time information is passed
 * between services, controllers, and templates in Craft, it’s passed via a model.
 *
 * https://craftcms.com/docs/plugins/models
 *
 * @author    Mark Middleton
 * @package   AlgoliaSync
 * @since     1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * Some field model attribute
     *
     * @var string
     */
    public string $algoliaAdmin = ''; // ALGOLIA_ADMIN
    public string $algoliaApp = '';              // ALGOLIA_APP
    public string $algoliaSearch = ''; // ALGOLIA_SEARCH
    public array $algoliaElements     = []; // sections, categories, usergroups, etc...

    public function getAlgoliaAdmin(): string
    {
        return App::parseEnv($this->algoliaAdmin);
    }
    public function getAlgoliaApp(): string
    {
        return App::parseEnv($this->algoliaApp);
    }
    public function getAlgoliaSearch(): string
    {
        return App::parseEnv($this->algoliaSearch);
    }
    public function rules(): array
    {
        return [];
    }
}
