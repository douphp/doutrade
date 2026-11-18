<?php

namespace Dou\Api\Controller\Brand;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Front\Service\Brand\BrandService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class BrandController extends BaseController
{
    /** @var BrandService */
    private $brandService;

    public function __construct(BrandService $brandService)
    {
        $this->brandService = $brandService;
    }

    public function index(Request $request)
    {
        $page = $request->integer('page', 1);
        $pageSize = $request->integer('pageSize', 0);
        $class = $request->input('class') ? trim($request->input('class')) : '';

        $data = $this->brandService->buildBrandListData($page, $class, $pageSize);

        $data['title'] = lang('brand');

        return ApiResponse::success($data);
    }

    public function show(Request $request)
    {
        $id = RouteId::single('brand', $request->input('id'));
        if ($id == -1) {
            throw new DomainException(lang('page_wrong'));
        }

        $detail = $this->brandService->buildBrandShowData($id);
        if (!$detail) {
            throw new DomainException(lang('page_wrong'));
        }

        $brandRow = $detail['brand'];

        $data = [
            'title' => $brandRow['name'],
            'brand' => $brandRow,
        ];

        return ApiResponse::success($data);
    }
}
