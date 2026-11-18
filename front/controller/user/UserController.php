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

namespace Dou\Front\Controller\User;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;
use Dou\Core\Web\Http\ApiCodes;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\File\FileService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;
use Dou\Front\Service\User\ProfileService;
use Dou\Front\Service\User\UserCenterPresenter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员中心主控制器（route=user/...）
 *
 * 仅承载会员中心首页与会员端工具入口（地区数据 / 文件上传）；登录注册找回密码归
 * {@see AuthController}（user/login 等），资料密码第三方账号归 {@see ProfileController}
 * （user/profile/*），验证码改手机改邮箱归 {@see VerificationController}（user/verify/*），
 * 我的订单视图归 {@see \Dou\Front\Controller\Order\OrderController}（order/order_list 等）。
 */
class UserController extends BaseController
{
    /** @var ProfileService */
    private $profileService;

    /** @var UserCenterPresenter */
    private $userCenterPresenter;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /** @var FileService */
    private $fileService;

    /**
     * @param ProfileService $profileService
     * @param UserCenterPresenter $userCenterPresenter
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     * @param FileService $fileService
     */
    public function __construct(
        ProfileService $profileService,
        UserCenterPresenter $userCenterPresenter,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo,
        FileService $fileService
    ) {
        $this->profileService = $profileService;
        $this->userCenterPresenter = $userCenterPresenter;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
        $this->fileService = $fileService;
    }

