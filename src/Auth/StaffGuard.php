<?php
declare(strict_types=1);

namespace Fleetify\Auth;

use RuntimeException;

final class StaffGuard
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function requireLogin(): array
    {
        $staff = $this->auth->staffIdentity();

        if ($staff === null) {
            throw new RuntimeException(
                'Authentication required.',
                401
            );
        }

        return $staff;
    }

    public function requirePermission(
        string $permission
    ): array {
        $staff = $this->requireLogin();

        if (!Authorization::can(
            (string) $staff['role'],
            $permission
        )) {
            throw new RuntimeException(
                'Access denied.',
                403
            );
        }

        return $staff;
    }
}
