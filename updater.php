<?php

if (IsModuleInstalled('mindbox.marketing')) {
    \CModule::IncludeModule('mindbox.marketing');

    if (is_dir(dirname(__FILE__).'/install/components')) {
        $updater->CopyFiles("install/components", "components/");
    }

    $eventController = new \Mindbox\EventController();
    $eventController->unRegisterEventHandler([
        'bitrixModule' => 'main',
        'bitrixEvent' => 'OnBeforeUserRegister',
        'class' => '\Mindbox\Event',
        'method' => 'OnBeforeUserRegisterHandler',
    ]);
    $eventController->unRegisterEventHandler([
        'bitrixModule' => 'main',
        'bitrixEvent' => 'OnAfterUserRegister',
        'class' => '\Mindbox\Event',
        'method' => 'OnAfterUserRegisterHandler',
    ]);
}
