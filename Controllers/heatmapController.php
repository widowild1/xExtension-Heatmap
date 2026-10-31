<?php

class FreshExtension_heatmap_Controller extends Minz_ActionController {
	
	private $db = null;
	private $multipliers = [];
	private $cacheDir = null;
	private $useSqlite = false;
	
	public function firstAction(): void {
		$extPath = dirname(__DIR__);
		
		$multipliersPath = $extPath . '/credibility_multipliers.json';
		if (file_exists($multipliersPath)) {
			$this->multipliers = json_decode(file_get_contents($multipliersPath), true) ?: [];
		}

		if (defined('DATA_PATH') && is_dir(DATA_PATH . '/cache') && is_writable(DATA_PATH . '/cache')) {
			$this->cacheDir = DATA_PATH . '/cache/heatmap';
		} elseif (is_writable($extPath)) {
			$this->cacheDir = $extPath . '/cache';
		} else {
			$this->cacheDir = sys_get_temp_dir() . '/freshrss_heatmap';
		}

		if (!is_dir($this->cacheDir)) {
			@mkdir($this->cacheDir, 0775, true);
		}

		if (extension_loaded('pdo_sqlite') && class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers())) {
			try {
				$dbPath = $this->cacheDir . '/heatmap_cache.sqlite';
				$this->db = new PDO('sqlite:' . $dbPath);
				$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				$this->db->exec("CREATE TABLE IF NOT EXISTS cache (
					url TEXT PRIMARY KEY,
					score REAL,
					timestamp INTEGER,
					first_check INTEGER,
					details TEXT
				)");

				try {
					$this->db->exec("ALTER TABLE cache ADD COLUMN first_check INTEGER");
					$this->db->exec("UPDATE cache SET first_check = timestamp WHERE first_check IS NULL");
					$this->db->exec("ALTER TABLE cache ADD COLUMN details TEXT");
				} catch (PDOException $e) {}

				if (rand(1, 100) === 1) {
					$this->db->exec("DELETE FROM cache WHERE timestamp < " . (time() - 86400 * 60));
				}
				$this->useSqlite = true;
			} catch (Throwable $e) {
				error_log('Heatmap SQLite init warning: ' . $e->getMessage());
				$this->db = null;
				$this->useSqlite = false;
			}
		}

