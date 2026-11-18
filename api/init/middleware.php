<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 鉴权模式配置（单中间件多模式）
 *
 * 规则键支持：module / module/action / module/sub / module/sub/action
 * 模式值：
 * - public   匿名可访问，且不尝试解析登录态
 * - optional 尝试解析登录态，失败不拦截
 * - required 必须登录
 *
 * work_required 为 required 的子策略，命中后额外校验工作端身份。
 *
 * 【硬要求】新增 API 模块必须在 auth_modes 中按模块级显式登记三选一；
 * 漏登记会被 devtools/api-auth-coverage-scan.php 拦截（防止新模块静默以
 * 匿名可访问形态上线）。
 */
return array(
    'auth_modes' => array(
        'user' => 'required',
        'aftersale' => 'required',
        'share' => 'required',
        'favorites' => 'required',
        'vip' => 'required',
        'point' => 'required',
        'money' => 'required',
        'withdraw' => 'required',
        'book' => 'required',
        'distribution' => 'required',
        'coupon' => 'required',
        'order' => 'required',
        'work' => 'required',

        // health 全模块会员私有（档案登记 / 详情 / 随访均按属主过滤），鉴权级别 required；
        // 随访端 health/work/* 另入 work_required 叠加工作人员身份校验
        'health' => 'required',

        // 会员私有子控制器覆盖：模块本体公共浏览（optional），但 user / work 子段含会员私有数据，
        // 在 module/sub 级（命中优先于模块根回退）收紧为 required
        'comment/user' => 'required',
        'product/work' => 'required',

        // 覆盖 `order` => required：购物车数量允许匿名（未登录返回 0），控制器自行兜底
        // CartController 注册为 sub='cart' 子控制器，候选键形如 parent/sub/action
        'order/cart/cart_number' => 'optional',

        // 覆盖 `user` => required：会员中心首页允许匿名，登录后增强
        'user/index' => 'optional',

        // 地区字典为公开只读数据：小程序公开表单 / 咨询页的四级联动需要游客可用，
        // 存在登录态时仍会回填默认联系人。
        'user/area' => 'optional',

        // 支付回调：公开 webhook，由微信 V2 验签保证来源可信
        'pay' => 'public',

        // user 模块 public 动作
        'user/login_post' => 'public',
        'user/register' => 'public',
        'user/register_post' => 'public',
        'user/password_reset' => 'public',
        'user/password_reset_post' => 'public',
        'user/sns_link' => 'public',
        'user/check_login_state' => 'public',
        'user/login_phone_post' => 'public',
        'user/weixin/login' => 'public',
        'user/weixin/get_phone' => 'public',

        // 覆盖 `book` => required：预约浏览类接口允许匿名（contact / booking 为登录动作，不放开）
        'book/index' => 'public',
        'book/class' => 'public',
        'book/show' => 'public',
        'book/schedule' => 'public',
        'book/date' => 'public',
        'book/time' => 'optional',

        // book 子段收紧：候选键 module/action 先于 module 命中，sub=user / sub=work 的
        // user/book*、book/work* 会被 book/index、book/show 的 public 遮蔽——中间件走「匿名直达」
        // 分支不解析登录态、不注入 guard，控制器内 auth('api')->id() 恒为 0，
        // 表现为已登录用户拿到 401「登录超时」。必须以 module/sub 键显式收紧（与 front 端一致）。
        'book/user' => 'required',
        'book/work' => 'required',

        // 客服可选登录，服务层校验受众与每日限额；会员历史保持必须登录。
        'chat/index' => 'optional',
        'chat/app' => 'optional',
        'chat/user' => 'required',
        'chat/stream' => 'optional',
        'chat/new_session' => 'optional',
        'chat/sessions' => 'optional',
        'chat/messages' => 'optional',
        'chat/daily_quota' => 'optional',

        // 覆盖 `aigc` => optional：应用中心 / 应用详情 / 套餐游客可浏览；
        // 生成、作品、文件、对话与会员中心按动作收紧为 required。
        'aigc' => 'optional',
        'aigc/submit' => 'required',
        'aigc/poll' => 'required',
        'aigc/works' => 'required',
        'aigc/file' => 'required',
        'aigc/task_delete' => 'required',
        'aigc/stream' => 'required',
        'aigc/new_session' => 'required',
        'aigc/sessions' => 'required',
        'aigc/messages' => 'required',
        'aigc/user' => 'required',

        // open：开放平台出站请求走 open_auth 签名校验（应用级身份，与会员登录态无关），
        // 模块根以 public 兜底避免误触会员解析；实际准入由 OpenAuthMiddleware 的签名与 scope 裁决。
        'open' => 'public',

        // geo：会员端点（/user/geo/*，sub=user）统一 required；公开端点按动作登记。
        // 模块根 optional 仅作为未预期动作的兼容兜底，安全边界由 geo/user 与各动作键承担。
        'geo' => 'optional',
        'geo/user' => 'required',
        'geo/index' => 'public',
        'geo/report' => 'public',
        'geo/evidence' => 'public',
        'geo/activation' => 'public',
        'geo/activate' => 'required',

        // optional：内容浏览 / 公共功能类模块，匿名可读、登录后增强（如会员价 / 收藏态）
        'article' => 'optional',
        'attribute' => 'optional',
        'bootstrap' => 'optional',
        'brand' => 'optional',
        'captcha' => 'optional',
        'cases' => 'optional',
        'certificate' => 'optional',
        'chat' => 'optional',
        'comment' => 'optional',
        'consultation' => 'optional',
        'course' => 'optional',
        'doc' => 'optional',
        'download' => 'optional',
        'email' => 'optional',
        'equipment' => 'optional',
        'faq' => 'optional',
        'form' => 'optional',
        'gallery' => 'optional',
        'guestbook' => 'optional',
        'index' => 'optional',
        'item' => 'optional',
        'job' => 'optional',
        'lang' => 'optional',
        'onepic' => 'optional',
        'page' => 'optional',
        'partner' => 'optional',
        'product' => 'optional',
        'professional' => 'optional',
        'search' => 'optional',
        'service' => 'optional',
        'sn' => 'optional',
        'solution' => 'optional',
        'store' => 'optional',
        'support' => 'optional',
        'team' => 'optional',
        'video' => 'optional',
        'vote' => 'optional',
    ),
    'work_required' => array(
        'work',
        'book/work',
        'order/work',
        'money/work',
        'user/work',
        'aftersale/work',
        'health/work',
        'product/work',
    ),
);
