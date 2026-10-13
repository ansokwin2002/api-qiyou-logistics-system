<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Controllers\Traits\FrontendResponse;
use App\Models\ActivityLog;
use App\Models\Menu;
use Illuminate\Http\Request;

/**
 * manageapi/sysmenu/* — menu / permission-key management used by the admin panel.
 */
class MenuManageController extends Controller
{
    use ApiResponse, FrontendResponse;

    public function tree()
    {
        return $this->frontendOk(Menu::tree());
    }

    public function get(Request $request)
    {
        $id = $request->input('data.id') ?? $request->query('id');
        $menu = Menu::find($id);

        if (! $menu) {
            return $this->frontendError('Menu not found');
        }

        return $this->frontendOk($this->transform($menu));
    }

    public function store(Request $request)
    {
        $data = $this->getParams($request);

        if (empty($data['menuNameKey'])) {
            return $this->frontendError('Menu name is required');
        }

        $menu = Menu::create($this->map($data));

        ActivityLog::record('menu.create', "Created menu {$menu->name_key}");

        return $this->frontendOk($this->transform($menu), 'Menu added');
    }

    public function update(Request $request)
    {
        $data = $this->getParams($request);
        $menu = Menu::find($data['id'] ?? null);

        if (! $menu) {
            return $this->frontendError('Menu not found');
        }

        $menu->update($this->map($data));

        ActivityLog::record('menu.update', "Updated menu {$menu->name_key}");

        return $this->frontendOk($this->transform($menu), 'Menu updated');
    }

    public function destroy(Request $request)
    {
        $data = $this->getParams($request);
        $menu = Menu::find($data['id'] ?? null);

        if (! $menu) {
            return $this->frontendError('Menu not found');
        }

        Menu::where('parent_id', $menu->id)->update(['parent_id' => $menu->parent_id]);
        $menu->delete();

        ActivityLog::record('menu.delete', 'Deleted menu');

        return $this->frontendOk(null, 'Menu deleted');
    }

    protected function map(array $data): array
    {
        return [
            'parent_id' => (int) ($data['parentId'] ?? 0),
            'menu_type' => (int) ($data['menuType'] ?? Menu::TYPE_MENU),
            'name_key' => $data['menuNameKey'] ?? '',
            'authority' => $data['authority'] ?? null,
            'route_name' => $data['identificationCode'] ?? null,
            'path' => $data['path'] ?? null,
            'component' => $data['component'] ?? null,
            'icon' => $data['menuIcon'] ?? null,
            'sort' => (int) ($data['sortNumber'] ?? 0),
            'hide' => (bool) ($data['hide'] ?? false),
            'is_keep_alive' => (bool) ($data['isKeepAlive'] ?? false),
            'is_affix' => (bool) ($data['isAffix'] ?? false),
        ];
    }

    protected function transform(Menu $menu): array
    {
        $parentIds = [];
        $node = $menu;
        while ($node && $node->parent_id) {
            $parentIds[] = $node->parent_id;
            $node = Menu::find($node->parent_id);
        }

        return [
            'id' => $menu->id,
            'parentId' => $menu->parent_id,
            'parent' => array_reverse($parentIds),
            'menuType' => $menu->menu_type,
            'menuNameKey' => $menu->name_key,
            'authority' => $menu->authority,
            'identificationCode' => $menu->route_name,
            'path' => $menu->path,
            'component' => $menu->component,
            'menuIcon' => $menu->icon,
            'sortNumber' => $menu->sort,
            'hide' => $menu->hide,
            'isKeepAlive' => $menu->is_keep_alive,
            'isAffix' => $menu->is_affix,
        ];
    }
}