    /**
     * 会员中心首页（route=user）
     *
     * @return Response
     */
    public function index()
    {
        $userInfo = $this->profileService->getUserInfo(auth('front')->id());
        if (!$userInfo) {
            return redirect(route('user.login'));
        }

        $urHere = $this->breadcrumb->build('user');

        return $this->view('user.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user'),

            // 页面骨架
            'rec' => 'default',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('user'),
            'user_info' => $userInfo,
            'welcome' => $this->userCenterPresenter->buildWelcomeText(),
        ]);
    }

    // -------------------------------------------------------------------------
    // 地区数据 JSON
    // -------------------------------------------------------------------------

    /**
     * 国家/省/市/区数据接口（route=user/area，JSON 输出）
     *
     * type=init   回填联系地址（含 selectedCountry/Province/City/District）
     * type=root   国家层（顶层节点）与 multiple 标识
     * type=province/city/district 下一级选项，优先取 parent_id，兼容旧版 parent 名称
     *
     * @param Request $request
     * @return void
     */
    public function area(Request $request)
    {
        $area = Module::make('area');
        if (!$area) {
            ApiResponse::throwSuccess(array());
        }

        $type = $request->enum('type', array('init', 'root', 'country', 'province', 'city', 'district'), 'province');
        $current = $request->plainText('current');
        $data = array();

        if ($type === 'init') {
            $roots = $area->getRoots();
            $data['contact'] = $area->getContact($request->integer('id', 0), (int) auth('front')->id());
            $data['roots'] = $this->areaRootItems($roots);
            $data['multiple'] = count($roots) > 1;
            ApiResponse::throwSuccess($data);
        }

        if ($type === 'root' || $type === 'country') {
            $roots = $area->getRoots();
            $list = $this->areaRootItems($roots);
            foreach ($list as $index => $item) {
                $list[$index]['cur'] = $item['name'] == $current;
            }
            $data['area_list'] = $list;
            $data['multiple'] = count($roots) > 1;
            ApiResponse::throwSuccess($data);
        }

        $parentId = $request->integer('parent_id', 0);
        if ($parentId <= 0) {
            $parent = $request->plainText('parent');
            $parentId = $parent === '' ? 0 : $area->findIdByName($parent);
        }
        if ($parentId <= 0) {
            if ($type === 'province') {
                // 单国家场景下省级的上级就是唯一顶层节点
                $parentId = $area->getSingleRootId();
            }
            if ($parentId <= 0) {
                // 多国家未选国家 / 缺上级：返回空，避免把顶层国家当作下级选项
                ApiResponse::throwSuccess(array('area_list' => array()));
            }
        }

        $data['area_list'] = $area->getAreaList($parentId, $current);
        ApiResponse::throwSuccess($data);
    }

    /**
     * 顶层节点转为接口输出项。
     *
     * @param array $roots
     * @return array
     */
    protected function areaRootItems($roots)
    {
        $list = array();
        foreach ((array) $roots as $root) {
            $list[] = array(
                'id' => (int) $root['id'],
                'name' => $root['name'],
                'cur' => false,
            );
        }

        return $list;
    }

    // -------------------------------------------------------------------------
    // 文件上传
    // -------------------------------------------------------------------------

    /**
     * 文件上传（route=user/filebox，HTML 输出）
     *
     * 兼容入口：参数契约（module / target / type / item_id / draft_token / img_width）
     * 由存量前端与外部调用方沿用，实现体薄转发到通用上传服务 {@see FileService}；
     * `target` 即 DOM 前缀。`type=content` 的编辑器插图不属于通用上传能力，留在本控制器。
     *
     * @param Request $request
     * @return Response
     */
    public function filebox(Request $request)
    {
        $type = $request->alpha('type');
        if ($type === 'content') {
            return $this->response($this->storeContentImages($request));
        }

        $userId = (int) auth('front')->id();
        if ($userId <= 0) {
            return ApiResponse::error(ApiCodes::AUTH_REQUIRED, lang('file_login_required'), array(), 401);
        }

        try {
            $html = $this->fileService->upload(
                $request->alpha('module'),
                $request->basicString('target'),
                $type,
                (string) $request->input('item_id', ''),
                (string) $request->post('draft_token', ''),
                $userId,
                array('img_width' => $request->digits('img_width', 0))
            );
        } catch (DomainException $e) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $e->getMessage(), array(), 422);
        }

        return $this->response($html);
    }

    /**
     * 删除文件（route=user/filedel，HTML 输出）
     *
     * 兼容入口：实现体薄转发到 {@see FileService::delete()}，顺带获得归属校验。
     *
     * @param Request $request
     * @return Response
     */
    public function filedel(Request $request)
    {
        $userId = (int) auth('front')->id();
        if ($userId <= 0) {
            return ApiResponse::error(ApiCodes::AUTH_REQUIRED, lang('file_login_required'), array(), 401);
        }

        try {
            $html = $this->fileService->delete(
                (string) $request->input('number', ''),
                $request->basicString('prefix', $request->basicString('target')),
                $userId
            );
        } catch (DomainException $e) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $e->getMessage(), array(), 422);
        }

        return $this->response($html);
    }

    /**
     * 编辑器插图（type=content）：UEditor 专用契约，不进通用上传能力。
     *
     * 仅由富文本「插入图片」调用，返回给编辑器的是 img 片段而不是列表 HTML。
     *
     * @param Request $request
     * @return string
     */
    private function storeContentImages(Request $request)
    {
        $module = $request->alpha('module');
        $target = $request->basicString('target');
        $field = $target . '_file';
        $itemId = $this->compatItemId((string) $request->input('item_id', ''));
        $draftToken = (string) $request->post('draft_token', '');
        $userId = (int) auth('front')->id();
        $imgWidth = (int) $request->digits('img_width', 0);
        if ($imgWidth <= 0) {
            $imgWidth = (int) Config::get('site.image_width', 0);
        }
        $useDraft = ($draftToken !== '' && $itemId === '');

        if ($itemId === '' && !$useDraft) {
            throw new DomainException(lang('file_draft_invalid'));
        }
        if (!$useDraft) {
            // 内容插图同样受 owned 归属约束，避免向他人业务主键挂附件
            $this->fileService->assertOwnedItem($module, $itemId, $userId);
        }

        if (!isset($_FILES[$field]['name'])) {
            return '';
        }

        $total = count((array) $_FILES[$field]['name']);
        $html = '';
        for ($i = 0; $i < $total; $i++) {
            $fname = $field . $i;
            $newFiles = array($fname => array(
                'name' => $_FILES[$field]['name'][$i],
                'type' => $_FILES[$field]['type'][$i],
                'tmp_name' => $_FILES[$field]['tmp_name'][$i],
                'error' => $_FILES[$field]['error'][$i],
                'size' => $_FILES[$field]['size'][$i],
            ));

            $customFilename = $itemId . '_' . $target . '_' . Str::randomByType('number', 6, time());
            $opts = AttachmentUploadOptions::create()
                ->withBasename($customFilename)
                ->withImageWidth($imgWidth)
                ->withWatermark(Config::get('site.watermark', false))
                ->withBusinessField($fname);
            $file = UploadedFile::fromGlobals($fname, $newFiles);

            if ($useDraft) {
                $image = attachment()->storeDraft($module, $file, 'user', $userId, $draftToken, 'content', $opts);
            } else {
                $image = attachment()->store($module, $itemId, $file, 'content', $opts->withUploader('user', $userId));
            }

            $html .= '<img src="' . attachment()->url($image) . '" data-file="' . $image . '" /><br/><br/>';
        }

        return $html;
    }

    /**
     * 业务主键兼容校验：内容插图的旧契约只认纯数字且 ≤20 位。
     *
     * @param string $itemIdRaw
     * @return string
     */
    private function compatItemId($itemIdRaw)
    {
        return ($itemIdRaw !== '' && Check::number($itemIdRaw) && strlen($itemIdRaw) <= 20) ? $itemIdRaw : '';
    }

    /**
     * 构建公共布局变量
     *
     * @return array
     */
    protected function layoutVars()
    {
        return array(
            'post' => array(
                'mobile' => '',
                'email' => '',
            ),
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
            'link_user_center' => $this->buildLinkUserCenter('user'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}
