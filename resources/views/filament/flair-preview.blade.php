{{--
    Live preview of the battlefield "orbiting halo" flair effect, embedded in
    the AiModelResource "Edit animation" modal (see AiModelResource::editAnimationAction()).

    Updates as the admin adjusts the color/duration fields below, with no
    Livewire round-trip: this canvas polls those fields' rendered `.value`
    every animation frame (60fps) rather than listening for an 'input' DOM
    event. That matters specifically for the color picker: its drag panel is
    a separate web component that updates Filament's own Alpine `state`
    directly, without ever firing a native 'input' event on the underlying
    text input -- only typing a hex value by hand does. Polling `.value`
    catches both interaction styles, since Alpine's x-model keeps that DOM
    property in sync regardless of which one changed the state. The two
    fields carry stable ids (`flair-preview-color-input` /
    `flair-preview-duration-input`, set via extraInputAttributes in the
    Action's ->schema()) so this view can find them without depending on
    Filament's own generated element ids. This view itself renders once per
    modal open -- it never depends on the live field state, so it is never
    replaced by a Livewire re-render.

    The whole Alpine component is defined INLINE in x-data rather than via a
    named function in a separate <script> tag: modalContent() is rendered on
    demand by a Livewire action call and injected into the DOM dynamically, so
    a <script> defining a function here would never execute (script elements
    inserted via innerHTML/morphing don't run) -- x-data's own expression is
    evaluated directly by Alpine regardless of when the element was inserted,
    which is what actually makes this show up at all.

    The drawing logic mirrors the real Phaser implementation in
    resources/js/battlefield/fighter/index.js + flair.js (ring math, spotlight
    sweep, additive burst) closely enough to give a faithful preview, but is
    an independent, simplified canvas port -- it has no dependency on the
    battlefield bundle and needs none of Phaser's scene/lifecycle machinery
    for a one-off admin preview.
--}}
<div
    x-data="{
        color: @js($color) || '#fbbf24',
        durationMs: @js($durationMs) || 6000,
        label: (@js($label) || 'MODEL').toUpperCase(),
        angle: 0,
        lastFrameAt: null,
        lastBurstAt: -Infinity,
        bursts: [],

        start() {
            this.burst();
            requestAnimationFrame(now => this.frame(now));
        },

        /**
         * Reads the two form fields' current DOM value every frame (see the
         * comment at the top of this file for why polling, not an event
         * listener). A genuine color change also replays the burst, so
         * picking a new color immediately shows what it looks like in
         * action rather than only changing the idle ring.
         */
        pollInputs() {
            const colorInput = document.getElementById('flair-preview-color-input');
            if (colorInput && colorInput.value && colorInput.value !== this.color) {
                this.color = colorInput.value;
                this.burst();
            }

            const durationInput = document.getElementById('flair-preview-duration-input');
            const durationValue = durationInput ? parseFloat(durationInput.value) : NaN;
            if (!Number.isNaN(durationValue) && durationValue > 0) {
                this.durationMs = durationValue;
            }
        },

        burst() {
            const now = performance.now();
            this.lastBurstAt = now;
            const particles = [];
            for (let i = 0; i < 10; i++) {
                const a = (i / 10) * Math.PI * 2 + Math.random() * 0.4;
                particles.push({ a, speed: 20 + Math.random() * 28, vy: -(40 + Math.random() * 34), size: 1.6 + Math.random() * 1.8 });
            }
            const spokes = Array.from({ length: 12 }, (_, i) => {
                const a = (i / 12) * Math.PI * 2 + (Math.random() - 0.5) * 0.22;
                const long = i % 3 === 0;
                return { a, len: long ? 70 + Math.random() * 24 : 32 + Math.random() * 22, width: long ? 2.4 : 1.2 };
            });
            this.bursts.push({ startedAt: now, particles, spokes });
            if (this.bursts.length > 2) this.bursts.shift();
        },

        burstT(now) {
            return now - this.lastBurstAt;
        },

        spinMultiplier(now) {
            const t = this.burstT(now);
            if (t < 0 || t > 1000) return 1;
            const p = t / 1000;
            return 1 + 3.2 * Math.pow(1 - p, 2.2);
        },

        spotlightBoost(a) {
            let d = a - Math.PI / 2;
            d = Math.atan2(Math.sin(d), Math.cos(d));
            return Math.max(0, Math.cos(d * 1.6));
        },

        darkenHex(hex, amount) {
            const m = /^#?([0-9a-f]{6})$/i.exec(hex);
            if (!m) return '#000000';
            const num = parseInt(m[1], 16);
            const ch = shift => Math.round(((num >> shift) & 0xff) * (1 - amount));
            const toHex = v => v.toString(16).padStart(2, '0');
            return `#${toHex(ch(16))}${toHex(ch(8))}${toHex(ch(0))}`;
        },

        buildRingChars() {
            const unit = `${this.label}  ✦  `;
            const step = 0.34;
            const repeats = Math.max(1, Math.round((Math.PI * 2) / step / unit.length));
            const n = repeats * unit.length;
            const chars = [];
            for (let i = 0; i < n; i++) {
                chars.push({ ch: unit[i % unit.length], phase: -(i / n) * Math.PI * 2 });
            }
            return chars;
        },

        drawFighter(ctx, cx, cy) {
            ctx.save();
            ctx.translate(cx, cy);
            ctx.beginPath();
            ctx.ellipse(0, 34, 20, 5, 0, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(0,0,0,.35)';
            ctx.fill();
            ctx.fillStyle = '#3a4258';
            ctx.beginPath();
            ctx.roundRect(-12, 2, 24, 32, 6);
            ctx.fill();
            ctx.fillStyle = '#4b5570';
            ctx.beginPath();
            ctx.arc(0, -13, 14, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        },

        drawGlyph(ctx, ch, x, y, alpha, scale, glow) {
            ctx.save();
            ctx.translate(x, y);
            ctx.scale(scale, scale);
            ctx.globalAlpha = alpha;
            ctx.font = '9px monospace';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.shadowColor = glow ? '#ffffff' : this.color;
            ctx.shadowBlur = glow ? 10 : 4;
            ctx.lineWidth = 2;
            ctx.strokeStyle = this.darkenHex(this.color, 0.65);
            ctx.strokeText(ch, 0, 0);
            ctx.fillStyle = this.color;
            ctx.fillText(ch, 0, 0);
            ctx.restore();
        },

        drawRing(ctx, cx, cy, back) {
            const rx = 40, ry = 15, headY = -4;
            this.buildRingChars().forEach(({ ch, phase }) => {
                const a = this.angle + phase;
                const sinA = Math.sin(a);
                if ((sinA < 0) !== back) return;
                const x = cx + Math.cos(a) * rx;
                const y = cy + headY + sinA * ry;
                const boost = back ? 0 : this.spotlightBoost(a);
                this.drawGlyph(ctx, ch, x, y, back ? 0.55 : 1, (back ? 0.7 : 1) * (1 + 0.4 * boost), boost > 0.6);
            });
        },

        drawBurst(ctx, cx, cy, now) {
            ctx.save();
            ctx.globalCompositeOperation = 'lighter';
            this.bursts.forEach(b => {
                const t = now - b.startedAt;
                if (t >= 0 && t <= 260) {
                    const p = t / 260;
                    const r = 4 + p * 20;
                    ctx.globalAlpha = 1 - p;
                    const grad = ctx.createRadialGradient(cx, cy - 4, 0, cx, cy - 4, r);
                    grad.addColorStop(0, '#ffffff');
                    grad.addColorStop(0.4, this.color);
                    grad.addColorStop(1, 'rgba(0,0,0,0)');
                    ctx.fillStyle = grad;
                    ctx.beginPath();
                    ctx.arc(cx, cy - 4, r, 0, Math.PI * 2);
                    ctx.fill();
                }
                if (t >= 0 && t <= 380) {
                    const p = t / 380;
                    const growth = Math.min(1, p / 0.35);
                    const alpha = p < 0.55 ? 1 : Math.max(0, 1 - (p - 0.55) / 0.45);
                    b.spokes.forEach(({ a, len, width }) => {
                        const l = len * growth;
                        const x0 = cx + Math.cos(a) * 6, y0 = cy - 4 + Math.sin(a) * 6;
                        const x1 = cx + Math.cos(a) * l, y1 = cy - 4 + Math.sin(a) * l;
                        const grad = ctx.createLinearGradient(x0, y0, x1, y1);
                        grad.addColorStop(0, '#ffffff');
                        grad.addColorStop(0.3, this.color);
                        grad.addColorStop(1, 'rgba(0,0,0,0)');
                        ctx.strokeStyle = grad;
                        ctx.lineWidth = width;
                        ctx.lineCap = 'round';
                        ctx.globalAlpha = alpha;
                        ctx.beginPath();
                        ctx.moveTo(x0, y0);
                        ctx.lineTo(x1, y1);
                        ctx.stroke();
                    });
                }
            });
            ctx.restore();

            ctx.save();
            this.bursts.forEach(b => {
                const t = now - b.startedAt;
                [0, 70].forEach(delay => {
                    const rt = t - delay;
                    if (rt < 0 || rt > 500) return;
                    const p = rt / 500;
                    ctx.save();
                    ctx.translate(cx, cy + 34);
                    ctx.beginPath();
                    ctx.ellipse(0, 0, 10 + p * 26, 3 + p * 8, 0, 0, Math.PI * 2);
                    ctx.strokeStyle = this.color;
                    ctx.globalAlpha = (1 - p) * 0.9;
                    ctx.lineWidth = 1.6;
                    ctx.stroke();
                    ctx.restore();
                });
                if (t >= 0 && t < 600) {
                    const lp = t / 600;
                    b.particles.forEach(pt => {
                        const x = cx + Math.cos(pt.a) * pt.speed * lp;
                        const y = cy + 34 + Math.sin(pt.a) * 5 + pt.vy * lp * 0.5;
                        ctx.save();
                        ctx.globalAlpha = (1 - lp) * 0.9;
                        ctx.fillStyle = this.color;
                        ctx.beginPath();
                        ctx.arc(x, y, pt.size * (1 - lp * 0.35), 0, Math.PI * 2);
                        ctx.fill();
                        ctx.restore();
                    });
                }
            });
            ctx.restore();

            this.bursts = this.bursts.filter(b => now - b.startedAt < 700);
        },

        frame(now) {
            this.pollInputs();

            if (this.lastFrameAt == null) this.lastFrameAt = now;
            const dt = now - this.lastFrameAt;
            this.lastFrameAt = now;
            this.angle += ((Math.PI * 2) / 2600) * this.spinMultiplier(now) * dt;

            const canvas = this.$refs.canvas;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, 220, 220);
            const cx = 110, cy = 122;

            this.drawRing(ctx, cx, cy, true);
            this.drawFighter(ctx, cx, cy);
            this.drawRing(ctx, cx, cy, false);
            this.drawBurst(ctx, cx, cy, now);

            requestAnimationFrame(next => this.frame(next));
        },
    }"
    x-init="start()"
    style="display:flex; flex-direction:column; align-items:center; gap:.5rem; padding:.5rem 0 1rem;"
>
    <div style="position:relative; width:220px; height:220px; border-radius:12px; background:#0f1524; border:1px solid #1b2439; overflow:hidden;">
        <canvas x-ref="canvas" width="220" height="220" style="width:100%; height:100%; display:block;"></canvas>
    </div>

    <button
        type="button"
        x-on:click="burst()"
        style="font-family:ui-monospace,monospace; font-size:.7rem; letter-spacing:.03em; padding:.4rem .8rem; border-radius:999px; border:1px solid #1b2439; background:transparent; color:#7d8598; cursor:pointer;"
    >
        ⟲ Replay hit
    </button>
</div>
