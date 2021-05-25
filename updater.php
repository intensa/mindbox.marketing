<?
if (IsModuleInstalled('mindbox.marketing')) {

    $obj = CModule::CreateModuleObject('mindbox.marketing');
    $curVersion = $obj->MODULE_VERSION;
    $allowUpdateVersion = '2.3.0';

    $objEventController = new \Mindbox\EventController();
    $objEventController->unInstallEvents();
    $objEventController->installEvents();
    $objEventController->revisionHandlers();

    $objHlInstaller = new \Mindbox\Installer\CartRulesInstaller();
    $objHlInstaller->install();
}

