<?php

/**
 * DouPHP®，基于 MIT 协议，完整声明见根目录 LICENSE。
 */

namespace Dou\Core\Infra\Plugin\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支付宝三渠道业务日志按日落盘及留存清理。
 */
class AlipayLog
{
    /**
     * 写入渠道日志，日志失败不影响支付流程。
     *
     * @param string $channel 支付渠道标识
     * @param string $text 日志内容
     * @return void 无返回值
     */
    public static function write($channel, $text)
    {
        $dir = rtrim(AOP_SDK_WORK_DIR, '/\\') . '/logs/';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . 'alipay_business_' . $channel . '_' . date('Y-m-d') . '.log';
        @file_put_contents($file, date('Y-m-d H:i:s') . '  ' . $text . "\r\n", FILE_APPEND | LOCK_EX);
        self::cleanIfDue($dir);
    }

    /**
     * 每日清理一次过期业务日志，兼顾系统临时目录回退场景。
     *
     * @param string $dir 日志目录
     * @return void 无返回值
     */
    private static function cleanIfDue($dir)
    {
        $marker = $dir . '.business_cleanup';
        if (is_link($marker)) {
            return;
        }
        clearstatcache(true, $marker);
        if (is_file($marker) && time() - (int) @filemtime($marker) < 86400) {
            return;
        }
        $handle = @fopen($marker, 'c+');
        if (!$handle) {
            return;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return;
        }
        try {
            rewind($handle);
            $last = (int) stream_get_contents($handle);
            $now = time();
            if ($last > 0 && $now - $last < 86400) {
                return;
            }
            foreach ((array) glob($dir . 'alipay_business_*.log') as $file) {
                if (is_link($file) || !is_file($file)
                    || !preg_match('/^alipay_business_(alipay|alipaywap|alipayf2f)_(\d{4}-\d{2}-\d{2})\.log$/D', basename($file), $parts)) {
                    continue;
                }
                $date = strtotime($parts[2] . ' 00:00:00');
                $modified = @filemtime($file);
                if ($date !== false && date('Y-m-d', $date) === $parts[2]
                    && $modified !== false && $now - $date >= 30 * 86400 && $now - $modified >= 30 * 86400) {
                    @unlink($file);
                }
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) $now);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
