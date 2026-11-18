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

namespace Dou\Admin\Controller\Favorites;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Favorites\FavoritesActionFormRequest;
use Dou\Admin\Request\Favorites\FavoritesDeleteFormRequest;
use Dou\Admin\Service\Favorites\FavoritesService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台收藏控制器（route=favorites/...）
 */
class FavoritesController extends BaseController
{
    /** @var FavoritesService */
    private $favoritesService;

    /**
     * @param FavoritesService $favoritesService
     */
    public function __construct(FavoritesService $favoritesService)
    {
        $this->favoritesService = $favoritesService;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $key = $request->input('key', '');
        $keyword = trim((string) $request->input('keyword', ''));
        $page = $request->integer('page', 1);

        $bundle = $this->favoritesService->buildFavoritesListData($key, $keyword, $page);

        return $this->view('favorites.htm', [
            'ur_here' => lang('favorites'),
            'rec' => 'default',
            'key_list' => $bundle['key_list'],
            'keyword' => $bundle['keyword'],
            'favorites_list' => $bundle['favorites_list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * @param FavoritesDeleteFormRequest $formRequest
     * @return Response
     */
    public function destroy(FavoritesDeleteFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $result = (array) $this->favoritesService->delete((int) $data['id'], $data);
        if (!empty($result['redirect'])) {
            return redirect($result['back_url']);
        }
        return $this->respondDeleteResult($result);
    }

    /**
     * @param FavoritesActionFormRequest $formRequest
     * @return Response
     */
    public function action(FavoritesActionFormRequest $formRequest)
    {
        $result = (array) $this->favoritesService->action((array) $formRequest->validated());
        $backUrl = isset($result['back_url']) ? $result['back_url'] : route('admin.favorites');
        $message = isset($result['message']) ? $result['message'] : '';
        return redirect($backUrl)->with('success', $message);
    }
}
