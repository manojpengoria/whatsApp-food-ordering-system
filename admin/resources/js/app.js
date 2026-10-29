// Mobile sidebar toggle
document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-sidebar-toggle]');
    if (!toggle) return;
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const open = sidebar.classList.toggle('-translate-x-full') === false;
    backdrop.classList.toggle('hidden', !open);
    document.body.classList.toggle('overflow-hidden', open);
});

// Ask before destructive actions: <form data-confirm="Delete this item?">
document.addEventListener('submit', (e) => {
    const msg = e.target.dataset.confirm;
    if (msg && !window.confirm(msg)) e.preventDefault();
});

// Auto-refresh pages like the kitchen board, unless the user is typing.
const refresh = document.querySelector('[data-auto-refresh]');
if (refresh) {
    const seconds = Number(refresh.dataset.autoRefresh) || 20;
    setInterval(() => {
        const el = document.activeElement;
        const typing = el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName);
        if (!typing && document.visibilityState === 'visible') window.location.reload();
    }, seconds * 1000);
}

// Chat boxes open at the latest message.
document.querySelectorAll('[data-scroll-bottom]').forEach((el) => { el.scrollTop = el.scrollHeight; });

// Show a preview of the chosen photo before upload.
document.querySelectorAll('input[type=file][data-preview]').forEach((input) => {
    input.addEventListener('change', () => {
        const img = document.querySelector(input.dataset.preview);
        const file = input.files && input.files[0];
        if (!img || !file) return;
        img.src = URL.createObjectURL(file);
        img.classList.remove('hidden');
    });
});

// ---------- Billing (POS) screen ----------
const pos = document.querySelector('[data-pos]');
if (pos) {
    const taxBp = Number(pos.dataset.taxBp) || 0;
    const lines = new Map(); // id -> {name, price, qty}
    const rupees = (p) => '₹' + (p % 100 === 0 ? (p / 100).toLocaleString('en-IN') : (p / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    const list = pos.querySelector('[data-pos-lines]');
    const inputs = pos.querySelector('[data-pos-inputs]');
    const submit = pos.querySelector('[data-pos-submit]');

    const render = () => {
        list.innerHTML = '';
        inputs.innerHTML = '';
        let subtotal = 0;
        lines.forEach((l, id) => {
            subtotal += l.price * l.qty;
            const li = document.createElement('li');
            li.className = 'flex items-center gap-2 py-2 text-sm';
            li.innerHTML = `<span class="min-w-0 flex-1 truncate"></span>
                <button type="button" class="btn-ghost size-7 p-0" data-dec aria-label="Less">−</button>
                <span class="w-6 text-center font-medium">${l.qty}</span>
                <button type="button" class="btn-ghost size-7 p-0" data-inc aria-label="More">+</button>
                <span class="w-20 text-right">${rupees(l.price * l.qty)}</span>`;
            li.querySelector('span').textContent = l.name;
            li.querySelector('[data-dec]').onclick = () => { l.qty--; if (l.qty <= 0) lines.delete(id); render(); };
            li.querySelector('[data-inc]').onclick = () => { if (l.qty < 50) l.qty++; render(); };
            list.appendChild(li);
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = `lines[${id}]`; input.value = l.qty;
            inputs.appendChild(input);
        });
        if (!lines.size) list.innerHTML = '<li class="py-6 text-center text-sm text-muted">No items yet</li>';
        const tax = Math.floor((subtotal * taxBp + 5000) / 10000);
        pos.querySelector('[data-pos-subtotal]').textContent = rupees(subtotal);
        pos.querySelector('[data-pos-tax]').textContent = rupees(tax);
        pos.querySelector('[data-pos-total]').textContent = rupees(subtotal + tax);
        submit.disabled = !lines.size;
    };

    pos.querySelectorAll('[data-pos-item]').forEach((btn) => btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const l = lines.get(id) || { name: btn.dataset.name, price: Number(btn.dataset.price), qty: 0 };
        if (l.qty < 50) l.qty++;
        lines.set(id, l);
        render();
    }));

    pos.querySelector('[data-pos-search]').addEventListener('input', (e) => {
        const q = e.target.value.trim().toLowerCase();
        pos.querySelectorAll('[data-pos-item]').forEach((b) => {
            b.hidden = q && !(b.dataset.name.toLowerCase().includes(q) || b.dataset.code.toLowerCase() === q);
        });
        pos.querySelectorAll('[data-pos-category]').forEach((c) => {
            c.hidden = ![...c.querySelectorAll('[data-pos-item]')].some((b) => !b.hidden);
        });
    });

    const tableBox = pos.querySelector('[data-pos-table]');
    const syncType = () => {
        const dine = pos.querySelector('[data-pos-type]:checked')?.value === 'dine_in';
        tableBox.hidden = !dine;
    };
    pos.querySelectorAll('[data-pos-type]').forEach((r) => r.addEventListener('change', syncType));
    syncType();
    render();
}

