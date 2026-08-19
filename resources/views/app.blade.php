<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
    <script>
        // Applied before first paint so there's no flash of the wrong theme.
        (function () {
            var stored = localStorage.getItem('theme');
            if (stored) document.documentElement.setAttribute('data-theme', stored);
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>

    <header class="site-header">
        <div class="container header-row">
            <a href="#" class="brand" data-nav="browse">CertiCode Hotel</a>

            <nav class="main-nav">
                <button class="nav-link hide-for-admin" data-nav="browse">Browse Rooms</button>
                <button class="nav-link customer-only hidden" data-nav="bookings">
                    My Bookings
                    <span class="nav-badge hidden" id="bookings-badge"></span>
                </button>
                <button class="nav-link admin-only hidden" data-nav="admin">Admin</button>
            </nav>

            <div class="auth-area">
                <button type="button" class="theme-switch" id="theme-toggle" role="switch" aria-checked="false" aria-label="Dark mode">
                    <span class="theme-switch-icon" aria-hidden="true">☀️</span>
                    <span class="theme-switch-thumb"></span>
                    <span class="theme-switch-icon" aria-hidden="true">🌙</span>
                </button>
                <span class="user-chip guest-only">
                    <button class="btn btn-ghost" data-nav="auth" data-tab="login">Log in</button>
                    <button class="btn btn-primary" data-nav="auth" data-tab="register">Sign up</button>
                </span>
                <span class="user-chip auth-only hidden">
                    <span class="user-name" id="current-user-name"></span>
                    <button class="btn btn-ghost" id="logout-btn">Log out</button>
                </span>
            </div>
        </div>
    </header>

    <main class="container">

        <!-- ============ BROWSE / SEARCH ============ -->
        <section id="view-browse" class="view">
            <div class="hero">
                <h1>Find your room</h1>
                <p>Search live availability &mdash; every result below is a real, unbooked room for those dates.</p>
            </div>

            <form id="search-form" class="search-panel">
                <div class="field">
                    <label for="f-check-in">Check-in</label>
                    @include('partials.date-field', ['id' => 'f-check-in'])
                </div>
                <div class="field">
                    <label for="f-check-out">Check-out</label>
                    @include('partials.date-field', ['id' => 'f-check-out', 'pairedMin' => 'f-check-in'])
                </div>
                <div class="field">
                    <label for="f-guests">Guests</label>
                    <div class="stepper">
                        <button type="button" class="stepper-btn" data-step="-1" aria-label="Decrease guests">&minus;</button>
                        <input type="number" id="f-guests" name="guests" min="1" value="1" readonly>
                        <button type="button" class="stepper-btn" data-step="1" aria-label="Increase guests">+</button>
                    </div>
                </div>
                <div class="field">
                    <label for="f-room-type">Room type</label>
                    <select id="f-room-type" name="room_type_id">
                        <option value="">Any type</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Search</button>
            </form>

            <div id="room-results" class="room-grid"></div>
        </section>

        <!-- ============ LOGIN / REGISTER ============ -->
        <section id="view-auth" class="view">
            <div class="auth-card">
                <div class="tabs">
                    <button class="tab-btn active" data-tab="login">Log in</button>
                    <button class="tab-btn" data-tab="register">Sign up</button>
                </div>

                <form id="login-form" class="tab-panel active">
                    <div class="field">
                        <label for="login-email">Email</label>
                        <input type="email" id="login-email" required>
                    </div>
                    <div class="field">
                        <label for="login-password">Password</label>
                        @include('partials.password-field', ['id' => 'login-password'])
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Log in</button>
                </form>

                <form id="register-form" class="tab-panel">
                    <div class="field">
                        <label for="reg-name">Full name</label>
                        <input type="text" id="reg-name" required>
                    </div>
                    <div class="field">
                        <label for="reg-email">Email</label>
                        <input type="email" id="reg-email" required>
                    </div>
                    <div class="field">
                        <label for="reg-phone">Phone (optional)</label>
                        <input type="text" id="reg-phone">
                    </div>
                    <div class="field">
                        <label for="reg-password">Password</label>
                        @include('partials.password-field', ['id' => 'reg-password', 'minlength' => 8])
                    </div>
                    <div class="field">
                        <label for="reg-password-confirm">Confirm password</label>
                        @include('partials.password-field', ['id' => 'reg-password-confirm', 'minlength' => 8])
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Create account</button>
                </form>
            </div>
        </section>

        <!-- ============ MY BOOKINGS ============ -->
        <section id="view-bookings" class="view">
            <h1>My Bookings</h1>
            <div id="bookings-list" class="stack"></div>
        </section>

        <!-- ============ ADMIN ============ -->
        <section id="view-admin" class="view">
            <h1>Admin</h1>

            <div class="tabs">
                <button class="tab-btn active" data-admin-tab="room-types">Room Types</button>
                <button class="tab-btn" data-admin-tab="rooms">Rooms</button>
                <button class="tab-btn" data-admin-tab="bookings">All Bookings</button>
            </div>

            <div id="admin-room-types" class="admin-panel active">
                <div class="panel-toolbar">
                    <div>
                        <h2 class="panel-toolbar-title">Room Types</h2>
                        <p class="panel-toolbar-sub">The categories guests choose from when they search.</p>
                    </div>
                    <button class="btn btn-primary" id="add-room-type-btn">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        Add Room Type
                    </button>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr><th>Name</th><th>Price/night</th><th>Capacity</th><th>Rooms</th><th></th></tr>
                        </thead>
                        <tbody id="admin-room-types-body"></tbody>
                    </table>
                </div>
            </div>

            <div id="admin-rooms" class="admin-panel">
                <div class="panel-toolbar">
                    <div>
                        <h2 class="panel-toolbar-title">Rooms</h2>
                        <p class="panel-toolbar-sub">Every physical room, and which room type it belongs to.</p>
                    </div>
                    <button class="btn btn-primary" id="add-room-btn">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        Add Room
                    </button>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr><th>Number</th><th>Type</th><th>Floor</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody id="admin-rooms-body"></tbody>
                    </table>
                </div>
            </div>

            <div id="admin-bookings" class="admin-panel">
                <div class="panel-toolbar">
                    <div>
                        <h2 class="panel-toolbar-title">All Bookings</h2>
                        <p class="panel-toolbar-sub">Every reservation across all guests, newest check-in first.</p>
                    </div>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr><th>Guest</th><th>Room</th><th>Dates</th><th>Status</th><th>Total</th><th>Special requests</th></tr>
                        </thead>
                        <tbody id="admin-bookings-body"></tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>

    <!-- ============ BOOKING MODAL ============ -->
    <div id="booking-modal" class="modal-overlay hidden">
        <div class="modal">
            <button class="modal-close" data-close-modal="booking-modal">&times;</button>
            <h3>Book Room <span id="modal-room-number"></span></h3>
            <p class="muted" id="modal-room-type"></p>
            <form id="booking-form">
                <div class="field-row">
                    <div class="field">
                        <label for="bk-check-in">Check-in</label>
                        @include('partials.date-field', ['id' => 'bk-check-in'])
                    </div>
                    <div class="field">
                        <label for="bk-check-out">Check-out</label>
                        @include('partials.date-field', ['id' => 'bk-check-out', 'pairedMin' => 'bk-check-in'])
                    </div>
                </div>
                <div class="field">
                    <label for="bk-guests">Guests</label>
                    <div class="stepper">
                        <button type="button" class="stepper-btn" data-step="-1" aria-label="Decrease guests">&minus;</button>
                        <input type="number" id="bk-guests" min="1" value="1" readonly>
                        <button type="button" class="stepper-btn" data-step="1" aria-label="Increase guests">+</button>
                    </div>
                </div>
                <div class="field">
                    <label for="bk-requests">Special requests (optional)</label>
                    <textarea id="bk-requests" rows="2" maxlength="1000"></textarea>
                    <span class="char-count" id="bk-requests-count">0 / 1000</span>
                </div>
                <div id="booking-feedback" class="feedback"></div>
                <button type="submit" class="btn btn-primary btn-block">Confirm Booking</button>
            </form>
        </div>
    </div>

    <!-- ============ ROOM TYPE MODAL (admin) ============ -->
    <div id="room-type-modal" class="modal-overlay hidden">
        <div class="modal">
            <button class="modal-close" data-close-modal="room-type-modal">&times;</button>
            <h3 id="room-type-modal-title">Add Room Type</h3>
            <form id="room-type-form">
                <input type="hidden" id="rt-id">
                <div class="field">
                    <label for="rt-name">Name</label>
                    <input type="text" id="rt-name" required>
                </div>
                <div class="field">
                    <label for="rt-description">Description</label>
                    <textarea id="rt-description" rows="2"></textarea>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="rt-price">Base price/night</label>
                        <input type="number" id="rt-price" step="0.01" min="0" required>
                    </div>
                    <div class="field">
                        <label for="rt-capacity">Capacity</label>
                        <input type="number" id="rt-capacity" min="1" required>
                    </div>
                </div>
                <div class="field">
                    <label for="rt-amenities-input">Amenities</label>
                    <div class="tag-input" id="rt-amenities-tags">
                        <input type="text" id="rt-amenities-input" placeholder="Type an amenity and press Enter&hellip;" autocomplete="off">
                    </div>
                    <div class="tag-input-suggestions hidden" id="rt-amenities-suggestions"></div>
                </div>
                <div id="room-type-feedback" class="feedback"></div>
                <button type="submit" class="btn btn-primary btn-block">Save Room Type</button>
            </form>
        </div>
    </div>

    <!-- ============ ROOM MODAL (admin) ============ -->
    <div id="room-modal" class="modal-overlay hidden">
        <div class="modal">
            <button class="modal-close" data-close-modal="room-modal">&times;</button>
            <h3 id="room-modal-title">Add Room</h3>
            <form id="room-form">
                <input type="hidden" id="rm-id">
                <div class="field">
                    <label for="rm-type">Room type</label>
                    <select id="rm-type" required></select>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="rm-number">Room number</label>
                        <input type="text" id="rm-number" required>
                    </div>
                    <div class="field">
                        <label for="rm-floor">Floor</label>
                        <input type="number" id="rm-floor" min="0">
                    </div>
                </div>
                <div class="field">
                    <label for="rm-status">Status</label>
                    <select id="rm-status">
                        <option value="available">Available</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div id="room-feedback" class="feedback"></div>
                <button type="submit" class="btn btn-primary btn-block">Save Room</button>
            </form>
        </div>
    </div>

    <!-- ============ CONFIRM DIALOG ============ -->
    <div id="confirm-modal" class="modal-overlay hidden">
        <div class="modal modal-small">
            <h3 id="confirm-modal-title">Are you sure?</h3>
            <p class="muted" id="confirm-modal-body"></p>
            <div class="confirm-actions">
                <button type="button" class="btn btn-ghost" id="confirm-modal-cancel">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirm-modal-confirm">Delete</button>
            </div>
        </div>
    </div>

    <!-- ============ SHARED DATE PICKER POPUP ============ -->
    <div id="date-picker-popup" class="date-picker-popup hidden">
        <div class="dp-header">
            <button type="button" class="dp-nav" id="dp-prev" aria-label="Previous month">&lsaquo;</button>
            <span id="dp-month-label"></span>
            <button type="button" class="dp-nav" id="dp-next" aria-label="Next month">&rsaquo;</button>
        </div>
        <div class="dp-weekdays">
            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
        </div>
        <div class="dp-grid" id="dp-grid"></div>
        <div class="dp-footer">
            <button type="button" class="dp-link" id="dp-clear">Clear</button>
            <button type="button" class="dp-link" id="dp-today">Today</button>
        </div>
    </div>

    <!-- ============ CHATBOT ============ -->
    <div id="chatbot-widget">
        <button id="chatbot-toggle" aria-label="Open booking assistant">💬</button>
        <div id="chatbot-panel" class="hidden">
            <div class="chatbot-header">Booking Assistant</div>
            <div id="chatbot-messages"></div>
            <form id="chatbot-form">
                <input type="text" id="chatbot-input" placeholder="Ask about rooms, prices, availability&hellip;" autocomplete="off">
                <button type="submit" class="btn btn-primary">Send</button>
            </form>
        </div>
    </div>

    <div id="toast-container"></div>

    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
