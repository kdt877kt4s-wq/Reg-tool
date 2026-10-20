/** Organization roles, matching App\Support\Role in the API. */
export const ROLES = [
  { value: "org_admin", label: "Organization administrator" },
  { value: "compliance_officer", label: "Compliance officer" },
  { value: "department_manager", label: "Department manager" },
  { value: "employee", label: "Employee" },
  { value: "auditor", label: "Auditor" },
] as const;
