<?php

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use \Bitrix\Main\Data\Cache;
use Mindbox\Ajax;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

class ProductBonus extends CBitrixComponent implements Controllerable
{
    const PLACEHOLDER_PREFIX = 'MINDBOX_BONUS';

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

    public function calculateProductAction($phone)
    {
        return ['test' => 1];
    }

    protected function createPlaceholder()
    {
        $prefix = self::PLACEHOLDER_PREFIX;
        return "{{{$prefix}|{$this->arParams['ID']}|{$this->arParams['PRICE']}}}";
    }

    public function executeComponent()
    {
        $productCache = \Mindbox\Components\CalculateProductData::getProductCache($this->arParams['ID']);

        if (!empty($productCache)) {
            $this->arResult['MINDBOX_BONUS'] = $productCache['MINDBOX_BONUS'];
        } else {
            $this->arResult['MINDBOX_BONUS'] = $this->createPlaceholder();
        }

        $this->includeComponentTemplate();
    }
}