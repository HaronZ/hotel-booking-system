const API_BASE = '/api';
let authToken = localStorage.getItem('token');
let currentUser = null;
let lastSearchParams = {};
let activeBookingRoom = null;

/* ---------------------------------------------------------------------
 * Tiny fetch wrapper: attaches the JWT (if we have one) and normalizes
 * Laravel's JSON error shape ({message, errors}) into a thrown Error.
 * ------------------------------------------------------------------- */
async function apiFetch(path, { method = 'GET', body = null, auth = true } = {}) {
    const headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    if (auth && authToken) headers.Authorization = `Bearer ${authToken}`;

    const res = await fetch(API_BASE + path, {
        method,
        headers,
        body: body ? JSON.stringify(body) : null,
    });

    let data = null;
    try { data = await res.json(); } catch (e) { /* empty body */ }

    if (!res.ok) {
        const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        const error = new Error(firstFieldError || data?.message || 'Something went wrong.');
        error.status = res.status;
        error.fieldErrors = data?.errors || null;
        throw error;
    }

    return data;
}

function formatMoney(value) {
    return '₱' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

/* The header wraps to two rows on narrow screens, so its height isn't a
 * fixed constant - measure it for real rather than guessing a value that's
 * only correct at desktop widths. */
function syncHeaderHeightVar() {
    const height = document.querySelector('.site-header').offsetHeight;
    document.documentElement.style.setProperty('--header-height', `${height}px`);
}
window.addEventListener('resize', syncHeaderHeightVar);

function toast(message, type = 'info') {
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = message;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

/**
 * Disables a submit button and swaps its label while an async action runs,
 * so double-clicking Search/Book/Save can't fire the request twice and the
 * user gets visible feedback that something is happening.
 */
function setButtonLoading(btn, isLoading, loadingText = 'Please wait…') {
    if (!btn) return;
    if (isLoading) {
        btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
        btn.textContent = loadingText;
        btn.disabled = true;
    } else {
        btn.textContent = btn.dataset.originalText || btn.textContent;
        btn.disabled = false;
    }
}

/** Renders every field error Laravel returned, not just the first one. */
function allValidationErrors(err) {
    if (!err.fieldErrors) return err.message;
    return Object.values(err.fieldErrors).flat().join(' ');
}

/* ============================== THEME (light / dark) ============================== */
/* The <head> inline script already applies any stored preference before first
 * paint. This just keeps the toggle button's icon in sync and handles clicks. */

function currentTheme() {
    const stored = localStorage.getItem('theme');
    if (stored) return stored;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function syncThemeToggleButton() {
    const isDark = currentTheme() === 'dark';
    const btn = document.getElementById('theme-toggle');
    btn.classList.toggle('is-dark', isDark);
    btn.setAttribute('aria-checked', String(isDark));
    btn.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
    btn.title = isDark ? 'Switch to light mode' : 'Switch to dark mode';
}

document.getElementById('theme-toggle').addEventListener('click', () => {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    localStorage.setItem('theme', next);
    document.documentElement.setAttribute('data-theme', next);
    syncThemeToggleButton();
});

// If the user never explicitly chose a theme, keep following the OS setting live.
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (!localStorage.getItem('theme')) syncThemeToggleButton();
});

/* ============================== CUSTOM DATE PICKER ============================== */
/* Replaces native <input type="date"> with a small styled calendar dropdown.
 * Each date field is a hidden <input id="..."> (so the rest of the app keeps
 * reading .value exactly like before) plus a visible trigger button that
 * opens ONE shared popup instance. */

const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const datePickerState = { targetInput: null, triggerEl: null, viewYear: 0, viewMonth: 0 };

function todayISO() {
    return toISO(new Date());
}

function parseISO(iso) {
    if (!iso) return null;
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
}

function toISO(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function formatDateDisplay(iso) {
    if (!iso) return 'Select date';
    return parseISO(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function getMinForDateField(input) {
    if (input.dataset.pairedMin) {
        const pairInput = document.getElementById(input.dataset.pairedMin);
        if (pairInput && pairInput.value) {
            // A paired field (e.g. check-out) must be strictly AFTER the
            // paired date (backend rule is after:check_in, not >=), so the
            // earliest allowed day is the paired date plus one.
            const d = parseISO(pairInput.value);
            d.setDate(d.getDate() + 1);
            return toISO(d);
        }
    }
    return todayISO();
}

/**
 * Sets a date field's value and its trigger's display text, fires a native
 * 'change' event, and clears any downstream field (e.g. check-out) that
 * would now fall before this date.
 */
function setDateValue(input, iso) {
    input.value = iso || '';
    const trigger = document.querySelector(`[data-date-trigger-for="${input.id}"]`);
    if (trigger) {
        trigger.querySelector('.date-display-text').textContent = formatDateDisplay(iso);
        trigger.classList.toggle('has-value', !!iso);
    }
    input.dispatchEvent(new Event('change'));

    document.querySelectorAll(`[data-paired-min="${input.id}"]`).forEach((downstream) => {
        // Downstream (e.g. check-out) must be strictly after this date now.
        if (downstream.value && iso && downstream.value <= iso) setDateValue(downstream, '');
    });
}

function positionDatePicker(triggerBtn) {
    const popup = document.getElementById('date-picker-popup');
    const rect = triggerBtn.getBoundingClientRect();
    const popupWidth = popup.offsetWidth || 296;
    const popupHeight = popup.offsetHeight || 330;

    let left = Math.min(rect.left, document.documentElement.clientWidth - popupWidth - 12);
    left = Math.max(12, left);

    let top = rect.bottom + 6;
    if (top + popupHeight > window.innerHeight - 12) top = rect.top - popupHeight - 6;
    top = Math.max(12, top);

    popup.style.left = `${left}px`;
    popup.style.top = `${top}px`;
}

function renderDatePicker() {
    const { viewYear, viewMonth, targetInput } = datePickerState;
    if (!targetInput) return;

    const min = getMinForDateField(targetInput);
    const selected = targetInput.value;
    const todayIso = todayISO();

    document.getElementById('dp-month-label').textContent = `${MONTH_NAMES[viewMonth]} ${viewYear}`;

    const firstWeekday = new Date(viewYear, viewMonth, 1).getDay();
    const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
    const daysInPrevMonth = new Date(viewYear, viewMonth, 0).getDate();

    const cells = [];
    for (let i = firstWeekday - 1; i >= 0; i--) {
        cells.push(new Date(viewYear, viewMonth - 1, daysInPrevMonth - i));
    }
    for (let d = 1; d <= daysInMonth; d++) {
        cells.push(new Date(viewYear, viewMonth, d));
    }
    let nextDay = 1;
    while (cells.length < 42) {
        cells.push(new Date(viewYear, viewMonth + 1, nextDay++));
    }

    const grid = document.getElementById('dp-grid');
    grid.innerHTML = cells.map((date) => {
        const iso = toISO(date);
        const outside = date.getMonth() !== viewMonth;
        const disabled = iso < min;
        const classes = ['dp-day'];
        if (outside) classes.push('dp-day-outside');
        if (disabled) classes.push('dp-day-disabled');
        if (iso === selected) classes.push('dp-day-selected');
        if (iso === todayIso) classes.push('dp-day-today');
        return `<button type="button" class="${classes.join(' ')}" data-iso="${iso}" ${disabled ? 'disabled' : ''}>${date.getDate()}</button>`;
    }).join('');

    grid.querySelectorAll('.dp-day:not(.dp-day-disabled)').forEach((btn) => {
        btn.addEventListener('click', () => {
            setDateValue(datePickerState.targetInput, btn.dataset.iso);
            closeDatePicker();
        });
    });
}

function openDatePicker(triggerBtn) {
    const input = document.getElementById(triggerBtn.dataset.dateTriggerFor);
    datePickerState.targetInput = input;
    datePickerState.triggerEl = triggerBtn;

    const base = parseISO(input.value) || parseISO(getMinForDateField(input));
    datePickerState.viewYear = base.getFullYear();
    datePickerState.viewMonth = base.getMonth();

    renderDatePicker();
    triggerBtn.classList.add('open');
    document.getElementById('date-picker-popup').classList.remove('hidden');
    positionDatePicker(triggerBtn);
}

function closeDatePicker() {
    document.getElementById('date-picker-popup').classList.add('hidden');
    if (datePickerState.triggerEl) datePickerState.triggerEl.classList.remove('open');
    datePickerState.targetInput = null;
    datePickerState.triggerEl = null;
}

document.getElementById('dp-prev').addEventListener('click', () => {
    datePickerState.viewMonth -= 1;
    if (datePickerState.viewMonth < 0) { datePickerState.viewMonth = 11; datePickerState.viewYear -= 1; }
    renderDatePicker();
});
document.getElementById('dp-next').addEventListener('click', () => {
    datePickerState.viewMonth += 1;
    if (datePickerState.viewMonth > 11) { datePickerState.viewMonth = 0; datePickerState.viewYear += 1; }
    renderDatePicker();
});
document.getElementById('dp-today').addEventListener('click', () => {
    const min = getMinForDateField(datePickerState.targetInput);
    setDateValue(datePickerState.targetInput, todayISO() >= min ? todayISO() : min);
    closeDatePicker();
});
document.getElementById('dp-clear').addEventListener('click', () => {
    setDateValue(datePickerState.targetInput, '');
    closeDatePicker();
});

document.querySelectorAll('[data-date-trigger-for]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const alreadyOpenForThis = datePickerState.triggerEl === btn && !document.getElementById('date-picker-popup').classList.contains('hidden');
        alreadyOpenForThis ? closeDatePicker() : openDatePicker(btn);
    });
});

document.addEventListener('click', (e) => {
    const popup = document.getElementById('date-picker-popup');
    if (!popup.classList.contains('hidden') && !popup.contains(e.target) && !e.target.closest('[data-date-trigger-for]')) {
        closeDatePicker();
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    closeDatePicker();
    // The confirm dialog manages its own Escape handling (inside
    // confirmDialog()) since it must resolve its pending promise, not just
    // hide the element. Plain modals can just be hidden directly.
    document.querySelectorAll('.modal-overlay:not(.hidden)').forEach((overlay) => {
        if (overlay.id !== 'confirm-modal') overlay.classList.add('hidden');
    });
});

window.addEventListener('resize', () => {
    if (datePickerState.triggerEl) positionDatePicker(datePickerState.triggerEl);
});

/* ============================== VIEWS ============================== */

function showView(name) {
    document.querySelectorAll('.view').forEach((v) => v.classList.remove('active'));
    document.getElementById(`view-${name}`).classList.add('active');
    document.querySelectorAll('.nav-link').forEach((b) => b.classList.toggle('active', b.dataset.nav === name));

    // The chatbot panel is position:fixed and can otherwise linger open over
    // content in other views, blocking clicks underneath it.
    document.getElementById('chatbot-panel').classList.add('hidden');

    if (name === 'bookings') loadMyBookings();
    if (name === 'admin') {
        // Room types must populate the #rm-type <select> before rooms load,
        // or "Add Room" can open with an empty type dropdown.
        loadAdminRoomTypes().then(() => loadAdminRooms());
        loadAdminBookings();
    }
}

function setAuthTab(tab) {
    document.querySelectorAll('.auth-card .tab-btn').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    document.getElementById('login-form').classList.toggle('active', tab === 'login');
    document.getElementById('register-form').classList.toggle('active', tab === 'register');
}

function updateAuthUI() {
    const isAuth = !!currentUser;
    const isAdmin = isAuth && currentUser.role === 'admin';
    document.querySelectorAll('.auth-only').forEach((el) => el.classList.toggle('hidden', !isAuth));
    document.querySelectorAll('.guest-only').forEach((el) => el.classList.toggle('hidden', isAuth));
    document.querySelectorAll('.admin-only').forEach((el) => el.classList.toggle('hidden', !isAdmin));
    // "My Bookings" is a customer concept - admins manage everyone's bookings
    // via Admin > All Bookings instead, so it doesn't belong in their nav.
    document.querySelectorAll('.customer-only').forEach((el) => el.classList.toggle('hidden', !isAuth || isAdmin));
    // Admin is a separate workspace from the guest-facing site, so "Browse
    // Rooms" as a primary nav tab doesn't belong there either - keep it for
    // logged-out visitors and customers only.
    document.querySelectorAll('.hide-for-admin').forEach((el) => el.classList.toggle('hidden', isAdmin));
    if (isAuth) document.getElementById('current-user-name').textContent = currentUser.name;
    refreshBookingsBadge();
    // Auth state changes what's in the header (name length, which buttons
    // show), which can change how many rows it wraps to on narrow screens.
    requestAnimationFrame(syncHeaderHeightVar);
}

/* ============================== BOOKING UPDATE NOTIFICATIONS ============================== */
/* Only an admin can move a booking to "confirmed" or "completed" (customers
 * can only create or cancel their own). So any booking in one of those
 * states that this browser hasn't shown yet means the hotel just acted on
 * it - worth a badge on "My Bookings" without needing a real notifications
 * system. */

function seenBookingUpdatesKey() {
    return `seen_booking_updates_${currentUser?.id}`;
}

function getSeenBookingUpdateIds() {
    try {
        return new Set(JSON.parse(localStorage.getItem(seenBookingUpdatesKey())) || []);
    } catch (e) {
        return new Set();
    }
}

function markBookingUpdatesSeen(ids) {
    localStorage.setItem(seenBookingUpdatesKey(), JSON.stringify([...ids]));
}

async function refreshBookingsBadge() {
    const badge = document.getElementById('bookings-badge');
    if (!currentUser || currentUser.role === 'admin') {
        badge.classList.add('hidden');
        return;
    }
    try {
        const data = await apiFetch('/bookings?per_page=50');
        const seen = getSeenBookingUpdateIds();
        const unseen = data.data.filter((b) => ['confirmed', 'completed'].includes(b.status) && !seen.has(b.id));
        badge.textContent = String(unseen.length);
        badge.classList.toggle('hidden', unseen.length === 0);
    } catch (e) {
        // Badge is a nice-to-have; a failed background check shouldn't surface an error.
    }
}

// Pick up admin-side status changes made in another tab while this one stays open.
setInterval(() => { if (currentUser && currentUser.role !== 'admin') refreshBookingsBadge(); }, 20000);

/* ============================== AUTH ============================== */

async function loadMe() {
    if (!authToken) return;
    try {
        currentUser = await apiFetch('/me');
    } catch (e) {
        authToken = null;
        currentUser = null;
        localStorage.removeItem('token');
    }
    updateAuthUI();
}

function handleAuthSuccess(data, welcomeVerb) {
    authToken = data.access_token;
    currentUser = data.user;
    localStorage.setItem('token', authToken);
    updateAuthUI();
    toast(`${welcomeVerb}, ${currentUser.name}.`, 'success');
    showView(currentUser.role === 'admin' ? 'admin' : 'browse');
}

/* ============================== BROWSE / SEARCH ============================== */

function roomCardHtml(room) {
    const amenities = (room.room_type.amenities || []).map((a) => `<span class="pill">${escapeHtml(a)}</span>`).join('');
    return `
        <div class="room-card">
            <div class="room-type-name">${escapeHtml(room.room_type.name)}</div>
            <div class="room-number">Room ${escapeHtml(room.room_number)}</div>
            <div class="room-meta">Floor ${room.floor ?? '—'} &middot; Up to ${room.room_type.capacity} guests</div>
            <div class="amenities">${amenities}</div>
            <div class="room-price">${formatMoney(room.room_type.base_price)} <span>/ night</span></div>
            <button class="btn btn-primary" data-book-room='${JSON.stringify(room).replace(/'/g, '&apos;')}'>Book this room</button>
        </div>`;
}

function skeletonCardHtml() {
    return `
        <div class="room-card skeleton-card" aria-hidden="true">
            <div class="skeleton-line" style="width:40%"></div>
            <div class="skeleton-line-lg" style="width:60%"></div>
            <div class="skeleton-line" style="width:70%"></div>
            <div class="skeleton-pills">
                <div class="skeleton-pill"></div>
                <div class="skeleton-pill"></div>
                <div class="skeleton-pill"></div>
            </div>
            <div class="skeleton-line-lg" style="width:45%"></div>
            <div class="skeleton-button"></div>
        </div>`;
}

async function searchRooms() {
    const checkIn = document.getElementById('f-check-in').value;
    const checkOut = document.getElementById('f-check-out').value;
    const roomType = document.getElementById('f-room-type').value;
    const guests = document.getElementById('f-guests').value;

    const params = new URLSearchParams({ per_page: '24', guests });
    if (checkIn) params.set('check_in', checkIn);
    if (checkOut) params.set('check_out', checkOut);
    if (roomType) params.set('room_type_id', roomType);

    lastSearchParams = { checkIn, checkOut, guests };

    const results = document.getElementById('room-results');
    results.innerHTML = Array.from({ length: 6 }, skeletonCardHtml).join('');

    try {
        const data = await apiFetch(`/rooms?${params.toString()}`, { auth: false });
        if (data.data.length === 0) {
            results.innerHTML = '<div class="empty-state">No rooms match those filters. Try different dates or filters.</div>';
            return;
        }
        results.innerHTML = data.data.map(roomCardHtml).join('');
        results.querySelectorAll('[data-book-room]').forEach((btn) => {
            btn.addEventListener('click', () => openBookingModal(JSON.parse(btn.dataset.bookRoom)));
        });
    } catch (err) {
        results.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
    }
}

async function loadRoomTypeFilter() {
    const data = await apiFetch('/room-types?per_page=50', { auth: false });
    const select = document.getElementById('f-room-type');
    data.data.forEach((rt) => {
        const opt = document.createElement('option');
        opt.value = rt.id;
        opt.textContent = `${rt.name} (${formatMoney(rt.base_price)}/night)`;
        select.appendChild(opt);
    });

    // Cap the search stepper at the largest room's capacity - without this
    // a guest can step up to an arbitrary number and get an unexplained
    // "no rooms match" instead of a clear reason why.
    const maxCapacity = Math.max(1, ...data.data.map((rt) => rt.capacity));
    document.getElementById('f-guests').max = maxCapacity;
}

/* ============================== BOOKING MODAL ============================== */

function openBookingModal(room) {
    if (!currentUser) {
        toast('Log in or create an account first to book a room.', 'error');
        setAuthTab('login');
        showView('auth');
        return;
    }
    activeBookingRoom = room;
    const bkCheckIn = document.getElementById('bk-check-in');
    const bkCheckOut = document.getElementById('bk-check-out');
    document.getElementById('modal-room-number').textContent = room.room_number;
    document.getElementById('modal-room-type').textContent =
        `${room.room_type.name} — ${formatMoney(room.room_type.base_price)}/night, up to ${room.room_type.capacity} guests`;
    setDateValue(bkCheckIn, lastSearchParams.checkIn || '');
    setDateValue(bkCheckOut, lastSearchParams.checkOut || '');
    document.getElementById('bk-guests').value = lastSearchParams.guests || 1;
    document.getElementById('bk-guests').max = room.room_type.capacity;
    document.getElementById('booking-feedback').textContent = '';
    document.getElementById('bk-requests').value = '';
    updateCharCount(document.getElementById('bk-requests'), document.getElementById('bk-requests-count'));
    openModal('booking-modal');
}

function updateCharCount(textarea, counterEl) {
    const max = Number(textarea.maxLength);
    const len = textarea.value.length;
    counterEl.textContent = `${len} / ${max}`;
    counterEl.classList.toggle('near-limit', len >= max * 0.9);
}

/* ============================== MY BOOKINGS ============================== */

function bookingRowHtml(b) {
    const canCancel = b.status === 'pending' || b.status === 'confirmed';
    return `
        <div class="booking-row">
            <div class="booking-main">
                <div class="booking-room">${escapeHtml(b.room.room_type.name)} &middot; Room ${escapeHtml(b.room.room_number)}</div>
                <div class="booking-dates">${b.check_in.slice(0, 10)} &rarr; ${b.check_out.slice(0, 10)} &middot; ${b.guests} guest(s)</div>
            </div>
            <div class="booking-actions">
                <span class="booking-price">${formatMoney(b.total_price)}</span>
                <span class="status-badge status-${b.status}">${b.status}</span>
                ${canCancel ? `<button class="btn btn-danger btn-small" data-cancel-booking="${b.id}">Cancel</button>` : ''}
            </div>
        </div>`;
}

async function loadMyBookings() {
    const list = document.getElementById('bookings-list');
    list.innerHTML = '<div class="empty-state">Loading&hellip;</div>';
    try {
        const data = await apiFetch('/bookings?per_page=50');

        const updatedIds = data.data.filter((b) => ['confirmed', 'completed'].includes(b.status)).map((b) => b.id);
        markBookingUpdatesSeen(new Set([...getSeenBookingUpdateIds(), ...updatedIds]));
        document.getElementById('bookings-badge').classList.add('hidden');

        if (data.data.length === 0) {
            list.innerHTML = '<div class="empty-state">No bookings yet — search for a room to get started.</div>';
            return;
        }
        list.innerHTML = data.data.map(bookingRowHtml).join('');
        list.querySelectorAll('[data-cancel-booking]').forEach((btn) => {
            btn.addEventListener('click', () => cancelBooking(btn.dataset.cancelBooking));
        });
    } catch (err) {
        list.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
    }
}

async function cancelBooking(id) {
    const ok = await confirmDialog({
        title: 'Cancel this booking?',
        body: 'The room will become available for others to book again. This cannot be undone.',
        confirmText: 'Cancel booking',
    });
    if (!ok) return;
    try {
        await apiFetch(`/bookings/${id}`, { method: 'DELETE' });
        toast('Booking cancelled.', 'success');
        loadMyBookings();
    } catch (err) {
        toast(err.message, 'error');
    }
}

/* ============================== ADMIN: ROOM TYPES ============================== */

async function loadAdminRoomTypes() {
    const tbody = document.getElementById('admin-room-types-body');
    tbody.innerHTML = '<tr><td colspan="5">Loading&hellip;</td></tr>';
    try {
        const data = await apiFetch('/room-types?per_page=50');
        tbody.innerHTML = data.data.map((rt) => `
            <tr>
                <td>${escapeHtml(rt.name)}</td>
                <td>${formatMoney(rt.base_price)}</td>
                <td>${rt.capacity}</td>
                <td>${rt.rooms_count ?? '—'}</td>
                <td class="row-actions">
                    <button class="btn btn-ghost btn-small" data-edit-room-type='${JSON.stringify(rt).replace(/'/g, '&apos;')}'>Edit</button>
                    <button class="btn btn-danger btn-small" data-delete-room-type="${rt.id}">Delete</button>
                </td>
            </tr>`).join('');

        tbody.querySelectorAll('[data-edit-room-type]').forEach((btn) => {
            btn.addEventListener('click', () => openRoomTypeModal(JSON.parse(btn.dataset.editRoomType)));
        });
        tbody.querySelectorAll('[data-delete-room-type]').forEach((btn) => {
            btn.addEventListener('click', () => deleteRoomType(btn.dataset.deleteRoomType));
        });

        populateRoomTypeSelect(data.data);
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="5">${escapeHtml(err.message)}</td></tr>`;
    }
}

function openRoomTypeModal(rt = null) {
    document.getElementById('room-type-modal-title').textContent = rt ? 'Edit Room Type' : 'Add Room Type';
    document.getElementById('rt-id').value = rt?.id || '';
    document.getElementById('rt-name').value = rt?.name || '';
    document.getElementById('rt-description').value = rt?.description || '';
    document.getElementById('rt-price').value = rt?.base_price || '';
    document.getElementById('rt-capacity').value = rt?.capacity || '';
    document.getElementById('rt-amenities').value = (rt?.amenities || []).join(', ');
    document.getElementById('room-type-feedback').textContent = '';
    openModal('room-type-modal');
}

async function deleteRoomType(id) {
    const ok = await confirmDialog({
        title: 'Delete this room type?',
        body: 'Rooms of this type must be removed first. This cannot be undone.',
    });
    if (!ok) return;
    try {
        await apiFetch(`/room-types/${id}`, { method: 'DELETE' });
        toast('Room type deleted.', 'success');
        loadAdminRoomTypes();
    } catch (err) {
        toast(err.message, 'error');
    }
}

/* ============================== ADMIN: ROOMS ============================== */

function populateRoomTypeSelect(roomTypes) {
    const select = document.getElementById('rm-type');
    const current = select.value;
    select.innerHTML = roomTypes.map((rt) => `<option value="${rt.id}">${escapeHtml(rt.name)}</option>`).join('');
    if (current) select.value = current;
}

async function loadAdminRooms() {
    const tbody = document.getElementById('admin-rooms-body');
    tbody.innerHTML = '<tr><td colspan="5">Loading&hellip;</td></tr>';
    try {
        const data = await apiFetch('/rooms?per_page=50');
        tbody.innerHTML = data.data.map((room) => `
            <tr>
                <td>${escapeHtml(room.room_number)}</td>
                <td>${escapeHtml(room.room_type.name)}</td>
                <td>${room.floor ?? '—'}</td>
                <td><span class="status-badge status-${room.status}">${room.status}</span></td>
                <td class="row-actions">
                    <button class="btn btn-ghost btn-small" data-edit-room='${JSON.stringify(room).replace(/'/g, '&apos;')}'>Edit</button>
                    <button class="btn btn-danger btn-small" data-delete-room="${room.id}">Delete</button>
                </td>
            </tr>`).join('');

        tbody.querySelectorAll('[data-edit-room]').forEach((btn) => {
            btn.addEventListener('click', () => openRoomModal(JSON.parse(btn.dataset.editRoom)));
        });
        tbody.querySelectorAll('[data-delete-room]').forEach((btn) => {
            btn.addEventListener('click', () => deleteRoom(btn.dataset.deleteRoom));
        });
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="5">${escapeHtml(err.message)}</td></tr>`;
    }
}

