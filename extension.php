<?php

class HeatmapExtension extends Minz_Extension {
	
	public function init() {
		// Log that init was called
		error_log("Heatmap init called on request: " . $_SERVER['REQUEST_URI'] ?? 'CLI');

		// Register hook for UI injection
		Minz_Extension::registerHook('entry_before_display', [$this, 'injectHeatmapUI']);
		
		// Register the controller
		$this->registerController('heatmap');
		$this->registerController('Heatmap');
		
		// Ensure JS and CSS are loaded
		Minz_View::appendScript($this->getFileUrl('heatmap.js', 'js'));
		Minz_View::appendStyle($this->getFileUrl('heatmap.css', 'css'));
	}

	public function handleConfigureAction() {
		if (Minz_Request::isPost()) {
			$searxngUrl = rtrim(trim(Minz_Request::param('searxng_url', '')), '/');
			FreshRSS_Context::$user_conf->heatmap_searxng_url = $searxngUrl;
			FreshRSS_Context::$user_conf->save();
		}
	}

	public function injectHeatmapUI($entry) {
		$url = $entry->link();
		$id = $entry->id();
		$pubdate = $entry->date(true); // Raw timestamp
		
		// Badge injected into the title (visible in list view) and content (visible in reading view)
		$badge = '<span class="ext-heatmap-badge" data-url="' . htmlspecialchars($url) . '" data-id="' . $id . '" data-pubdate="' . $pubdate . '" title="Click to refresh score">🌡️...</span> ';
		
		$entry->_title($badge . $entry->title());
		
		return $entry;
	}
}
