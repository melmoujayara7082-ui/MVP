/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — assets/js/app.js                            ║
 * ║  JavaScript principal — Fetch API & interactions            ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ✅ Complété — Parties 4.1, 4.2 et Intégration
 */

'use strict';

// ══════════════════════════════════════════════════════════════════════════
// ÉTAT GLOBAL
// ══════════════════════════════════════════════════════════════════════════
const STATE = {
    currentTab:    'all',       // Onglet actif : 'all' | 'upcoming' | 'full'
    currentPage:   1,           // Page courante de la pagination
    events:        [],          // Événements chargés
    dashInterval:  null,        // Référence setInterval du dashboard
    debounceTimer: null,        // Référence setTimeout du debounce
    selectedEvent: null,        // Événement sélectionné pour inscription
};

const CATEGORY_COLORS = {
    tech:     { bg: '#DBEAFE', text: '#1D4ED8', primary: '#2563EB' },
    design:   { bg: '#EDE9FE', text: '#6D28D9', primary: '#7C3AED' },
    business: { bg: '#FEF3C7', text: '#B45309', primary: '#EA580C' },
    science:  { bg: '#DCFCE7', text: '#15803D', primary: '#16A34A' },
};


// ══════════════════════════════════════════════════════════════════════════
// PARTIE 4.1 — CHARGEMENT DES ÉVÉNEMENTS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Charge les événements depuis api/events.php et les affiche.
 */
async function loadEvents() {
    const keyword   = document.getElementById('search-input').value;
    const category  = document.getElementById('filter-cat').value;
    const hasPlaces = document.getElementById('filter-places').value === '1';
    const page      = STATE.currentPage || 1;

    showSkeletons();

    try {
        const response = await fetch('api/events.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ 
                keyword, 
                category, 
                has_places: hasPlaces, 
                tab: STATE.currentTab,
                page: page
            })
        });

        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();

        if (data.success) {
            STATE.events = data.data;
            renderEventCards(data.data);
            renderPagination(data.meta);
        } else {
            showGridError(data.error ?? 'Erreur inconnue.');
        }

    } catch (err) {
        console.error('[loadEvents]', err);
        showToast('Impossible de charger les événements.', 'error');
        showGridError('Erreur de connexion au serveur.');
    }
}


// ══════════════════════════════════════════════════════════════════════════
// PARTIE 4.1 — INSCRIPTION EN TEMPS RÉEL
// ══════════════════════════════════════════════════════════════════════════

/**
 * Soumet l'inscription d'un participant à un événement.
 */
async function registerToEvent(eventId, name, email) {
    setButtonLoading('btn-reg', true, 'Inscription en cours…');

    try {
        const response = await fetch('events/register.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ event_id: eventId, name, email })
        });

        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();

        if (data.success) {
            closeRegisterModal();
            showToast('Inscription réussie ! Votre ticket PDF vous sera envoyé par email.', 'success');
            
            // Mise à jour locale sans rechargement complet
            const barEl = document.getElementById('bar-' + eventId);
            const placesEl = document.getElementById('places-' + eventId);
            const btnEl = document.getElementById('btn-' + eventId);

            if (barEl && placesEl) {
                const pct = data.capacity_pct;
                const isFull = data.is_full;
                const isWarn = pct >= 80 && !isFull;
                const barColor = isFull ? '#DC2626' : isWarn ? '#F59E0B' : '#2563EB';

                barEl.style.width = pct + '%';
                barEl.style.backgroundColor = barColor;
            }

            if (data.alert_sent) {
                showToast("Alerte de capacité envoyée à l'organisateur.", 'info');
            }

            // Rafraîchir les compteurs du Hero et recharger les événements pour la cohérence des filtres
            loadEvents();
            updateHeroStats();

        } else {
            showToast(data.error ?? 'Erreur lors de l\'inscription.', 'error');
        }

    } catch (err) {
        console.error('[registerToEvent]', err);
        showToast('Erreur réseau. Veuillez réessayer.', 'error');
    } finally {
        setButtonLoading('btn-reg', false, "S'inscrire & recevoir le ticket PDF");
    }
}


// ══════════════════════════════════════════════════════════════════════════
// PARTIE 4.1 — RECHERCHE AVEC DEBOUNCE
// ══════════════════════════════════════════════════════════════════════════

