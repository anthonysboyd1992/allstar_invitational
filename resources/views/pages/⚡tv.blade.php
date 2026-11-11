<?php

use App\Models\Setting;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('TV')] class extends Component
{
    public string $mode = Setting::TvBoardPillDraw;

    public function mount(): void
    {
        $this->refreshMode();
    }

    public function refreshMode(): void
    {
        $this->mode = Setting::tvBoardMode();
    }
};
?>

<div class="tv-page mx-auto max-w-[100rem] px-1 py-1 sm:px-2" wire:poll.2s="refreshMode">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2 px-1">
        <p class="text-[11px] font-semibold tracking-[0.22em] text-zinc-500 uppercase">
            TV · {{ \App\Models\Setting::tvBoardModeLabel($mode) }}
        </p>
        <p class="text-[11px] text-zinc-500">Leave this page open. Switch boards from Pill Draw or Lineup Board controls.</p>
    </div>

    @if ($mode === Setting::TvBoardLineup)
        <livewire:pages::lineup-board :display-only="true" :key="'tv-lineup-board'" />
    @else
        <livewire:pages::pill-draw :display-only="true" :key="'tv-pill-draw'" />
    @endif
</div>
