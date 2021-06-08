<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
global $APPLICATION;

if (empty($arResult['DATA']) && $arResult['INIT_CACHE'] !== 'Y') {
    ob_start();
}
?>
<div class="mindbox-product-bonus">
    Бонусы:
    <span class="mindbox-product-bonus__value">
        <?=($arResult['INIT_CACHE'] === 'Y') ? $arResult['DATA']['BONUS'] : '#BONUS#';?>
    </span>
</div>
<?php
if (empty($arResult['DATA']) && $arResult['INIT_CACHE'] !== 'Y') {
    $outTemplate = ob_get_contents();
    ob_end_clean();
    return $outTemplate;
}