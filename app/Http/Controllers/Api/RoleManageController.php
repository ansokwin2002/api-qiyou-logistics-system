<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Controllers\Traits\FrontendResponse;
use App\Models\ActivityLog;
use App\Models\Role;
use Illuminate\Http\Request;

/**
 * manageapi/sysrole/* — role management used by the admin panel.
 */
class RoleManageController extends Controller
{
    use ApiResponse, FrontendResponse;

    public function getAll()
    {
        $roles = Role::orderBy('sort')->orderBy('id')->get()->map(fn ($r) => $this->transform($r));

        return $this->frontendOk($roles);
    }

    public function getList(Request $request)
    {
        $params = $this->getParams($request);
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = max(1, (int) ($params['pageSize'] ?? 10));

        $paginator = Role::orderBy('sort')->orderBy('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->frontendOk([
            'data' => $paginator->getCollection()->map(fn ($r) => $this->transform($r))->values(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function get(Request $request)
    {
        $id = $request->input('data.id') ?? $request->query('id');
        $role = Role::find($id);

        if (! $role) {
            return $this->frontendError('Role not found');
        }

        return $this->frontendOk($this->transform($role));
    }

    public function store(Request $request)
    {
        $data = $this->getParams($request);

        if (empty($data['name'])) {
            return $this->frontendError('Role name is required');
        }

        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $data['name']), '-'));

        if (Role::where('slug', $slug)->exists()) {
            return $this->frontendError('A role with this name already exists');
        }

        $role = Role::create([
            'name' => $data['name'],
            'slug' => $slug,
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'is_enable' => array_key_exists('isEnable', $data) ? (bool) $data['isEnable'] : true,
            'permissions' => array_values(array_filter((array) ($data['permissions'] ?? []))),
        ]);

        ActivityLog::record('role.create', "Created role {$role->name}");

        return $this->frontendOk($this->transform($role), 'Role added');
    }

    public function update(Request $request)
    {
        $data = $this->getParams($request);
        $role = Role::find($data['id'] ?? null);

        if (! $role) {
            return $this->frontendError('Role not found');
        }

        if (! empty($data['name'])) {
            $role->name = $data['name'];
        }
        if (array_key_exists('code', $data)) {
            $role->code = $data['code'];
        }
        if (array_key_exists('description', $data)) {
            $role->description = $data['description'];
        }
        if (array_key_exists('sort', $data)) {
            $role->sort = (int) $data['sort'];
        }
        if (array_key_exists('isEnable', $data)) {
            $role->is_enable = (bool) $data['isEnable'];
        }
        if (array_key_exists('permissions', $data)) {
            $role->permissions = array_values(array_filter((array) $data['permissions']));
        }

        $role->save();

        ActivityLog::record('role.update', "Updated role {$role->name}");

        return $this->frontendOk($this->transform($role), 'Role updated');
    }

    public function destroy(Request $request)
    {
        $data = $this->getParams($request);
        $role = Role::find($data['id'] ?? null);

        if (! $role) {
            return $this->frontendError('Role not found');
        }

        if ($role->slug === 'admin') {
            return $this->frontendError('The admin role cannot be deleted');
        }

        if ($role->users()->exists()) {
            return $this->frontendError('Role is assigned to users and cannot be deleted');
        }

        $name = $role->name;
        $role->delete();

        ActivityLog::record('role.delete', "Deleted role {$name}");

        return $this->frontendOk(null, 'Role deleted');
    }

    protected function transform(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'code' => $role->code ?? $role->slug,
            'slug' => $role->slug,
            'description' => $role->description,
            'sort' => (int) ($role->sort ?? 0),
            'isEnable' => (bool) ($role->is_enable ?? true),
            'permissions' => $role->permissions ?? [],
            'addTime' => $role->created_at ? strtotime($role->created_at) : time(),
            'created_at' => $role->created_at?->toDateTimeString(),
        ];
    }
}
