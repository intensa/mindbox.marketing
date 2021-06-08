<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
global $APPLICATION;
ob_start();
?>
<div class="mindbox-product-price">
    <span class="mindbox-product-price__discount">#OLD_PRICE#</span>
    <span class="mindbox-product-price__price">#PRICE#</span> руб
</div>
<?php
$outTemplate = ob_get_contents();
ob_end_clean();
return $outTemplate;