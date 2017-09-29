<?php

namespace App\Services;

use stdClass;
use App\Exceptions\CmsException;
use Illuminate\Support\Collection;
use Illuminate\Database\ConnectionInterface;

// TODO(kjh): I NEED TO MAKE SURE THIS ALWAYS HAS A REAL ROOT

/**
 * The CMS uses a combination of the Closure Table & the Path Enumeration methods
 * for storing and interacting with a tree of data.
 * More information here: https://www.slideshare.net/billkarwin/models-for-hierarchical-data
 * A video here: https://www.youtube.com/watch?v=wuH5OoPC3hA
 */
class CmsService
{
    private $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }


    //----- PUBLIC METHODS -----------------------------// 

    /** Get the path map of values from the given root path */
    public function getPathMap(string $root) : array
    {
        $query = $this->db->table('cms_data')
            ->select('cms_data.path as path', 'cms_data.value as value')
            ->join('cms_closure', 'cms_data.id', '=', 'cms_closure.descendant_id')
            ->whereNotNull('cms_data.value')
            ->orderBy('cms_closure.length', 'asc');

        $rootLength = 1;
        $root = '/'.str_nopreslash($root);

        if ($root === '/') {
            $query->where('cms_closure.ancestor_id', 0);
        } else {
            $rootLength += strlen($root);
            $query->where('cms_closure.ancestor_id', function ($query) use ($root) {
                $query->select('id')->from('cms_data')->where('path', $root)->limit(1);
            });
        }
        
        return $query->get()->mapWithKeys(function ($leaf) use ($rootLength) {
            return [ substr($leaf->path, $rootLength) => $leaf->value ];
        })->all();
    }

    /** Get the value from the given path */
    public function getValueFromPath(string $path) : string
    {
        // Search for the normalized path
        $path = str_nopreslash($path);
        $leaf = $this->db->table('cms_data')
            ->where('path', "/{$path}")->first(['value']);

        // Make sure it exists
        if (is_null($leaf)) {
            throw new CmsException("Entry with path '{$path}' not found.", 404);
        }

        // Make sure it's not a parent leaf
        if (is_null($leaf->value)) {
            throw new CmsException("Entry with path '{$path}' has no value.", 403);
        }

        // Return the value
        return $leaf->value;
    }

    /**
     * Set a value on a given path
     *
     * 1. If the path is empty, then assume root & set key/value. Easy.
     * 2. If the key exists on the given path, then delete its descendants & set the value.
     * 3. Working backwards, search each path depth until found (or not found).
     * 4. Construct the tree from there, finally setting the value at the end.
     * 5. Return the id of the inserted leaf
     */
    public function setValueOnPath(string $path, string $value) : int
    {
        return $this->db->transaction(function () use ($path, $value) {
            // Explode the normalized path & pop the last element as the key
            $existingKeys = explode('/', str_nopreslash($path));
            $key = array_pop($existingKeys);

            if (is_null($key)) {
                throw new CmsException('Values cannot be set directly on the root.', 403);
            }

            /* 1. If the path is empty, then assume root & set key/value. Easy. */
            if (empty($existingKeys)) {
                return $this->insertChild(0, $key, $value);
            }

            /* 2. If the key exists on the given path, then delete its descendants & set the value. */
            $leaf = $this->db->table('cms_data')
                ->where('path', '/'.implode('/', $existingKeys).'/'.$key)->first(['id']);

            if (! is_null($leaf)) {
                $this->deleteSubtree($leaf->id);
                return $this->setValueOnPath($path, $value);
            }

            /* 3. Working backwards, search each path depth until found (or not found). */
            $newKeys = [];
            do {
                // Remove and save the last key
                $newKeys[] = array_pop($existingKeys);

                // Get the new shorter path
                $path = implode('/', $existingKeys);

                // If this path exists, then we can break
                $leaf = $this->db->table('cms_data')
                    ->where('path', "/{$path}")->first(['id']);

                if (! is_null($leaf)) {
                    break;
                }
            } while (! empty($existingKeys));

            // If leaf is null, just make it 0
            $id = is_null($leaf) ? 0 : $leaf->id;

            /* 4. Construct the tree from there, finally setting the value at the end. */
            do {
                $id = $this->insertChild($id, array_pop($newKeys));
            } while (! empty($newKeys));
            $id = $this->insertChild($id, $key, $value);

            // Finally, return the id of the inserted leaf
            return $id;
        });
    }


    //----- PROTECTED METHODS --------------------------// 

    /** Get the leaf of the given id and its descendants */
    protected function getDescendants(int $id) : Collection
    {
        return $this->db->table('cms_data')
            ->join('cms_closure', 'cms_data.id', '=', 'cms_closure.descendant_id')
            ->where('cms_closure.ancestor_id', $id)
            ->orderBy('cms_closure.length', 'asc')
            ->get();
    }

    /** Get the immediate children of the given id */
    protected function getChildren(int $id) : Collection
    {
        return $this->db->table('cms_data')
            ->join('cms_closure', 'cms_data.id', '=', 'cms_closure.descendant_id')
            ->where('cms_closure.ancestor_id', $id)
            ->where('length', 1)
            ->orderBy('cms_closure.length', 'asc')
            ->get();
    }

    /** Get the leaf of the given id and its ancestors */
    protected function getAncestors(int $id) : Collection
    {
        return $this->db->table('cms_data')
            ->join('cms_closure', 'cms_data.id', '=', 'cms_closure.ancestor_id')
            ->where('cms_closure.descendant_id', $id)
            ->orderBy('cms_closure.length', 'desc')
            ->get();
    }

    /** Get the immediate parent of the given id */
    protected function getParent(int $id, $columns = ['*']) : ?stdClass
    {
        return $this->db->table('cms_data')
            ->join('cms_closure', 'cms_data.id', '=', 'cms_closure.ancestor_id')
            ->where('cms_closure.descendant_id', $id)
            ->where('length', 1)
            ->orderBy('cms_closure.length', 'desc')
            ->first($columns);
    }

    /** Insert a new key/value under the given parent id */
    protected function insertChild(int $parentId, string $key, string $value = null) : int
    {
        return $this->db->transaction(function () use ($parentId, $key, $value) {
            // Get the parent path, if there is one
            $parentPath = $parentId === 0
                ? '' : $this->db->table('cms_data')->where('id', $parentId)->first(['path'])->path;

            // Store the new path
            $path = "{$parentPath}/{$key}";

            // Try to find a match first. If it exists, then return that id
            $match = $this->db->table('cms_data')->where('path', $path)->first(['id']);
            if (! is_null($match)) {
                return $match->id;
            }

            // Insert the child & get its id
            $childId = $this->db->table('cms_data')
                ->insertGetId(['path' => $path, 'value' => $value]);

            // If the parent id is 0, then connect it to the imaginary root
            if ($parentId === 0) {
                $this->db->table('cms_closure')
                    ->insert(['ancestor_id' => 0, 'descendant_id' => $childId, 'length' => 1]);
            }

            // With much complexity, insert it into the tree
            $this->db->insert('
                INSERT INTO cms_closure (ancestor_id, descendant_id, length)
                    SELECT ancestor_id, ?, SUM(length + 1)
                        FROM cms_closure
                        WHERE descendant_id = ?
                        GROUP BY ancestor_id
                    UNION ALL SELECT ?, ?, 0
            ', [$childId, $parentId, $childId, $childId, $childId]);

            // Return the child id
            return $childId;
        });
    }

    /** Update the value of the given leaf, referenced by id */
    protected function updateValue(int $id, string $value) : boolean
    {
        $numRowsUpdated = $this->db->table('cms_data')
            ->where('id', $id)->update(['value', $value]);

        if ($numRowsUpdated === 0) {
            throw new CmsException("Entry with id {$id} not found. Cannot update.", 404);
        }

        return true;
    }

    /** Delete the subtree under the given id and return the deleted ids */
    protected function deleteSubtree(int $id) : Collection
    {
        return $this->db->transaction(function () use ($id) {
            // Get the descendant ids
            $ids = $this->db->table('cms_closure')
                ->where('ancestor_id', $id)
                ->pluck('descendant_id');

            // Delete them from the closure table
            $this->db->table('cms_closure')
                ->whereIn('descendant_id', $ids)
                ->delete();

            // Delete them from the data table
            $this->db->table('cms_data')
                ->whereIn('id', $ids)
                ->delete();

            // Return the ids
            return $ids;
        });
    }
}
