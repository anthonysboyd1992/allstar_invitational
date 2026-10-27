<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Users')] class extends Component
{
    public bool $showFormModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** @var list<string> */
    public array $areas = [];

    public function boot(): void
    {
        Gate::authorize('access-users');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->orderBy('name')->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->areas = User::Presets['timing']['areas'];
        $this->showFormModal = true;
    }

    public function openEdit(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->areas = $user->permissions ?? [];
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->resetForm();
        $this->showFormModal = false;
    }

    public function applyPreset(string $preset): void
    {
        $this->areas = User::Presets[$preset]['areas'] ?? $this->areas;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'areas' => ['array'],
            'areas.*' => [Rule::in(array_keys(User::Areas))],
        ], [], ['areas' => 'access']);

        $permissions = array_values(array_intersect(array_keys(User::Areas), $validated['areas']));

        if ($this->editingId === auth()->id() && ! in_array('users', $permissions, true)) {
            $this->addError('areas', 'You can’t remove your own access to Users.');

            return;
        }

        $attributes = ['name' => $validated['name'], 'email' => $validated['email'], 'permissions' => $permissions];

        if (filled($validated['password'])) {
            $attributes['password'] = $validated['password'];
        }

        $user = User::query()->updateOrCreate(['id' => $this->editingId], $attributes);

        $this->dispatch('notify', message: ($this->editingId ? 'Saved ' : 'Added ').$user->name.'.');
        $this->closeFormModal();
    }

    public function delete(int $userId): void
    {
        if ($userId === auth()->id()) {
            $this->dispatch('notify', message: 'You can’t delete your own account.', type: 'error');

            return;
        }

        $user = User::query()->findOrFail($userId);
        $user->delete();

        $this->dispatch('notify', message: "Deleted {$user->name}.");
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'name', 'email', 'password', 'areas');
        $this->resetValidation();
    }
};
?>

<div class="mx-auto max-w-5xl space-y-4">
    @if ($showFormModal)
        <div
            class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-20"
            x-on:keydown.escape.window="$wire.closeFormModal()"
            wire:click.self="closeFormModal"
        >
            <form wire:submit="save" x-init="$nextTick(() => $el.querySelector('input')?.focus())" class="w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-black uppercase">{{ $editingId ? 'Edit user' : 'Add user' }}</h2>
                    <button type="button" wire:click="closeFormModal" class="rounded px-2 text-2xl leading-none text-slate-400 hover:text-slate-900">&times;</button>
                </div>

                <div class="space-y-3">
                    @foreach ([
                        'name' => ['Name', 'text', 'off'],
                        'email' => ['Email', 'email', 'off'],
                        'password' => [$editingId ? 'New password (leave blank to keep)' : 'Password', 'password', 'new-password'],
                    ] as $field => [$label, $type, $autocomplete])
                        <label class="block">
                            <span class="text-xs font-semibold text-slate-500">{{ $label }}</span>
                            <input
                                type="{{ $type }}"
                                wire:model="{{ $field }}"
                                autocomplete="{{ $autocomplete }}"
                                @class([
                                    'mt-0.5 w-full rounded-md border px-2 py-2 text-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500 focus:outline-none',
                                    'border-red-500' => $errors->has($field),
                                    'border-slate-300' => ! $errors->has($field),
                                ])
                            >
                            @error($field) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    @endforeach

                    <div>
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-500">Access</span>
                            <div class="flex gap-1">
                                @foreach (User::Presets as $presetKey => $preset)
                                    <button type="button" wire:click="applyPreset('{{ $presetKey }}')" class="rounded-md px-2 py-1 text-xs font-bold ring-1 ring-slate-300 hover:bg-slate-50">{{ $preset['label'] }}</button>
                                @endforeach
                            </div>
                        </div>
                        <div class="mt-1 grid grid-cols-2 gap-1.5">
                            @foreach (User::Areas as $area => $label)
                                <label class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm ring-1 ring-slate-200 has-checked:bg-amber-50 has-checked:ring-amber-400">
                                    <input type="checkbox" wire:model="areas" value="{{ $area }}" class="accent-amber-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <span class="mt-1 block text-xs text-slate-400">Users access lets someone add accounts and change permissions.</span>
                        @error('areas') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeFormModal" class="rounded-md px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">{{ $editingId ? 'Save changes' : 'Add user' }}</button>
                </div>
            </form>
        </div>
    @endif

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-black uppercase">Users</h1>
        <button type="button" wire:click="openCreate" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Add user</button>
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs font-bold tracking-wide text-slate-500 uppercase">
                <tr>
                    <th class="px-3 py-2">Name</th>
                    <th class="px-3 py-2">Email</th>
                    <th class="px-3 py-2">Access</th>
                    <th class="w-36 px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($this->users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td class="px-3 py-2 font-semibold">
                            {{ $user->name }}
                            @if ($user->id === auth()->id())
                                <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500 uppercase">You</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-slate-600">{{ $user->email }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                @if ($presetLabel = $user->presetLabel())
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-bold text-amber-800">{{ $presetLabel }}</span>
                                @else
                                    @forelse ($user->permissions ?? [] as $area)
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700">{{ User::Areas[$area] ?? $area }}</span>
                                    @empty
                                        <span class="text-xs text-red-600">No access</span>
                                    @endforelse
                                @endif
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <button type="button" wire:click="openEdit({{ $user->id }})" class="rounded-md px-2 py-1 text-xs font-bold text-slate-700 hover:bg-slate-100">Edit</button>
                            @if ($user->id !== auth()->id())
                                <button type="button" wire:click="delete({{ $user->id }})" wire:confirm="Delete {{ $user->name }}?" class="rounded-md px-2 py-1 text-xs font-bold text-red-600 hover:bg-red-50">Delete</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
