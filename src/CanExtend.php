<?php

namespace App\Support\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait CanExtend
{
    static public array $columns;
    static public array $morphColumns;
    static public string $parentClass;
    static public string $parentName;
    static public string $parentTable;
    static private Collection $parentAttributes;

    /**
     *
     * Recipe: Call this on model's boot method or a bootable trait.
     * Bootable trait: method in trait with name boot<Trait-name>
     * 
     * @param string $parent
     * @return void
     */
    static private function extends(string $parent, string $morphName): void
    {
        self::joinAndEagerLoad($parent = self::initializeProperties($parent, $morphName));

        self::resolveMorphOneRelation($morphName);

        self::creating(fn ($self) => self::extractParentAttributes($self));

        self::created(fn ($self) => self::createParentAndMergeAttributes($self, $parent));

        self::updating(fn ($self) => self::syncParentOnUpdate($self));

        self::retrieved(fn ($self) => self::withParent($self, $parent));
    }

    static private function initializeProperties(string $parentClass, string $morphName): Model
    {
        [$parent, self::$parentClass] = [new $parentClass, $parentClass];

        [self::$parentName, self::$parentTable] = [self::getClassName($parent), $parent->getTable()];

        self::$columns = Schema::getColumnListing(self::guessTableName(self::class));

        self::$morphColumns = ['id' => $morphName . '_id', 'type' => $morphName . '_type'];

        return $parent;
    }

    static private function createParentAndMergeAttributes(Model $self, $parent): void
    {
        $parentAttributes = (self::$parentAttributes = self::$parentAttributes
            ->merge($morphs = $self->getChildMorphs()))->toArray();

        $morphs = collect($morphs)
            ->mapWithKeys(fn ($val, $col) => [$parent->qualifyColumn($col) => $val])
            ->toArray();

        if (self::$parentClass::where($morphs)->get()->isEmpty())
            self::unguarded(fn () => self::$parentClass::create($parentAttributes));

        $self->forceFill($parentAttributes);
    }

    static private function resolveMorphOneRelation(string $morphName): void
    {
        self::resolveRelationUsing(
            self::$parentName,
            fn ($self) => $self->morphOne(self::$parentClass, $morphName)
        );
    }

    static public function syncParentOnUpdate(Model $self): void
    {
        if (Arr::hasAny($self->getDirty(), self::$morphColumns))
            throw new Exception("cannot update model's extending morph attributes");

        self::extractParentAttributes($self);

        self::unguarded(fn () => $self->{self::$parentName}
            ->update(self::$parentAttributes->toArray()));
    }

    static private function withParent(Model $self, Model $parent): void
    {
        $self->casts = array_merge($self->getCasts(), $parent->getCasts());
    }

    static private function extractParentAttributes(Model $self): void
    {
        self::$parentAttributes = new Collection;

        collect($self->getDirty())->each(function ($value, $key) use ($self) {
            if (!in_array($key, self::$columns)) {
                unset($self->{$key});

                self::$parentAttributes->put($key, $value);
                // self::$parentAttributes->prepend($value, $key);
            }
        });
    }

    static private function joinAndEagerLoad(Model $parent): void
    {
        // tested ONLY for two layers deep 
        self::addGlobalScope(
            fn ($query) => self::joinToParent(self::joinToParent($query, $self = new self), $parent)
                ->beforeQuery(fn ($query) => self::clarifyAmbiguousColumns($query, $self))
            // ->with(self::eagerLoadables($parent))
            // ->without(self::lazyLoadables($self, $parent))
        );
    }

    static private function joinToParent($query, $child)
    {
        if (!property_exists($child, 'parentClass')) return $query;

        // $morphCols = self::qualifyMorphCols($child);

        return $query->join($child::$parentTable, fn ($join) => $join
            ->on($child::$morphColumns['id'], $child->qualifyPrimaryKey())
            ->where($child::$morphColumns['type'], $child::class));
    }

    static private function clarifyAmbiguousColumns($query, $child): Collection
    {
        array_push($query->columns, ...self::getAmbiguousColumns($child = new self));

        return collect($query->wheres)
            ->each(fn ($where, $key) =>
            array_key_exists('column', $where) &&
                $where['column'] === $child->primaryKey &&
                $query->wheres[$key]['column'] = $child->qualifyPrimaryKey());
    }

    static private function getAmbiguousColumns($self): array
    {
        return [
            $self->qualifyColumn("id") . " AS id",
            "{$self::$parentTable}.id AS {$self::$parentTable}_id",
            // ...collect($self::qualifyMorphCols($self))
            //     ->map(fn ($col, $key) => $col . " AS " . pathinfo($col, PATHINFO_EXTENSION))
            //     ->values()
            //     ->toArray();
        ];
    }

    static private function eagerLoadables(Model $parent): array
    {
        return [self::getClassName($parent) => ($parent->with ?? [])];
        // return [...($parent->with ?? [])];
    }

    static private function lazyLoadables(Model $self, Model $parent): array
    {
        return [self::getClassName($self)];
    }

    static private function refreshAttributes(Model $self): Model
    {
        $self->attributes = $self->getAttributes() +
            ($parent = $self->{$self::$parentName})->only(array_flip($parent->getCasts())) +
            // ($parent = $self::$parentClass::where("{$MORPH_NAME}_id", $self->id)->first())->only(array_flip($parent->getCasts())) +
            $parent->getAttributes();

        return $self;
    }

    static private function getClassName(object $object): string
    {
        return Str::camel(class_basename($object));
    }

    static private function guessTableName(string $class): string
    {
        return Str::snake(Str::plural(class_basename($class)));
    }

    static private function qualifyMorphCols($child): array
    {
        return (new $child::$parentClass)
            ->qualifyColumns(collect(self::$morphColumns)
                ->mapWithKeys(fn ($col) => [Str::endsWith($col, 'id') ? 'id' : 'type' => $col])
                ->toArray());
    }

    private function getChildMorphs(): array
    {
        return collect(self::$morphColumns)
            ->flip()
            ->map(fn ($v, $col) => Str::endsWith($col, 'id') ?
                $this->id : $this::class)
            ->toArray();
    }

    public function hasParent(): bool
    {
        return property_exists($this, 'parentClass');
    }

    public function qualifyPrimaryKey(): string
    {
        return $this->qualifyColumn($this->primaryKey);
    }

    public function initializeCanExtend(): void
    {
        // $this->mergeFillable(self::$parentFillables);

        // $this->guarded = collect(self::$columns)->diff($this->fillable)->toArray();
        $this->guarded = [];
        $this->fillable = [];

        ($parent = new self::$parentClass);
        $this->setVisible(array_merge($this->getVisible(), $parent->getVisible()));
    }

    public function __get($name)
    {
        // return (bool) ($parent = parent::__get(self::$parentName)) ?
        //     parent::__get($name) ?? $parent->{$name} :
        //     parent::__get($name);

        return parent::__get($name) ??
            ((bool) ($parent = parent::__get(self::$parentName)) ?
                $parent->{$name} : parent::__get($name));
    }
}
