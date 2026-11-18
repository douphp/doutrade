<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="product-list">
 <div class="container">
  <div class="row"> 
   <!-- {list module="product" limit=8 item=product} -->
   <div class="col-md-3 col-6">
    <div class="item">
     <!-- {if $product.image_other} -->
     <div class="img multi"><a href="{$product.url}"><img class="main" src="{$product.thumb}" /><img class="other" src="{$product.image_other}" /></a></div>
     <!-- {else} -->
     <div class="img"><a href="{$product.url}"><img class="main" src="{$product.thumb}" /></a></div>
     <!-- {/if} -->
     <div class="name"><a href="{$product.url}">{$product.name}</a></div>
     <!-- {if $site.show_price} -->
     <div class="price-box"><em class="price">{$product.sale_price.format}</em><!-- {if $product.sale_price.name} --><i class="price-cue">{$product.sale_price.name}</i><!-- {/if} --><!-- {if $product.sale_price.type neq 'original'} --><em class="price-gray">{$product.price}</em><!-- {/if} --></div>
     <!-- {/if} -->
    </div>
   </div>
   <!-- {/list} --> 
  </div>
 </div>
</div>