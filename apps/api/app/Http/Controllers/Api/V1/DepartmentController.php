<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('departments.view');

        return response()->json([
            'data' => Department::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('departments.manage');
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120', $this->uniqueName()]]);

        $department = Department::create(['name' => $data['name']]);
        $audit->record('department.created', $department, null, ['name' => $department->name]);

        return response()->json(['data' => ['id' => $department->id, 'name' => $department->name]], 201);
    }

    public function update(Request $request, int $departmentId, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('departments.manage');
        $department = Department::query()->findOrFail($departmentId);
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120', $this->uniqueName($department->id)]]);

        $before = ['name' => $department->name];
        $department->update(['name' => $data['name']]);
        $audit->record('department.updated', $department, $before, ['name' => $department->name]);

        return response()->json(['data' => ['id' => $department->id, 'name' => $department->name]]);
    }

    private function uniqueName(?int $ignoreId = null): Unique
    {
        return Rule::unique('departments', 'name')
            ->where('organization_id', $this->tenant()->organizationId())
            ->ignore($ignoreId);
    }
}
