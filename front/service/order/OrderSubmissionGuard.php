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

namespace Dou\Front\Service\Order;

use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 提交幂等守卫（文件锁 + token 记录）。
 *
 * 同一 submit_token 的并发/重试请求：
 *   - 首个请求拿到锁并写下 creating，成功后落 done(order_sn)；
 *   - 并发请求拿不到锁 -> 返回「订单正在提交」；
 *   - 已完成的重复请求 -> 直接返回同一 order_sn，不再建单。
 *
 * 与项目现有 storage/state 节流一致，假设单机部署；多机需替换为共享存储。
 */
class OrderSubmissionGuard extends BaseService
{
    /** done 记录有效期（秒） */
    const TTL = 86400;

    /**
     * 开始一次提交。
     *
     * @param int $userId
     * @param string $token 客户端页面级 submit_token
     * @return array ok/duplicate/order_sn/handle | ok=false + code/msg
     */
    public function begin($userId, $token)
    {
        $userId = (int) $userId;
        $token = trim((string) $token);
        if ($userId <= 0) {
            return array('ok' => false, 'code' => 'invalid_params', 'msg' => lang('order_item_wrong'));
        }

        $this->gc();

        if ($token === '') {
            return array('ok' => true, 'duplicate' => false, 'handle' => null);
        }

        $path = $this->path($userId, $token);
        $gcFp = $this->acquireGcLock(LOCK_SH);
        $fp = $gcFp === false ? false : @fopen($path, 'c+b');
        if ($fp === false) {
            if ($gcFp !== false) {
                @flock($gcFp, LOCK_UN);
                fclose($gcFp);
            }
            // 状态目录不可写时不阻断下单，仅失去幂等保护；记录告警便于运维发现降级
            Log::warning('order submit guard disabled: storage unwritable', array(
                'channel' => 'order',
                'payload' => array('user_id' => $userId, 'path' => $path),
            ));
            return array('ok' => true, 'duplicate' => false, 'handle' => null);
        }

        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            @flock($gcFp, LOCK_UN);
            fclose($gcFp);

            return array(
                'ok' => false,
                'code' => 'order_submitting',
                'msg' => lang('order_submitting'),
            );
        }

