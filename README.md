# DouPHP 轻量级外贸商城系统（DouTrade）

DouPHP 轻量级外贸商城系统（别名 **DouTrade**）是一套面向跨境零售与外贸 B2C 场景的开源商城系统，一套代码同时覆盖 **PC 网站、手机端、微信小程序** 三端，内置从商品展示、购物车、下单支付到配送、售后的完整交易链路。

- **开发语言**：PHP 5.6 – 8.x
- **当前版本**：v2.0 Release 20261116
- **开源协议**：MIT，可自由使用、修改、分发（含商业用途），详见 [LICENSE](LICENSE)

## 项目定位

DouPHP 是「核心系统 + 模块」的模块化系统，业务能力由模块装配而来。DouTrade 不是独立分支，也不是二次开发框架，而是 **在 DouPHP 核心系统之上装配购物商城常用模块的组装发行版**：

- **核心一致**：`core/`、三端入口（front / admin / api）、框架机制与 DouPHP 核心系统保持一致，不修改核心代码
- **模块组装**：在核心系统之上装配外贸商城所需的 15 个原生模块与 5 个原生插件；模块、插件、主题、模板、语言包与小程序均为 DouPHP 官方原生组件，不新增、不改写业务模块
- **与全模块开发版的区别**：DouPHP 的全模块开发版安装全部官方模块，用于开发与功能体验；DouTrade 面向商城交付，只装配商城常用模块。两者的差异仅在模块清单，核心与模块代码同源同构
- **未装配的模块**：企业站类模块（案例、文档、课程、下载、视频、招聘、表单、投票、留言等）以及 AI、分销、钱包等模块不随包提供，可按需安装官方模块包或使用全模块开发版
- **同源同构**：目录结构、路由约定、模板语法、模块开发方式与 DouPHP 完全一致，官方开发规范与模块文档同样适用于本项目

## 在线演示

| 端   | 地址                             | 账号 / 密码           |
| ---- | -------------------------------- | --------------------- |
| 前台 | https://demo.dou.co/trade          | 访客 / 会员视角        |
| 后台 | https://demo.dou.co/trade/admin/   | `admin` / `admin888`  |

> 演示站数据定时还原，公共内容不支持修改；上传、备份、模块安装、系统升级等操作已禁用，请勿填写真实信息。

## 特性

- **三端一体**：前台（front）、后台（admin）、API（api）三端独立入口、共享核心，互不污染
- **商城交易闭环**：商品分类 / 品牌 / 规格属性 / 促销价 / 库存开关，购物车、下单、支付、配送、发货单号、售后与评论全流程
- **会员与营销**：会员中心、会员等级、收货地址、收藏夹、积分商城、优惠券（满减、适用范围、限领、叠加、首单专享）
- **多语言 & 多货币**：内置简体中文、繁体中文、英文语言包，后台可维护语言条目与内容翻译；每种语言可启用独立货币与汇率，前台支持语言前缀路由（如 `/en-us`）
- **跨境插件**：PayPal（USD / EUR / GBP / AUD / CAD / JPY / HKD）、连连支付（CNY）、Google 与 Amazon 账号登录、快递配送（固定运费 / 满额包邮）
- **微信小程序**：内置 TypeScript + WeUI 小程序源码（`shop` 商城版 / `default` 通用版），与 API 端控制器一一对应
- **SEO / GEO**：伪静态、自定义 URL、`sitemap.xml`、`llms.txt`（AI 站点地图）与 Schema 结构化数据开关，便于搜索引擎与 AI 搜索收录
- **安全机制**：CSRF 自动校验、XSS 过滤、登录限流与锁定、后台权限分级、安全响应头、后台操作审计日志
- **模板与主题**：自研 Smarty 风格 DouView 模板引擎（`.dwt` 模板 + 主题脚本），内置 `trade` 商城主题与 `default` 通用主题
- **现代 PHP 架构**：DI 容器、Facade 门面、ActiveRecord + Query Builder ORM、PSR-4 自动加载

## 环境要求

- PHP 5.6 – 8.x（建议 PHP 8.x）
- MySQL 5.5.3+（建议 5.7+）
- Apache / Nginx（启用 URL 重写）

PHP 需启用或安装以下扩展 / 配置：

- **GD** 扩展（图片处理）
- **PDO / mysqli** 扩展（数据库连接）
- **OpenSSL** 扩展（支付、第三方登录）
- **cURL** 扩展（云服务与部分支付接口）
- 伪静态：`mod_rewrite`（Apache）/ `try_files` 规则（Nginx）

建议使用平台：Linux + Apache / Nginx + PHP 8.x + MySQL 5.7

## 快速安装

