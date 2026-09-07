<x-filament-panels::page>
    @php($rows = $this->rows())

    <x-filament::section heading="Accounts needing attention">
        @if (empty($rows))
            <p style="opacity:.6;">No accounts expiring or stale right now.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">Account</th>
                            <th style="padding:.4rem .6rem;">Provider</th>
                            <th style="padding:.4rem .6rem;">Status</th>
                            <th style="padding:.4rem .6rem;">Repair</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem;">{{ $row['name'] ?? $row['email'] ?? '— unnamed —' }}</td>
                                <td style="padding:.4rem .6rem;">
                                    <x-filament::badge :color="$row['provider']->getColor()">
                                        {{ $row['provider']->getLabel() }}
                                    </x-filament::badge>
                                </td>
                                <td style="padding:.4rem .6rem; opacity:.85;">{{ $row['label'] }}</td>
                                {{-- The repair the admin came here to run, aimed at this row's
                                     account, so a listed account never has to be opened just to
                                     act on it. Claude gets a record-bound re-connect; Codex has
                                     no per-row connect to offer (its device-code flow binds to
                                     whoever approves), so it gets the re-probe instead. --}}
                                <td style="padding:.4rem .6rem;">
                                    @if ($row['provider'] === \App\Enums\Provider::Claude)
                                        {{ ($this->reconnectAccountAction)(['account' => $row['account_id']]) }}
                                    @else
                                        {{ ($this->refreshAccountUsageAction)(['account' => $row['account_id']]) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
