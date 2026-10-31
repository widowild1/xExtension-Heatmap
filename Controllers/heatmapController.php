<?php

class FreshExtension_heatmap_Controller extends Minz_ActionController {
	
	private $db = null;
	private $multipliers = [];
	private $cacheDir = null;
	private $useSqlite = false;
	
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
			return;
		}
		
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
		if ($this->useSqlite &&$this->db) {
			try {
				$stmt =$this->db->prepare("SELECT score, timestamp, first_check, details FROM cache WHERE url = :url");
				$stmt->execute([':url' =>$url]);
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

	private function setCache(string $url, float $score, int$firstCheck, array $details): void {$now = time();
		$detailsJson = json_encode($details);
		if ($this->useSqlite &&$this->db) {
			try {
				$stmt =$this->db->prepare("INSERT INTO cache (url, score, timestamp, first_check, details) VALUES (:url, :score, :timestamp, :first_check, :details)
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
			$file =$this->getCacheFilePath($url);$data = [
				'score' => $score,
				'timestamp' => $now,
				'first_check' => $firstCheck,
				'details' => $detailsJson
			];
			@file_put_contents($file, json_encode($data), LOCK_EX);
		}
	}

	private function fetchUrl(string $url, int$timeout = 3) {
		if (!function_exists('curl_init')) return false;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT,$timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(2,$timeout));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 (FreshRSS Heatmap)');
		
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $httpCode < 200 ||$httpCode >= 400) {
			return false;
		}
		return $response;
	}

	// Nettoyage des paramètres d'URL pour optimiser la correspondance sur les API
	private function cleanUrl(string $url): string {
		$parsed = parse_url($url);
		if (!$parsed || !isset($parsed['query'])) return$url;
		
		parse_str($parsed['query'], $params);$badKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'ref'];
		foreach ($badKeys as$k) {
			unset($params[$k]);
		}
		
		$cleanUrl = $parsed['scheme'] . '://' .$parsed['host'];
		if (isset($parsed['port'])) $cleanUrl .= ':' .$parsed['port'];
		if (isset($parsed['path'])) $cleanUrl .=$parsed['path'];
		if (!empty($params)) $cleanUrl .= '?' . http_build_query($params);
		if (isset($parsed['fragment'])) $cleanUrl .= '#' . $parsed['fragment'];
		
		return $cleanUrl;
	}

	public function scoreAction(): void {
		$this->view->_layout(false);
		header('Content-Type: application/json');
		
		try {
			$rawUrl = Minz_Request::param('url', '');
			$pubdate = intval(Minz_Request::param('pubdate', 0));$force = Minz_Request::param('force', 0);
			
			if (empty($rawUrl)) {
				echo json_encode(['error' => 'No URL provided']);
				return;
			}

			$url = $this->cleanUrl($rawUrl);

			$cache = $this->getCacheEntry($url);
			$now = time();$needsRefresh = false;
			$frozen = false;
			$firstCheck =$now;

			if (!$cache) {$needsRefresh = true;
			} else {
				$firstCheck = !empty($cache['first_check']) ?$cache['first_check'] : $cache['timestamp'];$lastCheck = $cache['timestamp'];$daysSinceFirst = ($now -$firstCheck) / 86400;
				$daysSincePub = ($now -$pubdate) / 86400;
				$hoursSinceLast = ($now -$lastCheck) / 3600;
				
				if ($force) {$needsRefresh = true;
				} elseif ($daysSinceFirst > 16) {$frozen = true;
					$needsRefresh = false;
				} elseif ($daysSinceFirst <= 5 &&$daysSincePub <= 7) {
					if ($hoursSinceLast >= 24) {$needsRefresh = true;
					}
				} else {
					if ($hoursSinceLast >= 48) {$needsRefresh = true;
					}
				}
			}

			if (!$needsRefresh &&$cache) {
				echo json_encode([
					'url' => $url,
					'score' => round(floatval($cache['score']), 1),
					'details' => isset($cache['details']) ? json_decode($cache['details'], true) : [],
					'cached' => true,
					'frozen' => $frozen
				]);
				return;
			}

			$userConf = FreshRSS_Context::$user_conf;
			$enableHN =$userConf->heatmap_enable_hn ?? true;
			$enableReddit =$userConf->heatmap_enable_reddit ?? true;
			$enableSearx =$userConf->heatmap_enable_searxng ?? true;
			$searxBaseUrl = trim($userConf->heatmap_searxng_url ?? '');
			$timeout = intval($userConf->heatmap_timeout ?? 3);
			if ($timeout < 1 || $timeout > 10)$timeout = 3;

			$degrees = 98.6;
			$scale = 1.25;
			$encodedUrl = urlencode($url);$details = [
				'hn' => ['points' => 0, 'mentions' => 0],
				'reddit' => ['ups' => 0, 'mentions' => 0],
				'searxng' => ['sources' => 0]
			];

			// 1. Hacker News
			if ($enableHN) {
				$hnRaw =$this->fetchUrl("https://hn.algolia.com/api/v1/search?query={$encodedUrl}&restrictSearchableAttributes=url", $timeout);
				if ($hnRaw) {
					$hnData = json_decode($hnRaw, true);
					if (isset($hnData['hits']) && is_array($hnData['hits'])) {
						$hnHits =$hnData['hits'];
						usort($hnHits, fn($a, $b) => intval($b['points'] ?? 0) <=> intval($a['points'] ?? 0));$weight = 0;
						foreach ($hnHits as$hit) {
							$points = intval($hit['points'] ?? 0);
							if ($points > 0) {
								$details['hn']['points'] +=$points;
								$details['hn']['mentions']++;$post_score = log10($points + 10) *$scale;
								$degrees +=$post_score / pow(2, $weight);$weight++;
							}
						}
					}
				}
			}

			// 2. Reddit
			if ($enableReddit) {
				$redditRaw =$this->fetchUrl("https://www.reddit.com/api/info.json?url={$encodedUrl}", $timeout);
				if ($redditRaw) {
					$redditData = json_decode($redditRaw, true);
					if (isset($redditData['data']['children']) && is_array($redditData['data']['children'])) {$subreddits = [];
						foreach ($redditData['data']['children'] as $post) {$sub = $post['data']['subreddit'] ?? 'unknown';$ups = intval($post['data']['ups'] ?? 0);$subreddits[$sub][] =$ups;
							$details['reddit']['ups'] +=$ups;
							$details['reddit']['mentions']++;
						}
						foreach ($subreddits as $sub =>$upsList) {
							rsort($upsList);
							foreach ($upsList as$weight => $ups) {$post_score = log10($ups + 10) *$scale;
								$degrees += $post_score / pow(2,$weight);
							}
						}
					}
				}
			}

			// 3. SearXNG
			if ($enableSearx && !empty($searxBaseUrl)) {$searxApiUrl = rtrim($searxBaseUrl, '/') . "/search?q={$encodedUrl}&format=json";
				$searxRaw =$this->fetchUrl($searxApiUrl,$timeout);
				if ($searxRaw) {
					$searxData = json_decode($searxRaw, true);
					if (isset($searxData['results']) && is_array($searxData['results'])) {$domains = [];
						foreach ($searxData['results'] as$result) {
							if (empty($result['url'])) continue;
							$host = parse_url($result['url'], PHP_URL_HOST);
							if ($host) {
								$host = strtolower(str_replace('www.', '',$host));
								$domains[$host][] = 1;
							}
						}
						foreach ($domains as $host =>$mentions) {
							$details['searxng']['sources']++;$credibility = $this->multipliers[$host] ?? 1.0;
							foreach ($mentions as$weight => $val) {$post_score = $scale * $credibility;
								$degrees += $post_score / pow(2,$weight);
							}
						}
					}
				}
			}

			$finalScore = round($degrees, 1);$this->setCache($url,$finalScore, $firstCheck,$details);

			echo json_encode([
				'url' => $url,
				'score' => $finalScore,
				'details' => $details,
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
