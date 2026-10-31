<?php

class HeatmapExtension extends Minz_Extension {
	
	public function init() {
		Minz_Extension::registerHook('entry_before_display', [$this, 'injectHeatmapUI']);
		
		$this->registerController('heatmap');
		$this->registerController('Heatmap');
		
		Minz_View::appendScript($this->getFileUrl('heatmap.js', 'js'));
		Minz_View::appendStyle($this->getFileUrl('heatmap.css', 'css'));
	}

	public function handleConfigureAction() {
		if (Minz_Request::isPost()) {
			FreshRSS_Context::$user_conf->heatmap_searxng_url = rtrim(trim(Minz_Request::param('searxng_url', '')), '/');
			FreshRSS_Context::$user_conf->heatmap_enable_searxng = Minz_Request::param('enable_searxng', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_hn = Minz_Request::param('enable_hn', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_reddit = Minz_Request::param('enable_reddit', 0) ? true : false;
			
			$timeout = intval(Minz_Request::param('timeout', 3));
			FreshRSS_Context::$user_conf->heatmap_timeout = ($timeout >= 1 && $timeout <= 10) ? $timeout : 3;
			
			FreshRSS_Context::$user_conf->save();
		}
	}

	public function injectHeatmapUI($entry) {
		$url = $entry->link();
		$id = $entry->id();
		$pubdate = $entry->date(true);
		
		static $i18nInjected = false;
		$i18nHtml = '';
		if (!$i18nInjected) {
			$i18nInjected = true;
			$i18n = [
				'sortByHeat' => _t('ext.heatmap.sort_by_heat'),
				'sorted' => _t('ext.heatmap.sorted'),
				'noUrl' => _t('ext.heatmap.no_url'),
				'errData' => _t('ext.heatmap.err_data'),
				'errFetch' => _t('ext.heatmap.err_fetch'),
			];
			$i18nHtml = '<script id="heatmap-i18n" type="application/json">' . json_encode($i18n) . '</script>';
		}
		
		$titleAttr = htmlspecialchars(_t('ext.heatmap.click_to_recalculate'), ENT_QUOTES, 'UTF-8');
		$badge = $i18nHtml . '<span class="ext-heatmap-badge" data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" data-id="' . $id . '" data-pubdate="' . $pubdate . '" title="' . $titleAttr . '">🌡️...</span> ';
		$entry->_title($badge . $entry->title());
		
		return $entry;
	}
}
