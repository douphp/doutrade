<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/comment.css" rel="stylesheet" type="text/css" />
<div id="comment"> 
 <!-- {if $comment.list} -->
 <h2>{$lang.comment_list}</h2>
 <div class="comment-list"> 
  <!-- {foreach from=$comment.list item=comment} -->
  <dl>
   <dt><span>{$comment.created_at}</span>{$comment.nickname}</dt>
   <dd class="content">
    <div class="comment-star">
     <!-- {foreach from=$comment.star_box.list item=item} -->
     <i class="bi bi-star active"></i>
     <!-- {/foreach} --> 
     <i class="star-number">{$comment.star_box.number}</i>
    </div>
    <p>{$comment.content}</p>
    <!-- {foreach from=$comment.img_list item=item} -->
    <a href="{$item.file}" target="_blank"><img src="{$item.file}" height="80" /></a>
    <!-- {/foreach} --> 
   </dd>
   <!-- {if $comment.reply} -->
   <dd class="reply"><p class="replyContent">{$lang.comment_reply}：{$comment.reply.content}</p><p class="replyTime">{$comment.reply.created_at}</p></dd>
   <!-- {/if} -->
  </dl>
  <!-- {/foreach} --> 
  {include file="inc/pager.tpl" pager=$comment.pager}
 </div>
 <!-- {/if} -->
 <!-- {if $param.comment_allow_no_order} -->
 <h2>{$lang.comment_add}</h2>
 <div class="form">
  <form id="commentAdd" x-data="douForm()" @submit.prevent="submit" action="{url link='comment.user.store'}" method="post">
   {include file="inc/honeypot.tpl"}
   <div class="form-item">
    <div class="form-label">{$lang.comment_content}：</div>
    <div class="input-block">
     <textarea name="content" cols="90" rows="5" class="textarea" maxlength="300" /></textarea>
     <p class="cue" x-show="hasError('content')" x-text="errorOf('content')"></p>
    </div>
   </div>
   <!-- {if $site.captcha} -->
   <div class="form-item">
    <div class="form-label">{$lang.captcha}：</div>
    <div class="input-block">
     <input type="text" name="captcha" class="textarea captcha" size="10">
     <img id="vcode" class="pointer" src="{url link='captcha'}" alt="{$lang.captcha}" border="1" onClick="refreshimage()" title="{$lang.captcha_refresh}">
     <p class="cue" x-show="hasError('captcha')" x-text="errorOf('captcha')"></p>
    </div>
   </div>
   <!-- {/if} -->
   <div class="form-item submit">
    <div class="input-block">
     <input type="hidden" name="module" value="{$comment.module}">
     <input type="hidden" name="item_id" value="{$comment.item_id}">
     <input type="hidden" name="token" value="{$token}" />
     <button type="submit" class="btn" :disabled="loading">{$lang.btn_submit}</button>
     <span class="cue" x-show="message" x-text="message"></span>
    </div>
   </div>
  </form>
 </div>
 <!-- {/if} -->
</div>

