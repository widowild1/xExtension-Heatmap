const BATCH_DEBOUNCE_MS = 150;
const BATCH_CHUNK_SIZE = 15;

const getCsrfToken = () => {
    if (typeof context !== 'undefined' && context && context.csrf) {
        return context.csrf;
    }
    const csrfInput = document.querySelector('input[name="_csrf"]');
    return csrfInput ? csrfInput.value : '';
};

const getI18n = () => {
    const el = document.getElementById('heatmap-i18n');
    if (el) {
        try {
            return JSON.parse(el.textContent);
        } catch (e) {}
    }
    return {
        sortByHeat: '🔥 Trier par popularité',
        sorted: '🔥 Trié !',
        filterCold: '❄️ Masquer articles froids',
        showAll: '👁️ Tout afficher',
        noUrl: '🌡️ No URL',
        errData: '🌡️ Err Data',
        errFetch: '🌡️ Err Fetch',
        unit: 'F'
    };
};

let isColdFiltered = false;
let batchQueue = [];
let batchTimer = null;
let batchSeq = 0;

const updateBadgeUI = (badge, fahrenheitScore, frozen, details, i18n) => {
    const numericScore = parseFloat(fahrenheitScore);
    let displayScore = numericScore;
    let symbol = '°F';

    if (i18n.unit === 'C') {
        displayScore = (numericScore - 32) * (5 / 9);
        symbol = '°C';
    }

    let emoji = '😊';
    let className = 'ext-heatmap-cold';

    if (numericScore >= 104) {
        emoji = '🌋';
        className = 'ext-heatmap-viral';
    } else if (numericScore >= 101) {
        emoji = '🔥';
        className = 'ext-heatmap-hot';
    } else if (numericScore > 98.6) {
        emoji = '🤒';
        className = 'ext-heatmap-warm';
    }

    const isCold = numericScore <= 98.6;
    badge.className = `ext-heatmap-badge ${className}`;
    badge.dataset.score = String(numericScore);
    badge.dataset.isCold = isCold ? 'true' : 'false';

    let text = `${emoji} ${displayScore.toFixed(1)}${symbol}`;
    if (frozen) {
        text += ' 🛑';
    }
    badge.innerText = text;

    if (isColdFiltered && isCold) {
        const entry = badge.closest('.flux');
        if (entry) {
            entry.style.display = 'none';
        }
    }

    if (details && typeof details === 'object') {
        if (details.discussion_url) {
            badge.dataset.discussionUrl = details.discussion_url;
        }

        const lines = [];
        if (details.hister && details.hister.matches > 0) {
            lines.push(`🔖 Hister: ${details.hister.matches} élément(s) dans votre index`);
        }
        if (details.hn && details.hn.mentions > 0) {
            lines.push(`HackerNews: ${details.hn.points} pts (${details.hn.mentions} posts)`);
        }
        if (details.reddit && details.reddit.mentions > 0) {
            lines.push(`Reddit: ${details.reddit.ups} upvotes (${details.reddit.mentions} posts)`);
        }
        if (details.searxng && details.searxng.sources > 0) {
            lines.push(`SearXNG: ${details.searxng.sources} sources web`);
        }
        if (details.bsky && (details.bsky.reposts > 0 || details.bsky.likes > 0)) {
            lines.push(`Bluesky: ${details.bsky.reposts} reposts, ${details.bsky.likes} likes`);
        }
        if (details.wikipedia && details.wikipedia.citations > 0) {
            lines.push(`Wikipédia: ${details.wikipedia.citations} citations`);
        }
        if (details.lobsters && (details.lobsters.score > 0 || details.lobsters.comments > 0)) {
            lines.push(`Lobste.rs: ${details.lobsters.score} pts (${details.lobsters.comments} comm.)`);
        }
        if (details.lemmy && (details.lemmy.score > 0 || details.lemmy.comments > 0)) {
            lines.push(`Lemmy: ${details.lemmy.score} pts (${details.lemmy.comments} comm.)`);
        }
        if (details.github && details.github.mentions > 0) {
            lines.push(`GitHub: ${details.github.mentions} mentions`);
        }
        if (details.wayback && details.wayback.archived) {
            lines.push('Wayback Machine: Présent dans les archives');
        }

        if (details.discussion_url) {
            lines.push('\n🔗 Astuce : Ctrl + Clic pour ouvrir la discussion');
        }

        badge.setAttribute('title', lines.length > 0 ? lines.join('\n') : 'Aucune mention externe trouvée.');
    }
};

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

    const csrfToken = getCsrfToken();
    const fetchUrl = `?c=heatmap&a=scores&_csrf=${encodeURIComponent(csrfToken)}`;

    fetch(fetchUrl, {
        method: 'POST',
        credentials: 'same-origin',
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

const enqueueBadge = (badge) => {
    if (badge.dataset.loaded === 'true') return;
    badge.dataset.loaded = 'true';

    const i18n = getI18n();
    if (!badge.dataset.url) {
        badge.innerText = i18n.noUrl;
        return;
    }

    badge.innerText = '🌡️ ...';
    batchQueue.push(badge);

    if (!batchTimer) {
        batchTimer = setTimeout(flushBatchQueue, BATCH_DEBOUNCE_MS);
    }
};

const forceRecalculateBadge = (badge) => {
    const i18n = getI18n();
    const url = badge.dataset.url;
    const pubdate = badge.dataset.pubdate || 0;
    const title = badge.dataset.title || '';

    if (!url) {
        badge.innerText = i18n.noUrl;
        return;
    }

    badge.dataset.loaded = 'true';
    badge.innerText = '🌡️ ...';

    const fetchUrl = `?c=heatmap&a=score&url=${encodeURIComponent(url)}&pubdate=${pubdate}&title=${encodeURIComponent(title)}&force=1`;

    fetch(fetchUrl, { credentials: 'same-origin' })
        .then((response) => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then((data) => {
            if (data && data.score !== undefined) {
                updateBadgeUI(badge, data.score, Boolean(data.frozen), data.details, i18n);
            } else {
                badge.dataset.loaded = 'false';
                badge.innerText = i18n.errData;
            }
        })
        .catch((err) => {
            console.error('Heatmap refresh error:', err);
            badge.dataset.loaded = 'false';
            badge.innerText = i18n.errFetch;
        });
};

let badgeObserver = null;
if ('IntersectionObserver' in window) {
    badgeObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                const badge = entry.target;
                observer.unobserve(badge);
                enqueueBadge(badge);
            }
        });
    }, { rootMargin: '250px 0px' });
}

