<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    // Legacy server callers remain compatible. Portal writes never mass-assign these fields.
    protected $fillable = ['name', 'username', 'email', 'account_type', 'tokens', 'password'];
    protected $hidden = ['password', 'remember_token'];
    public const TYPE_ADMIN = 99;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => \App\Support\NSX\AdminPolicy::allows($this->account_type),
            'player' => app()->environment(['local', 'testing']) && in_array((int) $this->account_type, [0, 1, 99], true),
            default => false,
        };
    }
}
