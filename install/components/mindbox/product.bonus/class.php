<?php

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use \Bitrix\Main\Data\Cache;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

class ProductBonus extends CBitrixComponent implements Controllerable
{

    public function __construct(CBitrixComponent $component = null)
    {
        parent::__construct($component);

        try {
            if (!Loader::includeModule('mindbox.marketing')) {
                ShowError(GetMessage('MODULE_NOT_INCLUDED', ['#MODULE#' => 'mindbox.marketing']));
                return;
            }
        } catch (LoaderException $e) {
            ShowError(GetMessage('MB_AUS_MODULE_NOT_INCLUDED', ['#MODULE#' => 'mindbox.marketing']));;
            return;
        }


    }

    public function configureActions()
    {
        return Ajax::configureActions($this->actions);
    }

    public function getIntegrationKey()
    {
        $this->InitComponentTemplate();
        return md5($this->GetTemplate()->__file . '_' . $this->arParams['XML_ID']);
    }

    protected function createPlaceholder()
    {
        $return = "{{MINDBOX_BONUS|{$this->arParams['ID']}|{$this->arParams['PRICE']}}}";
        return $return;
    }

    public function executeComponent()
    {
        $this->arResult['MINDBOX_PLACEHOLDER'] = $this->createPlaceholder();
        $this->includeComponentTemplate();
    }

    public function executeComponent__()
    {
        if (isset($this->arParams['XML_ID']) && isset($this->arParams['PRICE'])) {

            $useIntegration = true;
            $cache = Cache::createInstance();

            if ($cache->initCache(3600, 'mindbox_product_' . $this->arParams['XML_ID'])) {
                $cacheVars = $cache->getVars();
                $this->arResult['DATA'] = $cacheVars['mindbox_data'];
                $this->arResult['INIT_CACHE'] = 'Y';
                $useIntegration = false;
            } else {
                $integrationKey = $this->getIntegrationKey();

                if (!empty($integrationKey)) {
                    $this->arResult['INTEGRATION_KEY'] = $integrationKey;
                }

                $execComponentFields = [
                    'XML_ID' => $this->arParams['XML_ID'],
                    'PRICE' => $this->arParams['PRICE'],
                    'COMPONENT_TEMPLATE' => $this->GetTemplate()->__file,
                    'INTEGRATION_KEY' => $integrationKey
                ];

                \Mindbox\Components\CalculateProductData::getInstance()->setProduct($execComponentFields);
            }
        }

        $includeTemplate = ($useIntegration) ? 'view' : '';
        global $APPLICATION;
        $APPLICATION->RestartBuffer();
        var_dump($this->arResult);
        die();
        $this->includeComponentTemplate($includeTemplate);
    }
}