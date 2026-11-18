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

namespace Dou\Admin\Service\Brand;

use Dou\Admin\Model\Brand\Brand;
use Dou\Admin\Service\Concerns\SavesLanguageDrafts;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台品牌业务服务
 */
class BrandService extends BaseService
{
    use SavesLanguageDrafts;

    /** @var MarkdownRenderer */
    private $markdown;

    /**
     * @param MarkdownRenderer $markdown
     */
    public function __construct(MarkdownRenderer $markdown)
    {
        $this->markdown = $markdown;
    }

    /**
     * @param string $keyword
     * @param int $page
     * @return array
     */
    public function buildBrandListData($keyword, $page)
    {
        $page_url = route('admin.brand');
        if ($keyword !== '') {
            $page_url .= '&keyword=' . rawurlencode($keyword);
        }

        $result = Brand::filterByKeyword($keyword)
            ->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($page_url));

        $brand_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $brand_list[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'image' => $row['image'],
                'sort' => $row['sort'],
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'keyword' => $keyword,
            'brand_list' => $brand_list,
            'pager' => $result['pager'],
        );
    }

    /**
     * @return array
     */
    public function buildBrandDefaultData()
    {
        return array(
            'item_id' => 0,
            'brand' => array(
                'id' => '',
                'name' => '',
                'image' => '',
                'sort' => '50',
                'keywords' => '',
                'description' => '',
            ),
        );
    }

    /**
     * @param string $id
     * @return array|null
     */
    public function buildBrandEditData($id)
    {
        $brandModel = Brand::find($id);
        if (!$brandModel) {
            return null;
        }
        $brand = $brandModel->getAttributes();
        $brand['file_number'] = $brand['image'];
        $brand['image'] = attachment()->url($brand['image']);
        return array(
            'item_id' => $id,
            'brand' => $brand,
            'item_content' => $this->markdown->toHtml($brand['content']),
        );
    }

    /**
     * @see \Dou\Admin\Request\Brand\BrandFormRequest::rules()
     *
     * @param array $data
     * @param string $draftToken
     * @param int $adminId
     * @return int 新增记录主键
     */
    public function insert(array $data, $draftToken, $adminId)
    {
        $draftToken = (string) $draftToken;
        $adminId = (int) $adminId;
        if ($draftToken === '' || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.brand'));
        }

        $data['content'] = isset($data['content']) ? $this->markdown->toStore($data['content']) : '';
        $data['keywords'] = Arr::get($data, 'keywords', '');
        $data['description'] = Arr::get($data, 'description', '');
        $data['sort'] = isset($data['sort']) ? (int) $data['sort'] : 50;
        $data['created_at'] = time();

        $image = attachment()->reserve('brand', UploadedFile::fromGlobals('image'), 'admin', $adminId, 'main', AttachmentUploadOptions::create());
        $data['image'] = $image;
        $languagePlan = $this->languageDraftBegin('brand', $data, 0, $adminId);

        try {
            $newId = (int) DB::transaction(function () use ($data, $image, $draftToken, $adminId, $languagePlan) {
                $brand = Brand::create($data);
                if (!$brand) {
                    throw new DomainException(lang('illegal'), route('admin.brand'));
                }
                $newId = (int) $brand->getKey();
                if ($newId <= 0) {
                    throw new DomainException(lang('operation_failed'));
                }

                attachment()->claimByToken('brand', $draftToken, $newId, 'admin', $adminId);
                $this->languageDraftCommit($languagePlan, $newId, $adminId);
                if ($image !== '' && attachment()->adopt('brand', $image, $newId, 'admin', $adminId) < 1) {
                    throw new DomainException(lang('file_claim_failed'));
                }

                return $newId;
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $newId);
        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $data['name']);

        return $newId;
    }

    /**
     * @see \Dou\Admin\Request\Brand\BrandFormRequest::rules()
     *
     * @param array $data
     * @param int $adminId
     * @return void
     */
    public function update(array $data, $adminId)
    {
        $adminId = (int) $adminId;
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0 || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.brand'));
        }

        $brand = Brand::find($id);
        if (!$brand) {
            throw new DomainException(lang('item_not_exist'), route('admin.brand'));
        }
        $brandAttributes = $brand->getAttributes();
        $oldImageNumber = isset($brandAttributes['image']) ? (string) $brandAttributes['image'] : '';

        $languagePlan = $this->languageDraftBegin('brand', $data, $id, $adminId);
        $image = attachment()->store('brand', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withUploader('admin', $adminId));
        $deleteImage = ($image === '' && !empty($data['image_delete']));
        $data['content'] = isset($data['content']) ? $this->markdown->toStore($data['content']) : '';
        $data['keywords'] = Arr::get($data, 'keywords', '');
        $data['description'] = Arr::get($data, 'description', '');
        $data['sort'] = isset($data['sort']) ? (int) $data['sort'] : 50;

        if ($image) {
            $data['image'] = $image;
        } elseif ($deleteImage) {
            $data['image'] = '';
        }

        try {
            DB::transaction(function () use ($brand, $data, $id, $adminId, $languagePlan) {
                $brand->fill($data, 'update')->save();
                $this->languageDraftCommit($languagePlan, $id, $adminId);
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $id);
        if ($deleteImage && $oldImageNumber !== '') {
            attachment()->delete($oldImageNumber);
        }
        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * @param string $id
     * @param array $data
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 记录不存在或参数不合法时抛出
     */
    public function delete($id, array $data)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.brand'));
        }

        $brandModel = Brand::find($id, 'name, image');
        if (!$brandModel) {
            throw new DomainException(lang('item_not_exist'), route('admin.brand'));
        }
        $brand = $brandModel->getAttributes();

        if (isset($data['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $brand['name']);
            Brand::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.brand'),
            );
        }

        $delCheck = preg_replace('/d%/Ums', $brand['name'], lang('del_check'));
        return array(
            'message' => $delCheck,
            'back_url' => route('admin.brand'),
            'timeout' => '30',
            'confirm_url' => route('admin.brand.destroy', array('id' => $id)),
        );
    }

    /**
     * @param array $data
     * @return array{message: string, back_url: string}
     * @throws DomainException 参数不合法或动作类型不支持时抛出
     */
    public function action(array $data)
    {
        if (empty($data['checkbox']) || !is_array($data['checkbox'])) {
            throw new DomainException(lang('brand_select_empty'), route('admin.brand'));
        }

        $ids = Check::intIds($data['checkbox']);
        if (empty($ids)) {
            throw new DomainException(lang('brand_select_empty'), route('admin.brand'));
        }

        if (Arr::get($data, 'action', '') === 'del_all') {
            Brand::destroy($ids);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:BRAND (' . count($ids) . ' items)', 'brand');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.brand'));
        }

        throw new DomainException(lang('select_empty'), route('admin.brand'));
    }
}