const initBadges = () => {
    const elements = document.querySelectorAll('.ext-heatmap-badge:not([data-observed="true"])');
    elements.forEach((badge) => {
        badge.dataset.observed = 'true';
        badge.onclick = (e) => {
            e.preventDefault();
            e.stopPropagation();
            if ((e.ctrlKey || e.metaKey) && badge.dataset.discussionUrl) {
                window.open(badge.dataset.discussionUrl, '_blank', 'noopener,noreferrer');
                return;
            }
            forceRecalculateBadge(badge);
        };
        if (badgeObserver) {
            badgeObserver.observe(badge);
        } else {
            enqueueBadge(badge);
        }
    });
};

const sortFeedsByHeat = () => {
    const entries = Array.from(document.querySelectorAll('.flux'));
    if (entries.length === 0) return;

    const parent = entries[0].parentNode;

    entries.sort((a, b) => {
        const badgeA = a.querySelector('.ext-heatmap-badge');
        const badgeB = b.querySelector('.ext-heatmap-badge');

        const scoreA = (badgeA && badgeA.dataset.score !== undefined) ? parseFloat(badgeA.dataset.score) : -1;
        const scoreB = (badgeB && badgeB.dataset.score !== undefined) ? parseFloat(badgeB.dataset.score) : -1;

        return scoreB - scoreA;
    });

    document.querySelectorAll('.day, .transition').forEach((el) => {
        el.style.display = 'none';
    });

    const footer = parent.querySelector('.stream-footer');
    entries.forEach((entry) => {
        if (footer) {
            parent.insertBefore(entry, footer);
        } else {
            parent.appendChild(entry);
        }
    });

    const i18n = getI18n();
    const btnNav = document.getElementById('ext-heatmap-sort-btn-nav');
    if (btnNav) btnNav.innerText = i18n.sorted;
};

const toggleFilterCold = () => {
    isColdFiltered = !isColdFiltered;
    const i18n = getI18n();
    const btnFilter = document.getElementById('ext-heatmap-filter-btn-nav');

    document.querySelectorAll('.flux').forEach((entry) => {
        const badge = entry.querySelector('.ext-heatmap-badge');
        if (badge && badge.dataset.isCold === 'true') {
            entry.style.display = isColdFiltered ? 'none' : '';
        }
    });

    if (btnFilter) {
        btnFilter.innerText = isColdFiltered ? i18n.showAll : i18n.filterCold;
    }
};

const addNavbarControls = () => {
    const navMenu = document.querySelector('#nav_menu');
    if (!navMenu) return;

    const i18n = getI18n();

    if (!document.getElementById('ext-heatmap-sort-btn-nav')) {
        const sortLi = document.createElement('li');
        sortLi.className = 'item';
        const sortBtn = document.createElement('a');
        sortBtn.id = 'ext-heatmap-sort-btn-nav';
        sortBtn.innerText = i18n.sortByHeat;
        sortBtn.onclick = (e) => {
            e.preventDefault();
            sortFeedsByHeat();
        };
        sortLi.appendChild(sortBtn);
        navMenu.appendChild(sortLi);
    }

    if (!document.getElementById('ext-heatmap-filter-btn-nav')) {
        const filterLi = document.createElement('li');
        filterLi.className = 'item';
        const filterBtn = document.createElement('a');
        filterBtn.id = 'ext-heatmap-filter-btn-nav';
        filterBtn.innerText = isColdFiltered ? i18n.showAll : i18n.filterCold;
        filterBtn.onclick = (e) => {
            e.preventDefault();
            toggleFilterCold();
        };
        filterLi.appendChild(filterBtn);
        navMenu.appendChild(filterLi);
    }
};

initBadges();
addNavbarControls();

document.addEventListener('DOMContentLoaded', () => {
    initBadges();
    addNavbarControls();
});

const setupObserver = () => {
    if (!document.body) {
        setTimeout(setupObserver, 100);
        return;
    }
    const observer = new MutationObserver(() => {
        initBadges();
        if ((!document.getElementById('ext-heatmap-sort-btn-nav') || !document.getElementById('ext-heatmap-filter-btn-nav')) && document.querySelector('#nav_menu')) {
            addNavbarControls();
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
};
setupObserver();
