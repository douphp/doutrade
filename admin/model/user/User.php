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

namespace Dou\Admin\Model\User;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员数据模型（后台 user 表）。
 */
class User extends Model
{
    protected $table = 'user';
    protected $primary = 'id';

    /** @var array 列表读取时格式化（created_at→Y-m-d、计数/等级→int；sex 走 accessor） */
    protected $casts = array(
        'level_id' => 'int',
        'login_count' => 'int',
        'status' => 'string',
        'created_at' => 'datetime:Y-m-d',
    );

    /**
     * 允许批量写入的数据表字段白名单（持久化层）。
     *
     * 说明：
     * - 此处仅声明 user 表真实字段，供 Model::fill() 过滤入库数据使用。
     * - 不承担请求参数校验职责；表单校验规则请见 UserFormRequest::rules()。
     * - 仅用于请求层的控制参数（如 password_confirm、checkbox、confirm）不应放在此处。
     *
     * @var array
     */
    protected $fillable = array(
        'user_sn',
        'level_id',
        'distribution_level_id',
        'direct_user_id',
        'indirect_user_id',
        'mobile',
        'email',
        'password',
        'token',
        'token_expires_at',
        'reset_token',
        'reset_token_expires_at',
        'sex',
        'nickname',
        'avatar',
        'pay_code',
        'pay_code_updated_at',
        'login_count',
        'login_fail_count',
        'login_locked_at',
        'status',
        'created_at',
    );

    /**
     * 关联会员等级（level_id → user_level）。
     *
     * @return \Dou\Core\Orm\Relations\BelongsTo
     */
    public function level()
    {
        return $this->belongsTo(UserLevel::class, 'level_id');
    }

    /**
     * 性别展示串：1=男，其它=女（读原始 sex）。
     *
     * @param mixed $value
     * @return string
     */
    public function getSexAttribute($value)
    {
        return $value ? lang('user_man') : lang('user_woman');
    }

    /**
     * 按会员 id 精确筛选；空字符串 / 0 透传，-1 视作强制空集。
     *
     * @param Builder $query
     * @param mixed $userId
     * @return Builder
     */
    public function scopeFilterByUserId(Builder $query, $userId)
    {
        if ($userId === '' || $userId === null) {
            return $query;
        }
        $userId = (int) $userId;
        if ($userId === 0) {
            return $query;
        }

        return $query->where('id', $userId);
    }

    /**
     * 按会员状态筛选（active/suspended/deactivated）；空串透传不过滤。
     *
     * @param Builder $query
     * @param mixed $status
     * @return Builder
     */
    public function scopeFilterByStatus(Builder $query, $status)
    {
        $status = (string) $status;
        if ($status === '') {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * 仅当会员模块启用且 user_level 表存在时，预加载 level 关联（消除列表 N+1）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithLevelIfAvailable(Builder $query)
    {
        if (Config::get('features.user', false) && DB::table('user_level')->exists()) {
            return $query->with('level');
        }

        return $query;
    }

    /**
     * 默认列表排序：id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }

    /**
     * 主键查询。
     *
     * @param int|string $userId
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findByUserId($userId)
    {
        return static::where('id', $userId)->first();
    }

    /**
     * 删除关联 user_sns 行（联动删除会员时调用）。
     *
     * @param int|string $userId
     * @return mixed
     */
    public static function deleteUserSnsByUserId($userId)
    {
        return DB::table('user_sns')->where('user_id', $userId)->delete();
    }

    /**
     * 邮箱 → id 查询。
     *
     * @param string $email
     * @return mixed
     */
    public static function getUserIdByEmail($email)
    {
        return DB::table(static::tableName())->where('email', $email)->value('id');
    }

    /**
     * 手机号 → user_id 查询。
     *
     * @param string $mobile
     * @return mixed
     */
    public static function getUserIdByMobile($mobile)
    {
        return DB::table(static::tableName())->where('mobile', $mobile)->value('id');
    }

    /**
     * 头像字段值（按 id）。
     *
     * @param int|string $userId
     * @return mixed
     */
    public static function getAvatarValue($userId)
    {
        return DB::table(static::tableName())->where('id', $userId)->value('avatar');
    }

    /**
     * 会员操作日志分页（user_log 表）。
     *
     * @param int|string $userId
     * @param int $page
     * @return array
     */
    public static function getUserLogList($userId, $page = 1)
    {
        return DB::table('user_log')
            ->where('user_id', $userId)
            ->order('id DESC')
            ->paginate(15, $page);
    }
}