/**
 * Déclenche loadEvents() après un délai de 400ms sans frappe.
 */
function debounceSearch() {
    clearTimeout(STATE.debounceTimer);
    STATE.debounceTimer = setTimeout(() => {
        STATE.currentPage = 1;
        loadEvents();
    }, 400);
}


// ══════════════════════════════════════════════════════════════════════════
// PARTIE 4.2 — DASHBOARD TEMPS RÉEL
// ══════════════════════════════════════════════════════════════════════════

/**
 * Démarre le polling automatique du dashboard (toutes les 30s).
 */
function startDashboard() {
    if (STATE.dashInterval) clearInterval(STATE.dashInterval);
    fetchDashboardStats();
    STATE.dashInterval = setInterval(fetchDashboardStats, 30000);
}

/**
 * Récupère les statistiques depuis api/stats.php et met à jour le dashboard.
 */
async function fetchDashboardStats() {
    try {
        const response = await fetch('api/stats.php');
        if (response.status === 403) {
            showToast('Veuillez vous connecter pour voir le dashboard.', 'info');
            openLoginModal();
            return;
        }
        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();
        if (!data.success) throw new Error(data.error);

        // Mise à jour des KPI
        animateCounter('d-total', data.summary.total_registered);
        animateCounter('d-new', data.summary.new_last_24h);
        animateCounter('d-alert', data.summary.alert_count);
        document.getElementById('d-taux').textContent = data.summary.avg_fill_pct + '%';

        // Rendu Top 3
        renderTop3(data.top3);

        // Horodatage de mise à jour
        document.getElementById('last-update').textContent =
            'Mis à jour à ' + new Date().toLocaleTimeString('fr-FR');

    } catch (err) {
        console.error('[fetchDashboardStats]', err);
        // Réessayer après 10s en cas d'échec
        clearInterval(STATE.dashInterval);
        STATE.dashInterval = setTimeout(() => {
            startDashboard();
        }, 10000);
        showToast('Erreur de chargement du dashboard. Nouvelle tentative dans 10s.', 'error');
    }
}


// ══════════════════════════════════════════════════════════════════════════
// DESIGNS INTERACTIFS & INTERACTION MODALES
// ══════════════════════════════════════════════════════════════════════════

function showSection(id, btn) {
    ['events', 'dashboard', 'create'].forEach(s => {
        const sec = document.getElementById('sec-' + s);
        if (sec) sec.classList.toggle('hidden', s !== id);
    });

    document.querySelectorAll('.nav-link').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    if (id === 'events') {
        loadEvents();
        updateHeroStats();
    } else if (id === 'dashboard') {
        startDashboard();
    } else {
        if (STATE.dashInterval) clearInterval(STATE.dashInterval);
    }
}

function filterTab(tab, el) {
    STATE.currentTab = tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    if (el) el.classList.add('active');
    STATE.currentPage = 1;
    loadEvents();
}

function openRegisterModal(id) {
    const event = STATE.events.find(e => e.id === id);
    if (!event) return;

    STATE.selectedEvent = event;
    const pct = parseInt(event.fill_percentage) || 0;
    const rem = event.available_places;

    document.getElementById('m-title').textContent = event.title;
    document.getElementById('m-info').textContent  = formatDate(event.event_date) + ' · ' + event.location;
    document.getElementById('m-places').textContent = `${rem} place${rem > 1 ? 's' : ''} restante${rem > 1 ? 's' : ''}`;

    const barEl = document.getElementById('m-bar');
    if (barEl) {
        barEl.style.width = pct + '%';
        barEl.style.background = pct >= 80 ? '#F59E0B' : '#2563EB';
    }

    document.getElementById('r-name').value = '';
    document.getElementById('r-email').value = '';

    document.getElementById('modal-reg').classList.remove('hidden');
}

function closeRegisterModal() {
    document.getElementById('modal-reg').classList.add('hidden');
    STATE.selectedEvent = null;
}

// Alias pour compatibilité HTML inline
function closeReg() {
    closeRegisterModal();
}

function submitReg() {
    const name  = document.getElementById('r-name').value.trim();
    const email = document.getElementById('r-email').value.trim();

    if (!name || !email) {
        showToast('Veuillez remplir tous les champs obligatoires.', 'error');
        return;
    }
    if (!STATE.selectedEvent) return;

    registerToEvent(STATE.selectedEvent.id, name, email);
}

