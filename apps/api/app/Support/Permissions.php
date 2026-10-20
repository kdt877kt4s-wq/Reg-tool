<?php

namespace App\Support;

/**
 * The permission matrix from the plan (section 9), in one place. Policies
 * and gates read from here; the web app receives the resolved list only
 * to hide controls, never to enforce them.
 */
final class Permissions
{
    public const MATRIX = [
        'organization.update' => [Role::OrgAdmin],
        'members.view' => [Role::OrgAdmin, Role::ComplianceOfficer],
        'members.manage' => [Role::OrgAdmin],
        'departments.view' => [Role::OrgAdmin, Role::ComplianceOfficer, Role::DepartmentManager, Role::Employee, Role::Auditor],
        'departments.manage' => [Role::OrgAdmin],
        'profile.view' => [Role::OrgAdmin, Role::ComplianceOfficer, Role::DepartmentManager, Role::Employee, Role::Auditor],
        'profile.edit' => [Role::OrgAdmin, Role::ComplianceOfficer],
        'audit.view' => [Role::OrgAdmin, Role::ComplianceOfficer, Role::Auditor],
        'regulations.view' => [Role::OrgAdmin, Role::ComplianceOfficer, Role::DepartmentManager, Role::Employee, Role::Auditor],
    ];

    public static function allows(?Role $role, string $permission): bool
    {
        return $role !== null && in_array($role, self::MATRIX[$permission] ?? [], true);
    }

    /** @return list<string> */
    public static function for(?Role $role): array
    {
        return array_values(array_filter(
            array_keys(self::MATRIX),
            fn (string $p) => self::allows($role, $p),
        ));
    }
}
