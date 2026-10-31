<?php

class HeatmapExtension extends Minz_Extension {
	
	public function init() {
		$this->registerTranslates();

		Minz_Extension::registerHook('entry_before_display', [$this, 'injectHeatmapUI']);
		
		$this->registerController('heatmap');$this->registerController('Heatmap');
		
		Minz_View::appendScript($this->getFileUrl('heatmap.js', 'js'));
		Minz_View::appendStyle($this->getFileUrl('heatmap.css', 'css'));
	}

	public function handleConfigureAction() {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			FreshRSS_Context::$user_conf->heatmap_searxng_url = rtrim(trim(Minz_Request::param('searxng_url', '')), '/');
			FreshRSS_Context::$user_conf->heatmap_hister_url = rtrim(trim(Minz_Request::param('hister_url', '')), '/');
			FreshRSS_Context::$user_conf->heatmap_hister_token = trim(Minz_Request::param('hister_token', ''));
			
			FreshRSS_Context::$user_conf->heatmap_enable_hister = Minz_Request::param('enable_hister', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_searxng = Minz_Request::param('enable_searxng', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_hn = Minz_Request::param('enable_hn', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_reddit = Minz_Request::param('enable_reddit', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_bsky = Minz_Request::param('enable_bsky', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_wikipedia = Minz_Request::param('enable_wikipedia', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_lobsters = Minz_Request::param('enable_lobsters', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_lemmy = Minz_Request::param('enable_lemmy', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_wayback = Minz_Request::param('enable_wayback', 0) ? true : false;
			FreshRSS_Context::$user_conf->heatmap_enable_github = Minz_Request::param('enable_github', 0) ? true : false;
			
			$timeout = intval(Minz_Request::param('timeout', 3));
			FreshRSS_Context::$user_conf->heatmap_timeout = ($timeout >= 1 && $timeout <= 10) ?$timeout : 3;
			
			$unit = Minz_Request::param('temp_unit', 'F');
			FreshRSS_Context::$user_conf->heatmap_unit = ($unit === 'C') ? 'C' : 'F';
			
			FreshRSS_Context::$user_conf->save();
		}
	}

	public function injectHeatmapUI($entry) {
		$url =$entry->link();
		$id =$entry->id();
		$pubdate =$entry->date(true);
		$rawTitle =$entry->title();
		$cleanTitle = strip_tags($rawTitle);
		
		static $i18nInjected = false;
		$i18nHtml = '';
		if (!$i18nInjected) {$i18nInjected = true;
			$unit = FreshRSS_Context::$user_conf->heatmap_unit ?? 'F';
			$i18n = [
				'sortByHeat' => _t('ext.heatmap.sort_by_heat'),
				'sorted' => _t('ext.heatmap.sorted'),
				'noUrl' => _t('ext.heatmap.no_url'),
				'errData' => _t('ext.heatmap.err_data'),
				'errFetch' => _t('ext.heatmap.err_fetch'),
				'unit' => $unit
			];
			$i18nHtml = '<script id="heatmap-i18n" type="application/json">' . json_encode($i18n) . '</script>';
		}
		
		$titleAttr = htmlspecialchars(_t('ext.heatmap.click_to_recalculate'), ENT_QUOTES, 'UTF-8');
		$badge =$i18nHtml . '<span class="ext-heatmap-badge" data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" data-id="' . $id . '" data-pubdate="' . $pubdate . '" data-title="' . htmlspecialchars($cleanTitle, ENT_QUOTES, 'UTF-8') . '" title="' . $titleAttr . '">🌡️...</span> ';
		$entry->_title($badge .$rawTitle);
		
		return $entry;
	}
}
