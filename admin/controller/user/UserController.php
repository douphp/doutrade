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

namespace Dou\Admin\Controller\User;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\User\UserActionFormRequest;
use Dou\Admin\Request\User\UserFormRequest;
use Dou\Admin\Request\User\UserStatusFormRequest;
use Dou\Admin\Service\User\UserLevelOptionBuilder;
use Dou\Admin\Service\User\UserService;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Support\Check;
use Dou\Core\Support\FileHelper;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员管理（route=user/...）
 *
 * 控制器只做路由调度、取参、assign；
 * 列表与表单数据来自 UserService::build*Data；新增 / 更新 / 批量动作走 *FormRequest。
 */
class UserController extends BaseController
{
    /** @var UserService */
    private $userService;

    /** @var UserLevelOptionBuilder */
    private $userLevelOptions;

    /**
     * @param UserService $userService
     * @param UserLevelOptionBuilder $userLevelOptions
     */
    public function __construct(UserService $userService, UserLevelOptionBuilder $userLevelOptions)
    {
        $this->userService = $userService;
        $this->userLevelOptions = $userLevelOptions;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'wrong' => array('sex' => ''),
        );
    }

    /**
     * 会员列表（route=user）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $page = $request->integer('page', 1);
        $usernameRaw = trim((string) $request->input('username'));
        $username = Check::username($usernameRaw) ? $usernameRaw : '';
        $statusFilter = (string) $request->input('status', '');
        $rejectFilter = array(
            'username' => $usernameRaw !== '' && !Check::username($usernameRaw),
        );
        $bundle = $this->userService->buildUserListData($username, $page, $rejectFilter, $statusFilter);

        if (!Config::has('param.login_mode')) {
            return redirect(route('admin.user.set'));
        }

        $pageActions = array(
            array('href' => route('admin.user.create'), 'text' => lang('user_create'), 'style' => 'add'),
        );
        if (Config::get('features.user', false)) {
            $pageActions[] = array('href' => route('admin.user.level'), 'text' => lang('user_level'), 'style' => '');
        }
        $pageActions[] = array('href' => route('admin.user.set'), 'text' => lang('set'), 'style' => '');

        return $this->view('user.htm', [
            'ur_here' => lang('user'),
            'page_actions' => $pageActions,
            'rec' => 'default',
            'req' => $request->all(),
            'username' => $usernameRaw,
            'status_filter' => $bundle['status_filter'],
            'status_options' => $bundle['status_options'],
            'user_list' => $bundle['user_list'],
            'pager' => $bundle['pager'],
            'has_user_level_data' => $bundle['has_user_level_data'],
            'excel' => file_exists(LIBRARY_PATH . 'excel/Excel.php'),
        ]);
    }

    /**
     * 添加会员表单（route=user/create）
     *
     * @return Response
     */
    public function create()
    {
        $user = $this->userService->buildUserDefaultData();

        return $this->view('user.htm', [
            'ur_here' => lang('user_edit'),
            'page_actions' => array(
                array('href' => route('admin.user'), 'text' => lang('user'), 'style' => ''),
            ),
            'rec' => 'create',
            'user' => $user,
            'user_level_option' => $this->userLevelOptions->options(),
            'distribution_level_option' => $this->distributionLevelOptions(),
        ]);
    }

    /**
     * 添加会员提交（route=user/store）。
     *
     * 字段白名单与校验规则定义在 UserFormRequest::rules()。
     *
     * @param UserFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(UserFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $newId = $this->userService->insert($data);
        return redirect(route('admin.user.edit', array('id' => $newId)))
            ->with('success', lang('user_create_success'), route('admin.user'), lang('back_to_list'));
    }

    /**
     * 编辑会员表单（GET route=user/{id}/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $userId = $request->integer('id', 0);
        if ($userId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }

        $user = $this->userService->buildUserEditData($userId);
        if (!$user) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }

        return $this->view('user.htm', [
            'ur_here' => lang('user_edit'),
            'page_actions' => array(
                array('href' => route('admin.user'), 'text' => lang('user'), 'style' => ''),
            ),
            'rec' => 'edit',
            'user' => $user,
            'user_level_option' => $this->userLevelOptions->options($user['level_id']),
            'distribution_level_option' => $this->distributionLevelOptions(isset($user['distribution_level_id']) ? (int) $user['distribution_level_id'] : 0),
        ]);
    }

    /**
     * 编辑会员提交（route=user/update）。
     *
     * 字段白名单与校验规则定义在 UserFormRequest::rules()。
     *
     * @param UserFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(UserFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        if ($userId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }

        $avatar = '';
        if (!empty($_FILES['avatar']['name'])) {
            $fileName = FileHelper::filename(DB::table('user')->where('id', $userId)->value('avatar'));
            $avatarFilename = $fileName ? $fileName : user()->createAvatarFilename();
            $avatar = attachment()->store('user', $userId, UploadedFile::fromGlobals('avatar'), 'main', AttachmentUploadOptions::create()->withBasename($avatarFilename)->withImageWidth(200)->withPrimaryKey('id')->withBusinessField('avatar')->withUploader('admin', (int) auth('admin')->id()));
        }

        $this->userService->update($data, $avatar);

        $page = Session::get('page', 1);
        $backUrl = Util::normalizeQueryString(route('admin.user', array(), array('page' => $page)));
        return redirect(route('admin.user.edit', array('id' => $userId)))
            ->with('success', lang('user_edit_success'), $backUrl, lang('back_to_list'));
    }

    /**
     * 删除会员（DELETE route=user/{id}）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $userId = $request->integer('id', 0);
        if ($userId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }

        $result = $this->userService->delete($userId, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 批量操作（route=user/action）。
     *
     * 字段白名单与校验规则定义在 UserActionFormRequest::rules()。
     *
     * @param UserActionFormRequest $formRequest
     * @return Response|null
     */
    public function action(UserActionFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $result = $this->userService->action($data);
        if (!is_array($result)) {
            // excel/excel_all 分支已由 Excel::export 直接发送二进制响应
            return null;
        }

        return redirect($result['back_url'])->with('success', $result['message']);
    }

    /**
     * 会员状态变更（route=user/set_status）：封禁 / 解禁 / 注销。
     *
     * 合法迁移由 UserStatus::canTransit 校验，审计写 user_log。
     *
     * @param UserStatusFormRequest $formRequest
     * @return Response
     */
    public function setStatus(UserStatusFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $userId = (int) $data['user_id'];

        $this->userService->setStatus($userId, (string) $data['new_status'], (int) auth('admin')->id());

        return redirect(route('admin.user.edit', array('id' => $userId)))
            ->with('success', lang('user_status_change_success'));
    }

    /**
     * 分销等级下拉（distribution 模块未安装时返回空数组，模板不渲染对应表单项）。
     *
     * @param int $selectedId 当前选中等级 ID
     * @return array
     */
    private function distributionLevelOptions($selectedId = 0)
    {
        $service = Module::make('distribution.distribution_level_service');
        if ($service === null) {
            return array();
        }

        return $service->options($selectedId);
    }

    /**
     * 初始化会员登录参数（route=user/set）
     *
     * @return Response
     */
    public function set()
    {
        $this->userService->set();
        return redirect(route('admin.parameter.set', ['group' => 'user']));
    }
}