function openRoomModal(room = null) {
    document.getElementById('room-modal-title').textContent = room ? 'Edit Room' : 'Add Room';
    document.getElementById('rm-id').value = room?.id || '';
    document.getElementById('rm-type').value = room?.room_type_id || room?.room_type?.id || '';
    document.getElementById('rm-number').value = room?.room_number || '';
    document.getElementById('rm-floor').value = room?.floor ?? '';
    document.getElementById('rm-status').value = room?.status || 'available';
    document.getElementById('room-feedback').textContent = '';
    openModal('room-modal');
}

async function deleteRoom(id) {
    const ok = await confirmDialog({
        title: 'Delete this room?',
        body: 'This cannot be undone.',
    });
    if (!ok) return;
    try {
        await apiFetch(`/rooms/${id}`, { method: 'DELETE' });
        toast('Room deleted.', 'success');
        loadAdminRooms();
    } catch (err) {
        toast(err.message, 'error');
    }
}

/* ============================== ADMIN: ALL BOOKINGS ============================== */

async function loadAdminBookings() {
    const tbody = document.getElementById('admin-bookings-body');
    tbody.innerHTML = '<tr><td colspan="6">Loading&hellip;</td></tr>';
    try {
        const data = await apiFetch('/bookings?per_page=50');
        tbody.innerHTML = data.data.map((b) => `
            <tr>
                <td>${escapeHtml(b.user.name)}</td>
                <td>${escapeHtml(b.room.room_number)}</td>
                <td>${b.check_in.slice(0, 10)} &rarr; ${b.check_out.slice(0, 10)}</td>
                <td>
                    <select class="status-select" data-booking-id="${b.id}">
                        ${['pending', 'confirmed', 'cancelled', 'completed'].map((s) => `<option value="${s}" ${s === b.status ? 'selected' : ''}>${s}</option>`).join('')}
                    </select>
                </td>
                <td>${formatMoney(b.total_price)}</td>
                <td></td>
            </tr>`).join('');

        tbody.querySelectorAll('.status-select').forEach((sel) => {
            sel.addEventListener('change', () => updateBookingStatus(sel.dataset.bookingId, sel.value));
        });
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="6">${escapeHtml(err.message)}</td></tr>`;
    }
}

async function updateBookingStatus(id, status) {
    try {
        await apiFetch(`/bookings/${id}`, { method: 'PUT', body: { status } });
        toast('Booking status updated.', 'success');
    } catch (err) {
        toast(err.message, 'error');
        loadAdminBookings();
    }
}

/* ============================== CHATBOT ============================== */

function appendChatMessage(text, from) {
    const msg = document.createElement('div');
    msg.className = `chat-msg ${from}`;
    msg.textContent = text;
    const box = document.getElementById('chatbot-messages');
    box.appendChild(msg);
    box.scrollTop = box.scrollHeight;
}

/* ============================== STEPPER (guest count) ============================== */

function wireStepper(container) {
    const input = container.querySelector('input[type="number"]');
    container.querySelectorAll('[data-step]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const step = Number(btn.dataset.step);
            const min = input.min !== '' ? Number(input.min) : -Infinity;
            const max = input.max !== '' ? Number(input.max) : Infinity;
            input.value = Math.min(max, Math.max(min, (Number(input.value) || 0) + step));
        });
    });
}

document.querySelectorAll('.stepper').forEach(wireStepper);

document.getElementById('bk-requests').addEventListener('input', (e) => {
    updateCharCount(e.target, document.getElementById('bk-requests-count'));
});

/* ============================== PASSWORD VISIBILITY TOGGLE ============================== */

document.querySelectorAll('[data-password-toggle-for]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.passwordToggleFor);
        const nowVisible = input.type === 'password';
        input.type = nowVisible ? 'text' : 'password';
        btn.classList.toggle('is-visible', nowVisible);
        btn.setAttribute('aria-label', nowVisible ? 'Hide password' : 'Show password');
    });
});

/* ============================== CONFIRM DIALOG ============================== */
/* Promise-based replacement for window.confirm() so delete/cancel prompts
 * match the app's own modal styling instead of a native browser dialog. */

function confirmDialog({ title = 'Are you sure?', body = '', confirmText = 'Delete', danger = true } = {}) {
    return new Promise((resolve) => {
        const modal = document.getElementById('confirm-modal');
        const confirmBtn = document.getElementById('confirm-modal-confirm');
        const cancelBtn = document.getElementById('confirm-modal-cancel');

        document.getElementById('confirm-modal-title').textContent = title;
        document.getElementById('confirm-modal-body').textContent = body;
        confirmBtn.textContent = confirmText;
        confirmBtn.className = `btn ${danger ? 'btn-danger' : 'btn-primary'}`;

        function settle(result) {
            modal.classList.add('hidden');
            confirmBtn.removeEventListener('click', onConfirm);
            cancelBtn.removeEventListener('click', onCancel);
            modal.removeEventListener('click', onBackdrop);
            document.removeEventListener('keydown', onKeydown);
            resolve(result);
        }
        function onConfirm() { settle(true); }
        function onCancel() { settle(false); }
        function onBackdrop(e) { if (e.target === modal) settle(false); }
        function onKeydown(e) { if (e.key === 'Escape') settle(false); }

        confirmBtn.addEventListener('click', onConfirm);
        cancelBtn.addEventListener('click', onCancel);
        modal.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKeydown);
        modal.classList.remove('hidden');
    });
}

/* ============================== WIRE UP EVENTS ============================== */

document.querySelectorAll('[data-nav]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        if (btn.dataset.nav === 'auth' && btn.dataset.tab) setAuthTab(btn.dataset.tab);
        // Admin is a separate workspace - even the brand link's "home" should
        // land there instead of the guest-facing browse page it targets by default.
        const isAdmin = currentUser && currentUser.role === 'admin';
        const target = (btn.dataset.nav === 'browse' && isAdmin) ? 'admin' : btn.dataset.nav;
        showView(target);
    });
});

document.querySelectorAll('.auth-card .tab-btn').forEach((b) => b.addEventListener('click', () => setAuthTab(b.dataset.tab)));

document.querySelectorAll('[data-close-modal]').forEach((btn) => {
    btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
});
document.querySelectorAll('.modal-overlay').forEach((overlay) => {
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.add('hidden'); });
});

document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.target.querySelector('button[type="submit"]');
    setButtonLoading(submitBtn, true, 'Logging in…');
    try {
        const data = await apiFetch('/login', {
            method: 'POST',
            auth: false,
            body: {
                email: document.getElementById('login-email').value,
                password: document.getElementById('login-password').value,
            },
        });
        handleAuthSuccess(data, 'Welcome back');
    } catch (err) {
        toast(allValidationErrors(err), 'error');
    } finally {
        setButtonLoading(submitBtn, false);
    }
});

document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const password = document.getElementById('reg-password').value;
    const passwordConfirm = document.getElementById('reg-password-confirm').value;
    if (password !== passwordConfirm) { toast('Passwords do not match.', 'error'); return; }

    const submitBtn = e.target.querySelector('button[type="submit"]');
    setButtonLoading(submitBtn, true, 'Creating account…');
    try {
        const data = await apiFetch('/register', {
            method: 'POST',
            auth: false,
            body: {
                name: document.getElementById('reg-name').value,
                email: document.getElementById('reg-email').value,
                phone: document.getElementById('reg-phone').value || null,
                password,
                password_confirmation: passwordConfirm,
            },
        });
        handleAuthSuccess(data, 'Account created — welcome');
    } catch (err) {
        toast(allValidationErrors(err), 'error');
    } finally {
        setButtonLoading(submitBtn, false);
    }
});

document.getElementById('logout-btn').addEventListener('click', async () => {
    try { await apiFetch('/logout', { method: 'POST' }); } catch (e) { /* token likely already expired */ }
    authToken = null;
    currentUser = null;
    localStorage.removeItem('token');
    updateAuthUI();
    showView('browse');
    toast('Logged out.', 'success');
});

document.getElementById('search-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.target.querySelector('button[type="submit"]');
    setButtonLoading(submitBtn, true, 'Searching…');
    try {
        await searchRooms();
    } finally {
        setButtonLoading(submitBtn, false);
    }
});

document.getElementById('booking-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const feedback = document.getElementById('booking-feedback');
    feedback.textContent = '';
    feedback.className = 'feedback';

    const checkIn = document.getElementById('bk-check-in').value;
    const checkOut = document.getElementById('bk-check-out').value;
    if (!checkIn || !checkOut) {
        feedback.textContent = 'Please select both a check-in and check-out date.';
        feedback.className = 'feedback error';
        return;
    }

    const submitBtn = e.target.querySelector('button[type="submit"]');
    setButtonLoading(submitBtn, true, 'Booking…');
    try {
        await apiFetch('/bookings', {
            method: 'POST',
            body: {
                room_id: activeBookingRoom.id,
                check_in: checkIn,
                check_out: checkOut,
                guests: Number(document.getElementById('bk-guests').value),
                special_requests: document.getElementById('bk-requests').value || null,
            },
        });
        closeModal('booking-modal');
        toast('Booking submitted — check the confirmation email.', 'success');
        searchRooms();
    } catch (err) {
        feedback.textContent = allValidationErrors(err);
        feedback.className = 'feedback error';
    } finally {
        setButtonLoading(submitBtn, false);
    }
});

document.getElementById('add-room-type-btn').addEventListener('click', () => openRoomTypeModal());

document.getElementById('room-type-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const feedback = document.getElementById('room-type-feedback');
    const id = document.getElementById('rt-id').value;
    const body = {
        name: document.getElementById('rt-name').value,
        description: document.getElementById('rt-description').value || null,
        base_price: Number(document.getElementById('rt-price').value),
        capacity: Number(document.getElementById('rt-capacity').value),
        amenities: document.getElementById('rt-amenities').value.split(',').map((s) => s.trim()).filter(Boolean),
    };
    const submitBtn = e.target.querySelector('button[type="submit"]');
    setButtonLoading(submitBtn, true, 'Saving…');
    try {
        await apiFetch(id ? `/room-types/${id}` : '/room-types', { method: id ? 'PUT' : 'POST', body });
        closeModal('room-type-modal');
        toast('Room type saved.', 'success');
        loadAdminRoomTypes();
    } catch (err) {
        feedback.textContent = allValidationErrors(err);
        feedback.className = 'feedback error';
    } finally {
        setButtonLoading(submitBtn, false);
    }
});

document.getElementById('add-room-btn').addEventListener('click', () => openRoomModal());

document.getElementById('room-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const feedback = document.getElementById('room-feedback');
    const id = document.getElementById('rm-id').value;
    const body = {
        room_type_id: Number(document.getElementById('rm-type').value),
        room_number: document.getElementById('rm-number').value,
        floor: document.getElementById('rm-floor').value ? Number(document.getElementById('rm-floor').value) : null,
        status: document.getElementById('rm-status').value,
    };
    const submitBtn = e.target.querySelector('button[type="submit"]');
    setButtonLoading(submitBtn, true, 'Saving…');
    try {
        await apiFetch(id ? `/rooms/${id}` : '/rooms', { method: id ? 'PUT' : 'POST', body });
        closeModal('room-modal');
        toast('Room saved.', 'success');
        loadAdminRooms();
    } catch (err) {
        feedback.textContent = allValidationErrors(err);
        feedback.className = 'feedback error';
    } finally {
        setButtonLoading(submitBtn, false);
    }
});

document.querySelectorAll('#view-admin .tab-btn[data-admin-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('#view-admin .tab-btn').forEach((b) => b.classList.toggle('active', b === btn));
        document.querySelectorAll('.admin-panel').forEach((p) => p.classList.remove('active'));
        document.getElementById(`admin-${btn.dataset.adminTab}`).classList.add('active');
    });
});

document.getElementById('chatbot-toggle').addEventListener('click', () => {
    document.getElementById('chatbot-panel').classList.toggle('hidden');
});

document.getElementById('chatbot-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = document.getElementById('chatbot-input');
    const message = input.value.trim();
    if (!message) return;
    appendChatMessage(message, 'user');
    input.value = '';
    try {
        const data = await apiFetch('/chatbot', { method: 'POST', auth: false, body: { message } });
        appendChatMessage(data.reply, 'bot');
    } catch (err) {
        appendChatMessage("Sorry, I couldn't reach the server just now.", 'bot');
    }
});

/* ============================== INIT ============================== */

(async function init() {
    syncThemeToggleButton();
    syncHeaderHeightVar();
    appendChatMessage('Hi! Ask me about room types, prices, availability, or how to book.', 'bot');

    await loadMe();
    await loadRoomTypeFilter();
    await searchRooms();
    showView(currentUser && currentUser.role === 'admin' ? 'admin' : 'browse');
})();
