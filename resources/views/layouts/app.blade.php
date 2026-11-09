<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-100">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? config('app.name') }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex h-full flex-col overflow-hidden font-sans text-slate-900 antialiased">
        <header class="z-30 shrink-0 bg-slate-900 text-white shadow">
            <div class="flex h-14 items-center gap-6 px-4">
                <a href="{{ route('home') }}" class="text-lg font-black uppercase tracking-wide">
                    Allstar <span class="text-amber-400">Invitational</span>
                </a>

                <nav class="flex flex-wrap gap-1 text-sm font-semibold">
                    @foreach (\App\Models\User::Areas as $routeName => $label)
                        @continue(! auth()->user()?->canAccessArea($routeName))
                        <a
                            href="{{ route($routeName) }}"
                            wire:navigate
                            @class([
                                'rounded-md px-3 py-1.5 transition',
                                'bg-amber-400 text-slate-900' => request()->routeIs($routeName),
                                'text-slate-300 hover:bg-slate-800 hover:text-white' => ! request()->routeIs($routeName),
                            ])
                        >{{ $label }}</a>
                        @if ($routeName === 'timing' || ($routeName === 'results' && ! auth()->user()?->canAccessArea('timing')))
                            <a
                                href="{{ route('live-timing') }}"
                                wire:navigate
                                @class([
                                    'rounded-md px-3 py-1.5 transition',
                                    'bg-amber-400 text-slate-900' => request()->routeIs('live-timing'),
                                    'text-slate-300 hover:bg-slate-800 hover:text-white' => ! request()->routeIs('live-timing'),
                                ])
                            >Live Timing</a>
                        @endif
                    @endforeach
                </nav>

                <form method="POST" action="{{ route('logout') }}" class="ml-auto flex items-center gap-3 text-sm">
                    @csrf
                    <span class="text-slate-400">{{ auth()->user()?->name }}</span>
                    <button type="submit" class="rounded-md px-3 py-1.5 font-semibold text-slate-300 hover:bg-slate-800 hover:text-white">Log out</button>
                </form>
            </div>
        </header>

        <main class="min-h-0 flex-1 overflow-y-auto p-4">
            {{ $slot }}
        </main>

        <div
            x-data="{ messages: [] }"
            x-on:notify.window="let id = Date.now(); messages.push({ id, text: $event.detail.message, type: $event.detail.type ?? 'success' }); setTimeout(() => messages = messages.filter(m => m.id !== id), 3000)"
            class="pointer-events-none fixed right-4 bottom-4 z-50 flex flex-col gap-2"
        >
            <template x-for="message in messages" :key="message.id">
                <div
                    x-text="message.text"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-lg"
                    :class="message.type === 'error' ? 'bg-red-600' : 'bg-emerald-600'"
                ></div>
            </template>
        </div>
    </body>
</html>
