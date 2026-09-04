<div>
    @include('partials.account-nav', ['active' => 'profile'])

    <div class="p-8 max-w-3xl mx-auto space-y-6">
        <header class="flex items-center gap-4">
            <img src="{{ $user->avatar_url }}" class="w-14 h-14 rounded-full ring-2 ring-white ring-offset-2 ring-offset-orange-100">
            <div class="flex-1">
                <h1 class="text-xl font-bold text-gray-900">{{ $user->name }}</h1>
                <p class="text-sm text-gray-500">{{ $user->display_name }}</p>
            </div>
            <nav class="flex flex-col items-stretch gap-1.5 text-center">
                <a href="{{ route('battlefield') }}" class="px-3 py-2 bg-gray-900 hover:bg-gray-700 text-white rounded-lg text-sm font-medium transition">Battlefield →</a>
                <a href="{{ route('filament.admin.pages.dashboard') }}" class="px-3 py-2 bg-white border border-gray-200 hover:border-gray-400 hover:bg-gray-50 text-gray-700 rounded-lg text-sm font-medium transition">Dashboard →</a>
            </nav>
        </header>

        <section class="bg-white border border-gray-200 rounded-xl p-5">
            <h2 class="text-sm font-bold text-gray-900 mb-4">Battlefield stats</h2>
            <dl class="grid grid-cols-4 gap-3 text-center">
                <div class="bg-gray-50 border border-gray-100 rounded-lg py-3">
                    <dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-1">Hourly</dt>
                    <dd class="text-xl font-mono font-bold text-gray-900">{{ number_format($damageTotals['hourly']) }}</dd>
                </div>
                <div class="bg-orange-50 border border-orange-100 rounded-lg py-3">
                    <dt class="text-[10px] uppercase tracking-wide text-orange-600 font-semibold mb-1">Daily</dt>
                    <dd class="text-xl font-mono font-bold text-orange-600">{{ number_format($damageTotals['daily']) }}</dd>
                </div>
                <div class="bg-gray-50 border border-gray-100 rounded-lg py-3">
                    <dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-1">Monthly</dt>
                    <dd class="text-xl font-mono font-bold text-gray-900">{{ number_format($damageTotals['monthly']) }}</dd>
                </div>
                <div class="bg-gray-50 border border-gray-100 rounded-lg py-3">
                    <dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-1">All-time</dt>
                    <dd class="text-xl font-mono font-bold text-gray-900">{{ number_format($damageTotals['allTime']) }}</dd>
                </div>
            </dl>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-5">
            <h2 class="text-sm font-bold text-gray-900 mb-3">Attribution</h2>
            @php($event = $attribution['event'])
            @if ($event)
                @if ($event->account_id)
                    <p class="text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2">
                        Your latest usage matched <span class="font-medium">{{ $event->account->email }}</span> — an org account.
                    </p>
                @elseif ($event->account_source === 'proxy')
                    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        Your latest usage went through a proxy (<code>ANTHROPIC_BASE_URL</code> is set), so the account couldn't be detected. Set <code>account.json</code> to attribute it manually.
                    </p>
                @elseif ($event->account_email)
                    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        Your latest usage claimed <span class="font-medium">{{ $event->account_email }}</span>, which isn't a known org account — counted as personal usage. Tell an admin if this should be an org account.
                    </p>
                @else
                    <p class="text-sm text-gray-500">Your latest usage wasn't tied to any account — counted as personal usage.</p>
                @endif
            @else
                <p class="text-sm text-gray-500">No usage recorded yet.</p>
            @endif
            @if ($attribution['outdated'])
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-2">
                    Your client is running an outdated version{{ $attribution['clientVersion'] ? " ({$attribution['clientVersion']})" : '' }}. Run <code>token-slayer update</code> to get the latest.
                </p>
            @endif
            @if ($attribution['hookOutdated'])
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-2">
                    Your hook is on v{{ $attribution['hookVersion'] }} but v{{ $attribution['latestHookVersion'] }} is available &mdash; usage may be recorded with less detail until you run <code>token-slayer update</code>.
                </p>
            @endif
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-bold text-gray-900">Usage</h2>
            <div>
                <h3 class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-2">All users</h3>
                <dl class="grid grid-cols-3 gap-3 text-center">
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">Hourly</dt><dd class="text-lg font-mono font-bold text-gray-900">{{ number_format($globalUsage['hourly']) }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">Daily</dt><dd class="text-lg font-mono font-bold text-gray-900">{{ number_format($globalUsage['daily']) }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">Monthly</dt><dd class="text-lg font-mono font-bold text-gray-900">{{ number_format($globalUsage['monthly']) }}</dd></div>
                </dl>
            </div>

            @if (count($accountRows) > 0)
                <div>
                    <h3 class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-2">Your accounts</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($accountRows as $row)
                            <div class="border border-gray-200 rounded-lg p-3">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-gray-900">{{ $row['email'] }}</span>
                                    <span class="text-xs bg-gray-100 text-gray-600 rounded-full px-2 py-0.5">{{ $row['memberCount'] }} {{ Str::plural('member', $row['memberCount']) }}</span>
                                </div>
                                <p class="text-xs text-gray-400 mb-2">{{ $row['plan']?->getLabel() ?? 'no plan on file' }}</p>
                                @unless ($row['isMember'])
                                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1 mb-2">You're not a member of this account — usage was attributed here but you may lose access.</p>
                                @endunless
                                <dl class="grid grid-cols-3 gap-2 text-center">
                                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">Hourly</dt><dd class="text-sm font-mono font-bold text-gray-900">{{ number_format($row['hourly']) }}</dd></div>
                                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">Daily</dt><dd class="text-sm font-mono font-bold text-gray-900">{{ number_format($row['daily']) }}</dd></div>
                                    <div><dt class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">Monthly</dt><dd class="text-sm font-mono font-bold text-gray-900">{{ number_format($row['monthly']) }}</dd></div>
                                </dl>
                                @if (! is_null($row['util_5h']) || ! is_null($row['util_7d']))
                                    <div class="mt-3 space-y-2">
                                        @foreach ($quotaBars($row) as $bar)
                                            <div>
                                                <div class="flex justify-between text-xs text-gray-500 mb-0.5"><span>{{ $bar['label'] }}</span><span class="font-mono">{{ $bar['pct'] }}%</span></div>
                                                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden"><div class="h-full {{ $bar['band'] }}" style="width: {{ min($bar['pct'], 100) }}%"></div></div>
                                            </div>
                                        @endforeach
                                        @if ($row['lastProbedAt'])
                                            <p class="text-xs text-gray-400">probed {{ $row['lastProbedAt']->diffForHumans() }}</p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <div class="grid sm:grid-cols-2 gap-4">
            <a href="{{ route('setup') }}" class="bg-orange-50 border border-orange-200 hover:border-orange-400 rounded-xl p-5 flex items-center justify-between transition">
                <div>
                    <div class="font-bold text-gray-900 text-sm mb-0.5">Set up Claude tracking</div>
                    <div class="text-xs text-gray-500">Step-by-step install for CLI, chat, or Cowork</div>
                </div>
                <svg class="w-5 h-5 text-orange-600 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
            </a>
            <a href="{{ route('guide') }}" class="bg-gray-50 border border-gray-200 hover:border-gray-400 rounded-xl p-5 flex items-center justify-between transition">
                <div>
                    <div class="font-bold text-gray-900 text-sm mb-0.5">CLI command reference</div>
                    <div class="text-xs text-gray-500">token-slayer commands and when to use them</div>
                </div>
                <svg class="w-5 h-5 text-gray-500 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
            </a>
        </div>
    </div>
