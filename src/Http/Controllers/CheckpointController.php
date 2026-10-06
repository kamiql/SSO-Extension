<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Data\LoginCheckpoint;

final class CheckpointController
{
    /**
     * @var CompletesLogins
     */
    protected CompletesLogins $logins;

    /**
     * @param CompletesLogins
     */
    public function __construct(CompletesLogins $logins)
    {
        $this->logins = $logins;
    }

    /**
     * @param Request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $checkpoint = $request->session()->get(AuthorizationController::CHECKPOINT) === true ? $this->logins->pendingCheckpoint() : null;
        if (! $checkpoint instanceof LoginCheckpoint) {
            return new JsonResponse(['errors' => [['code' => 'NotFound', 'status' => '404', 'detail' => 'There is no sign-in waiting for two-factor authentication.']]], 404);
        }

        return new JsonResponse(['data' => ['confirmation_token' => $checkpoint->token]]);
    }
}
