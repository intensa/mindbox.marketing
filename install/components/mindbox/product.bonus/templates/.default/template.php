<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
global $APPLICATION;
?>
<?php if (!empty($arResult['INT_KEY'])):?>
<div class="mindbox-bonus-wrapper">
    <span><?
        $ter = $APPLICATION->GetViewContent($arResult['INT_KEY']);
        var_dump($ter);
        ?></span>
</div>
<?php endif;?>