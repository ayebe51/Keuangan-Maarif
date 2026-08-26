<?php

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $table = 'organizations';

    protected $fillable = [
        'code',
        'name',
        'type',
        'address',
        'phone',
        'email',
        'website',
        'npwp',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'organization_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'organization_id');
    }
}
