<?php

declare(strict_types=1);

namespace Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\User;

final class Identity extends Model
{
    /**
     * @var string
     */
    protected $table = 'sso_identities';

    /**
     * @var array
     */
    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'name', 'email', 'avatar_url', 'last_login_at'];

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @inheritdoc
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
