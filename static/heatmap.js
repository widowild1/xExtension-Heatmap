const getI18n = () => {
    const el = document.getElementById('heatmap-i18n');
    if (el) {
        try {
            return JSON.parse(el.textContent);
        } catch (e) {}
    }
    return {
        sortByHeat: '🔥 Sort by Heat',
        sorted: '🔥 Sorted!',
        noUrl: '🌡️ No URL',
        errData: '🌡️ Err Data',
        errFetch: '🌡️ Err Fetch',
        unit: 'F'
    };
};

const processBadge = (badge, force = false) => {
    if (badge.dataset.loaded === 'true' && !force) return;
    badge.dataset.loaded = 'true';
    
    const i18n = getI18n();
    const url = badge.dataset.url;
    const pubdate = badge.dataset.pubdate || 0;
    
    if (!url) {
        badge.innerText = i18n.noUrl;
        return;
    }

    badge.innerText = '🌡️ ...';

    let fetchUrl = `?c=heatmap&a=score&url=${encodeURIComponent(url)}&pubdate=${pubdate}`;
    if (force) fetchUrl += '&force=1';

    fetch(fetchUrl)
        .then(response => {
            if (!response.ok) throw new Error("HTTP " + response.status);
            return response.json();
        })
        .then(data => {
            if (data && data.score !== undefined) {
                updateBadgeUI(badge, data.score, data.frozen, data.details, i18n);
            } else {
                badge.innerText = i18n.errData;
            }
        })
        .catch(e => {
            console.error("Heatmap extension error:", e);
            badge.innerText = i18n.errFetch;
        });
};

const updateBadgeUI = (badge, fahrenheitScore, frozen, details, i18n) => {
    let displayScore = fahrenheitScore;
    let symbol = '°F';
    
    // Conversion Celsius : C = (F - 32) * 5/9
    if (i18n.unit === 'C') {
        displayScore = (fahrenheitScore - 32) * (5/9);
        symbol = '°C';
    }
    
    let emoji = '❄️';
    let className = 'ext-heatmap-cold';
    
    if (fahrenheitScore >= 104) {
        emoji = '🌋';
        className = 'ext-heatmap-viral';
    } else if (fahrenheitScore >= 101) {
        emoji = '🔥';
        className = 'ext-heatmap-hot';
    } else if (fahrenheitScore > 98.6) {
        emoji = '🤒';
        className = 'ext-heatmap-warm';
    } else {
        emoji = '😊';
    }

    badge.className = `ext-heatmap-badge ${className}`;
    
    let text = `${emoji} ${displayScore.toFixed(1)}${symbol}`;
    if (frozen) {
        text += ' 🛑';
    }
    badge.innerText = text;
    
    // Infobulle détaillée au survol
    if (details) {
        let title = '';
        if (details.hn && details.hn.mentions > 0) {
            title += `HackerNews: ${details.hn.points} pts (${details.hn.mentions} mentions)\n`;
        }
        if (details.reddit && details.reddit.mentions > 0) {
            title += `Reddit: ${details.reddit.ups} upvotes (${details.reddit.mentions} posts)\n`;
        }
        if (details.searxng && details.searxng.sources > 0) {
            title += `SearXNG: ${details.searxng.sources} sources\n`;
        }
        if (!title) {
            title = 'Aucune mention externe trouvée pour le moment.';
        }
        badge.setAttribute('title', title.trim());
    }
};

let badgeObserver = null;
if ('IntersectionObserver' in window) {
    badgeObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const badge = entry.target;
                observer.unobserve(badge);
                processBadge(badge);
            }
        });
    }, { rootMargin: '200px 0px' });
}

const initBadges = () => {
    const elements = document.querySelectorAll('.ext-heatmap-badge:not([data-observed="true"])');
    if (elements.length > 0) {
        elements.forEach(badge => {
            badge.dataset.observed = 'true';
            badge.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                processBadge(badge, true);
            };
            if (badgeObserver) {
                badgeObserver.observe(badge);
            } else {
                processBadge(badge);
            }
        });
    }
};

const sortFeedsByHeat = () => {
    const entries = Array.from(document.querySelectorAll('.flux'));
    if (entries.length === 0) return;
    
    const parent = entries[0].parentNode;
    
    entries.sort((a, b) => {
        const badgeA = a.querySelector('.ext-heatmap-badge');
        const badgeB = b.querySelector('.ext-heatmap-badge');
        
        let scoreA = -1;
        let scoreB = -1;
        
        if (badgeA) {
            const matchA = badgeA.innerText.match(/([\d.]+)(°C|°F)/);
            if (matchA) scoreA = parseFloat(matchA[1]);
        }
        if (badgeB) {
            const matchB = badgeB.innerText.match(/([\d.]+)(°C|°F)/);
            if (matchB) scoreB = parseFloat(matchB[1]);
        }
        
        return scoreB - scoreA;
    });
    
    document.querySelectorAll('.day, .transition').forEach(el => el.style.display = 'none');
    
    const footer = parent.querySelector('.stream-footer');
    entries.forEach(entry => {
        if (footer) {
            parent.insertBefore(entry, footer);
        } else {
            parent.appendChild(entry);
        }
    });
    
    const i18n = getI18n();
    const btn = document.getElementById('ext-heatmap-sort-btn');
    if (btn) btn.innerText = i18n.sorted;
};

const addSortButton = () => {
    if (document.getElementById('ext-heatmap-sort-btn')) return;
    
    const i18n = getI18n();
    const sortBtn = document.createElement('button');
    sortBtn.id = 'ext-heatmap-sort-btn';
    sortBtn.innerText = i18n.sortByHeat;
    sortBtn.style.position = 'fixed';
    sortBtn.style.bottom = '20px';
    sortBtn.style.right = '20px';
    sortBtn.style.zIndex = '9999';
    sortBtn.style.padding = '10px 16px';
    sortBtn.style.borderRadius = '20px';
    sortBtn.style.boxShadow = '0 3px 10px rgba(0,0,0,0.25)';
    sortBtn.style.background = '#ff4500';
    sortBtn.style.color = '#fff';
    sortBtn.style.border = 'none';
    sortBtn.style.fontWeight = 'bold';
    sortBtn.style.fontSize = '13px';
    sortBtn.style.cursor = 'pointer';
    sortBtn.onclick = (e) => {
        e.preventDefault();
        sortFeedsByHeat();
    };

    document.body.appendChild(sortBtn);
};

initBadges();
addSortButton();

document.addEventListener('DOMContentLoaded', () => {
    initBadges();
    addSortButton();
});

const setupObserver = () => {
    if (!document.body) {
        setTimeout(setupObserver, 100);
        return;
    }
    const observer = new MutationObserver(initBadges);
    observer.observe(document.body, { childList: true, subtree: true });
};
setupObserver();
