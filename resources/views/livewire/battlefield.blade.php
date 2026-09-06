<div class="relative min-h-screen bg-slate-950 text-white">
    @if ($hookOutdated)
        {{-- Dismissal is namespaced by version, so dismissing hides it until a
             NEWER version ships rather than until the next reload. This page
             gets reloaded constantly; a banner that returns every time gets
             muted and then ignored. localStorage throws outright in some
             contexts, so every access is guarded. --}}
        <div
            x-data="{
                show: false,
                key: 'ts:update-dismissed:{{ $latestHookVersion }}',
                init() { try { this.show = localStorage.getItem(this.key) !== '1'; } catch (e) { this.show = true; } },
                dismiss() { this.show = false; try { localStorage.setItem(this.key, '1'); } catch (e) {} },
            }"
            x-show="show"
            x-cloak
            class="absolute top-0 inset-x-0 z-30 flex items-center justify-center gap-3 bg-amber-500/15 border-b border-amber-500/40 px-4 py-2 text-sm text-amber-200"
        >
            <span>Your hook is out of date &mdash; usage is being recorded with less detail.</span>
            <code class="rounded bg-black/40 px-2 py-0.5 text-amber-100">token-slayer update</code>
            <button type="button" @click="dismiss()" class="ml-2 text-amber-300/70 hover:text-amber-100" aria-label="Dismiss">&times;</button>
        </div>
    @endif
    <div
        id="battlefield-mount"
        data-battlefield-state="{{ json_encode([
            'boss' => [
                'number' => $boss->number,
                'name' => $boss->name,
                'currentHp' => $boss->current_hp,
                'maxHp' => $boss->max_hp,
            ],
            'currentUserId' => auth()->id(),
            'fighters' => $fighters->map(fn ($f) => [
                'id' => $f->id,
                'handle' => $f->displayHandle(),
                'avatarUrl' => route('avatar', $f),
                'character' => $f->characterForBoss($boss->id),
                'charging' => $this->chargingByUser[$f->id] ?? null,
                'position' => $this->positionsByUser[$f->id] ?? null,
            ])->values(),
            'leaderboard' => $this->leaderboardForCurrentBoss(),
            'damageTotals' => $this->damageTotalsForCurrentBoss(),
            'globalDamage' => $this->globalDamage(),
        ]) }}"
        class="fixed inset-0" style="background-color:#020617"
    >
        <div id="bf-loader" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;pointer-events:none">
            <div style="width:180px;height:4px;background:#1e293b;border-radius:2px;overflow:hidden">
                <div id="bf-loader-bar" style="height:100%;width:0%;background:#60a5fa;border-radius:2px;transition:width 0.2s ease"></div>
            </div>
            <span style="font-family:monospace;font-size:12px;color:#475569;letter-spacing:0.05em">Loading arena…</span>
        </div>
    </div>

    @unless (request('embed') === 'ide')
        <nav id="bf-nav" class="absolute left-3 top-3 z-10 flex flex-wrap items-center gap-1.5">
            <a
                href="{{ route('profile') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-black/50 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur-sm transition-colors hover:border-amber-500/40 hover:text-amber-300"
            >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
                Profile
            </a>
            <a
                href="{{ route('filament.admin.pages.dashboard') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-black/50 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur-sm transition-colors hover:border-amber-500/40 hover:text-amber-300"
            >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                </svg>
                Dashboard
            </a>
            @auth
                <button
                    type="button"
                    @click="$dispatch('open-character-select')"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-black/50 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur-sm transition-colors hover:border-amber-500/40 hover:text-amber-300"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Loadout
                </button>
            @else
                <a
                    href="{{ route('slack.login') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-black/50 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur-sm transition-colors hover:border-amber-500/40 hover:text-amber-300"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Loadout
                </a>
            @endauth
        </nav>

        @auth
            <livewire:character-select />
        @endauth
    @endunless

    @if (session('error'))
        <div class="absolute left-1/2 top-3 z-20 max-w-[90vw] -translate-x-1/2 rounded-lg border border-red-500/40 bg-red-950/85 px-4 py-2 text-center text-xs font-medium text-red-200 backdrop-blur-sm">
            {{ session('error') }}
        </div>
    @endif

    {{-- Mirrors the in-canvas "▸ TOP DAMAGE" leaderboard panel (resources/js/battlefield/leaderboard.js). --}}
    <div
        x-data="battlefieldDamageHud()"
        x-init="init()"
        x-show="ready"
        x-cloak
        class="absolute left-0 top-0 z-10 w-44 border-2 border-amber-400 bg-[#0b1629]/95 px-3 py-2 font-mono backdrop-blur-sm"
    >
        {{-- corner rivets --}}
        <span class="pointer-events-none absolute -left-0.5 -top-0.5 h-[5px] w-[5px] bg-amber-400"></span>
        <span class="pointer-events-none absolute -right-0.5 -top-0.5 h-[5px] w-[5px] bg-amber-400"></span>
        <span class="pointer-events-none absolute -bottom-0.5 -left-0.5 h-[5px] w-[5px] bg-amber-400"></span>
        <span class="pointer-events-none absolute -bottom-0.5 -right-0.5 h-[5px] w-[5px] bg-amber-400"></span>

        <div class="text-[13px] font-semibold tracking-wide text-amber-400">▸ DAMAGE</div>
        <div class="mt-1.5 space-y-1 border-t border-amber-400/40 pt-1.5">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-[11px] uppercase tracking-wide text-slate-400">All-time</span>
                <span class="tabular-nums text-sm font-semibold text-sky-400" x-text="fmt(allTime)"></span>
            </div>
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-[11px] uppercase tracking-wide text-slate-400">Monthly</span>
                <span class="tabular-nums text-sm text-sky-400" x-text="fmt(monthly)"></span>
            </div>
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-[11px] uppercase tracking-wide text-slate-400">Daily</span>
                <span class="tabular-nums text-sm text-sky-400" x-text="fmt(daily)"></span>
            </div>
        </div>
    </div>

    <div
        x-data="battlefieldLeaderboardOverlay()"
        x-init="init()"
        class="bf-portrait-only"
    >
        <button
            type="button"
            @click="open = true"
            class="absolute right-3 top-3 z-10 inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-black/50 px-3 py-1.5 text-xs font-medium text-slate-400 backdrop-blur-sm transition-colors hover:border-amber-500/40 hover:text-amber-300"
        >
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
            </svg>
            <span x-show="!victory">Board</span>
            <span x-show="victory" x-cloak class="text-amber-300">Victory!</span>
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-4"
            @click.self="open = false"
            class="fixed inset-0 z-20 flex items-end"
            style="background: linear-gradient(to top, rgba(2,6,23,0.92) 0%, transparent 55%)"
        >
            <div class="w-full rounded-t-2xl border-t border-white/8 bg-slate-950/95 px-4 pb-10 pt-4 shadow-[0_-12px_48px_rgba(0,0,0,0.7)] backdrop-blur-md">
                <div class="mb-4 flex justify-center">
                    <div class="h-1 w-10 rounded-full bg-slate-700"></div>
                </div>

                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-amber-400" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 0 0 .95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 0 0-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 0 0-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 0 0-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 0 0 .951-.69l1.519-4.674Z" />
                        </svg>
                        <h2 class="text-sm font-semibold tracking-wide text-white">
                            <span x-show="!victory">Leaderboard</span>
                            <template x-if="victory">
                                <span x-text="`${victory.bossLabel} Defeated`"></span>
                            </template>
                        </h2>
                    </div>
                    <button type="button" @click="open = false; victory = null" class="rounded px-2 py-1 text-xs text-slate-500 transition hover:text-slate-300">
                        Done
                    </button>
                </div>

                <template x-if="victory && victory.killerHandle">
                    <div class="mb-3 rounded-lg border border-amber-500/20 bg-amber-500/5 px-3 py-2">
                        <p class="text-xs text-amber-400/80">
                            ⚔️ Killing blow —
                            <span class="font-semibold text-amber-300" x-text="victory.killerHandle"></span>
                        </p>
                    </div>
                </template>

                <ul>
                    <template x-for="(row, i) in rows" :key="row.userId">
                        <li class="flex items-center gap-3 border-b border-slate-800/50 py-2.5 last:border-b-0">
                            <span
                                class="w-6 shrink-0 text-center text-xs font-bold"
                                :class="{
                                    'text-amber-400': i === 0,
                                    'text-slate-400': i === 1,
                                    'text-orange-500': i === 2,
                                    'text-slate-600': i >= 3
                                }"
                                x-text="i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}`"
                            ></span>
                            <span class="flex-1 truncate text-sm text-slate-100" x-text="row.handle"></span>
                            <span
                                class="shrink-0 tabular-nums text-sm"
                                :class="i === 0 ? 'font-semibold text-amber-300' : 'text-slate-400'"
                                x-text="row.damage >= 1000 ? (row.damage / 1000).toFixed(1) + 'k' : row.damage.toLocaleString()"
                            ></span>
                        </li>
                    </template>
                    <li x-show="rows.length === 0" class="py-8 text-center text-sm text-slate-600">
                        No damage logged yet.
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        window.battlefieldLeaderboardOverlay = function () {
            return {
                open: false,
                rows: [],
                victory: null,
                init() {
                    const tryWire = () => {
                        if (!window.__battlefield?.bus) {
                            setTimeout(tryWire, 50);
                            return;
                        }
                        const bus = window.__battlefield.bus;
                        bus.on('leaderboard-updated', ranked => {
                            this.rows = ranked;
                        });
                        bus.on('show-mvp-overlay', payload => {
                            this.victory = {
                                bossLabel: payload.bossLabel,
                                killerHandle: payload.killerHandle,
                            };
                            this.rows = payload.ranked;
                            this.open = true;
                        });
                        bus.on('boss-spawned', () => {
                            this.victory = null;
                            this.rows = [];
                        });
                    };
                    tryWire();
                },
            };
        };

        window.battlefieldDamageHud = function () {
            return {
                allTime: 0,
                monthly: 0,
                daily: 0,
                ready: false,
                init() {
                    const mount = document.getElementById('battlefield-mount');
                    if (mount) {
                        try {
                            const state = JSON.parse(mount.dataset.battlefieldState);
                            const g = state.globalDamage || {};
                            this.allTime = g.allTime || 0;
                            this.monthly = g.monthly || 0;
                            this.daily = g.daily || 0;
                        } catch (e) {
                            // leave counters at 0 if state is missing/malformed
                        }
                    }
                    const reposition = () => requestAnimationFrame(() => this.fitToCanvas());
                    const tryWire = () => {
                        const bf = window.__battlefield;
                        const loaderEl = document.getElementById('bf-loader');
                        const loaderGone = !loaderEl || loaderEl.style.display === 'none';
                        if (!bf?.bus || !bf.game || !loaderGone) {
                            setTimeout(tryWire, 50);
                            return;
                        }
                        bf.bus.on('hit', payload => {
                            const dmg = Number(payload?.damage) || 0;
                            this.allTime += dmg;
                            this.monthly += dmg;
                            this.daily += dmg;
                        });
                        // Track the Phaser canvas FIT scaling so the HUD grows and
                        // shrinks exactly like the in-canvas TOP DAMAGE panel.
                        bf.game.scale.on('resize', reposition);
                        reposition();
                        this.ready = true;
                    };
                    window.addEventListener('resize', reposition);
                    window.addEventListener('orientationchange', reposition);
                    tryWire();
                },
                fitToCanvas() {
                    const bf = window.__battlefield;
                    const canvas = bf?.game?.canvas;
                    // The AUTHORED width (960/540), not game.scale.gameSize:
                    // the canvas is deliberately created at logical size
                    // times renderScale for pixel density (see index.js), so
                    // scaling against the canvas size would shrink this HUD
                    // by that same factor.
                    const logicalW = bf?.logicalWidth ?? bf?.game?.scale?.gameSize?.width;
                    if (!canvas || !logicalW) {
                        return;
                    }
                    const rect = canvas.getBoundingClientRect();
                    const parent = (this.$el.offsetParent || document.body).getBoundingClientRect();
                    const scale = rect.width / logicalW;
                    const LOGICAL_X = 12; // mirrors the panel's left inset
                    // The HUD mirrors the in-canvas panel's y, but the top-left nav
                    // stack occupies the same corner in every orientation, so clear
                    // it whenever the canvas starts high enough to collide. Measured,
                    // not hard-coded: the stack grows by a pill per added link.
                    const navRect = document.getElementById('bf-nav')?.getBoundingClientRect();
                    this.$el.style.transformOrigin = 'top left';
                    this.$el.style.left = (rect.left - parent.left + LOGICAL_X * scale) + 'px';
                    this.$el.style.top = bf.computeHudTop({
                        navBottom: navRect ? navRect.bottom : null,
                        canvasTop: rect.top,
                        parentTop: parent.top,
                        scale,
                    }) + 'px';
                    this.$el.style.transform = `scale(${scale})`;
                },
                fmt(n) {
                    // Delegate to the boss HP formatter exposed on window.__battlefield
                    // (resources/js/battlefield/format.js) so this never drifts from it.
                    // Reads `this.ready` (set by tryWire() once window.__battlefield
                    // exists) so Alpine re-evaluates this binding the moment the game
                    // finishes booting — without it, init()'s synchronous first render
                    // (before the game is ready) bakes in the plain-integer fallback
                    // and nothing ever re-triggers it until the next live damage hit.
                    return this.ready && window.__battlefield?.formatHp
                        ? window.__battlefield.formatHp(n)
                        : String(Math.max(0, Math.round(n)));
                },
            };
        };
    </script>

    @script
    <script>
        (() => {
            const mount = document.getElementById('battlefield-mount');
            if (!mount) {
                return;
            }
            const boot = () => {
                if (window.__battlefield?.game) {
                    window.__battlefield.game.destroy(true);
                    window.__battlefield = null;
                }
                const state = JSON.parse(mount.dataset.battlefieldState);
                window.bootBattlefield(mount, state);
            };
            // bootBattlefield may not be defined yet if Phaser is still loading
            if (window.bootBattlefield) {
                boot();
            } else {
                window.__battlefieldModule?.then(boot) ?? boot();
            }
        })();
    </script>
    @endscript
</div>
