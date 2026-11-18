<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="product-list">
 <div class="container">
  <div class="row">
   <div class="col-md-8 col-12">
    <!-- {foreach from=$recommend_product name=rec_product item=product} -->
    <!-- {if $product@first} -->
    <div class="item">
     <!-- {if $product.image_other} -->
     <div class="img multi"><a href="{$product.url}"><img class="main" src="{$product.image}" /><img class="other" src="{$product.image_other}" /></a></div>
     <!-- {else} -->
     <div class="img"><a href="{$product.url}"><img class="main" src="{$product.image}" /></a></div>
     <!-- {/if} -->
     <div class="name"><a href="{$product.url}">{$product.name}</a></div>
     <!-- {if $site.show_price} -->
     <div class="price-box"><em class="price">{$product.sale_price.format}</em><!-- {if $product.sale_price.name} --><i class="price-cue">{$product.sale_price.name}</i><!-- {/if} --><!-- {if $product.sale_price.type neq 'original'} --><em class="price-gray">{$product.price}</em><!-- {/if} --></div>
     <!-- {/if} -->
    </div>
    <!-- {/if} -->
    <!-- {/foreach} --> 
   </div>
   <div class="col-md-4 col-12">
    <div class="row">
     <!-- {foreach from=$recommend_product name=rec_product item=product} -->
     <!-- {if !$product@first} -->
     <div class="col-md-12 col-6">
      <div class="item">
       <!-- {if $product.image_other} -->
       <div class="img multi"><a href="{$product.url}"><img class="main" src="{$product.image}" /><img class="other" src="{$product.image_other}" /></a></div>
       <!-- {else} -->
       <div class="img"><a href="{$product.url}"><img class="main" src="{$product.image}" /></a></div>
       <!-- {/if} -->
       <div class="name"><a href="{$product.url}">{$product.name}</a></div>
       <!-- {if $site.show_price} -->
       <div class="price-box"><em class="price">{$product.sale_price.format}</em><!-- {if $product.sale_price.name} --><i class="price-cue">{$product.sale_price.name}</i><!-- {/if} --><!-- {if $product.sale_price.type neq 'original'} --><em class="price-gray">{$product.price}</em><!-- {/if} --></div>
       <!-- {/if} -->
      </div>
     </div>
     <!-- {/if} -->
     <!-- {/foreach} --> 
    </div>
   </div>
  </div>
 </div>
</div>