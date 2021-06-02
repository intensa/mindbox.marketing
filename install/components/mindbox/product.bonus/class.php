<?php

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

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

    public function executeComponent()
    {

        if (isset($this->arParams['PRODUCT_ID']) && isset($this->arParams['PRODUCT_PRICE'])) {
            $this->arResult['INT_KEY'] = 'bonus_' . $this->arParams['PRODUCT_ID'];
             \Mindbox\Components\CalculateProductData::getInstance()->setProduct([
                'id' => $this->arParams['PRODUCT_ID'],
                'price' => $this->arParams['PRODUCT_PRICE']
            ]);
        }

        $this->includeComponentTemplate();
    }
}