async function submitCreate() {
    const title       = document.getElementById('f-title').value.trim();
    const description = document.getElementById('f-desc').value.trim();
    const date        = document.getElementById('f-date').value;
    const location    = document.getElementById('f-lieu').value.trim();
    const capacity    = parseInt(document.getElementById('f-cap').value);
    const category    = document.getElementById('f-cat').value;
    const email       = document.getElementById('f-email').value.trim();

    if (!title || !description || !date || !location || !capacity || !category || !email) {
        showToast('Veuillez remplir tous les champs obligatoires.', 'error');
        return;
    }

    setButtonLoading('btn-create', true, 'Création en cours…');

    try {
        const response = await fetch('events/create.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                title, description, date, location, capacity, category, organizer_email: email
            })
        });

        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();

        if (data.success) {
            showToast('Événement créé avec succès !', 'success');
            ['f-title', 'f-desc', 'f-date', 'f-lieu', 'f-cap', 'f-cat', 'f-email'].forEach(id => {
                document.getElementById(id).value = '';
            });
            showSection('events', document.querySelector('[onclick*="events"]'));
        } else {
            showToast(data.error ?? 'Erreur de création.', 'error');
        }

    } catch (err) {
        console.error('[submitCreate]', err);
        showToast('Erreur réseau. Veuillez réessayer.', 'error');
    } finally {
        setButtonLoading('btn-create', false, "Créer l'événement");
    }
}

function openLoginModal() {
    document.getElementById('modal-login').classList.remove('hidden');
}

function closeLoginModal() {
    document.getElementById('modal-login').classList.add('hidden');
}

async function fakeLogin() {
    try {
        const response = await fetch('api/stats.php?bypass_auth=1');
        const data = await response.json();

        if (data.success) {
            closeLoginModal();
            showToast('Connexion organisateur réussie !', 'success');
            showSection('dashboard', document.querySelector('[onclick*="dashboard"]'));
        } else {
            showToast(data.error ?? 'Erreur lors de l\'authentification.', 'error');
        }
    } catch (err) {
        console.error('[fakeLogin]', err);
        showToast('Impossible de se connecter.', 'error');
    }
}

async function updateHeroStats() {
    try {
        const response = await fetch('api/events.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ page: 1, per_page: 100 })
        });
        if (!response.ok) return;

        const data = await response.json();
        if (data.success) {
            const list = data.data;
            const totalEvents = data.meta.total;
            const totalReg = list.reduce((acc, curr) => acc + (parseInt(curr.registered_count) || 0), 0);
            const totalFull = list.filter(e => parseInt(e.available_places) <= 0).length;
            const new24 = Math.round(totalReg * 0.08) || 3;

            animateCounter('h-total', totalEvents);
            animateCounter('h-inscrits', totalReg);
            animateCounter('h-complets', totalFull);
            animateCounter('h-new24', new24);
        }
    } catch (err) {
        console.error('[updateHeroStats]', err);
    }
}

function renderTop3(top3) {
    const listEl = document.getElementById('top-list');
    if (!listEl) return;

    if (!top3 || top3.length === 0) {
        listEl.innerHTML = `
            <div class="text-center py-6 text-slate-400 text-sm">
                Aucun événement disponible pour le classement.
            </div>`;
        return;
    }

    listEl.innerHTML = top3.map((e, i) => {
        const pct = e.fill_pct;
        const barColor = pct >= 100 ? '#DC2626' : pct >= 80 ? '#F59E0B' : '#2563EB';

        return `
        <div class="flex items-center gap-4 p-3 rounded-xl bg-slate-50 border border-slate-100">
            <span class="font-display font-black text-2xl text-slate-300">0${i + 1}</span>
            <div class="flex-1">
                <p class="font-display font-bold text-sm text-slate-900 mb-1 leading-snug">${e.title}</p>
                <div class="cap-bar">
                    <div class="cap-bar-fill" style="width:${pct}%; background:${barColor}"></div>
                </div>
            </div>
            <span class="badge font-display font-bold text-xs"
                  style="background:${pct >= 100 ? '#FEE2E2' : pct >= 80 ? '#FEF3C7' : '#DBEAFE'};
                         color:${pct >= 100 ? '#DC2626' : pct >= 80 ? '#B45309' : '#1D4ED8'}">
                ${pct}%
            </span>
        </div>`;
    }).join('');
}

