<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * Syncing elements with Algolia using their API
 *
 * @link      https://www.brilliancenw.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync;

use brilliance\algoliasync\services\AlgoliaSyncService as AlgoliaSyncServiceService;
use brilliance\algoliasync\variables\AlgoliaSyncVariable;
use brilliance\algoliasync\twigextensions\AlgoliaSyncTwigExtension;
use brilliance\algoliasync\models\Settings;
use brilliance\algoliasync\fields\AlgoliaSyncField as AlgoliaSyncFieldField;
use brilliance\algoliasync\utilities\AlgoliaSyncUtility as AlgoliaSyncUtilityUtility;

use Craft;
use craft\base\Plugin;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\services\Utilities;
use craft\console\Application as ConsoleApplication;
use craft\web\UrlManager;
use craft\elements\Asset;
use craft\services\Elements;
use craft\services\Users;
use craft\elements\User;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;

use craft\events\ModelEvent;

use yii\base\Event;

/**
 * @author    Brilliance Northwest LLC
 * @package   AlgoliaSync
 * @since     1.0.0
 *
 * @property  AlgoliaSyncServiceService $algoliaSyncService
 * @property  Settings $settings
 * @method    Settings getSettings()
 */

// Example of calling this event from another plugin
//        Event::on(
//            \brilliance\algoliasync\services\AlgoliaSyncService::class,
//            \brilliance\algoliasync\services\AlgoliaSyncService::EVENT_BEFORE_ALGOLIA_SYNC,
//            function (\brilliance\algoliasync\events\beforeAlgoliaSyncEvent $event) {
//
//                $event->recordUpdate['attributes']['hoopla-custom'] = true;
//
//            }
//        );


