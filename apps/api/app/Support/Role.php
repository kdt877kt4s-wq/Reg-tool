<?php

namespace App\Support;

/**
 * Roles inside an organization. Platform administration is a separate,
 * user-level flag with no default access to tenant data.
 */
enum Role: string
{
    case OrgAdmin = 'org_admin';
    case ComplianceOfficer = 'compliance_officer';
    case DepartmentManager = 'department_manager';
    case Employee = 'employee';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::OrgAdmin => 'Organization administrator',
            self::ComplianceOfficer => 'Compliance officer',
            self::DepartmentManager => 'Department manager',
            self::Employee => 'Employee',
            self::Auditor => 'Auditor',
        };
    }

    /** Roles that approve or administer must use two-factor login. */
    public function requiresMfa(): bool
    {
        return in_array($this, [self::OrgAdmin, self::ComplianceOfficer], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
