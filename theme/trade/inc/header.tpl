<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<header id="header">
 <div class="top">
  <div class="container">
   <div class="word">{$fragment.welcome.text}</div>
  </div>
 </div>
 <nav class="navbar navbar-expand-lg">
  <div class="container">
   <div class="navbar-brand"> <a href="{$site.home_url}" class="logo"><img src="../images/{$site.site_logo}" alt="{$site.site_name}" /></a> </div>
   <div class="navbar-action d-lg-none"> 
    <a class="icon bi bi-search show-hidden-search" href="javascript:;"></a>
    <a class="icon bi bi-person-circle" href="{url link='user'}"></a>
    <a class="icon bi bi-cart cart-link" href="{url link='order.cart'}"><!-- {if $cart_total} --><span class="cart-badge">{$cart_total}</span><!-- {/if} --></a>
    <button type="button" class="menu" data-toggle="collapse" data-target="#main-nav" aria-controls="main-nav" aria-expanded="false" aria-label="Toggle navigation"> <span class="icon bi bi-list"></span> </button>
   </div>
   <div class="main-nav collapse navbar-collapse" id="main-nav">
    <ul class="navbar-nav">
     <li class="nav-item"> <a class="nav-link" href="{$site.home_url}">{$lang.home}</a></li>
     <!-- {foreach from=$nav_middle_list name=nav_middle_list item=nav} -->
     <li class="nav-item{if $nav.child} dropdown{/if}{if $nav.cur} active{/if}"> <a href="{$nav.url}" class="nav-link{if $nav.child} dropdown-toggle{/if}{if $nav.cur} active{/if}" {if $nav.child} data-toggle="dropdown"{/if} aria-haspopup="true" aria-expanded="false"{if $nav.target} target="_blank"{/if}>{$nav.name}</a> 
      <!-- {if $nav.child} -->
      <ul class="dropdown-menu">
       <!-- {foreach from=$nav.child item=child} --> 
       <li{if $child.child} class="dropdown"{/if}> <a href="{$child.url}" class="dropdown-item{if $child.child} dropdown-toggle{/if}" {if $child.child} data-toggle="dropdown"{/if}>{$child.name}</a> 
        <!-- {if $child.child} -->
        <ul class="dropdown-menu">
         <!-- {foreach from=$child.child item=children} --> 
         <li{if $children.child} class="dropdown"{/if}> <a class="dropdown-item{if $children.child} dropdown-toggle{/if}" href="{$children.url}">{$children.name}</a> 
          <!-- {if $children.child} -->
          <ul class="dropdown-menu">
           <!-- {foreach from=$children.child item=item} -->
           <li><a class="dropdown-item" href="{$item.url}">{$item.name}</a></li>
           <!-- {/foreach} -->
          </ul>
          <!-- {/if} -->
         </li>
         <!-- {/foreach} -->
        </ul>
        <!-- {/if} -->
       </li>
       <!-- {/foreach} -->
      </ul>
      <!-- {/if} -->
     </li>
     <!-- {/foreach} -->
     <!-- {if $features.language} -->
     <li class="nav-item dropdown d-lg-none">
      <a href="javascript:;" class="nav-link dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{$lang.cur_language}</a> 
      <ul class="dropdown-menu">
       <!-- {foreach from=$lang_menu item=item} -->
       <li> <a href="{$item.url}" class="dropdown-item">{$item.name}</a> 
       <!-- {/foreach} -->
      </ul>
     </li>
     <!-- {/if} --> 
    </ul>
    <form class="form-inline my-2 my-lg-0 d-lg-none" action="{url link='search'}">
     <!-- {if !$site.rewrite} --><input type="hidden" name="route" value="search"><!-- {/if} -->
     <input class="form-control mr-sm-2" name="q" type="text" value="{$keyword|escape}" placeholder="{$lang.search_placeholder}">
     <button class="btn btn-outline-success my-2 my-sm-0" type="submit">{$lang.btn_submit}</button>
    </form>
   </div>
   <div class="action-btn d-none d-lg-block">
    <a class="bi bi-search show-hidden-search" href="javascript:;"></a>
    <a class="bi bi-person-circle" href="{url link='user'}"></a>
    <a class="bi bi-cart cart-link" href="{url link='order.cart'}"><!-- {if $cart_total} --><span class="cart-badge">{$cart_total}</span><!-- {/if} --></a>
   </div>
  </div>
 </nav>
 <div class="search-popup">
  <div class="form-box">
   <form action="{url link='search'}" method="get">
    <div class="form-mini">
     <!-- {if !$site.rewrite} --><input type="hidden" name="route" value="search"><!-- {/if} -->
     <input type="hidden" name="module" value="product">
     <input name="q" type="text" class="keyword" value="{$keyword|escape}" required="required" autocomplete="off" />
     <label>{$lang.search}</label>
     <button type="submit" class="btn-submit bi bi-search"></button>
    </div>
   </form>
   <i class="bi bi-x-lg show-hidden-search"></i>
  </div>
  <div class="popup-bg show-hidden-search"></div>
 </div>
</header>