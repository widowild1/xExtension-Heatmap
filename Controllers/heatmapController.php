<?php

class FreshExtension_heatmap_Controller extends Minz_ActionController {
	
	private $db = null;
	private $multipliers = [];
	private $cacheFile = null;
	private $fileCache = null;
	
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
			return;
		}
		
		$extPath = dirname(__DIR__);
		
		// 1. Multiplicateurs de crédibilité
		$multipliersPath = $extPath . '/credibility_multipliers.json';
		if (file_exists($multipliersPath)) {
			$this->multipliers = json_decode(file_get_contents($multipliersPath), true) ?: [];
		}

		// Dossier de cache privilégié
		$cacheDir = (defined('DATA_PATH') && is_dir(DATA_PATH . '/cache') && is_writable(DATA_PATH . '/cache'))
			? DATA_PATH . '/cache'
			: (is_writable($extPath) ? $extPath : sys_get_temp_dir());

		// 2. Initialisation du cache : SQLite si le driver PDO SQLite est installé, sinon Fallback JSON
		if (extension_loaded('pdo_sqlite') && class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers())) {
			try {
				$dbPath = $cacheDir . '/heatmap_cache.sqlite';
				$this->db = new PDO('sqlite:' . $dbPath);
				$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				$this->db->exec("CREATE TABLE IF NOT EXISTS cache (
					url TEXT PRIMARY KEY,
					score REAL,
					timestamp INTEGER,
					first_check INTEGER
				)");

				try {
					$this->db->exec("ALTER TABLE cache ADD COLUMN first_check INTEGER");
					$this->db->exec("UPDATE cache SET first_check = timestamp WHERE first_check IS NULL");
				} catch (PDOException $e) {}

				if (rand(1, 100) === 1) {
					$this->db->exec("DELETE FROM cache WHERE timestamp < " . (time() - 86400 * 60));
				}
			} catch (Throwable $e) {
				error_log('Heatmap Extension SQLite Error: ' . $e->getMessage());
				$this->db = null;
			}
		}

		// Fallback fichier JSON si SQLite n'est pas disponible ou a échoué
		if (!$this->db) {
			$this->cacheFile = $cacheDir . '/heatmap_cache.json';
			if (file_exists($this->cacheFile)) {
				$this->fileCache = json_decode(file_get_contents($this->cacheFile), true) ?: [];
			} else {
				$this->fileCache = [];
			}
		}
	}

	private function getCacheEntry($url) {
		if ($this->db) {
			try {
				$stmt = $this->db->prepare("SELECT score, timestamp, first_check FROM cache WHERE url = :url");
				$stmt->execute([':url' => $url]);
				return $stmt->fetch(PDO::FETCH_ASSOC);
			} catch (Throwable $e) {
				return false;
			}
		} elseif ($this->fileCache !== null && isset($this->fileCache[$url])) {
			return $this->fileCache[$url];
		}
		return false;
	}

	private function setCache($url, $score, $firstCheck) {
		if ($this->db) {
			try {
				$stmt = $this->db->prepare("INSERT INTO cache (url, score, timestamp, first_check) VALUES (:url, :score, :timestamp, :first_check)
					ON CONFLICT(url) DO UPDATE SET score=excluded.score, timestamp=excluded.timestamp");
				$stmt->execute([
					':url' => $url,
					':score' => $score,
					':timestamp' => time(),
					':first_check' => $firstCheck
				]);
			} catch (Throwable $e) {}
		} elseif ($this->cacheFile) {
			$this->fileCache[$url] = [
				'score' => $score,
				'timestamp' => time(),
				'first_check' => $firstCheck
			];
			@file_put_contents($this->cacheFile, json_encode($this->fileCache), LOCK_EX);
		}
	}

	private function fetchUrl($url) {
		if (!function_exists('curl_init')) {
			return false;
		}
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
		
		try {
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

			$degrees = 98.6;
			$scale = 1.25;
			$encodedUrl = urlencode($url);

			// 2. HackerNews (Algolia)
			$hnRaw = $this->fetchUrl("https://hn.algolia.com/api/v1/search?query={$encodedUrl}&restrictSearchableAttributes=url");
			if ($hnRaw) {
				$hnData = json_decode($hnRaw, true);
				if (isset($hnData['hits']) && is_array($hnData['hits'])) {
					$hnHits = $hnData['hits'];
					usort($hnHits, fn($a, $b) => intval($b['points'] ?? 0) <=> intval($a['points'] ?? 0));
					$weight = 0;
					foreach ($hnHits as $hit) {
						$points = intval($hit['points'] ?? 0);
						$post_score = log10($points + 10) * $scale;
						$degrees += $post_score / pow(2, $weight);
						$weight++;
					}
				}
			}

			// 3. Reddit API
			$redditRaw = $this->fetchUrl("https://www.reddit.com/api/info.json?url={$encodedUrl}");
			if ($redditRaw) {
				$redditData = json_decode($redditRaw, true);
				if (isset($redditData['data']['children']) && is_array($redditData['data']['children'])) {
					$subreddits = [];
					foreach ($redditData['data']['children'] as $post) {
						$sub = $post['data']['subreddit'] ?? 'unknown';
						$subreddits[$sub][] = intval($post['data']['ups'] ?? 0);
					}
					foreach ($subreddits as $sub => $upsList) {
						rsort($upsList);
						foreach ($upsList as $weight => $ups) {
							$post_score = log10($ups + 10) * $scale;
							$degrees += $post_score / pow(2, $weight);
						}
					}
				}
			}

			// 4. SearXNG API (dynamique)
			$searxBaseUrl = '';
			if (isset(FreshRSS_Context::$user_conf) && isset(FreshRSS_Context::$user_conf->heatmap_searxng_url)) {
				$searxBaseUrl = trim(FreshRSS_Context::$user_conf->heatmap_searxng_url);
			}

			if (!empty($searxBaseUrl)) {
				$searxApiUrl = rtrim($searxBaseUrl, '/') . "/search?q={$encodedUrl}&format=json";
				$searxRaw = $this->fetchUrl($searxApiUrl);
				if ($searxRaw) {
					$searxData = json_decode($searxRaw, true);
					if (isset($searxData['results']) && is_array($searxData['results'])) {
						$domains = [];
						foreach ($searxData['results'] as $result) {
							if (empty($result['url'])) continue;
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
			$this->setCache($url, $finalScore, $firstCheck);

			echo json_encode([
				'url' => $url,
				'score' => $finalScore,
				'cached' => false,
				'frozen' => $frozen
			]);
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
