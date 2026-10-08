/*
 * CLI Mode: the browser side of the fake terminal. Talks to the JSON endpoints of the package (boot, exec, complete),
 * renders the ANSI output of the server, keeps history, completes with Tab. No dependencies, nothing to do with the
 * theme: the overlay brings its own CSS (css/terminal.css).
 */
(function () {
    'use strict';
    var cfg = window.CliModeConfig;
    if (!cfg || window.CliMode) {
        return;
    }

    var forced = !!cfg.forced;
    // mandatory mode and the replaced 404 page both turn the terminal into the page: full screen, no way to close it
    var locked = forced || !!cfg.notfound;
    var THEMES = ['blackout', 'green', 'amber', 'matrix', 'paper'];
    var STORE = { theme: 'climTheme', log: 'climLog', booted: 'climBooted', reopen: 'climReopen', landed: 'climLanded' };
    var root, screen, out, promptEl, input, titleEl, launcher, keysEl;
    var state = { open: false, booted: false, busy: false, token: '', prompt: null, history: [], index: -1, draft: '', opener: null, lastTab: 0, login: null, reload: false };

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }

    function store(key, value) {
        try {
            if (value === undefined) { return window.sessionStorage.getItem(key); }
            if (value === null) { window.sessionStorage.removeItem(key); } else { window.sessionStorage.setItem(key, value); }
        } catch (e) { /* private mode */ }
        return null;
    }

    function esc(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ANSI SGR codes (what Symfony Console and our messages emit) to HTML spans. */
    function ansi(text) {
        var html = '', st = { fg: null, bg: null, b: false, u: false, r: false }, re = /\x1b\[([0-9;]*)m/g, last = 0, m;
        function chunk(str) {
            if (!str) { return; }
            var cls = [];
            if (st.fg !== null) { cls.push('a' + st.fg); }
            if (st.bg !== null) { cls.push('b' + st.bg); }
            if (st.b) { cls.push('ab'); }
            if (st.u) { cls.push('au'); }
            if (st.r) { cls.push('ar'); }
            var safe = esc(str).replace(/(https?:\/\/[^\s<>"']+[^\s<>"'.,;:!?)])/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
            html += cls.length ? '<span class="' + cls.join(' ') + '">' + safe + '</span>' : safe;
        }
        while ((m = re.exec(text)) !== null) {
            chunk(text.slice(last, m.index));
            last = re.lastIndex;
            (m[1] === '' ? ['0'] : m[1].split(';')).forEach(function (code) {
                var n = parseInt(code, 10);
                if (n === 0) { st = { fg: null, bg: null, b: false, u: false, r: false }; }
                else if (n === 1) { st.b = true; } else if (n === 22) { st.b = false; }
                else if (n === 4) { st.u = true; } else if (n === 24) { st.u = false; }
                else if (n === 7) { st.r = true; } else if (n === 27) { st.r = false; }
                else if ((n >= 30 && n <= 37) || (n >= 90 && n <= 97)) { st.fg = n; }
                else if (n === 39) { st.fg = null; }
                else if ((n >= 40 && n <= 47) || (n >= 100 && n <= 107)) { st.bg = n >= 100 ? n - 60 : n; }
                else if (n === 49) { st.bg = null; }
            });
        }
        chunk(text.slice(last));
        return html;
    }

    function print(text, cls) {
        if (text === '') { return; }
        var block = el('div', 'clim-block' + (cls ? ' ' + cls : ''));
        block.innerHTML = ansi(text.replace(/\n$/, ''));
        out.appendChild(block);
        scroll();
        persist();
    }

    function scroll() {
        screen.scrollTop = screen.scrollHeight;
    }

    function persist() {
        // keep the last part of the session across page loads (navigating with "open" must not feel like a reboot)
        var blocks = out.children, keep = [];
        for (var i = Math.max(0, blocks.length - 150); i < blocks.length; i++) { keep.push(blocks[i].outerHTML); }
        store(STORE.log, keep.join(''));
    }

    function promptHtml(p) {
        p = p || state.prompt;
        if (!p) { return ''; }
        var cwd = p.cwd === '/' ? '~' : p.cwd;
        return '<span class="clim-u">' + esc(p.user) + '@' + esc(p.host) + '</span>:<span class="clim-p">' + esc(cwd) + '</span>' + (p.admin ? '# ' : '$ ');
    }

    function setPrompt(p) {
        state.prompt = p;
        promptEl.innerHTML = promptHtml();
        titleEl.textContent = 'snarkbash — ' + p.user + '@' + p.host;
    }

    function setTheme(name) {
        if (THEMES.indexOf(name) === -1) { name = 'blackout'; }
        root.setAttribute('data-theme', name);
        try { window.localStorage.setItem(STORE.theme, name); } catch (e) { /* ignore */ }
    }

    function request(url, data, method) {
        var body = null, opts = { method: method || 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (opts.method === 'POST') {
            body = new URLSearchParams();
            Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
            body.append('token', state.token);
            if (cfg.preview) { body.append('preview', '1'); }
            if (cfg.notfound) { body.append('notfound', '1'); body.append('path', cfg.path || '/'); }
            opts.body = body;
        }
        return fetch(url, opts).then(function (r) {
            return r.json().then(function (json) {
                if (!r.ok) { throw new Error(json && json.error ? json.error : 'HTTP ' + r.status); }
                return json;
            });
        });
    }

    function fakeBoot() {
        var lines = [
            '\x1b[32m[  OK  ]\x1b[39m Reached target Snark Services.',
            '\x1b[32m[  OK  ]\x1b[39m Started Webserver of Questionable Intent.',
            '\x1b[32m[  OK  ]\x1b[39m Mounted /var/www/lan.',
            '\x1b[33m[ WARN ]\x1b[39m Coffee level low.',
            '\x1b[32m[  OK  ]\x1b[39m Started snarkbash.'
        ];
        return new Promise(function (resolve) {
            var i = 0, skipped = false;
            function skip() { skipped = true; }
            document.addEventListener('keydown', skip, { once: true });
            (function next() {
                if (skipped || i >= lines.length) { resolve(); return; }
                print(lines[i++]);
                setTimeout(next, 90);
            }());
        });
    }

    function boot() {
        state.busy = true;
        input.disabled = true;
        var restored = store(STORE.log);
        var bootUrl = cfg.boot + '?path=' + encodeURIComponent(cfg.path || '/') + (cfg.preview ? '&preview=1' : '') + (cfg.notfound ? '&notfound=1' : '');
        return request(bootUrl, null, 'GET').then(function (data) {
            state.token = data.token;
            state.history = data.history || [];
            setPrompt(data.prompt);
            if (restored && !cfg.notfound) {
                out.innerHTML = restored;
                if (data.landing && store(STORE.landed) !== cfg.path) { print(data.landing); }
                store(STORE.landed, cfg.path || '/');
                scroll();
                return null;
            }
            var first = !store(STORE.booted) && !cfg.notfound;
            store(STORE.booted, '1');
            store(STORE.landed, cfg.path || '/');
            return (first ? fakeBoot() : Promise.resolve()).then(function () { print(data.banner); if (data.landing) { print(data.landing); } });
        }).catch(function (e) {
            print('\x1b[31m' + e.message + '\x1b[39m');
        }).then(function () {
            state.booted = true;
            state.busy = false;
            input.disabled = false;
            input.focus();
        });
    }

    function open() {
        if (state.open) { return; }
        state.open = true;
        state.opener = document.activeElement;
        root.hidden = false;
        document.documentElement.classList.add('clim-active');
        launcher && (launcher.hidden = true);
        if (!state.booted) { boot(); } else { input.focus(); }
        scroll();
    }

    function close() {
        if (!state.open || locked) { return; }
        if (state.login) { endLogin(); }
        state.open = false;
        root.hidden = true;
        document.documentElement.classList.remove('clim-active');
        launcher && (launcher.hidden = false);
        if (state.opener && state.opener.focus) { try { state.opener.focus(); } catch (e) { /* ignore */ } }
        // signed in meanwhile: the page behind the terminal still shows the guest
        if (state.reload) { window.location.reload(); }
    }

    function echoLine(text) {
        var line = el('div', 'clim-block clim-echo');
        line.textContent = text;
        out.appendChild(line);
        scroll();
        persist();
    }

    /*
     * The login prompt. The user name and the password are asked for here and sent to their own endpoint (cfg.login),
     * never as a command line: they do not end up in the history, in the screen that is kept between pages or in the
     * output. The password is a password field, so nothing of it is shown.
     */
    function startLogin(username) {
        state.login = { step: 'user', username: '' };
        if (username) {
            state.login.username = username;
            askPassword();
        } else {
            promptEl.textContent = 'login: ';
            input.type = 'text';
            input.value = '';
        }
    }

    function askPassword() {
        state.login.step = 'pass';
        promptEl.textContent = 'Password: ';
        input.type = 'password';
        input.value = '';
        input.setAttribute('autocomplete', 'current-password');
    }

    function endLogin() {
        state.login = null;
        input.type = 'text';
        input.value = '';
        input.setAttribute('autocomplete', 'off');
        if (state.prompt) { promptEl.innerHTML = promptHtml(); }
    }

    function submitLogin(password) {
        var username = state.login.username;
        state.busy = true;
        input.disabled = true;
        request(cfg.login, { username: username, password: password }).then(function (res) {
            print(res.output || '');
            if (res.token) { state.token = res.token; }
            endLogin();
            if (res.prompt) { setPrompt(res.prompt); }
            applyActions(res.actions);
        }).catch(function (e) {
            endLogin();
            print('\x1b[31m' + e.message + '\x1b[39m');
        }).then(function () {
            state.busy = false;
            input.disabled = false;
            if (state.open) { input.focus(); }
        });
    }

    function loginKey(e) {
        if (e.key === 'Escape' || (e.ctrlKey && (e.key === 'c' || e.key === 'C'))) {
            e.preventDefault();
            echoLine(state.login.step === 'user' ? 'login: ' + input.value + '^C' : 'Password: ^C');
            endLogin();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var value = input.value;
            input.value = '';
            if (state.login.step === 'user') {
                echoLine('login: ' + value);
                if (value.trim() === '') { endLogin(); return; }
                state.login.username = value.trim();
                askPassword();
            } else {
                echoLine('Password: ');
                submitLogin(value);
            }
        } else if (e.key === 'Tab' || e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
        }
    }

    function applyActions(actions) {
        (actions || []).forEach(function (a) {
            if (a.type === 'clear') { out.innerHTML = ''; persist(); }
            else if (a.type === 'theme') { setTheme(a.data.name); }
            else if (a.type === 'exit') { if (!locked) { setTimeout(close, 450); } }
            else if (a.type === 'login') { startLogin(a.data.username || ''); }
            else if (a.type === 'reload') {
                if (a.data.now) { setTimeout(function () { window.location.reload(); }, 900); } else { state.reload = true; }
            }
            else if (a.type === 'redirect') {
                // leaving for another page closes the terminal (data.reopen asks for it to come back there)
                if (a.data.reopen) { store(STORE.reopen, '1'); }
                setTimeout(function () {
                    close();
                    window.location.href = a.data.url;
                }, 700);
            }
        });
    }

    function run(line) {
        var echo = el('div', 'clim-block clim-echo');
        echo.innerHTML = promptHtml() + esc(line);
        out.appendChild(echo);
        scroll();
        persist();
        if (line.trim() === '') { return; }
        if (state.history[state.history.length - 1] !== line) { state.history.push(line); }
        state.busy = true;
        input.disabled = true;
        request(cfg.exec, { line: line }).then(function (res) {
            print(res.output || '');
            if (res.prompt) { setPrompt(res.prompt); }
            applyActions(res.actions);
        }).catch(function (e) {
            print('\x1b[31m' + e.message + '\x1b[39m');
        }).then(function () {
            state.busy = false;
            input.disabled = false;
            if (state.open) { input.focus(); }
        });
    }

    function complete() {
        var pos = input.selectionStart, before = input.value.slice(0, pos), after = input.value.slice(pos);
        request(cfg.complete, { line: before }).then(function (res) {
            var matches = res.matches || [], word = res.word || '';
            if (!matches.length) { return; }
            var prefix = matches[0];
            matches.forEach(function (m) {
                while (m.indexOf(prefix) !== 0) { prefix = prefix.slice(0, -1); }
            });
            var base = before.slice(0, before.length - word.length);
            if (matches.length === 1) {
                input.value = base + prefix + (/\/$/.test(prefix) ? '' : ' ') + after;
                var p = (base + prefix).length + (/\/$/.test(prefix) ? 0 : 1);
                input.setSelectionRange(p, p);
            } else if (prefix.length > word.length) {
                input.value = base + prefix + after;
                input.setSelectionRange((base + prefix).length, (base + prefix).length);
            } else {
                var echo = el('div', 'clim-block clim-echo');
                echo.innerHTML = promptHtml() + esc(before);
                out.appendChild(echo);
                print(matches.join('   '));
            }
        }).catch(function () { /* completion is a convenience */ });
    }

    function historyStep(by) {
        if (!state.history.length) { return; }
        if (state.index === -1) {
            if (by > 0) { return; }
            state.draft = input.value;
            state.index = state.history.length;
        }
        state.index = Math.max(0, Math.min(state.history.length, state.index + by));
        input.value = state.index === state.history.length ? state.draft : state.history[state.index];
        if (state.index === state.history.length) { state.index = -1; }
        setTimeout(function () { input.setSelectionRange(input.value.length, input.value.length); }, 0);
    }

    function onKey(e) {
        if (state.login && !state.busy) { loginKey(e); return; }
        if (e.key === 'Escape') { if (!locked) { e.preventDefault(); close(); } return; }
        if (state.busy) { if (e.key !== 'Tab') { return; } e.preventDefault(); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            var line = input.value;
            input.value = '';
            state.index = -1;
            run(line);
        } else if (e.key === 'ArrowUp') { e.preventDefault(); historyStep(-1); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); historyStep(1); }
        else if (e.key === 'Tab') { e.preventDefault(); complete(); }
        else if (e.ctrlKey && (e.key === 'l' || e.key === 'L')) { e.preventDefault(); out.innerHTML = ''; persist(); }
        else if (e.ctrlKey && (e.key === 'c' || e.key === 'C') && !window.getSelection().toString()) {
            e.preventDefault();
            var echo = el('div', 'clim-block clim-echo');
            echo.innerHTML = promptHtml() + esc(input.value) + '^C';
            out.appendChild(echo);
            input.value = '';
            state.index = -1;
            scroll();
        } else if (e.ctrlKey && (e.key === 'd' || e.key === 'D') && input.value === '') { e.preventDefault(); run('exit'); }
        else if (e.ctrlKey && (e.key === 'u' || e.key === 'U')) { e.preventDefault(); input.value = input.value.slice(input.selectionStart); }
    }

    function build() {
        root = el('div', 'clim' + (locked ? ' clim-forced' : ''));
        root.hidden = true;
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Terminal');
        var win = el('div', 'clim-window');
        var bar = el('div', 'clim-bar');
        var dots = el('span', 'clim-dots');
        dots.appendChild(el('i')); dots.appendChild(el('i')); dots.appendChild(el('i'));
        titleEl = el('span', 'clim-title', 'snarkbash');
        var closeBtn = el('button', 'clim-close', '×');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Close terminal');
        closeBtn.addEventListener('click', close);
        bar.appendChild(dots); bar.appendChild(titleEl);
        if (!locked) { bar.appendChild(closeBtn); }

        screen = el('div', 'clim-screen');
        screen.setAttribute('role', 'log');
        screen.setAttribute('aria-live', 'polite');
        out = el('div', 'clim-out');
        var line = el('div', 'clim-line');
        promptEl = el('span', 'clim-prompt');
        input = el('input', 'clim-input');
        input.type = 'text';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('autocapitalize', 'off');
        input.setAttribute('autocorrect', 'off');
        input.setAttribute('spellcheck', 'false');
        input.setAttribute('aria-label', 'Command');
        input.addEventListener('keydown', onKey);
        line.appendChild(promptEl); line.appendChild(input);
        screen.appendChild(out); screen.appendChild(line);
        screen.addEventListener('click', function () {
            if (!window.getSelection().toString()) { input.focus(); }
        });

        // touch devices have no Tab, arrows or Esc: a small row of keys
        keysEl = el('div', 'clim-keys');
        [['Tab', 'Tab'], ['↑', 'ArrowUp'], ['↓', 'ArrowDown']].concat(locked ? [] : [['Esc', 'Escape']]).forEach(function (k) {
            var b = el('button', '', k[0]);
            b.type = 'button';
            b.addEventListener('click', function () { onKey({ key: k[1], preventDefault: function () {}, ctrlKey: false }); input.focus(); });
            keysEl.appendChild(b);
        });

        win.appendChild(bar); win.appendChild(screen); win.appendChild(keysEl);
        root.appendChild(win);
        root.addEventListener('mousedown', function (e) { if (e.target === root && !locked) { close(); } });
        document.body.appendChild(root);

        if (cfg.launcher) {
            launcher = el('button', 'clim-launcher', '>_');
            launcher.type = 'button';
            launcher.title = cfg.title || 'Terminal';
            launcher.setAttribute('aria-label', cfg.title || 'Open the terminal');
            launcher.addEventListener('click', open);
            document.body.appendChild(launcher);
        }
        var saved = null;
        try { saved = window.localStorage.getItem(STORE.theme); } catch (e) { /* ignore */ }
        setTheme(saved || 'blackout');
    }

    function typing(target) {
        return target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    }

    function init() {
        build();
        document.addEventListener('keydown', function (e) {
            if (!locked && (e.key === '`' || e.key === '´' || e.code === 'Backquote') && !e.ctrlKey && !e.metaKey && !e.altKey && !typing(e.target)) {
                e.preventDefault();
                if (state.open) { close(); } else { open(); }
            }
        });
        window.addEventListener('hashchange', function () { if (window.location.hash === '#cli') { open(); } });
        if (locked || window.location.hash === '#cli' || store(STORE.reopen)) {
            store(STORE.reopen, null);
            open();
        }
        window.CliMode = { open: open, close: close };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