// ---------- Live order alerts (poll every 5 s) ----------
(() => {
    const body = document.body;
    const url = body.dataset.liveUrl;
    if (!url) return;
    let after = Number(body.dataset.liveAfter) || 0;
    let since = Number(body.dataset.liveNow) || Math.floor(Date.now() / 1000);
    const toasts = document.querySelector('[data-live-toasts]');
    const badge = document.querySelector('[data-live-badge]');
    const toggle = document.querySelector('[data-sound-toggle]');
    const baseTitle = document.title;
    let soundOn = false;
    let audio = null;
    let flashTimer = null;

    const readPref = () => { try { return localStorage.getItem('wm-alarm') === 'on'; } catch { return false; } };
    const writePref = (v) => { try { localStorage.setItem('wm-alarm', v ? 'on' : 'off'); } catch { /* ignore */ } };

    // Browsers allow sound only after a click, so the alarm is switched on with a button.
    const setSound = (on) => {
        soundOn = on;
        writePref(on);
        if (!toggle) return;
        toggle.setAttribute('aria-pressed', String(on));
        toggle.querySelector('[data-sound-label]').textContent = on ? 'Order alarm on' : 'Turn on order alarm';
        toggle.classList.toggle('border-brand-600', on);
        toggle.classList.toggle('text-brand-700', on);
    };
    const unlockAudio = () => {
        if (!audio) audio = new (window.AudioContext || window.webkitAudioContext)();
        if (audio.state === 'suspended') audio.resume();
    };
    const alarm = () => {
        if (!soundOn || !audio) return;
        // Three rising double-beeps (~2.5 s)
        const t0 = audio.currentTime;
        for (let r = 0; r < 3; r++) {
            [880, 1175].forEach((freq, i) => {
                const start = t0 + r * 0.8 + i * 0.22;
                const osc = audio.createOscillator();
                const gain = audio.createGain();
                osc.type = 'square';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.25, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.2);
                osc.connect(gain).connect(audio.destination);
                osc.start(start);
                osc.stop(start + 0.22);
            });
        }
    };

    if (toggle) {
        toggle.addEventListener('click', () => {
            unlockAudio();
            setSound(!soundOn);
            if (soundOn) {
                alarm(); // short test so staff know it works
                if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
            }
        });
        // Remembered preference: unlock on the first click anywhere on the page.
        if (readPref()) {
            setSound(true);
            document.addEventListener('pointerdown', unlockAudio, { once: true });
        }
    }

    const toast = (title, lines, href, tone) => {
        const a = document.createElement('a');
        a.href = href;
        a.className = 'pointer-events-auto block rounded-xl border bg-white p-4 shadow-lg ring-1 transition hover:shadow-xl '
            + (tone === 'pay' ? 'border-emerald-200 ring-emerald-100' : 'border-brand-500 ring-brand-100');
        a.innerHTML = '<p class="text-sm font-semibold"></p><p class="mt-1 text-sm text-muted"></p><p class="mt-2 text-xs font-medium text-brand-700">Open order →</p>';
        a.children[0].textContent = title;
        a.children[1].textContent = lines;
        toasts.prepend(a);
        setTimeout(() => a.remove(), 60000);
    };
    const flashTitle = (text) => {
        clearInterval(flashTimer);
        let on = false;
        flashTimer = setInterval(() => { document.title = (on = !on) ? text : baseTitle; }, 1000);
        const stop = () => { clearInterval(flashTimer); document.title = baseTitle; };
        window.addEventListener('focus', stop, { once: true });
        document.addEventListener('pointerdown', stop, { once: true });
    };
    const desktop = (title, text, href) => {
        if (!('Notification' in window) || Notification.permission !== 'granted' || document.visibilityState === 'visible') return;
        const n = new Notification(title, { body: text, tag: href });
        n.onclick = () => { window.focus(); location.href = href; };
    };

    const poll = async () => {
        try {
            const res = await fetch(`${url}?after=${after}&since=${since}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.status === 401 || res.redirected) return; // logged out
            if (res.ok) {
                const d = await res.json();
                after = Math.max(after, d.latest_id);
                since = d.now;
                if (badge) {
                    badge.textContent = d.pending_count;
                    badge.classList.toggle('hidden', !d.pending_count);
                }
                d.new_orders.forEach((o) => {
                    const text = `${o.customer} · ${o.type} · ${o.items} items · ${o.total} · ${o.payment}`;
                    toast(`🛎️ New WhatsApp order #${o.number}`, text, o.url, 'order');
                    desktop(`New order #${o.number}`, text, o.url);
                });
                d.payments.forEach((p) => toast(`✅ Paid online #${p.number}`, `${p.total} received via Razorpay`, p.url, 'pay'));
                if (d.new_orders.length) {
                    alarm();
                    flashTitle(`🛎️ New order (${d.new_orders.length})`);
                    // Pages showing order lists pick up the new order on their own.
                    if (document.querySelector('[data-refresh-on-order]')) setTimeout(() => location.reload(), 4000);
                }
            }
        } catch { /* network blip: try again next round */ }
        setTimeout(poll, document.visibilityState === 'visible' ? 5000 : 10000);
    };
    setTimeout(poll, 5000);
})();