1. 下载源码并部署到网站根目录
2. 访问网站首页，系统自动跳转到安装程序 `install/index.php`
3. 按提示完成环境检测，填写数据库信息与管理员账号（可选择是否导入演示数据）
4. 安装完成后系统会在 `storage/state/install.lock` 写入安装锁，再次访问将不再进入安装程序
5. 后台默认入口为 `admin/`，可在后台「工具箱 → 后台目录自定义」中修改以隐藏后台地址

如需重新安装，删除 `storage/state/install.lock` 后重新访问安装程序即可。

## 目录结构

```
doutrade/
├── admin/            后台（管理员使用，HTML 渲染）
├── api/              HTTP API（JSON，供小程序 / SPA / 第三方调用）
├── front/            前台（访客与会员使用，HTML 渲染）
├── core/             核心层（三端共享：ORM / 容器 / 安全 / 通用服务）
├── config/           站点配置（数据库、模块清单、路由、安全等）
├── install/          安装程序
├── languages/        多语言包（zh_cn / zh_tw / en_us，按端 + 模块分文件）
├── theme/            前台主题（trade 商城主题 / default 通用主题）
├── miniprogram/      微信小程序源码（shop 商城版 / default 通用版）
├── plugin/           插件（支付 / 登录 / 配送）
├── storage/          运行时存储（cache / log / backup / snapshot / state）
├── images/           站点静态图片资源
├── index.php         前台入口
├── admin/index.php   后台入口
└── api/index.php     API 入口
```

## 已装配模块

本发行版在核心系统之上装配外贸商城所需的 15 个 DouPHP 原生模块，安装时全部启用：

| 形态 | 模块 |
| --- | --- |
| **栏目模块**（分类树 + 列表 + 详情） | `product` 商品、`article` 文章 |
| **交易与会员** | `order` 订单、`user` 会员、`coupon` 优惠券、`point` 积分商城、`favorites` 收藏夹、`aftersale` 售后服务、`comment` 评论 |
| **商品辅助** | `brand` 品牌、`attribute` 规格属性、`area` 地区 |
| **系统类** | `language` 多语言设置、`plugin` 插件、`email` 邮件订阅 |

模块清单由安装包内置的 `install/data/module.php` 提供，安装时写入 `config/module.php`；后台「模块管理」可查看与配置已安装模块。

## 已装配插件

| 分组 | 插件 |
| --- | --- |
| **支付 `payment`** | PayPal（多币种）、连连支付（CNY） |
| **登录 `connect`** | Google OAuth 2.0 登录、Login with Amazon |
| **配送 `shipping`** | Express 快递配送（固定运费、满额包邮） |

插件位于 `plugin/`，按子目录独立成包，后台「插件管理」中启用并填写商户参数。

## 主题与小程序

- 前台主题：`theme/trade`（默认启用，商城风格）、`theme/default`（通用风格），后台「主题管理」中切换
- 微信小程序：`miniprogram/shop`（商城版）、`miniprogram/default`（通用版），均为 TypeScript + WeUI 工程，接口与 `api/` 端对应
- 小程序 AppID、服务器域名与站点地址在小程序工程 `project.config.json` / 后台「小程序」中配置

## 后台能力

- 商品、文章、品牌、订单、会员、评论、优惠券、积分、售后等业务管理
- 模块 / 插件 / 主题管理，支持在线安装官方扩展（需连通豆壳云服务）
- 站点设置、导航、单页、多语言、货币、邮件、SEO 配置
- 数据备份、站点快照、系统升级、目录权限检测、后台目录自定义
- 管理员权限分级与操作审计日志

## 二次开发

- 项目与 DouPHP 核心系统同源同构，模块开发遵循官方约定
- 新建一个模块 = 在三端按约定落地 controller / service / model / route / request + 语言包 + 模板
- 后台模板为 `admin/view/<模块名>/*.htm`，前台模板为 `theme/<主题名>/<模块名>.dwt`
- 编码规范：UTF-8 无 BOM、4 空格缩进、LF 换行，PHP 语法需兼容 5.6

## 相关链接

- 官方网站：https://www.douphp.com
- 项目仓库：[GitHub](https://github.com/douphp/doutrade) / [Gitee](https://gitee.com/douphp/doutrade)

## 开源协议

本项目基于 [MIT License](LICENSE) 开源发布。

## 补充声明

- 本项目名称 "DouPHP" 及官方 Logo 不在 MIT 授权范围内，未经授权不得用于衍生项目的名称与宣传
- DouTrade 仅为 DouPHP 官方原生模块与组件的组装发行版，模块与组件的著作权归原项目所有（漳州豆壳网络科技有限公司）
- 项目内置的第三方组件（jQuery、Bootstrap Icons、WeUI 等）遵循其各自的原始授权协议
