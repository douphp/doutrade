<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<footer id="footer">
 <div class="container">
  <div class="foot-nav"> 
   <div class="row"> 
    <!-- {foreach from=$nav_bottom_list name=nav_bottom_list item=nav} --> 
    <!-- {if $nav@iteration le 2} -->
    <div class="col-md-4">
     <div class="item">
      <div class="nav-parent"> <a href="{$nav.url}">{$nav.name}</a> </div>
      <div class="nav-child"> 
       <!-- {foreach from=$nav.child item=child} --> 
       <a href="{$child.url}">{$child.name}</a> 
       <!-- {/foreach} --> 
      </div>
     </div>
    </div>
    <!-- {/if} --> 
    <!-- {/foreach} --> 
    <div class="col-md-4">
     <div class="item">
      <div class="nav-parent"><em>{$lang.contact}</em></div>
      <div class="nav-child"> 
       <em>{$site.address}</em> 
       <em>{$site.tel}</em> 
       <em>{$site.email}</em> 
      </div>
     </div>
    </div>
   </div>
  </div>
  <div class="email-social"> 
   <div class="subscribe">
    <div class="head">{$lang.email_title}</div>
    <div class="form-box">
     <form id="emailSubscribe" action="{url link='user.email_post'}" method="post">
      <input type="hidden" name="token" value="{$token}" />
      <div class="form-mini">
       <input name="email" type="text" class="keyword" required="required" />
       <label>{$lang.email_email}</label>
       <button type="button" class="btn-submit bi bi-arrow-right" onclick="douSubmit('emailSubscribe', 'alert')"></button>
      </div>
     </form>
    </div>
   </div>
   <div class="social">
    <!-- {if $site.facebook} -->
    <a class="bi bi-facebook" href="{$site.facebook}" target="_blank"></a>
    <!-- {/if} -->
    <!-- {if $site.instagram} -->
    <a class="bi bi-instagram" href="{$site.instagram}" target="_blank"></a>
    <!-- {/if} -->
    <!-- {if $site.youtube} -->
    <a class="bi bi-youtube" href="{$site.youtube}" target="_blank"></a>
    <!-- {/if} -->
    <!-- {if $site.tiktok} -->
    <a class="bi bi-tiktok" href="{$site.tiktok}" target="_blank"></a>
    <!-- {/if} -->
    <!-- {if $site.twitter} -->
    <a class="bi bi-twitter" href="{$site.twitter}" target="_blank"></a>
    <!-- {/if} -->
   </div>
  </div>
 </div>
 <div class="info-box">
  <div class="container">
   <!-- {if $features.language} -->
   <div class="lang-icon"> 
    <div class="foot-lang">
     <div class="head">{$lang.language}</div>
     <div class="lang-select"><a class="cur-lang" href="javascript:;">{$lang.cur_language}</a>
      <div class="lang-list">
       <!-- {foreach from=$lang_menu item=item} -->
       <a href="{$item.url}">{$item.name}</a>
       <!-- {/foreach} -->
      </div>
     </div>
    </div>
    <div class="express-payment">
     <!-- {foreach from=$box_list.express_payment item=item} -->
     <img src="{$item.image}" />
     <!-- {/foreach} -->
    </div>
   </div>
   <!-- {/if} -->
   <div class="copy-right">{$lang.copyright} {$lang.powered_by nofilter} <!-- {if $site.icp} --><a href="https://beian.miit.gov.cn/" target="_blank">{$site.icp}</a><!-- {/if} --><!-- {if $site.net_safe_record} --><a href="http://www.beian.gov.cn/portal/registerSystemInfo?recordcode={$site.net_safe_record_number}" class="net-safe-record" target="_blank"><img src="../images/icon_net_safe_record.png" />{$site.net_safe_record}</a><!-- {/if} --></div>
  </div>
 </div>
</footer>
<!-- {if $site.code} -->
<div style="display:none">{$site.code nofilter}</div>
<!-- {/if} -->