        $raw = stream_get_contents($fp);
        $record = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($record)
            && isset($record['status'])
            && $record['status'] === 'done'
            && !empty($record['order_sn'])
        ) {
            $updated = isset($record['updated_at']) ? (int) $record['updated_at'] : (int) @filemtime($path);
            if ($updated <= 0 || time() - $updated <= self::TTL) {
                @flock($fp, LOCK_UN);
                fclose($fp);
                @flock($gcFp, LOCK_UN);
                fclose($gcFp);

                return array(
                    'ok' => true,
                    'duplicate' => true,
                    'order_sn' => (string) $record['order_sn'],
                    'handle' => null,
                );
            }
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(array('status' => 'creating', 'created_at' => time())));
        fflush($fp);

        return array(
            'ok' => true,
            'duplicate' => false,
            'handle' => array('fp' => $fp, 'path' => $path, 'gc_fp' => $gcFp),
        );
    }

    /**
     * 标记提交成功并释放锁。
     *
     * @param array|null $handle
     * @param string $orderSn
     * @return void
     */
    public function finish($handle, $orderSn)
    {
        if (!is_array($handle) || !isset($handle['fp'])) {
            return;
        }

        $fp = $handle['fp'];
        try {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(array(
                'status' => 'done',
                'order_sn' => (string) $orderSn,
                'updated_at' => time(),
            )));
            fflush($fp);
        } finally {
            $this->releaseTokenLock($handle);
        }
    }

    /**
     * 标记提交失败：清空记录并释放锁，允许用户重试。
     *
     * @param array|null $handle
     * @return void
     */
    public function fail($handle)
    {
        if (!is_array($handle) || !isset($handle['fp'])) {
            return;
        }

        try {
            // 保留空记录，避免解锁后删除文件让并发请求持有不同文件的锁。
            @ftruncate($handle['fp'], 0);
            @fflush($handle['fp']);
        } finally {
            $this->releaseTokenLock($handle);
        }
    }

    /**
     * 丢弃指定 token 的记录（done 对应的订单已不存在时使用）。
     *
     * @param int $userId
     * @param string $token
     * @return void
     */
    public function forget($userId, $token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return;
        }

        $gcFp = $this->acquireGcLock(LOCK_SH);
        if ($gcFp === false) {
            return;
        }
        $fp = false;
        try {
            $fp = @fopen($this->path((int) $userId, $token), 'r+b');
            if ($fp !== false && @flock($fp, LOCK_EX | LOCK_NB)) {
                @ftruncate($fp, 0);
                @fflush($fp);
            }
        } finally {
            $this->releaseTokenLock(array('fp' => $fp, 'gc_fp' => $gcFp));
        }
    }

    /**
     * 用户级下单串行锁：同一会员同一时刻只允许一个建单流程。
     *
     * @param int $userId
     * @return array ok/handle | ok=false + code/msg
     */
    public function lockUser($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array('ok' => false, 'code' => 'invalid_params', 'msg' => lang('order_item_wrong'));
        }

        $path = $this->dir() . 'user_' . $userId . '.lock';
        $fp = @fopen($path, 'c+b');
        if ($fp === false) {
            // 加购与下单必须持有同一互斥锁，锁不可用时禁止无保护写入。
            Log::warning('order submit user lock unavailable: storage unwritable', array(
                'channel' => 'order',
                'payload' => array('user_id' => $userId, 'path' => $path),
            ));
            return array('ok' => false, 'code' => 'order_submitting', 'msg' => lang('order_submitting'));
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return array(
                'ok' => false,
                'code' => 'order_submitting',
                'msg' => lang('order_submitting'),
            );
        }

        return array('ok' => true, 'handle' => array('fp' => $fp, 'path' => $path));
    }

    /**
     * 释放用户级下单锁。
     *
     * @param array|null $handle
     * @return void
     */
    public function unlockUser($handle)
    {
        if (!is_array($handle) || !isset($handle['fp'])) {
            return;
        }

        @flock($handle['fp'], LOCK_UN);
        fclose($handle['fp']);
    }

    /**
     * 释放 token 文件锁及清理协调锁。
     *
     * @param array $handle 锁句柄
     * @return void 无返回值
     */
    private function releaseTokenLock(array $handle)
    {
        foreach (array('fp', 'gc_fp') as $name) {
            if (isset($handle[$name]) && is_resource($handle[$name])) {
                @flock($handle[$name], LOCK_UN);
                fclose($handle[$name]);
            }
        }
    }

    /**
     * 过期 token 记录清理：每小时最多一次，跳过 user_*.lock。
     *
     * @return void
     */
    private function gc()
    {
        $dir = $this->dir();
        $gcFile = $dir . '.gc';
        $existed = file_exists($gcFile);
        $fp = $this->acquireGcLock(LOCK_EX | LOCK_NB);
        if ($fp === false) {
            return;
        }
        try {
            // 新建文件 mtime 即当前时间，首次创建仍需扫描。
            clearstatcache(true, $gcFile);
            $mtime = $existed ? @filemtime($gcFile) : 0;
            if ($mtime && time() - $mtime < 3600) {
                return;
            }

            foreach ((array) glob($dir . '*.json') as $file) {
                if (is_link($file) || !is_file($file) || !preg_match('/^[1-9][0-9]*_[a-f0-9]{40}\.json$/D', basename($file))) {
                    continue;
                }
                clearstatcache(true, $file);
                $updated = (int) @filemtime($file);
                if ($updated <= 0 || time() - $updated <= self::TTL) {
                    continue;
                }
                $raw = @file_get_contents($file);
                if ($raw === false) {
                    continue;
                }
                if ($raw !== '') {
                    $record = json_decode($raw, true);
                    if (!is_array($record) || !isset($record['status']) || !in_array($record['status'], array('creating', 'done'), true)) {
                        continue;
                    }
                    $field = $record['status'] === 'done' ? 'updated_at' : 'created_at';
                    if (isset($record[$field])) {
                        if (!is_numeric($record[$field]) || (int) $record[$field] <= 0) {
                            continue;
                        }
                        $updated = max($updated, (int) $record[$field]);
                    }
                }
                if (time() - $updated > self::TTL) {
                    @unlink($file);
                }
            }
            @touch($gcFile);
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * 获取常驻清理协调锁，写入使用共享锁，回收使用独占锁。
     *
     * @param int $mode flock 锁模式
     * @return resource|false 锁句柄；无法加锁返回 false
     */
    private function acquireGcLock($mode)
    {
        $file = $this->dir() . '.gc';
        if (is_link($file)) {
            return false;
        }
        $fp = @fopen($file, 'c+b');
        if ($fp === false) {
            return false;
        }
        if (!@flock($fp, $mode)) {
            fclose($fp);
            return false;
        }
        return $fp;
    }

    /**
     * @param int $userId
     * @param string $token
     * @return string
     */
    private function path($userId, $token)
    {
        return $this->dir() . (int) $userId . '_' . sha1((string) $token) . '.json';
    }

    /**
     * @return string
     */
    private function dir()
    {
        $dir = (defined('STORAGE_PATH') ? STORAGE_PATH : sys_get_temp_dir() . DIRECTORY_SEPARATOR) . 'state/order_submit/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }
}
