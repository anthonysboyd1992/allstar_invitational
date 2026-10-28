<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Settings')] class extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $leftLogo = null;

    public ?TemporaryUploadedFile $rightLogo = null;

    public function boot(): void
    {
        Gate::authorize('access-settings');
    }

    /**
     * @return array{left: string, right: string}
     */
    #[Computed]
    public function logos(): array
    {
        return [
            'left' => Setting::letterheadUrl('left'),
            'right' => Setting::letterheadUrl('right'),
        ];
    }

    /**
     * @return array{left: bool, right: bool}
     */
    #[Computed]
    public function uploaded(): array
    {
        return [
            'left' => filled(Setting::letterheadPath('left')),
            'right' => filled(Setting::letterheadPath('right')),
        ];
    }

    public function updatedLeftLogo(): void
    {
        $this->storeLogo('left', $this->leftLogo);
        $this->leftLogo = null;
    }

    public function updatedRightLogo(): void
    {
        $this->storeLogo('right', $this->rightLogo);
        $this->rightLogo = null;
    }

    public function removeLogo(string $side): void
    {
        if (! in_array($side, Setting::LetterheadSides, true)) {
            return;
        }

        Setting::deleteLetterhead($side);
        unset($this->logos, $this->uploaded);
        $this->dispatch('notify', message: 'Removed the '.$side.' letterhead logo.');
    }

    protected function storeLogo(string $side, mixed $photo): void
    {
        if ($error = Setting::replaceLetterhead($side, $photo)) {
            $this->dispatch('notify', message: $error, type: 'error');
        } else {
            $this->dispatch('notify', message: 'Updated the '.$side.' letterhead logo.');
        }

        unset($this->logos, $this->uploaded);
    }
};
?>

<div class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-2xl font-black uppercase">Settings</h1>

    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-100 px-4 py-3">
            <h2 class="font-black uppercase">Letterhead logos</h2>
            <p class="text-sm text-slate-500">The left logo is the main mark on printed lineups; the right logo stays smaller. PNG, JPG, GIF, or WebP.</p>
        </div>
        <div class="grid gap-4 p-4 sm:grid-cols-2">
            @foreach (['left' => 'Left (primary)', 'right' => 'Right'] as $side => $label)
                <div wire:key="letterhead-{{ $side }}" class="space-y-2">
                    <span class="text-xs font-semibold text-slate-500">{{ $label }}</span>
                    <div @class(['flex items-center justify-center rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200', 'h-36' => $side === 'left', 'h-28' => $side === 'right'])>
                        <img src="{{ $this->logos[$side] }}" alt="{{ $label }} letterhead" @class(['max-h-full max-w-full object-contain', 'object-left' => $side === 'left', 'object-right' => $side === 'right'])>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-block cursor-pointer rounded-md bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-700">
                            {{ $this->uploaded[$side] ? 'Replace' : 'Upload' }}
                            <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" wire:model="{{ $side }}Logo" class="hidden">
                        </label>
                        <span wire:loading wire:target="{{ $side }}Logo" class="text-xs font-semibold text-amber-600">Uploading…</span>
                        @if ($this->uploaded[$side])
                            <button type="button" wire:click="removeLogo('{{ $side }}')" class="text-xs font-semibold text-red-600 hover:text-red-800">Remove</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
