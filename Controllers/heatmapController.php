<?php

class FreshExtension_heatmap_Controller extends Minz_ActionController {
	
	private $db;
	private $multipliers;
	
	public function firstAction(): void {
		// Ensure only logged in users can access this API
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
			return;
		}
		
		$extPath = dirname(__DIR__);
		
		// Load credibility multipliers
		$multipliersPath = $extPath . '/credibility_multipliers.json';
		if (file_exists($multipliersPath)) {
			$this->multipliers = json_decode(file_get_contents($multipliersPath), true);
		} else {
			$this->multipliers = [];
		}
		
		// Initialize SQLite cache
		// IMPORTANT: Ensure the db file is writable by the web server
		$dbPath = $extPath . '/heatmap_cache.sqlite';
		$this->db = new PDO('sqlite:' . $dbPath);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->db->exec("CREATE TABLE IF NOT EXISTS cache (
			url TEXT PRIMARY KEY,
			score REAL,
			timestamp INTEGER,
			first_check INTEGER
		)");
		
		// Migration for existing users (ignore exception if column already exists)
		try {
			$this->db->exec("ALTER TABLE cache ADD COLUMN first_check INTEGER");
			$this->db->exec("UPDATE cache SET first_check = timestamp WHERE first_check IS NULL");
		} catch (PDOException $e) {}

		// Garbage Collection: 1% chance to delete cached items that haven't been checked in 60 days
		// This keeps the DB lean as old posts are naturally trashed by FreshRSS
		if (rand(1, 100) === 1) {
			$this->db->exec("DELETE FROM cache WHERE timestamp < " . (time() - 86400 * 60));
		}
	}

	private function getCacheEntry($url) {
		$stmt = $this->db->prepare("SELECT score, timestamp, first_check FROM cache WHERE url = :url");
		$stmt->execute([':url' => $url]);
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	private function setCache($url, $score, $firstCheck) {
		$stmt = $this->db->prepare("INSERT INTO cache (url, score, timestamp, first_check) VALUES (:url, :score, :timestamp, :first_check)
			ON CONFLICT(url) DO UPDATE SET score=excluded.score, timestamp=excluded.timestamp");
		$stmt->execute([
			':url' => $url,
			':score' => $score,
			':timestamp' => time(),
			':first_check' => $firstCheck
		]);
	}

	private function fetchUrl($url) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3);
		curl_setopt($ch, CURLOPT_USERAGENT, 'FreshRSS-Heatmap-Extension/1.0');
		$response = curl_exec($ch);
		curl_close($ch);
		return $response;
	}

	public function scoreAction(): void {
		$this->view->_layout(false);
		header('Content-Type: application/json');
		
		$url = Minz_Request::param('url', '');
		$pubdate = intval(Minz_Request::param('pubdate', 0));
		$force = Minz_Request::param('force', 0);
		
		if (empty($url)) {
			echo json_encode(['error' => 'No URL provided']);
			return;
		}

		$cache = $this->getCacheEntry($url);
		$now = time();
		$needsRefresh = false;
		$frozen = false;
		$firstCheck = $now;

		if (!$cache) {
			$needsRefresh = true;
		} else {
			$firstCheck = $cache['first_check'] ?: $cache['timestamp'];
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
				if ($hoursSinceLast >= 24) {
					$needsRefresh = true;
				}
			} else {
				if ($hoursSinceLast >= 48) {
					$needsRefresh = true;
				}
			}
		}

		if (!$needsRefresh && $cache) {
			echo json_encode(['url' => $url, 'score' => round($cache['score'], 1), 'cached' => true, 'frozen' => $frozen]);
			return;
		}

		// FEVER MATH: Base Temp is 98.6, Scale is 1.25
		$degrees = 98.6;
		$scale = 1.25;
		$encodedUrl = urlencode($url);

		// 2. HackerNews (Algolia)
		$hnData = json_decode($this->fetchUrl("https://hn.algolia.com/api/v1/search?query={$encodedUrl}&restrictSearchableAttributes=url"), true);
		if (isset($hnData['hits'])) {
			$hnHits = $hnData['hits'];
			usort($hnHits, fn($a, $b) => intval($b['points']) <=> intval($a['points']));
			$weight = 0;
			foreach ($hnHits as $hit) {
				$points = intval($hit['points']);
				$post_score = log10($points + 10) * $scale;
				$degrees += $post_score / pow(2, $weight);
				$weight++;
			}
		}

		// 3. Reddit API
		$redditData = json_decode($this->fetchUrl("https://www.reddit.com/api/info.json?url={$encodedUrl}"), true);
		if (isset($redditData['data']['children'])) {
			$subreddits = [];
			foreach ($redditData['data']['children'] as $post) {
				$sub = $post['data']['subreddit'];
				$subreddits[$sub][] = intval($post['data']['ups']);
			}
			foreach ($subreddits as $sub => $upsList) {
				rsort($upsList);
				foreach ($upsList as $weight => $ups) {
					$post_score = log10($ups + 10) * $scale;
					$degrees += $post_score / pow(2, $weight);
				}
			}
		}

		// 4. SearXNG API
		$searxBaseUrl = FreshRSS_Context::$user_conf->heatmap_searxng_url ?? '';

		if (!empty($searxBaseUrl)) {
			$searxApiUrl = rtrim($searxBaseUrl, '/') . "/search?q={$encodedUrl}&format=json";
			$searxRaw = $this->fetchUrl($searxApiUrl);
			if ($searxRaw) {
			    $searxData = json_decode($searxRaw, true);

			    if (isset($searxData['results']) && is_array($searxData['results'])) {
				    $domains = [];
				    foreach ($searxData['results'] as $result) {
					    if (empty($result['url'])) {
						    continue;
					    }
					    $host = parse_url($result['url'], PHP_URL_HOST);
					    if ($host) {
						    $host = strtolower(str_replace('www.', '', $host));
						    $domains[$host][] = 1;
					    }
				    }
				    
				    foreach ($domains as $host => $mentions) {
					    $credibility = $this->multipliers[$host] ?? 1.0;
					    foreach ($mentions as $weight => $val) {
						    $post_score = $scale * $credibility;
						    $degrees += $post_score / pow(2, $weight);
					    }
				    }
			    }
            }
		}

		$finalScore = round($degrees, 1);

		// Save to cache
		$this->setCache($url, $finalScore, $firstCheck);

		echo json_encode([
			'url' => $url,
			'score' => $finalScore,
			'cached' => false,
			'frozen' => $frozen
		]);
	}
}
