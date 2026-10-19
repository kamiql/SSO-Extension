<?php

declare(strict_types=1);

namespace Sso\Contracts;

use Illuminate\Http\Request;

interface ReadsCallback extends IdentityProvider
{
    /**
     * @param Request
     * @return string|null
     */
    public function callbackCode(Request $request): ?string;
}
