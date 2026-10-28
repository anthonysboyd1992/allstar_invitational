<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'permissions'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Areas of the app a user can be given access to, keyed by the route name of the area's page.
     */
    public const Areas = [
        'competitors' => 'Competitors',
        'cars' => 'Cars',
        'lineups' => 'Races & Lineups',
        'timing' => 'Timing',
        'vmix' => 'vMix',
        'vmix-data' => 'vMix Data',
        'settings' => 'Settings',
        'users' => 'Users',
    ];

    /**
     * Quick picks on the Users page.
     *
     * @var array<string, array{label: string, areas: list<string>}>
     */
    public const Presets = [
        'everything' => ['label' => 'Everything', 'areas' => ['competitors', 'cars', 'lineups', 'timing', 'vmix', 'vmix-data', 'settings', 'users']],
        'timing' => ['label' => 'Timing', 'areas' => ['timing']],
        'vmix' => ['label' => 'vMix', 'areas' => ['vmix', 'vmix-data']],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }

    public function canAccessArea(string $area): bool
    {
        return in_array($area, $this->permissions ?? [], true);
    }

    /**
     * The route of the first area this user can open, or null when they have none.
     */
    public function homeRoute(): ?string
    {
        return collect(array_keys(self::Areas))->first(fn (string $area) => $this->canAccessArea($area));
    }

    /**
     * The preset whose areas exactly match this user's, if any.
     */
    public function presetLabel(): ?string
    {
        $areas = collect($this->permissions ?? [])->sort()->values()->all();

        return collect(self::Presets)->first(fn (array $preset) => collect($preset['areas'])->sort()->values()->all() === $areas)['label'] ?? null;
    }
}
