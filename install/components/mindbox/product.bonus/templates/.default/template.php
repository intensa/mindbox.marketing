<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
global $APPLICATION;
?>
<div id="mindbox-product-bonus" class="mindbox-product-bonus">
    <?if (isset($arParams['LABEL']) && !empty($arParams['LABEL'])):?>
        <span class="mindbox-product-bonus__label"><?=$arParams['LABEL']?></span>
    <?endif;?>
    <span class="mindbox-product-bonus__value"><?=$arResult['MINDBOX_BONUS']?></span>
</div>
<?php
$jsVar = 'mb_bonus_' . md5($arParams['ID']);
?>
<script>

    if (!window.mindboxEventRegist) {
      BX.addCustomEvent('mindboxChangeOffer', BX.delegate(function(data){
        console.log('Events of moduleName', data);
        data.elem.querySelector('.mindbox-product-bonus__value').innerHTML = data.id;
      }, this));

      /*let request = BX.ajax.runComponentAction('mindbox:product.bonus', 'calculateProduct', {
        mode:'class',
        data: {
        }
      });

      request.then(function (response) {
        console.log(response)
      });*/

      window.mindboxEventRegist = true;
    }


</script>


