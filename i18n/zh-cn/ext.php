<?php

return array(
    'heatmap' => array(
        'searxng_url' => 'SearXNG 实例地址',
        'searxng_help' => '您的本地或公共 SearXNG 实例地址。请确保 settings.yml 中已启用 JSON 格式。',
        'active_sources' => '启用的热度数据源',
        'enable_searxng' => '启用 <strong>SearXNG</strong> 搜索（全网引用）',
        'enable_hn' => '启用 <strong>Hacker News</strong>（Algolia API）',
        'enable_reddit' => '启用 <strong>Reddit</strong>（公开 API）',
        'timeout' => '网络超时时间（秒）',
        'timeout_help' => '每个外部请求的最大超时时间（默认：3秒）。较低的值可防止服务器变慢。',
        'save' => '保存设置',
        'click_to_recalculate' => '点击重新计算',
        'sort_by_heat' => '🔥 按热度排序',
        'sorted' => '🔥 已排序！',
        'no_url' => '🌡️ 无 URL',
        'err_data' => '🌡️ 数据错误',
        'err_fetch' => '🌡️ 请求失败',
    ),
);
