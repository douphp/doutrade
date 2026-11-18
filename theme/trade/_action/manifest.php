<?php
// 主题扩展初始化清单：依赖 dou_data / dou_file，缺表时跳过演示数据，不阻断安装。
return array(
    'requires' => array('tables' => array('data', 'file')),
);
