<?php

namespace App\Livewire;

use App\Models\Event;
use App\Models\User;
use App\Services\DamageTotals;
use App\Services\GitHub\CachedLatestVersion;
use Livewire\Component;

class Profile extends Component
{
    /**
     * Snapshot of how the user's latest event was attributed, for the status
     * block. `latestVersion` is null when the latest release cannot be
     * determined — the badge hides rather than the page erroring. It is read
     * through the cache, so it may lag a fresh release by a few minutes; the
     * badge is allowed to be approximate.
     *
     * @param  User  $user  the profile owner whose latest event is being inspected
     * @param  CachedLatestVersion  $latest  supplies the latest released CLI version
     * @return array{event:?Event, clientVersion:?string, latestVersion:?string, outdated:bool, hookVersion:?string, latestHookVersion:string, hookOutdated:bool}
     */
    private function attributionStatus(User $user, CachedLatestVersion $latest): array
    {
        $latestVersion = $latest->get();

        return [
            'event' => Event::where('user_id', $user->id)->latest('id')->first(),
            'clientVersion' => $user->client_version,
            'latestVersion' => $latestVersion,
            'outdated' => $latestVersion !== null && $user->client_version !== $latestVersion,
            'hookVersion' => $user->hook_version,
            'latestHookVersion' => config('token_slayer.hook_version'),
            'hookOutdated' => $user->hook_version !== null
                && $user->hook_version !== config('token_slayer.hook_version'),
        ];
    }

    public function render(CachedLatestVersion $latest)
    {
        return view('livewire.profile', [
            'user' => auth()->user(),
            'damageTotals' => app(DamageTotals::class)->forUser(auth()->user()),
            'globalUsage' => app(DamageTotals::class)->global(),
            'accountRows' => app(DamageTotals::class)->forUserByAccount(auth()->user()),
            'quotaBars' => fn (array $row): array => $this->quotaBars($row),
            'attribution' => $this->attributionStatus(auth()->user(), $latest),
        ]);
    }

    /**
     * Shape an account row's 5h/7d utilization into renderable quota-bar
     * descriptors (label, percent, Tailwind band class), skipping buckets the
     * account has never been probed for.
     *
     * @param  array{util_5h:?int, util_7d:?int}  $row  one account row from DamageTotals::forUserByAccount
     * @return array<int, array{label:string, pct:int, band:string}>
     */
    private function quotaBars(array $row): array
    {
        $bars = [];

        foreach (['5h quota' => $row['util_5h'], '7d quota' => $row['util_7d']] as $label => $pct) {
            if ($pct === null) {
                continue;
            }

            $band = match (true) {
                $pct >= 90 => 'bg-red-500',
                $pct >= 70 => 'bg-amber-500',
                default => 'bg-emerald-500',
            };

            $bars[] = ['label' => $label, 'pct' => $pct, 'band' => $band];
        }

        return $bars;
    }
}
