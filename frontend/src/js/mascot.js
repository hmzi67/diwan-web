// Diwan's helper: a page guide that glides beside the section being read,
// thinks, then says one short line about it, and watches the form fields: it
// stands on the one in focus, gets angry at a wrong value (shakes, turns red)
// and cheers once it is fixed. Drawn by @bible-strong/avatar-web (vendored in
// js/lib/, MIT). The character is the Avatar Lab export diwan-dark.avatar.json,
// inlined because .htaccess refuses to serve .json. A module, so nothing here
// is global.
//
// Not on phones: there is no room beside anything, and they are spared the
// 70 KB download. Reduced motion: the library holds a still picture, the
// helper jumps instead of gliding, and lines appear whole without pops.
const HIDDEN_KEY = 'diwan-helper-hidden';
let hiddenByVisitor = false;
try { hiddenByVisitor = sessionStorage.getItem(HIDDEN_KEY) === '1'; } catch {}

if (matchMedia('(min-width: 640px)').matches && !hiddenByVisitor) {
  const { createAvatar } = await import('./lib/avatar-web-1.1.0.js');

  const character = {
    name: 'Diwan Dark',
    body: {
      primary: { type: 'cube', width: 176, height: 152, depth: 130, roundness: 0.88 },
      nodes: [
        { surface: { type: 'sphere', width: 78, height: 78, depth: 42, roundness: 1 }, position: [-76, -72, -14], rotation: [0, 0, 0] },
        { surface: { type: 'sphere', width: 78, height: 78, depth: 42, roundness: 1 }, position: [76, -72, -14], rotation: [0, 0, 0] },
        { surface: { type: 'cylinder', width: 52, height: 56, depth: 64, roundness: 1.5, morphRoundness: 0.45 }, position: [0, -74, -14], rotation: [0, 0, 0] },
      ],
    },
    colors: { body: '#00e5a0', eyes: '#000000' },
    face: {
      eyes: { width: 1.15, height: 1.15, gap: 1.05, shiftY: -0.06 },
      nose: { enabled: true, shape: 'inverted-triangle', roundness: 65, width: 12, height: 9, x: 0, y: 18, angle: 0 },
      mouth: { enabled: true, shape: 'curve', curve: 50, width: 18, height: 6, x: 0, y: 32, angle: 0 },
      perspective: 1,
    },
  };

  const S = 96;      // the helper's box, px
  const EDGE = 16;   // kept this far from the window's edges
  const TOP = 88;    // and below the fixed header
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const pricing = document.getElementById('pricing') ? '#pricing' : '/pricing.html';

  // Said the first time each section is read. Keyed by section id; 'hero' is the page's first section.
  const LINES = {
    hero: { text: "Hi! I'm Diwan's helper. Scroll and I'll show you around.", action: ['See the plans', pricing] },
    demo: { text: 'One minute, from start to receipt. Press play!' },
    pricing: { text: 'Simple monthly plans. Pick one and request access.' },
    faq: { text: 'Got a question? The answer is probably down here.' },
    download: { text: 'Ready? Get Diwan running on your counter.' },
  };
  // Said the first time a field is focused. Keyed by name, then by type.
  const FIELD_LINES = {
    email: 'Just your email, so we can reply.',
    license_key: 'Paste your licence key to get your installer link.',
    message: "Take your time, I'm listening.",
  };
  // Said, crossly, about a field with a wrong value.
  const complaint = (el) => {
    if (!el.value.trim()) return 'Hey! You skipped this one.';
    if (el.type === 'email') return "Hey! That's not a real email.";
    if (el.tagName === 'TEXTAREA' || el.validity.tooShort) return 'Too short! Tell us a bit more.';
    return "Hmm! That's not right.";
  };

  const off = new AbortController();   // every listener goes when the visitor hides the helper
  const on = (target, type, fn, opts = {}) => target.addEventListener(type, fn, { ...opts, signal: off.signal });
  const wait = (ms) => new Promise((done) => setTimeout(done, ms));
  const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);

  // ── markup ───────────────────────────────────────────────────────────
  const root = document.createElement('div');
  root.className = 'group fixed top-0 left-0 z-40 drop-shadow-[0_0_22px_var(--color-glow)] transition-[filter] duration-500 will-change-transform data-[mood=angry]:drop-shadow-[0_0_26px_rgb(255_77_61/.6)]';
  root.innerHTML = `
    <button type="button" class="block cursor-pointer border-0 bg-transparent p-0" aria-label="Diwan helper"></button>
    <button type="button" class="absolute -top-1 -right-1 grid size-6 cursor-pointer place-items-center rounded-full border border-line bg-surface text-[.85rem] leading-none text-muted opacity-0 transition-opacity group-hover:opacity-100 hover:text-ink focus-visible:opacity-100" aria-label="Hide the helper">&times;</button>`;
  const [hit, hideButton] = root.querySelectorAll('button');

  // The outer box only moves; the card inside it pops, fades and changes colour.
  const bubble = document.createElement('div');
  bubble.className = 'fixed top-0 left-0 z-40 will-change-transform';
  bubble.hidden = true;
  bubble.innerHTML = `
    <div class="relative w-max max-w-[17rem] rounded-2xl bg-[#e9fff5] px-4 py-3 text-[.9rem] leading-snug font-medium text-[#04120c] shadow-[0_14px_40px_-14px_rgb(0_229_160/.6),0_0_0_1px_rgb(0_229_160/.3)] transition-[background-color,color,box-shadow] duration-300 data-[mood=angry]:bg-[#ffebe8] data-[mood=angry]:text-[#5c0e07] data-[mood=angry]:shadow-[0_14px_40px_-14px_rgb(255_77_61/.65),0_0_0_1px_rgb(255_77_61/.4)]">
      <span class="absolute size-3.5 rotate-45 rounded-[3px] bg-inherit"></span>
      <p class="relative" aria-hidden="true"></p>
      <a class="relative mt-2 inline-block rounded-full bg-[#04120c] px-3 py-1.5 text-[.82rem] font-semibold text-white transition-colors hover:bg-black" hidden></a>
    </div>`;
  const card = bubble.firstElementChild;
  const [tail, said, act] = card.children;
  const live = document.createElement('p');   // screen readers get the whole line once, not every typed letter
  live.className = 'sr-only';
  live.setAttribute('aria-live', 'polite');

  document.body.append(bubble, root, live);
  const mascot = createAvatar(hit, { character, state: 'waking', size: S, followPointer: true, ariaLabel: 'Diwan helper' });

  // Reactions loop until told otherwise, so each one may carry its way back to idle.
  let back;
  const react = (state, ms) => {
    clearTimeout(back);
    mascot.setState(state);
    root.dataset.mood = state === 'angry' ? 'angry' : '';
    if (ms) back = setTimeout(() => react('idle'), ms);
  };

  // ── motion: a spring towards the goal, the bubble riding along ─────────
  // Stiffness and damping give a quick glide with a small overshoot.
  const K = 140, D = 16;
  let x = innerWidth - S - EDGE, y = innerHeight - S - EDGE;   // starts in the corner, glides in
  let gx = x, gy = y, vx = 0, vy = 0, raf = 0, last = 0;
  let bw = 0, bh = 0;   // the card's size, measured when its text changes

  const draw = () => {
    root.style.transform = `translate3d(${x}px, ${y}px, 0)`;
    if (bubble.hidden) return;
    const bx = clamp(x + S * 0.55, EDGE, innerWidth - bw - EDGE);
    const above = y - bh - 14 >= TOP;
    const by = above ? y - bh - 14 : y + S + 14;
    const tailX = clamp(x + S / 2 - bx - 7, 14, bw - 28);   // the tail points at the helper
    bubble.style.transform = `translate3d(${bx}px, ${by}px, 0)`;
    tail.style.left = `${tailX}px`;
    tail.style.top = above ? '' : '-6px';
    tail.style.bottom = above ? '-6px' : '';
    card.style.transformOrigin = `${tailX + 7}px ${above ? '100%' : '0%'}`;
  };
  const step = (now) => {
    const dt = Math.min((now - last) / 1000, 1 / 30);
    last = now;
    vx += (K * (gx - x) - D * vx) * dt;
    vy += (K * (gy - y) - D * vy) * dt;
    x += vx * dt;
    y += vy * dt;
    if (Math.abs(gx - x) + Math.abs(gy - y) < 0.5 && Math.abs(vx) + Math.abs(vy) < 5) {
      x = gx; y = gy; vx = vy = 0; raf = 0;
    } else raf = requestAnimationFrame(step);
    draw();
  };
  const moveTo = (nx, ny) => {
    gx = nx; gy = ny;
    if (reduced) { x = gx; y = gy; draw(); }
    else if (!raf) { last = performance.now(); raf = requestAnimationFrame(step); }
  };

  // ── where it stands ──────────────────────────────────────────────────
  const sections = [...document.querySelectorAll('main section')]
    .map((el, i) => ({ el, key: i === 0 ? 'hero' : el.id, heading: el.querySelector('h1, h2') }))
    .filter((s) => s.heading);
  let anchor = null, field = null, current = null;

  // A heading's own text, not its full-width block; a field's box.
  const boxOf = (el) => {
    if (!/^H\d$/.test(el.tagName)) return el.getBoundingClientRect();
    const range = document.createRange();
    range.selectNodeContents(el);
    return range.getBoundingClientRect();
  };

  const place = () => {
    let nx = innerWidth - S - EDGE, ny = innerHeight - S - EDGE;   // no anchor: the corner
    if (anchor) {
      const r = boxOf(anchor);
      nx = r.right + 20;
      ny = r.top + r.height / 2 - S / 2;
      // A heading with no room on its right: its left. A field never goes left (the
      // field beside it is there); it, or a heading with no room either, gets the
      // helper standing on its top edge, at its right end.
      if (nx > innerWidth - S - EDGE && /^H\d$/.test(anchor.tagName)) nx = r.left - S - 20;
      if (nx < EDGE || nx > innerWidth - S - EDGE) { nx = r.right - S; ny = r.top - S + 14; }
    }
    moveTo(clamp(nx, EDGE, innerWidth - S - EDGE), clamp(ny, TOP, innerHeight - S - EDGE));
  };

  // The section being read: the last one whose top is above the middle of the window.
  const follow = () => {
    if (!field && sections.length) {
      let s = sections[0];
      for (const t of sections) if (t.el.getBoundingClientRect().top < innerHeight / 2) s = t;
      anchor = s.heading;
      if (s !== current) {
        current = s;
        mascot.poke('bounce');
        const line = LINES[s.key];
        if (line && !s.said) { s.said = true; say(line); } else hush();
      }
    }
    place();
  };

  // ── talking ──────────────────────────────────────────────────────────
  let talk = 0, hushTimer;
  const measure = () => { bw = card.offsetWidth; bh = card.offsetHeight; draw(); };
  const pop = () => {
    if (!bubble.hidden) return measure();
    bubble.hidden = false;
    measure();
    if (!reduced) card.animate(
      [{ opacity: 0, transform: 'scale(.6)' }, { opacity: 1, transform: 'scale(1)' }],
      { duration: 340, easing: 'cubic-bezier(.34,1.56,.64,1)' },
    );
  };
  const hush = () => {
    const me = ++talk;
    clearTimeout(hushTimer);
    if (bubble.hidden) return;
    if (reduced) { bubble.hidden = true; return; }
    card.animate(
      [{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(.85)' }],
      { duration: 180, easing: 'ease-in' },
    ).onfinish = () => { if (me === talk) bubble.hidden = true; };
  };
  // A normal line is thought about ("…"), then typed. An angry one bursts out whole.
  const say = async ({ text, action, angry = false }) => {
    const me = ++talk;
    clearTimeout(hushTimer);
    card.dataset.mood = angry ? 'angry' : '';
    act.hidden = true;
    act.style.visibility = '';
    card.style.width = '';
    said.textContent = angry ? text : '…';
    pop();
    if (!angry) {
      react('curious');
      await wait(reduced ? 0 : 650);
      if (me !== talk) return;
      react('happy', 1500);
    }
    live.textContent = text;
    // Sized for the whole line first, so typing never reshapes or moves the bubble.
    said.textContent = text;
    card.style.width = `${card.offsetWidth}px`;
    if (action) { [act.textContent, act.href] = action; act.hidden = false; }
    measure();
    if (!reduced && !angry) {
      act.style.visibility = 'hidden';
      for (let i = 1; i <= text.length; i++) {
        said.textContent = text.slice(0, i);
        await wait(22);
        if (me !== talk) return;
      }
      act.style.visibility = '';
    }
    hushTimer = setTimeout(hush, angry ? 4500 : 7000);
  };
  on(card, 'pointerenter', () => clearTimeout(hushTimer));
  on(card, 'pointerleave', () => { if (!bubble.hidden) hushTimer = setTimeout(hush, 2500); });
  on(act, 'click', hush);

  // ── fields ───────────────────────────────────────────────────────────
  const watched = (el) => el.matches?.('input, textarea, select') && el.type !== 'hidden'
    && el.tabIndex >= 0 && !el.closest('[aria-hidden="true"]');   // never the honeypot
  // contact.js's email rule: the browser's alone lets "name@shop" through.
  const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const wrong = (el) => !el.validity.valid || (el.type === 'email' && !!el.value && !EMAIL.test(el.value.trim()));
  const SHAKE =[0, -8, 8, -6, 6, -3, 0].map((px) => ({ transform: `translateX(${px}px) rotate(${px / 2}deg)` }));
  let warnedAt = -Infinity, lookBack;

  // Cross at a field: goes to it, shakes, turns red, says what is wrong.
  const scold = (el) => {
    if (performance.now() - warnedAt < 400) return;   // one submit can flag several fields: the first is enough
    warnedAt = performance.now();
    // Cheers when it is fixed, but only for a wrong format: a skipped field
    // would count as fixed at its first letter.
    const v = el.validity;
    if ((el.type === 'email' && el.value) || v.typeMismatch || v.patternMismatch || v.tooShort) el.helperFlagged = true;
    anchor = el;
    place();
    react('angry', 2800);
    if (!reduced) hit.animate(SHAKE, { duration: 520, easing: 'ease-in-out' });
    say({ text: complaint(el), angry: true });
    clearTimeout(lookBack);   // then back to whichever field is in focus
    lookBack = setTimeout(() => { if (field) { anchor = field; place(); } }, 2800);
  };

  on(document, 'focusin', (e) => {
    if (!watched(e.target)) return;
    field = e.target;
    if (performance.now() - warnedAt < 2500) return;   // still pointing at the field it is cross about
    anchor = field;
    place();
    react('listening');
    const line = FIELD_LINES[field.name] ?? FIELD_LINES[field.type];
    if (line && !field.helperSaid) { field.helperSaid = true; say({ text: line }); }
  });
  on(document, 'focusout', (e) => {
    if (e.target !== field) return;
    const el = field;
    field = null;
    if (el.value && el.type !== 'radio' && el.type !== 'checkbox') {
      if (wrong(el)) return scold(el);
      react('happy', 1800);
    } else react('idle');
    follow();
  });
  // A field it was cross about, now right: cheers up.
  on(document, 'input', (e) => {
    const el = e.target;
    if (!el.helperFlagged || !el.value || wrong(el)) return;
    el.helperFlagged = false;
    react('happy', 1800);
    mascot.poke('bounce');
    say({ text: "That's better, thank you!" });
  });
  // A submit the browser refused…
  on(document, 'invalid', (e) => { if (watched(e.target)) scold(e.target); }, { capture: true });
  // …or one a page script refused (contact.js marks the field aria-invalid).
  const marks = new MutationObserver((records) => {
    const el = records.find((r) => r.target.getAttribute('aria-invalid') === 'true' && watched(r.target))?.target;
    if (el) scold(el);
  });
  marks.observe(document.body, { subtree: true, attributeFilter: ['aria-invalid'] });

  // ── scrolling, the pointer, idling ───────────────────────────────────
  let queued = false, lastY = scrollY, glance;
  const onScroll = () => {
    const dy = scrollY - lastY;
    lastY = scrollY;
    if (dy) {   // glances the way the page moves
      mascot.setLookAt([0, dy > 0 ? -0.6 : 0.6]);
      clearTimeout(glance);
      glance = setTimeout(() => mascot.setLookAt(null), 350);
    }
    if (!queued) { queued = true; requestAnimationFrame(() => { queued = false; follow(); }); }
  };
  on(window, 'scroll', onScroll, { passive: true });
  on(window, 'resize', onScroll);

  on(hit, 'click', () => { react('laughing', 2500); mascot.poke('bounce'); say({ text: 'Hehe, that tickles!' }); });

  // Primary buttons (bg-accent) and the pricing cards' buttons.
  on(document, 'pointerover', (e) => {
    if (root.dataset.mood !== 'angry' && e.target.closest?.('a.bg-accent, button.bg-accent, .plan__cta')) react('excited', 2000);
  });

  // Dozes after 25 s without input, sleeps 25 s later; any input wakes it.
  let quiet, nudged = false;
  const rouse = () => {
    clearTimeout(quiet);
    if (mascot.getState().state === 'sleeping') { react('waking'); say({ text: "Oh! You're back. Hi!" }); }
    else if (mascot.getState().state === 'drowsy') react('waking');
    quiet = setTimeout(() => {
      react('drowsy');
      if (!nudged) { nudged = true; say({ text: "Still there? Take your time, I'll wait." }); }
      quiet = setTimeout(() => { hush(); react('sleeping'); }, 25000);
    }, 25000);
  };
  for (const type of ['pointermove', 'keydown', 'scroll']) on(window, type, rouse, { passive: true });

  on(hideButton, 'click', () => {
    try { sessionStorage.setItem(HIDDEN_KEY, '1'); } catch {}
    off.abort();
    marks.disconnect();
    talk++;
    [back, quiet, glance, hushTimer, lookBack].forEach(clearTimeout);
    cancelAnimationFrame(raf);
    mascot.destroy();
    root.remove();
    bubble.remove();
    live.remove();
  });

  rouse();
  follow();
}
