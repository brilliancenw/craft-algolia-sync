<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace brilliance\algoliasync\events;

use yii\base\Event;

class beforeAlgoliaSyncEvent extends Event
{
    // Properties
    // =========================================================================

    public $recordUpdate;
    public $recordElement;

    public function __construct(array $config = [])
    {
        parent::__construct($config);

    }

}
