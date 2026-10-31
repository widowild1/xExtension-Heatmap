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
    const title = badge.dataset.title || '';
    
    if (!url) {
        badge.innerText = i18n.noUrl;
        return;
    }

    badge.innerText = '🌡️ ...';

    let fetchUrl = `?c=heatmap&a=score&url=${encodeURIComponent(url)}&pubdate=${pubdate}&title=${encodeURIComponent(title)}`;
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
    
    if (details) {
        let lines = [];
        if (details.hister && details.hister.matches > 0) {
            lines.push(`🔖 Hister: ${details.hister.matches} élément(s) dans votre index personnel`);
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
            lines.push(`Wayback Machine: Présent dans les archives`);
        }
        
        badge.setAttribute('title', lines.length > 0 ? lines.join('\n') : 'Aucune mention externe trouvée.');
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
    const btnNav = document.getElementById('ext-heatmap-sort-btn-nav');
    if (btnNav) btnNav.innerText = i18n.sorted;
};

const addSortButtonToNav = () => {
    if (document.getElementById('ext-heatmap-sort-btn-nav')) return;
    
    const navMenu = document.querySelector('#nav_menu');
    if (!navMenu) return;
    
    const i18n = getI18n();
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
    
    const toggleViewBtn = navMenu.querySelector('.toggle_view');
    if (toggleViewBtn) {
        navMenu.insertBefore(sortLi, toggleViewBtn);
    } else {
        navMenu.appendChild(sortLi);
    }
};

initBadges();
addSortButtonToNav();

document.addEventListener('DOMContentLoaded', () => {
    initBadges();
    addSortButtonToNav();
});

const setupObserver = () => {
    if (!document.body) {
        setTimeout(setupObserver, 100);
        return;
    }
    const observer = new MutationObserver((mutations) => {
        initBadges();
        if (!document.getElementById('ext-heatmap-sort-btn-nav') && document.querySelector('#nav_menu')) {
            addSortButtonToNav();
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
};
setupObserver();