		if (!$this->useSqlite && rand(1, 100) === 1 && is_dir($this->cacheDir)) {
			$files = @glob($this->cacheDir . '/*.json');
			if ($files) {
				$expire = time() - (86400 * 60);
				foreach ($files as $file) {
					if (@filemtime($file) < $expire) {
						@unlink($file);
					}
				}
			}
		}
	}

	private function getCacheFilePath(string $url): string {
		return $this->cacheDir . '/' . sha1($url) . '.json';
	}

	private function getCacheEntry(string $url) {
		if ($this->useSqlite && $this->db) {
			try {
				$stmt = $this->db->prepare("SELECT score, timestamp, first_check, details FROM cache WHERE url = :url");
				$stmt->execute([':url' => $url]);
				return $stmt->fetch(PDO::FETCH_ASSOC);
			} catch (Throwable $e) {
				return false;
			}
		} else {
			$file = $this->getCacheFilePath($url);
			if (file_exists($file)) {
				$data = json_decode(@file_get_contents($file), true);
				if (is_array($data) && isset($data['score'])) {
					return $data;
				}
			}
		}
		return false;
	}

	private function setCache(string $url, float $score, int $firstCheck, array $details): void {
		$now = time();
		$detailsJson = json_encode($details);
		if ($this->useSqlite && $this->db) {
			try {
				$stmt = $this->db->prepare("INSERT INTO cache (url, score, timestamp, first_check, details) VALUES (:url, :score, :timestamp, :first_check, :details)
					ON CONFLICT(url) DO UPDATE SET score=excluded.score, timestamp=excluded.timestamp, details=excluded.details");
				$stmt->execute([
					':url' => $url,
					':score' => $score,
					':timestamp' => $now,
					':first_check' => $firstCheck,
					':details' => $detailsJson
				]);
			} catch (Throwable $e) {}
		} else {
			$file = $this->getCacheFilePath($url);
			$data = [
				'score' => $score,
				'timestamp' => $now,
				'first_check' => $firstCheck,
				'details' => $detailsJson
			];
			@file_put_contents($file, json_encode($data), LOCK_EX);
		}
	}

	private function cleanUrl(string $url): string {
		$parsed = parse_url($url);
		if (!$parsed || !isset($parsed['query'])) return $url;
		
		parse_str($parsed['query'], $params);
		$badKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'ref'];
		foreach ($badKeys as $k) {
			unset($params[$k]);
		}
		
		$cleanUrl = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
		if (isset($parsed['port'])) $cleanUrl .= ':' . $parsed['port'];
		if (isset($parsed['path'])) $cleanUrl .= $parsed['path'];
		if (!empty($params)) $cleanUrl .= '?' . http_build_query($params);
		if (isset($parsed['fragment'])) $cleanUrl .= '#' . $parsed['fragment'];
		
		return $cleanUrl;
	}

	private function fetchUrlsMulti(array $requests, int $timeout = 3, array $customHeaders = []): array {
		if (!function_exists('curl_multi_init')) {
			$results = [];
			foreach ($requests as $key => $url) {
				if (empty($url)) {
					$results[$key] = false;
					continue;
				}
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(2, $timeout));
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
				curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 (FreshRSS Heatmap)');
				if (!empty($customHeaders[$key])) {
					curl_setopt($ch, CURLOPT_HTTPHEADER, $customHeaders[$key]);
				}
				$response = curl_exec($ch);
				$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				$results[$key] = ($response !== false && $httpCode >= 200 && $httpCode < 400) ? $response : false;
			}
			return $results;
		}

		$mh = curl_multi_init();
		$chList = [];
		$results = [];

		foreach ($requests as $key => $url) {
			if (empty($url)) {
				$results[$key] = false;
				continue;
			}
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(2, $timeout));
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 (FreshRSS Heatmap)');
			if (!empty($customHeaders[$key])) {
				curl_setopt($ch, CURLOPT_HTTPHEADER, $customHeaders[$key]);
			}
			curl_multi_add_handle($mh, $ch);
			$chList[$key] = $ch;
			$results[$key] = false;
		}

		if (empty($chList)) {
			curl_multi_close($mh);
			return $results;
		}

		$active = null;
		do {
			$mrc = curl_multi_exec($mh, $active);
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);

		while ($active && $mrc == CURLM_OK) {
			if (curl_multi_select($mh) != -1) {
				do {
					$mrc = curl_multi_exec($mh, $active);
				} while ($mrc == CURLM_CALL_MULTI_PERFORM);
			}
		}

		foreach ($chList as $key => $ch) {
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			if ($httpCode >= 200 && $httpCode < 400 && empty($error)) {
				$results[$key] = curl_multi_getcontent($ch);
			}
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		
		curl_multi_close($mh);
		return $results;
	}

	public function calculateAndStoreScore(string $rawUrl, string $rawTitle, int $pubdate, bool $force = false): array {
		$url = $this->cleanUrl($rawUrl);
		$cleanTitle = trim(preg_replace('/["\r\n\t]+/', ' ', strip_tags(html_entity_decode($rawTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

		$originalHost = parse_url($url, PHP_URL_HOST);
		if ($originalHost) {
			$originalHost = strtolower(preg_replace('/^www\./', '', $originalHost));
		}

		$cache = $this->getCacheEntry($url);
		$now = time();
		$needsRefresh = false;
		$frozen = false;
		$firstCheck = $now;

		if (!$cache) {
			$needsRefresh = true;
		} else {
			$firstCheck = !empty($cache['first_check']) ? $cache['first_check'] : $cache['timestamp'];
			$lastCheck = $cache['timestamp'];
			
			$daysSinceFirst = ($now - $firstCheck) / 86400;
			$daysSincePub = ($now - $pubdate) / 86400;
			$hoursSinceLast = ($now - $lastCheck) / 3600;
			
			if ($force) {
				$needsRefresh = true;
			} elseif ($daysSinceFirst > 16) {
				$frozen = true;
				$needsRefresh = false;
			} elseif ($daysSinceFirst <= 5 && $daysSincePub <= 7) {
				if ($hoursSinceLast >= 24) $needsRefresh = true;
			} else {
				if ($hoursSinceLast >= 48) $needsRefresh = true;
			}
		}

		if (!$needsRefresh && $cache) {
			return [
				'url' => $url,
				'score' => round(floatval($cache['score']), 1),
				'details' => isset($cache['details']) ? json_decode($cache['details'], true) : [],
				'cached' => true,
				'frozen' => $frozen
			];
		}

		$userConf = FreshRSS_Context::$user_conf;
		$enableHN = $userConf->heatmap_enable_hn ?? true;
		$enableReddit = $userConf->heatmap_enable_reddit ?? true;
		$enableSearx = $userConf->heatmap_enable_searxng ?? true;
		$enableHister = $userConf->heatmap_enable_hister ?? false;
		$enableBsky = $userConf->heatmap_enable_bsky ?? true;
		$enableWikipedia = $userConf->heatmap_enable_wikipedia ?? true;
		$enableLobsters = $userConf->heatmap_enable_lobsters ?? true;
		$enableLemmy = $userConf->heatmap_enable_lemmy ?? false;
		$enableWayback = $userConf->heatmap_enable_wayback ?? true;
		$enableGithub = $userConf->heatmap_enable_github ?? false;

		$searxBaseUrl = trim($userConf->heatmap_searxng_url ?? '');
		$histerBaseUrl = trim($userConf->heatmap_hister_url ?? '');
		$histerToken = trim($userConf->heatmap_hister_token ?? '');
		$githubToken = trim($userConf->heatmap_github_token ?? '');
		
		$timeout = intval($userConf->heatmap_timeout ?? 3);
		if ($timeout < 1 || $timeout > 10) $timeout = 3;

		$degrees = 98.6;
		$scale = 1.25;
		$encodedUrl = urlencode($url);
		
		$details = [
			'hn' => ['points' => 0, 'mentions' => 0],
			'reddit' => ['ups' => 0, 'mentions' => 0],
			'searxng' => ['sources' => 0],
			'hister' => ['matches' => 0],
			'bsky' => ['reposts' => 0, 'likes' => 0],
			'wikipedia' => ['citations' => 0],
			'lobsters' => ['score' => 0, 'comments' => 0],
			'lemmy' => ['score' => 0, 'comments' => 0],
			'wayback' => ['archived' => false],
			'github' => ['mentions' => 0],
			'discussion_url' => ''
		];

		$requests = [];
		$headers = [];
		
		if ($enableHN) {
			$requests['hn'] = "https://hn.algolia.com/api/v1/search?query={$encodedUrl}&restrictSearchableAttributes=url";
		}
		if ($enableReddit) {
			$requests['reddit'] = "https://www.reddit.com/api/info.json?url={$encodedUrl}";
		}
		if ($enableSearx && !empty($searxBaseUrl)) {
			$q = !empty($cleanTitle) ? '"' . $cleanTitle . '"' : $url;
			$requests['searxng'] = rtrim($searxBaseUrl, '/') . "/search?q=" . urlencode($q) . "&format=json";
		}
		if ($enableHister && !empty($histerBaseUrl)) {
			$hQ = !empty($cleanTitle) ? '"' . $cleanTitle . '"' : $url;
			$requests['hister'] = rtrim($histerBaseUrl, '/') . "/api/search?q=" . urlencode($hQ);
			$headers['hister'] = !empty($histerToken) ? ['Authorization: Bearer ' . $histerToken, 'Accept: application/json'] : ['Accept: application/json'];
		}
		if ($enableBsky) {
			$requests['bsky'] = "https://public.api.bsky.app/xrpc/app.bsky.feed.searchPosts?q={$encodedUrl}&limit=25";
		}
		if ($enableWikipedia && !empty($originalHost)) {
			$parsedUrl = parse_url($url);
			$path = $parsedUrl['path'] ?? '';
			$euquery = $originalHost . $path;
			$requests['wikipedia_fr'] = "https://fr.wikipedia.org/w/api.php?action=query&list=exturlusage&euquery=" . urlencode($euquery) . "&format=json";
			$requests['wikipedia_en'] = "https://en.wikipedia.org/w/api.php?action=query&list=exturlusage&euquery=" . urlencode($euquery) . "&format=json";
		}
		if ($enableLobsters) {
			$requests['lobsters'] = "https://lobste.rs/search.json?q={$encodedUrl}&what=stories";
		}
		if ($enableLemmy) {
			$requests['lemmy'] = "https://lemmy.world/api/v3/post/list?sort=TopAll&page=1&limit=5&q={$encodedUrl}";
		}
		if ($enableWayback) {
			$requests['wayback'] = "https://archive.org/wayback/available?url={$encodedUrl}";
		}
		if ($enableGithub) {
			$requests['github'] = "https://api.github.com/search/issues?q={$encodedUrl}+in:body";
			if (!empty($githubToken)) {
				$headers['github'] = ['Authorization: token ' . $githubToken, 'User-Agent: FreshRSS-Heatmap'];
			}
		}

		$responses = $this->fetchUrlsMulti($requests, $timeout, $headers);

		$fallbackRequests = [];
		$hnData = !empty($responses['hn']) ? json_decode($responses['hn'], true) : null;
		if ($enableHN && empty($hnData['hits']) && !empty($cleanTitle)) {
			$fallbackRequests['hn'] = "https://hn.algolia.com/api/v1/search?query=" . urlencode($cleanTitle) . "&tags=story&hitsPerPage=3";
		}
		$redditData = !empty($responses['reddit']) ? json_decode($responses['reddit'], true) : null;
		if ($enableReddit && empty($redditData['data']['children']) && !empty($cleanTitle)) {
			$fallbackRequests['reddit'] = "https://www.reddit.com/search.json?q=" . urlencode('"' . $cleanTitle . '"') . "&sort=relevance&limit=3";
		}
		$bskyData = !empty($responses['bsky']) ? json_decode($responses['bsky'], true) : null;
		if ($enableBsky && empty($bskyData['posts']) && !empty($cleanTitle)) {
			$fallbackRequests['bsky'] = "https://public.api.bsky.app/xrpc/app.bsky.feed.searchPosts?q=" . urlencode('"' . $cleanTitle . '"') . "&limit=10";
		}
		$lobstersData = !empty($responses['lobsters']) ? json_decode($responses['lobsters'], true) : null;
		if ($enableLobsters && empty($lobstersData) && !empty($cleanTitle)) {
			$fallbackRequests['lobsters'] = "https://lobste.rs/search.json?q=" . urlencode($cleanTitle) . "&what=stories";
		}

		if (!empty($fallbackRequests)) {
			$fallbackResponses = $this->fetchUrlsMulti($fallbackRequests, $timeout);
			if (isset($fallbackResponses['hn']) && $fallbackResponses['hn'] !== false) {
				$hnData = json_decode($fallbackResponses['hn'], true);
			}
			if (isset($fallbackResponses['reddit']) && $fallbackResponses['reddit'] !== false) {
				$redditData = json_decode($fallbackResponses['reddit'], true);
			}
			if (isset($fallbackResponses['bsky']) && $fallbackResponses['bsky'] !== false) {
				$bskyData = json_decode($fallbackResponses['bsky'], true);
			}
			if (isset($fallbackResponses['lobsters']) && $fallbackResponses['lobsters'] !== false) {
				$lobstersData = json_decode($fallbackResponses['lobsters'], true);
			}
		}

		$topDiscussionUrl = '';
		$topDiscussionPoints = 0;

		if (isset($hnData['hits']) && is_array($hnData['hits'])) {
			$hnHits = $hnData['hits'];
			usort($hnHits, fn($a, $b) => intval($b['points'] ?? 0) <=> intval($a['points'] ?? 0));
			$weight = 0;
			foreach ($hnHits as $hit) {
				$points = intval($hit['points'] ?? 0);
				if ($points > 0) {
					$details['hn']['points'] += $points;
					$details['hn']['mentions']++;
					$degrees += (log10($points + 10) * $scale) / pow(2, $weight);
					if ($points > $topDiscussionPoints && !empty($hit['objectID'])) {
						$topDiscussionPoints = $points;
						$topDiscussionUrl = 'https://news.ycombinator.com/item?id=' . $hit['objectID'];
					}
					$weight++;
				}
			}
		}

		if (isset($redditData['data']['children']) && is_array($redditData['data']['children'])) {
			$subreddits = [];
			foreach ($redditData['data']['children'] as $post) {
				$sub = $post['data']['subreddit'] ?? 'unknown';
				$ups = intval($post['data']['ups'] ?? 0);
				$subreddits[$sub][] = $ups;
				$details['reddit']['ups'] += $ups;
				$details['reddit']['mentions']++;
				if ($ups > $topDiscussionPoints && !empty($post['data']['permalink'])) {
					$topDiscussionPoints = $ups;
					$topDiscussionUrl = 'https://www.reddit.com' . $post['data']['permalink'];
				}
			}
			foreach ($subreddits as $sub => $upsList) {
				rsort($upsList);
				foreach ($upsList as $weight => $ups) {
					$degrees += (log10($ups + 10) * $scale) / pow(2, $weight);
				}
			}
		}

		if ($enableSearx && !empty($responses['searxng'])) {
			$searxData = json_decode($responses['searxng'], true);
			if (isset($searxData['results']) && is_array($searxData['results'])) {
				$domains = [];
				foreach ($searxData['results'] as $result) {
					if (empty($result['url'])) continue;
					$host = parse_url($result['url'], PHP_URL_HOST);
					if ($host) {
						$host = strtolower(preg_replace('/^www\./', '', $host));
						if (!empty($originalHost) && $host === $originalHost) continue;
						$domains[$host][] = 1;
					}
				}
				foreach ($domains as $host => $mentions) {
					$details['searxng']['sources']++;
					$credibility = $this->multipliers[$host] ?? 1.0;
					foreach ($mentions as $weight => $val) {
						$degrees += ($scale * $credibility) / pow(2, $weight);
					}
				}
			}
		}

		if ($enableHister && !empty($responses['hister'])) {
			$histerData = json_decode($responses['hister'], true);
			$hResults = $histerData['results'] ?? $histerData['documents'] ?? (is_array($histerData) ? $histerData : []);
			$hCount = count($hResults);
			if ($hCount > 0) {
				$details['hister']['matches'] = $hCount;
				$degrees += 2.5 + (log10($hCount + 10) * $scale);
			}
		}

		if (isset($bskyData['posts']) && is_array($bskyData['posts'])) {
			$totalEngage = 0;
			foreach ($bskyData['posts'] as $post) {
				$reposts = intval($post['repostCount'] ?? 0);
				$likes = intval($post['likeCount'] ?? 0);
				$replies = intval($post['replyCount'] ?? 0);
				$details['bsky']['reposts'] += $reposts;
				$details['bsky']['likes'] += $likes;
				$postScore = ($reposts * 2) + $likes + $replies;
				$totalEngage += $postScore;
				if ($postScore > $topDiscussionPoints && !empty($post['uri'])) {
					$topDiscussionPoints = $postScore;
					$parts = explode('/', $post['uri']);
					$rkey = end($parts);
					$author = $post['author']['handle'] ?? '';
					if ($author) {
						$topDiscussionUrl = "https://bsky.app/profile/{$author}/post/{$rkey}";
					}
				}
			}
			if ($totalEngage > 0) {
				$degrees += log10($totalEngage + 10) * $scale;
			}
		}

		if ($enableWikipedia) {
			$citations = 0;
			if (!empty($responses['wikipedia_fr'])) {
				$wData = json_decode($responses['wikipedia_fr'], true);
				if (!empty($wData['query']['exturlusage'])) $citations += count($wData['query']['exturlusage']);
			}
			if (!empty($responses['wikipedia_en'])) {
				$wData = json_decode($responses['wikipedia_en'], true);
				if (!empty($wData['query']['exturlusage'])) $citations += count($wData['query']['exturlusage']);
			}
			if ($citations > 0) {
				$details['wikipedia']['citations'] = $citations;
				$degrees += log10($citations + 10) * $scale * 2.0;
			}
		}

		if (is_array($lobstersData) && !empty($lobstersData)) {
			$weight = 0;
			foreach ($lobstersData as $story) {
				$storyScore = intval($story['score'] ?? 0);
				$commentCount = intval($story['comment_count'] ?? 0);
				$details['lobsters']['score'] += $storyScore;
				$details['lobsters']['comments'] += $commentCount;
				$degrees += (log10($storyScore + $commentCount + 10) * $scale) / pow(2, $weight);
				if ($storyScore > $topDiscussionPoints && !empty($story['comments_url'])) {
					$topDiscussionPoints = $storyScore;
					$topDiscussionUrl = $story['comments_url'];
				}
				$weight++;
			}
		}

		if ($enableLemmy && !empty($responses['lemmy'])) {
			$lemmyData = json_decode($responses['lemmy'], true);
			if (isset($lemmyData['posts']) && is_array($lemmyData['posts'])) {
				$weight = 0;
				foreach ($lemmyData['posts'] as $postView) {
					$counts = $postView['counts'] ?? [];
					$lScore = intval($counts['score'] ?? 0);
					$lComments = intval($counts['comments'] ?? 0);
					$details['lemmy']['score'] += $lScore;
					$details['lemmy']['comments'] += $lComments;
					$degrees += (log10($lScore + $lComments + 10) * $scale) / pow(2, $weight);
					$weight++;
				}
			}
		}

		if ($enableWayback && !empty($responses['wayback'])) {
			$wbData = json_decode($responses['wayback'], true);
			if (!empty($wbData['archived_snapshots']['closest']['available'])) {
				$details['wayback']['archived'] = true;
				$degrees += 1.5;
			}
		}

		if ($enableGithub && !empty($responses['github'])) {
			$ghData = json_decode($responses['github'], true);
			$ghCount = intval($ghData['total_count'] ?? 0);
			if ($ghCount > 0) {
				$details['github']['mentions'] = $ghCount;
				$degrees += log10($ghCount + 10) * $scale;
			}
		}

		$details['discussion_url'] = $topDiscussionUrl;
		$finalScore = round($degrees, 1);
		$this->setCache($url, $finalScore, $firstCheck, $details);

		return [
			'url' => $url,
			'score' => $finalScore,
			'details' => $details,
			'cached' => false,
			'frozen' => $frozen
		];
	}

	public function scoreAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
			return;
		}
		
		$this->view->_layout(false);
		header('Content-Type: application/json');
		
		try {
			$rawUrl = Minz_Request::param('url', '');
			$rawTitle = Minz_Request::param('title', '');
			$pubdate = intval(Minz_Request::param('pubdate', 0));
			$force = (bool)Minz_Request::param('force', 0);
			
			if (empty($rawUrl)) {
				echo json_encode(['error' => 'No URL provided']);
				return;
			}

			$result = $this->calculateAndStoreScore($rawUrl, $rawTitle, $pubdate, $force);
			echo json_encode($result);
		} catch (Throwable $e) {
			echo json_encode([
				'url' => Minz_Request::param('url', ''),
				'score' => 98.6,
				'cached' => false,
				'frozen' => false,
				'error' => $e->getMessage()
			]);
		}
	}
}
