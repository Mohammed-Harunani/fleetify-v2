<?php
declare(strict_types=1);

namespace Fleetify\Auth;

final class Authorization
{
    private const PERMISSIONS = [
        'admin' => [
            'users.manage',
            'offices.manage',
            'vehicles.manage',
            'routes.manage',
            'consignments.create',
            'consignments.view',
            'consignments.update',
            'consignments.assign',
            'reports.view',
        ],

        'branch_manager' => [
            'consignments.create',
            'consignments.view',
            'consignments.update',
            'consignments.assign',
            'reports.view',
        ],

        'dispatcher' => [
            'consignments.create',
            'consignments.view',
            'consignments.update',
            'consignments.assign',
        ],

        'driver' => [
            'consignments.view',
        ],
    ];

    public static function can(
        string $role,
        string $permission
    ): bool {
        return in_array(
            $permission,
            self::PERMISSIONS[$role] ?? [],
            true
        );
    }

    public static function requirePermission(
        string $role,
        string $permission
    ): void {
        if (!self::can($role, $permission)) {
            throw new \RuntimeException(
                'Access denied: insufficient permissions.'
            );
        }
    }
}
