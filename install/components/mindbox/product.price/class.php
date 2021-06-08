<?php

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

class ProductPrice extends CBitrixComponent implements Controllerable
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

    public function executeComponent()
    {
        if (isset($this->arParams['XML_ID']) && isset($this->arParams['PRICE'])) {

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

        $this->includeComponentTemplate('view');
    }
}