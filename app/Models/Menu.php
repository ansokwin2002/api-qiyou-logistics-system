<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    const TYPE_MENU = 0;
    const TYPE_BUTTON = 1;

    protected $fillable = [
        'parent_id',
        'menu_type',
        'name_key',
        'authority',
        'route_name',
        'path',
        'component',
        'icon',
        'sort',
        'hide',
        'is_keep_alive',
        'is_affix',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'menu_type' => 'integer',
        'sort' => 'integer',
        'hide' => 'boolean',
        'is_keep_alive' => 'boolean',
        'is_affix' => 'boolean',
    ];

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    /**
     * Build the nested tree the admin menu table expects.
     * Rows are keyed with `title`, `order` and `name` to match the legacy panel.
     */
    public static function tree(): array
    {
        $items = static::orderBy('sort')->orderBy('id')->get()->toBase();

        $byParent = $items->groupBy('parent_id');

        $build = function ($parentId) use (&$build, $byParent) {
            return $byParent->get($parentId, collect())->map(function ($item) use ($build) {
                $children = $build($item->id);

                return [
                    'id' => $item->id,
                    'parentId' => $item->parent_id,
                    'name' => $item->name_key,
                    'title' => $item->name_key,
                    'label' => $item->name_key,
                    'menuType' => $item->menu_type,
                    'authority' => $item->authority,
                    'path' => $item->path,
                    'routeName' => $item->route_name,
                    'component' => $item->component,
                    'icon' => $item->icon,
                    'order' => $item->sort,
                    'sort' => $item->sort,
                    'hide' => $item->hide,
                    'isKeepAlive' => $item->is_keep_alive,
                    'isAffix' => $item->is_affix,
                    'children' => $children->values()->all(),
                    'hasChildren' => $children->isNotEmpty(),
                ];
            })->values()->all();
        };

        return $build(0);
    }
}
