const flushBatchQueue = () => {
    batchTimer = null;
    if (batchQueue.length === 0) return;

    const chunk = batchQueue.splice(0, BATCH_CHUNK_SIZE);
    if (batchQueue.length > 0) {
        batchTimer = setTimeout(flushBatchQueue, BATCH_DEBOUNCE_MS);
    }

    const i18n = getI18n();
    const badgeMap = new Map();
    const itemsPayload = [];

    chunk.forEach((badge) => {
        const key = String(++batchSeq);
        badgeMap.set(key, badge);
        itemsPayload.push({
            key,
            url: badge.dataset.url || '',
            pubdate: parseInt(badge.dataset.pubdate || '0', 10) || 0,
            title: badge.dataset.title || ''
        });
    });

    // Récupération du jeton CSRF de la session FreshRSS (s'il existe)
    const csrfToken = (typeof context !== 'undefined' && context.csrf) ? context.csrf : '';
    const fetchUrl = `?c=heatmap&a=scores&_csrf=${encodeURIComponent(csrfToken)}`;

    fetch(fetchUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ items: itemsPayload })
    })
        .then((response) => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then((data) => {
            const results = (data && data.results && typeof data.results === 'object') ? data.results : {};
            const deferredBadges = [];

            badgeMap.forEach((badge, key) => {
                const res = results[key];
                if (res && res.deferred) {
                    badge.dataset.loaded = 'false';
                    deferredBadges.push(badge);
                } else if (res && res.score !== undefined) {
                    updateBadgeUI(badge, res.score, Boolean(res.frozen), res.details, i18n);
                } else {
                    badge.dataset.loaded = 'false';
                    badge.innerText = i18n.errData;
                }
            });

            if (deferredBadges.length > 0) {
                deferredBadges.forEach((b) => enqueueBadge(b));
            }
        })
        .catch((err) => {
            console.error('Heatmap batch error:', err);
            badgeMap.forEach((badge) => {
                badge.dataset.loaded = 'false';
                badge.innerText = i18n.errFetch;
            });
        });
};