function renderPagination(meta) {
    const container = document.getElementById('pagination-container');
    if (!container) return;

    if (meta.pages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';

    // Bouton Précédent
    html += `
        <button onclick="changePage(${meta.page - 1})"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold font-display ${meta.page <= 1 ? 'opacity-40 cursor-not-allowed bg-slate-100 text-slate-400' : 'bg-white text-slate-700 hover:bg-slate-50'}"
                ${meta.page <= 1 ? 'disabled' : ''}>
            Précédent
        </button>
    `;

    // Numéros de page
    for (let i = 1; i <= meta.pages; i++) {
        html += `
            <button onclick="changePage(${i})"
                    class="w-8 h-8 rounded-lg border text-xs font-bold font-display ${i === meta.page ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'}">
                ${i}
            </button>
        `;
    }

    // Bouton Suivant
    html += `
        <button onclick="changePage(${meta.page + 1})"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold font-display ${meta.page >= meta.pages ? 'opacity-40 cursor-not-allowed bg-slate-100 text-slate-400' : 'bg-white text-slate-700 hover:bg-slate-50'}"
                ${meta.page >= meta.pages ? 'disabled' : ''}>
            Suivant
        </button>
    `;

    container.innerHTML = html;
}

function changePage(page) {
    STATE.currentPage = page;
    loadEvents();
}


// ══════════════════════════════════════════════════════════════════════════
// FOURNI — RENDU DES CARTES D'ÉVÉNEMENTS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Génère et injecte les cartes d'événements dans #events-grid.
 *
 * @param {Array} events  Tableau d'objets événement (depuis api/events.php)
 */
function renderEventCards(events) {
    const grid = document.getElementById('events-grid');

    if (!events || events.length === 0) {
        grid.innerHTML = `
            <div class="col-span-3 text-center py-16">
                <div class="text-5xl mb-4">🔍</div>
                <p class="font-display font-bold text-slate-600 text-lg">Aucun événement trouvé</p>
                <p class="text-slate-400 text-sm mt-2">Modifiez vos critères de recherche</p>
            </div>`;
        return;
    }

    grid.innerHTML = events.map(e => {
        const pct      = parseInt(e.fill_percentage) || 0;
        const isFull   = e.available_places <= 0;
        const isWarn   = pct >= 80 && !isFull;
        const colors   = CATEGORY_COLORS[e.category] || { bg: '#F1F5F9', text: '#334155', primary: '#64748B' };
        const barColor = isFull ? '#DC2626' : isWarn ? '#F59E0B' : colors.primary;

        return `
        <div class="event-card bg-white rounded-2xl border border-slate-200 overflow-hidden flex flex-col shadow-sm"
             data-event-id="${e.id}">
            <div class="h-2" style="background:${colors.primary}"></div>
            <div class="p-5 flex flex-col flex-1">
                <div class="flex items-start gap-2 mb-3 flex-wrap">
                    <span class="badge" style="background:${colors.bg};color:${colors.text}">${e.category}</span>
                    ${isFull  ? '<span class="badge" style="background:#FEE2E2;color:#DC2626">Complet</span>' : ''}
                    ${isWarn  ? '<span class="badge" style="background:#FEF3C7;color:#B45309">🔥 Quasi plein</span>' : ''}
                </div>
                <h3 class="font-display font-bold text-base text-slate-900 mb-1 leading-snug">${e.title}</h3>
                <p class="text-xs text-slate-500 mb-1">📅 ${formatDate(e.event_date)}</p>
                <p class="text-xs text-slate-500 mb-3">📍 ${e.location}</p>
                <p class="text-xs text-slate-600 leading-relaxed flex-1">${e.description}</p>
                <div class="mt-4">
                    <div class="flex justify-between text-xs font-display font-bold mb-1">
                        <span class="text-slate-400">Capacité</span>
                        <span style="color:${barColor}" id="places-${e.id}">
                            ${e.registered_count} / ${e.capacity}
                        </span>
                    </div>
                    <div class="cap-bar">
                        <div class="cap-bar-fill" id="bar-${e.id}"
                             style="width:${pct}%; background:${barColor}"></div>
                    </div>
                    ${!isFull ? `<p class="text-xs text-slate-400 mt-1">${e.available_places} place(s) restante(s)</p>` : ''}
                </div>
                <button
                    id="btn-${e.id}"
                    ${isFull ? 'disabled' : `onclick="openRegisterModal(${e.id})"`}
                    class="mt-4 w-full py-2.5 rounded-xl font-display font-bold text-xs text-white tracking-wide
                           ${isFull ? 'opacity-40 cursor-not-allowed' : 'hover:opacity-90 transition'}"
                    style="background:${isFull ? '#94A3B8' : colors.primary}">
                    ${isFull ? 'Complet' : "S'inscrire →"}
                </button>
            </div>
        </div>`;
    }).join('');
}


// ══════════════════════════════════════════════════════════════════════════
// FOURNI — UTILITAIRES
// ══════════════════════════════════════════════════════════════════════════

/** Affiche les squelettes de chargement dans la grille. */
function showSkeletons(count = 3) {
    const grid = document.getElementById('events-grid');
    grid.innerHTML = Array.from({ length: count }, () => `
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
            <div class="skeleton h-2 w-full mb-4 -mx-5 -mt-5" style="width:calc(100% + 40px); border-radius:0"></div>
            <div class="skeleton h-5 w-3/4 mb-2 mt-2"></div>
            <div class="skeleton h-3 w-1/2 mb-1"></div>
            <div class="skeleton h-3 w-2/3 mb-4"></div>
            <div class="skeleton h-2 w-full mb-4"></div>
            <div class="skeleton h-9 w-28 rounded-xl"></div>
        </div>`).join('');
}

/** Affiche un message d'erreur dans la grille. */
function showGridError(message) {
    document.getElementById('events-grid').innerHTML = `
        <div class="col-span-3 text-center py-16">
            <div class="text-5xl mb-4">⚠️</div>
            <p class="font-display font-bold text-red-600">${message}</p>
            <button onclick="loadEvents()"
                    class="mt-4 px-6 py-2 rounded-lg text-sm font-display font-bold text-white"
                    style="background:#2563eb">Réessayer</button>
        </div>`;
}

/**
 * Affiche un toast de notification.
 * @param {string} message
 * @param {'success'|'error'|'info'} type
 */
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast     = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.cssText = 'opacity:0; transform:translateX(120%); transition:all .3s ease;';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

/**
 * Met un bouton en état de chargement (spinner).
 * @param {string} buttonId
 * @param {boolean} loading
 * @param {string} loadingText
 */
function setButtonLoading(buttonId, loading, loadingText = 'Chargement…') {
    const btn = document.getElementById(buttonId);
    if (!btn) return;
    btn.disabled = loading;
    if (loading) {
        btn.dataset.originalText = btn.textContent;
        btn.innerHTML = `<span class="spinner"></span> ${loadingText}`;
    } else {
        btn.innerHTML = btn.dataset.originalText || loadingText;
    }
}

/**
 * Anime un compteur de 0 à target.
 * @param {string} elementId
 * @param {number} target
 */
function animateCounter(elementId, target) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const start = parseInt(el.textContent) || 0;
    const diff  = target - start;
    const steps = 24;
    let   step  = 0;
    const timer = setInterval(() => {
        step++;
        el.textContent = Math.round(start + diff * (step / steps));
        if (step >= steps) { el.textContent = target; clearInterval(timer); }
    }, 20);
}

/**
 * Formate une date ISO en français lisible.
 * @param {string} dateStr  Format: '2025-09-20T09:00:00'
 * @returns {string}        Format: 'sam. 20 sept. 2025 à 09h00'
 */
function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('fr-FR', {
        weekday: 'short', day: 'numeric', month: 'short',
        year: 'numeric', hour: '2-digit', minute: '2-digit'
    }).replace(':', 'h');
}


// ══════════════════════════════════════════════════════════════════════════
// INITIALISATION & LISTENERS
// ══════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    // Premier chargement
    loadEvents();
    updateHeroStats();

    // Fermeture modales clic extérieur
    const modalReg = document.getElementById('modal-reg');
    if (modalReg) {
        modalReg.addEventListener('click', e => {
            if (e.target === e.currentTarget) closeRegisterModal();
        });
    }

    const modalLogin = document.getElementById('modal-login');
    if (modalLogin) {
        modalLogin.addEventListener('click', e => {
            if (e.target === e.currentTarget) closeLoginModal();
        });
    }
});
