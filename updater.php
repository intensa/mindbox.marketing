<?

if (IsModuleInstalled('mindbox.marketingdev')) {

    $obj = CModule::CreateModuleObject('mindbox.marketingdev');
    $curVersion = $obj->MODULE_VERSION;
    $allowUpdateVersion = '2.3.0';

    $objEventController = new \Mindbox\EventController();
    $optionsEvent = \COption::SetOptionString('mindbox.marketing', 'ENABLE_EVENT_LIST', '');

    if (empty($optionsEvent)) {
        $objEventController->unInstallEvents();
        $objEventController->installEvents();
    }

    $objEventController->revisionHandlers();

    $objHlInstaller = new \Mindbox\Installer\CartRulesInstaller();
    $objHlInstaller->install();
}