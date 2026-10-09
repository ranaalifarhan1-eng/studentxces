<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'school_id',
        'name',
        'username',
        'email',
        'phone',
        'avatar',
        'status',
        'password',
        'temporary_password_encrypted',
        'temporary_password_expires_at',
        'must_change_password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'temporary_password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'             => 'datetime',
            'last_login_at'                 => 'datetime',
            'temporary_password_expires_at' => 'datetime',
            'must_change_password'          => 'boolean',
            'password'                      => 'hashed',
        ];
    }

    public function school(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function guardian(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    public function hasActiveTemporaryPassword(): bool
    {
        return ! empty($this->temporary_password_encrypted)
            && ($this->temporary_password_expires_at === null || $this->temporary_password_expires_at->isFuture());
    }
}
