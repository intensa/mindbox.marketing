<?php

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

class ProductCalculate extends CBitrixComponent implements Controllerable
{

    public function __construct(CBitrixComponent $component = null)
    {

    }

    public function configureActions()
    {
        return Ajax::configureActions($this->actions);
    }

    public function executeComponent()
    {
        global $APPLICATION;
        // @todo тут нужна проверка на пожключение модуля
        $productData = \Mindbox\Components\CalculateProductData::getInstance()->getData();
        if (!empty($productData)) {
            foreach ($productData as $k => $item) {
                $APPLICATION->AddViewContent('bonus_' . $k, $item['price']);
            }
        }
        var_dump($productData);


        $this->includeComponentTemplate();
    }

    public function calculateAction($products, $page, $pagen, $useCache)
    {
    }
}