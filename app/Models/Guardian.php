<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
    use BelongsToSchool, SoftDeletes;

    protected $fillable = [
        'school_id', 'user_id', 'guardian_code', 'name', 'relation',
        'phone', 'email', 'occupation', 'address', 'photo',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (Guardian $guardian) {
            if (empty($guardian->guardian_code)) {
                $count = static::withoutGlobalScopes()->withTrashed()->where('school_id', $guardian->school_id)->count() + 1;
                $candidate = 'PAR-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                while (static::withoutGlobalScopes()->withTrashed()->where('school_id', $guardian->school_id)->where('guardian_code', $candidate)->exists()) {
                    $count++;
                    $candidate = 'PAR-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                }
                $guardian->guardian_code = $candidate;
            }
        });
    }
}