class AlgoliaSync extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * AlgoliaSync::$plugin
     *
     * @var AlgoliaSync
     */
    public static AlgoliaSync $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public string $schemaVersion = '1.0.1';


    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * AlgoliaSync::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Add in our Twig extensions
        Craft::$app->view->registerTwigExtension(new AlgoliaSyncTwigExtension());

        // Add in our console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'brilliance\algoliasync\console\controllers';
        }

        // Register our site routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['siteActionTrigger1'] = 'algolia-sync/default';
            }
        );

        // Register our CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['cpActionTrigger1'] = 'algolia-sync/default/do-something';
            }
        );

        // Register our utilities
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITY_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = AlgoliaSyncUtilityUtility::class;
            }
        );

        // Register our fields
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = AlgoliaSyncFieldField::class;
            }
        );

        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('algoliaSync', AlgoliaSyncVariable::class);
            }
        );

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function ($event) {

                $thisEventId = (int)$event->element->id;

                static $recursionLevel = 0;
                static $recursiveRecord = array();

                if (!in_array($thisEventId, $recursiveRecord)) {
                    $recursionLevel++;
                    $recursiveRecord[] = $thisEventId;

                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($event->element, 'delete');
                }

                return $event;
            }
        );

        Event::on(
            User::class,
            User::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {

                $thisEventId = (int)$event->sender->id;

                static $recursionLevel = 0;
                static $recursiveRecord = array();

                if (!in_array($thisEventId, $recursiveRecord)) {
                    $recursionLevel++;
                    $recursiveRecord[] = $thisEventId;

                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($event->sender, 'save');
                }

                return $event;
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,

            function ($event) {

                if ($event->element instanceof craft\elements\User || ElementHelper::isDraftOrRevision($event->element)) {
                    return $event;
                }

                $thisEventId = (int)$event->element->id;

                static $recursionLevel = 0;
                static $recursiveRecord = array();

                if (!in_array($thisEventId, $recursiveRecord)) {
                    $recursionLevel++;
                    $recursiveRecord[] = $thisEventId;

                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($event->element, 'save');
                }

                return $event;
            });

        Event::on(
            Users::class,
            Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS,

            function ($event) {

                $user = User::find()->id($event->userId)->one();

                if ($user) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($user, 'save');
                }

                return $event;
            });


        Craft::info(
            Craft::t(
                'algolia-sync',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel() : \craft\base\Model|null
    {
        return new Settings();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     */

    protected function settingsHtml(): string
    {

        $env = strtolower(App::env('CRAFT_ENVIRONMENT') ?? App::env('ENVIRONMENT') ?? 'site');

        // all Channel Sections
        $sectionsConfig = array();
        $allSections = Craft::$app->sections->getAllSections();
        foreach ($allSections as $section) {
            if ($section->type == 'channel') {
                $sectionIndex = 'section-'.$section->id;
                $sectionsConfig[$sectionIndex] = array(
                    'default_index' => $env.'_section_'.$section->handle,
                    'label' => $section->name,
                    'handle' => $section->handle,
                    'value' => $section->id
                );
            }
        }

        // all Asset Volumes
        $volumes = Craft::$app->volumes->getAllVolumes();
        $volumesConfig = [];
        foreach ($volumes AS $volume) {
            $volumesConfig[] = array(
                'default_index' => $env.'_volume_'.$volume->handle,
                'label' => $volume->name,
                'handle' => $volume->handle,
                'value' => $volume->id
            );
        }

        // all Category Groups
        $catGroups = Craft::$app->categories->getAllGroups();
        $categoriesConfig = [];
        foreach ($catGroups AS $group) {
            $categoriesConfig[] = array(
                'default_index' => $env.'_category_'.$group->handle,
                'label' => $group->name,
                'handle' => $group->handle,
                'value' => $group->id
            );
        }



        // $tagGroupsConfig
        $tagGroups = Craft::$app->tags->getAllTagGroups();
        $tagGroupsConfig = [];
        foreach ($tagGroups AS $tagGroup) {
            $tagGroupsConfig[] = array(
                'default_index' => $env.'_tag_'.$tagGroup->handle,
                'label' => $tagGroup->name,
                'handle' => $tagGroup->handle,
                'value' => $tagGroup->id
            );
        }

        // $globalSetsConfig
        $globalSets = Craft::$app->globals->getAllSets();
        $globalSetsConfig = [];
        foreach ($globalSets AS $globalSet) {
            $globalSetsConfig[] = array(
                'default_index' => $env.'_global_'.$globalSet->handle,
                'label' => $globalSet->name,
                'handle' => $globalSet->handle,
                'value' => $globalSet->id
            );
        }

        // user groups list
        $userGroups = Craft::$app->userGroups->getAllGroups();
        $userGroupsConfig = [];

        foreach ($userGroups AS $group) {
            $userGroupsConfig[] = array(
                'default_index' => $env.'_user_'.$group->handle,
                'label' => $group->name,
                'handle' => $group->handle,
                'value' => $group->id
            );
        }

        $supportedElements = [
            ['label' => 'Sections', 'handle' => 'section', 'data' => $sectionsConfig],
            ['label' => 'Asset Volumes', 'handle' => 'volume', 'data' => $volumesConfig],
            ['label' => 'Categories', 'handle' => 'category', 'data' => $categoriesConfig],
            ['label' => 'Tag Groups', 'handle' => 'tagGroup', 'data' => $tagGroupsConfig],
            ['label' => 'Global Sets', 'handle' => 'globalSet', 'data' => $globalSetsConfig],
            ['label' => 'User Groups', 'handle' => 'userGroup', 'data' => $userGroupsConfig]
        ];



        return Craft::$app->view->renderTemplate(
            'algolia-sync/settings',
            [
                'settings' => $this->getSettings(),
                'sectionsConfig' => $sectionsConfig,
                'categoriesConfig' => $categoriesConfig,
                'usersConfig' => $userGroupsConfig,
                'supportedElementsConfig' => $supportedElements
            ]
        );
    }
}
