<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Log in')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages(['email' => 'Those credentials do not match our records.']);
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        $this->redirectIntended(route('home'), navigate: false);
    }
};
?>

<form wire:submit="login" class="w-full max-w-sm rounded-xl bg-white p-6 shadow-2xl">
    <h1 class="mb-6 text-center text-xl font-black uppercase">
        Allstar <span class="text-amber-500">Invitational</span>
    </h1>

    <label class="block">
        <span class="text-xs font-semibold text-slate-500">Email</span>
        <input type="email" wire:model="email" autofocus autocomplete="username" class="mt-0.5 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none">
        @error('email') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
    </label>

    <label class="mt-3 block">
        <span class="text-xs font-semibold text-slate-500">Password</span>
        <input type="password" wire:model="password" autocomplete="current-password" class="mt-0.5 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none">
        @error('password') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
    </label>

    <label class="mt-3 flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" wire:model="remember"> Keep me logged in
    </label>

    <button type="submit" class="mt-5 w-full rounded-md bg-slate-900 py-2.5 text-sm font-bold text-white hover:bg-slate-700">Log in</button>
</form>
