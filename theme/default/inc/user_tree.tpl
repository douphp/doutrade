<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="userTree">
 <h3>{$lang.user}</h3>
 <ul>
  <li{if $rec eq 'default'} class="cur"{/if}><a href="{url link='user'}">{$lang.user_main}</a></li>
  <!-- {if $features.order} -->
 <li{if $rec eq 'order_list' || $rec eq 'order'} class="cur"{/if}><a href="{url link='order.user'}">{$lang.order_my}</a></li>
  <!-- {/if} -->
  <!-- {foreach from=$link_user_center.user_tree item=tree} -->
  <li{if $tree.cur} class="cur"{/if}><a href="{$tree.url}">{$tree.name}</a></li>
  <!--{/foreach}-->
 <li{if $rec eq 'contact'} class="cur"{/if}><a href="{url link='user.contact'}">{$lang.user_contact_manager}</a></li>
  <!-- {if $if_connect_plugin} -->
 <li{if $rec eq 'sns'} class="cur"{/if}><a href="{url link='user.profile.sns'}">{$lang.user_sns}</a></li>
  <!-- {/if} -->
  <li{if $rec eq 'edit'} class="cur"{/if}><a href="{url link='user.profile.edit'}">{$lang.user_edit}</a></li>
  <li{if $rec eq 'password'} class="cur"{/if}><a href="{url link='user.profile.password'}">{$lang.user_password_edit}</a></li>
  <li class="logout"><a href="{url link='user.logout' token=$token}">{$lang.user_logout}</a></li>
 </ul>
</div>