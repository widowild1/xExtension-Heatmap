const processBadge = (badge, force = false) => {
    if (badge.dataset.loaded && !force) return;
    badge.dataset.loaded = 'true';
    
    const url = badge.dataset.url;
    const pubdate = badge.dataset.pubdate || 0;
    
    if (!url) {
        badge.innerText = '🌡️ No URL';
        return;
    }

    badge.innerText = force ? '🌡️ Refetching...' : '🌡️ Fetching...';

    let fetchUrl = `?c=heatmap&a=score&url=${encodeURIComponent(url)}&pubdate=${pubdate}`;
    if (force) fetchUrl += '&force=1';

    fetch(fetchUrl)
        .then(response => {
            if (!response.ok) throw new Error("HTTP " + response.status);
            return response.json();
        })
        .then(data => {
            if (data && data.score !== undefined) {
                updateBadgeUI(badge, data.score, data.frozen);
            } else {
                badge.innerText = '🌡️ Err Data';
            }
        })
        .catch(e => {
            console.error("Heatmap extension error:", e);
            badge.innerText = '🌡️ Err Fetch';
        });
};

const updateBadgeUI = (badge, score, frozen) => {
    let emoji = '❄️';
    let className = 'ext-heatmap-cold';
    
    if (score >= 104) {
        emoji = '🌋';
        className = 'ext-heatmap-viral';
    } else if (score >= 101) {
        emoji = '🔥';
        className = 'ext-heatmap-hot';
    } else if (score > 98.6) {
        emoji = '🤒';
        className = 'ext-heatmap-warm';
    } else {
        emoji = '😊';
    }

    badge.className = `ext-heatmap-badge ${className}`;
    
    // Add visual indicator if the score is frozen
    let text = `${emoji} ${score.toFixed(1)}°F`;
    if (frozen) {
        text += ' 🛑'; // Indicates it's no longer automatically updating
    }
    
    badge.innerText = text;
};

const initBadges = () => {
    const elements = document.querySelectorAll('.ext-heatmap-badge:not([data-loaded="true"])');
    if (elements.length > 0) {
        elements.forEach(badge => {
            badge.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                processBadge(badge, true);
            };
            processBadge(badge);
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
            const matchA = badgeA.innerText.match(/([\d.]+)°F/);
            if (matchA) scoreA = parseFloat(matchA[1]);
        }
        if (badgeB) {
            const matchB = badgeB.innerText.match(/([\d.]+)°F/);
            if (matchB) scoreB = parseFloat(matchB[1]);
        }
        
        return scoreB - scoreA;
    });
    
    // Hide date and transition dividers to remove empty gaps at the top
    document.querySelectorAll('.day, .transition').forEach(el => el.style.display = 'none');
    
    // Ensure we don't push items below the footer (Load More button)
    const footer = parent.querySelector('.stream-footer');
    
    // Reorder DOM
    entries.forEach(entry => {
        if (footer) {
            parent.insertBefore(entry, footer);
        } else {
            parent.appendChild(entry);
        }
    });
    
    const btn = document.getElementById('ext-heatmap-sort-btn');
    if (btn) btn.innerText = '🔥 Sorted!';
};

const addSortButton = () => {
    if (document.getElementById('ext-heatmap-sort-btn')) return;
    
    const sortBtn = document.createElement('button');
    sortBtn.id = 'ext-heatmap-sort-btn';
    sortBtn.innerText = '🔥 Sort by Heat';
    sortBtn.style.position = 'fixed';
    sortBtn.style.bottom = '20px';
    sortBtn.style.right = '20px';
    sortBtn.style.zIndex = '9999';
    sortBtn.style.padding = '12px 18px';
    sortBtn.style.borderRadius = '25px';
    sortBtn.style.boxShadow = '0 4px 12px rgba(0,0,0,0.3)';
    sortBtn.style.background = '#ff4500';
    sortBtn.style.color = '#fff';
    sortBtn.style.border = 'none';
    sortBtn.style.fontWeight = 'bold';
    sortBtn.style.cursor = 'pointer';
    sortBtn.onclick = (e) => {
        e.preventDefault();
        sortFeedsByHeat();
    };

    document.body.appendChild(sortBtn);
};

// Run immediately in case elements already exist
initBadges();
addSortButton();

// Also run on DOMContentLoaded just in case
document.addEventListener('DOMContentLoaded', () => {
    initBadges();
    addSortButton();
});

// Set up MutationObserver if body exists, otherwise wait
const setupObserver = () => {
    if (!document.body) {
        setTimeout(setupObserver, 100);
        return;
    }
    const observer = new MutationObserver(initBadges);
    observer.observe(document.body, { childList: true, subtree: true });
};
setupObserver();
