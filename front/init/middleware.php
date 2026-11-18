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
 * 前台鉴权模式配置（单中间件多模式）
 *
 * 规则键支持：module / module/action / module/sub / module/sub/action
 * 模式值：
 * - public   匿名可访问，且不尝试 Session 登录态
 * - optional 尝试登录态，失败不拦截（未在下方列出的路由默认即为此）
 * - required 必须登录
 *
 * work_required 为 required 的子策略，命中后额外校验工作端身份。
 *
 * 【约定】新增需登录 / 需匿名豁免的前台模块时，必须在 auth_modes 中显式登记，
 * 不要依赖「未登记默认 optional」的兜底语义承载安全边界。
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
        'comment' => 'required',
        'health' => 'required',
        'work' => 'required',
        'order' => 'required',

        // file：通用文件上传（form / aftersale / user / share / health / distribution / comment
        // 的共同依赖），必须绑定会员，游客在路由层直接拦下。
        'file' => 'required',

        // book 子段收紧：核销端 book/work（同时纳入 work_required 二次校验）；会员中心
        // book/user——book/index、book/show 等公共键在候选顺序中先于 module/sub 命中，
        // sub=user 路由（/user/book*）会被 public 遮蔽，须以本键显式收紧（门禁可拦）。
        'book/work' => 'required',
        'book/user' => 'required',

        // chat：模块级默认必须登录（未知动作段经 MethodResolver 兜底 index 时同样被拦），
        // 动作级显式登记游客可达；安全边界由服务层承担（audience 受众闸 + 三层游客额度
        // + session_sn 持有校验）。claim 为会员专有端点，保持 required。
        // 键须与「路由动作名」一致（如列表动作为 listing，非 URL 段的 list）；/user/chat*
        // 会员中心（sub=user）与 chat/index 存在候选同名冲突（module/action 先命中），
        // 须以 module/sub 键 chat/user 收紧为 required。
        'chat' => 'required',
        'chat/user' => 'required',
        'chat/index' => 'optional',
        'chat/app' => 'optional',
        'chat/stream' => 'optional',
        'chat/new_session' => 'optional',
        'chat/sessions' => 'optional',
        'chat/messages' => 'optional',
        'chat/daily_quota' => 'optional',
        'chat/feedback' => 'optional',
        'chat/claim' => 'required',

        // geo：会员中心 /user/geo/* 按 module/sub 键收紧为 required（会员中心子域一一对应 group 的 sub）。
        // 冲突处置：公开分享报告的动作名 report 与会员中心子段 report 同形（候选顺序 module/sub 先于
        // module/action），故公开页以 geo/report 键登记 public，会员中心报表组须以 geo/report/<action>
        // 精确键先行命中，避免被 public 键遮蔽。
        'geo/user' => 'required',
        'geo/profile' => 'required',
        'geo/keyword' => 'required',
        'geo/knowledge' => 'required',
        'geo/template' => 'required',
        'geo/article' => 'required',
        'geo/check' => 'required',
        'geo/site' => 'required',
        'geo/package' => 'required',
        'geo/image' => 'required',
        'geo/analysis' => 'required',
        'geo/fact' => 'required',
        'geo/report/index' => 'required',
        'geo/report/create' => 'required',
        'geo/report/store' => 'required',
        'geo/report/destroy' => 'required',

        // geo 公开页（服务介绍 / 分享报告 / 单条证据）匿名可达；激活码流程为 optional
        // （游客可预览激活码，提交时按登录态要求走控制器内的 redirect）
        // aigc：会员付费 AI 应用；应用中心可游客浏览，生成/对话/作品/套餐均需登录
        'aigc' => 'required',
        'aigc/user' => 'required',
        'aigc/index' => 'optional',
        'aigc/listing' => 'optional',
        'aigc/app' => 'required',
        'aigc/submit' => 'required',
        'aigc/poll' => 'required',
        'aigc/works' => 'required',
        'aigc/file' => 'required',
        'aigc/task_delete' => 'required',
        'aigc/stream' => 'required',
        'aigc/new_session' => 'required',
        'aigc/sessions' => 'required',
        'aigc/messages' => 'required',

        'geo/index' => 'public',
        'geo/report' => 'public',
        'geo/evidence' => 'public',
        'geo/activate' => 'optional',
        'geo/activatepost' => 'optional',
        'geo/enter' => 'optional',
        'geo/exitenter' => 'required',

        // optional：公共浏览 / 基础设施模块。游客可访问，且尝试恢复登录态用于会员相关
        // 展示位（收藏态、会员价等），失败不拦截。此处显式登记仅把 UserAuthPolicy 的
        // DEFAULT_MODE（optional）落到明面，满足 front-auth-coverage-scan 的「每模块显式
        // 分类」门禁——新增会员私有模块时若漏登记会被门禁拦下，而非静默公开。
        'article' => 'optional',
        'brand' => 'optional',
        'captcha' => 'optional',
        'cases' => 'optional',
        'certificate' => 'optional',
        'consultation' => 'optional',
        'course' => 'optional',
        'dh' => 'optional',
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
        'landing' => 'optional',
        'lang_js' => 'optional', // 语言包 manifest（模块名含下划线，候选拆 lang/js）
        'llms' => 'optional',
        'onepic' => 'optional',
        'page' => 'optional',
        'partner' => 'optional',
        'plugin' => 'optional',
        'product' => 'optional',
        'professional' => 'optional',
        'routes_js' => 'optional', // 前台路由 manifest（语言包同源演化，资源型公开 GET）
        'search' => 'optional',
        'service' => 'optional',
        'sitemap' => 'optional',
        'sn' => 'optional',
        'solution' => 'optional',
        'store' => 'optional',
        'support' => 'optional',
        'tag' => 'optional',
        'team' => 'optional',
        'video' => 'optional',
        'vote' => 'optional',
        'weixin' => 'optional',

        // public：会员入口与预约公开页面
        'user/login' => 'public',
        'user/login_post' => 'public',
        'user/login_phone' => 'public',
        'user/login_phone_post' => 'public',
        'user/register' => 'public',
        'user/register_post' => 'public',
        'user/password_reset' => 'public',
        'user/password_reset_post' => 'public',
        'user/sns_link' => 'public',
        'user/verification' => 'public',
        'user/verification_post' => 'public',
        // 地区字典为公开只读数据：公开表单 / 咨询页（form、consultation 均 optional）
        // 的四级联动需要游客可用；存在登录态时仍会回填默认联系人。
        'user/area' => 'optional',

        'book/index' => 'public',
        'book/listing' => 'public',
        'book/class' => 'public',
        'book/show' => 'public',
        'book/schedule' => 'public',
        'book/date' => 'public',
        'book/time' => 'optional',
        'plugin/notify' => 'public',
        'plugin/finish' => 'public',
        'plugin/status' => 'public',
        'plugin/start' => 'optional',
    ),
    'work_required' => array(
        'work',
        'book/work',
    ),
);
