<?php

namespace Brandmovers\Catalog\Models;

use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Collection;

class CatalogCategory extends Model
{
    public $timestamps = false;
    protected $fillable = ['name'];

    //----- PUBLIC STATIC METHODS ----------------------// 

    /** Get all of the tree roots */
    public static function getRoot() : CatalogCategory
    {
        return static::find(1);
    }

    /**
     * Import an array of categories, building the lineage
     * as necessary from 0 -> n. Then return the final
     * leaf in the lineage.
     */
    public static function import(array $categories) : CatalogCategory
    {
        // Get the root category
        $parent = static::getRoot();

        // Loop through each category name in the name list
        foreach ($categories as $category) {
            // Get the matching category from the children
            $match = $parent->getChildren()
                ->first(function (CatalogCategory $possibleMatch) use ($category) {
                    return $possibleMatch->name === $category->name;
                });

            // If there is no match, then create it.
            // Replace $parent with the new value
            $parent = $match ?: $parent->insertChild($category);
        }

        // Return the final category
        return $parent;
    }

    /** Get this catalog category and its descendants */
    public static function getDescendants(array $parentIds, array $attributes = ['*']) : Collection
    {
        return static::query()
            ->join('catalog_categories_closure', 'catalog_categories.id', '=', 'catalog_categories_closure.descendant_id')
            ->whereIn('catalog_categories_closure.ancestor_id', $parentIds)
            ->groupBy(['catalog_categories.id', 'catalog_categories_closure.ancestor_id', 'catalog_categories_closure.length'])
            ->orderBy('catalog_categories_closure.length', 'asc')
            ->get($attributes);
    }

    /** Get the immediate children of this catalog category */
    public static function getChildren(array $parentIds, array $attributes = ['*']) : Collection
    {
        return static::query()
            ->join('catalog_categories_closure', 'catalog_categories.id', '=', 'catalog_categories_closure.descendant_id')
            ->whereIn('catalog_categories_closure.ancestor_id', $parentIds)
            ->where('catalog_categories_closure.length', 1)
            ->groupBy(['catalog_categories.id', 'catalog_categories_closure.ancestor_id', 'catalog_categories_closure.length'])
            ->orderBy('catalog_categories_closure.length', 'asc')
            ->get($attributes);
    }

    /** Get this catalog category and its ancestors */
    public static function getAncestors(array $parentIds, array $attributes = ['*']) : Collection
    {
        return static::query()
            ->join('catalog_categories_closure', 'catalog_categories.id', '=', 'catalog_categories_closure.ancestor_id')
            ->whereIn('catalog_categories_closure.descendant_id', $parentIds)
            ->groupBy(['catalog_categories.id', 'catalog_categories_closure.ancestor_id', 'catalog_categories_closure.length'])
            ->orderBy('catalog_categories_closure.length', 'desc')
            ->get($attributes);
    }

    /** Get the immediate parent of this catalog category */
    public static function getParent(array $parentIds, array $attributes = ['*']) : CatalogCategory
    {
        return static::query()
            ->join('catalog_categories_closure', 'catalog_categories.id', '=', 'catalog_categories_closure.ancestor_id')
            ->where('catalog_categories_closure.descendant_id', $parentIds)
            ->where('catalog_categories_closure.length', 1)
            ->groupBy(['catalog_categories.id', 'catalog_categories_closure.ancestor_id', 'catalog_categories_closure.length'])
            ->orderBy('catalog_categories_closure.length', 'desc')
            ->first($attributes);
    }

    /** Insert a new catalog category under this catalog category */
    public static function insertChild(int $parentId, CatalogCategory $child) : CatalogCategory
    {
        return DB::transaction(function () use ($parentId, $child) {
            // Try to find a match
            $match = null;
            $matches = static::query()->where('name', $child->name)->get();
            foreach ($matches as $possibleMatch) {
                $parent = $possibleMatch->getParent();
                if (!is_null($parent) && $parent->id === $parentId) {
                    $match = $possibleMatch;
                    break;
                }
            }

            // If there is a match, then return it
            if (! is_null($match)) {
                return $match;
            }

            // Save the child
            $child->save();

            // With much complexity, insert it into the tree
            DB::insert('
                INSERT INTO catalog_categories_closure (ancestor_id, descendant_id, length)
                    SELECT ancestor_id, ?, SUM(length + 1)
                        FROM catalog_categories_closure
                        WHERE descendant_id = ?
                        GROUP BY ancestor_id
                    UNION ALL SELECT ?, ?, 0
            ', [$child->id, $parentId, $child->id, $child->id]);

            // Return the child
            return $child;
        });
    }

    /** Delete the subtree under this catalog category and return the deleted ids */
    public static function deleteSubtree(int $parentId) : Collection
    {
        return $this->db->transaction(function () use ($parentId) {
            // Get the descendant ids
            $ids = DB::table('catalog_categories_closure')
                ->where('ancestor_id', $parentId)
                ->pluck('descendant_id');

            // Delete them from the closure table
            DB::table('catalog_categories_closure')
                ->whereIn('descendant_id', $ids)
                ->delete();

            // Delete them from the data table
            static::query()
                ->whereIn('id', $ids)
                ->delete();

            // Return the ids
            return $ids;
        });
    }

    //----- RELATIONSHIPS ------------------------------// 

    public function items() : Relations\HasMany
    {
        return $this->hasMany(CatalogItem::class, 'category_id');
    }

    //----- MAGIC METHODS ------------------------------// 

    /** Call the tree methods in reference to $this->id */
    public function __call($method, $parameters)
    {
        $methods = ['getDescendants', 'getChildren', 'getParent', 'insertChild', 'deleteSubtree'];

        if (in_array($method, $methods)) {
            $ids = in_array($method, ['insertChild', 'deleteSubtree']) ? $this->id : [$this->id];
            return static::$method(...array_merge([$ids], $parameters));
        }

        return parent::__call($method, $parameters);
    }
}
