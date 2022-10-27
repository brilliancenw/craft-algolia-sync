<?php
namespace brilliance\algoliasync\assetbundles\algoliasync;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class AlgoliaSyncAsset extends AssetBundle
{

    public function init()
    {
        // define the path that your publishable resources live
        $this->sourcePath = "@brilliance/algoliasync/assetbundles/algoliasync/dist";

        // define the dependencies
        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/AlgoliaSync.js',
        ];

        $this->css = [
            'css/AlgoliaSync.css',
        ];

        parent::init();
    }
}
