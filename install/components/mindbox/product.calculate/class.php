<?php

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use \Bitrix\Main\Data\Cache;

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

    public function prepareMindboxProductData($mindboxData)
    {
        $result = [
            'BONUS' => false,
            'PRICE' => false,
            'OLD_PRICE' => false,
        ];

        if (!empty($mindboxData['basePricePerItem'])) {
            $result['PRICE'] = $mindboxData['basePricePerItem'];
        }

        if (!empty($mindboxData['priceForCustomer'])) {
            $result['OLD_PRICE'] = $mindboxData['basePricePerItem'];
            $result['PRICE'] = $mindboxData['priceForCustomer'];
        }

        if (!empty($mindboxData['appliedPromotions']) && is_array($mindboxData['appliedPromotions'])) {
            foreach ($mindboxData['appliedPromotions'] as $promotion) {
                if ($promotion['type'] === 'earnedBonusPoints' && !empty($promotion['amount'])) {
                    $result['BONUS'] = $promotion['amount'];

                } elseif ($promotion['discount']) {

                }
            }
        }

        return $result;
    }

    public function executeComponent()
    {
        global $APPLICATION;

        $productData = \Mindbox\Components\CalculateProductData::getInstance()->getIntegrationData();

        if (!empty($productData)) {
           foreach ($productData as $item) {
               if (!empty($item['INTEGRATION_KEY'])) {
                   $replaceProductData = $this->prepareMindboxProductData($item['CALCULATE']);

                   $cache = Cache::createInstance();
                   $cache->initCache(3600, 'mindbox_product_' . $item['XML_ID']);
                   $cache->startDataCache();
                   $cache->endDataCache(['mindbox_data' => $replaceProductData]);

                   if (!empty($replaceProductData)) {
                       $template = @include $_SERVER['DOCUMENT_ROOT'] . $item['COMPONENT_TEMPLATE'];

                       if (!empty($template)) {
                            foreach ($replaceProductData as $placeholder => $datum) {
                                if (strpos($template, '#' . $placeholder . '#') !== false) {
                                    $replaceValue = (!empty($datum)) ? $datum : '';
                                    $template = str_replace('#' . $placeholder . '#', $replaceValue, $template);
                                }
                            }
                       }

                       //var_dump($template);

                       $APPLICATION->AddViewContent($item['INTEGRATION_KEY'], $template);
                   }
               }

           }
       }


        $this->includeComponentTemplate();
    }

    public function calculateAction($products, $page, $pagen, $useCache)
    {
    }
}