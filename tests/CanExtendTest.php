<?php

namespace Models\Traits;

use App\Support\Traits\CanExtend;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

const INITIAL_SETUP_QUERIES = ['getting_columns'];
const CREATING_QUERIES = ['self_insert', 'parent_existence_check', 'parent_insert'];
const MUTATOR_MESSAGE = 'attribute was received in mutator.';

class CanExtendTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        Schema::create('grandparent_models', function (Blueprint $table) {
            $table->id();
            $table->morphs('grandparentable');
            $table->string('legacy');
        });

        Schema::create('parent_models', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('parentable');
            $table->string('lastname');
            $table->string('guarded_attribute_a');
            $table->string('guarded_attribute_b')->default('value');
            $table->json('cast')->nullable();
        });

        Schema::create('child_a_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('child_b_models', function (Blueprint $table) {
            $table->id();
            $table->string('nickname')->default('K1');
        });

        $this->grandparentDefinitions = [
            'legacy' => 'money',
        ];

        $this->parentDefinitions = $this->grandparentDefinitions + [
            'guarded_attribute_a' => 1234,
            'lastname' => 'Zang',
            'cast' => ['key' => 'value']
        ];

        $this->childADefinitions = $this->parentDefinitions + [
            'name' => 'Keivan'
        ];
    }

    public function test_fillable_parent_attributes_passed_from_child_guard()
    {
        ChildAModel::creating(fn ($child) =>
        self::assertArrayKeysContainInArray($this->childADefinitions, $child->getAttributes()));

        self::createChildA();
    }

    public function test_child_instant_contains_parent_attributes_after_created()
    {
        self::assertEquals(
            self::createChildA()->guarded_attribute_a,
            $this->parentDefinitions['guarded_attribute_a']
        );
    }

    public function test_child_instant_contains_parent_attributes_on_refresh()
    {
        self::assertEquals(
            self::createChildA()->refresh()->guarded_attribute_a,
            $this->parentDefinitions['guarded_attribute_a']
        );
    }

    public function test_parent_created_on_child_create()
    {
        self::assertModelExists(self::createChildA()->parentModel);
    }

    public function test_any_custom_fillable_included()
    {
        ChildAModel::creating(function ($child) {
            self::assertArrayHasKey('guarded_attribute_a', $child->getAttributes());
        });

        self::createChildA();
    }

    public function test_guarded_attributes_did_not_get_merged()
    {
        ChildAModel::creating(function ($child) {
            self::assertArrayNotHasKey('guarded_attribute_b', $child->getAttributes());
        });

        self::createChildA();
    }


    public function test_create_exactly_one_when_creating_one()
    {
        self::createChildA();

        self::assertCount(1, ChildAModel::all());
        self::assertCount(1, ParentModel::all());
    }


    public function test_when_creating_many_not_miss_or_exceed_by_count()
    {
        foreach (range(1, 3) as $ignored) self::createChildA();
        foreach (range(1, 2) as $ignored) ChildBModel::create($this->parentDefinitions);

        self::assertCount(3, ChildAModel::all());
        self::assertCount(2, ChildBModel::all());
        self::assertCount(5, ParentModel::all());
    }

    public function test_all_create_child_exist()
    {
        $childA1 = self::createChildA();
        $childB1 = ChildBModel::create($this->parentDefinitions);
        $childA2 = self::createChildA();

        self::assertModelExists($childA1);
        self::assertModelExists($childA2);
        self::assertModelExists($childB1);
    }

    public function test_each_child_has_its_own_parent_record()
    {
        [self::createGrandparent(), self::createParent(), self::createChildA()];
        $childA = self::createChildA();
        $childB = ChildBModel::create($this->parentDefinitions);
        [self::createGrandparent(), self::createParent(), self::createChildA()];

        $childAParent = ParentModel::find(3);
        self::assertModelExists($childAParent);
        self::assertEquals($childA::class, $childAParent->parentable_type);

        $childBParent = ParentModel::find(4);
        self::assertModelExists($childBParent);
        self::assertEquals($childB::class, $childBParent->parentable_type);
    }

    public function test_primary_key_did_not_get_submerged_while_merging_attributes()
    {
        [self::createGrandparent(), self::createParent(), self::createChildA()];
        $childA1 = self::createChildA();
        $childB1 = ChildBModel::create($this->parentDefinitions);
        $childA2 = self::createChildA();

        $childA1Parent = ParentModel::find(3);
        $childB1Parent = ParentModel::find(4);
        $childA2Parent = ParentModel::find(5);

        self::assertEquals([
            'ids' => [$childA1->id, $childB1->id, $childA2->id,],
            'types' => [$childA1::class, $childB1::class, $childA2::class,]
        ], [
            'ids' => [$childA1Parent->parentable_id, $childB1Parent->parentable_id, $childA2Parent->parentable_id],
            'types' => [$childA1Parent->parentable_type, $childB1Parent->parentable_type, $childA2Parent->parentable_type]
        ]);
    }

    public function test_has_morph_one_relation_with_parent()
    {
        self::assertModelExists(self::createChildA()->parentModel);
    }

    public function test_do_not_create_parent_if_exists()
    {
        [self::createGrandparent(), self::createParent(), self::createChildA()];
        self::createParent($morphs = ['parentable_type' => ChildAModel::class, 'parentable_id' => 2]);
        self::createChildA();

        $parent = ParentModel::find(3);

        self::assertCount(3, ParentModel::all());
        self::assertEquals($morphs, $parent->only(array_keys($morphs)));
    }


    public function test_updated_parent_attribute_in_child_does_not_fail_updating_child()
    {
        ($child = self::createChildA())->forceFill(
            ['lastname' => 'a parent attribute', 'name' => 'a child attribute',] +
                $this->grandparentDefinitions
        )->save();

        self::assertEquals('a child attribute', $child->name);
    }

    public function test_can_update_parent_through_child()
    {
        [self::createGrandparent(), self::createParent(), self::createChildA()];
        $child = self::createChildA();

        $child->forceFill(['lastname' => 'a parent attribute'] + $this->grandparentDefinitions)->save();

        self::assertEquals('a parent attribute', $child->parentModel->lastname);
    }

    public function test_update_parent_and_child_with_mixed_attributes_in_child()
    {
        $child = self::createChildA();

        $child->forceFill(
            ['lastname' => 'a parent attribute', 'name' => 'a child attribute'] +
                $this->grandparentDefinitions
        )->save();

        self::assertEquals('a parent attribute', $child->parentModel->lastname);
        self::assertEquals('a child attribute', $child->name);
    }

    public function test_can_access_updated_parent_attributes_after_updating_without_refreshing()
    {
        $child = self::createChildA();

        $child->forceFill(['lastname' => 'a parent attribute',] + $this->grandparentDefinitions)->save();
        self::assertEquals('a parent attribute', $child->lastname);

        $child->forceFill(
            ['lastname' => 'another parent attribute', 'name' => 'another child attribute'] +
                $this->grandparentDefinitions
        )->save();
        self::assertEquals('another parent attribute', $child->lastname);
        self::assertEquals('another child attribute', $child->name);
    }

    public function test_trying_to_update_parent_morphs_throws_exception()
    {
        self::expectExceptionMessage("cannot update model's extending morph attributes");

        self::createChildA()->forceFill([
            'parentable_type' => 'to something else',
            'parentable_id' => 'to something else',
        ])->save();
    }

    public function test_child_access_to_casted_version_of_an_attribute()
    {
        $this->childADefinitions['cast'] = ['key' => 'value'];

        $child = self::createChildA();

        self::assertEquals($this->childADefinitions['cast'], $child->cast);

        $child->parentModel->refresh();
        self::assertEquals($this->childADefinitions['cast'], $child->cast);

        self::assertEquals($this->childADefinitions['cast'], ChildAModel::find($child->id)->cast);
    }

    // public function test_child_has_access_to_updated_version_of_parent_attributes()
    // {
    //     $child = self::createChildA();

    //     $child->parentModel->lastname = 'changed';

    //     self::assertEquals('changed', $child->lastname);
    // }

    public function test_creating_using_new_key_word()
    {
        self::assertModelExists(self::createParent());
    }

    public function test_parent_has_access_to_grand_parent_attributes()
    {
        self::assertEquals('money', self::createParent()->legacy);
    }

    public function test_child_has_access_to_grand_parent_attributes()
    {
        self::assertEquals('money', ($child = self::createChildA())->refresh()->legacy);
    }

    // deprecated
    // public function test_child_and_parent_same_attribute_has_given_to_both()
    // {
    //     $child = ChildAModel::create(['common_attribute' => 2] + $this->childADefinitions);
    //     $child->refresh();

    //     self::assertEquals(2, $child->attributes['common_attribute']);
    //     self::assertEquals(2, $child->parentModel->getAttributes()['common_attribute']);
    // }

    // deprecated
    // public function test_child_and_grandparent_same_attribute_has_given_to_both() 
    // {
    //     $child = ChildAModel::create(['common_attribute' => 2] + $this->childADefinitions);
    //     $child->refresh();

    //     self::assertEquals(2, $child->attributes['common_attribute']);
    //     self::assertEquals(2, $child->grandparentModel->getAttributes()['common_attribute']);
    // }

    public function test_prove_table_joined_with_parent()
    {
        $parentAttributes = [$attribute = 'lastname' => 'changed'];

        [self::createChildA(), self::createChildA($parentAttributes), self::createChildA(),];

        self::assertEquals(
            $parentAttributes,
            ChildAModel::where($parentAttributes)->first()->only($attribute)
        );
    }

    public function test_querying_where_and_primary_key()
    {
        $parent = self::createParent();

        self::assertEquals($parent->id, ParentModel::where('id', $parent->id)->first()->id);
    }

    public function test_prove_table_joined_with_grandparent()
    {
        $grandparentAttributes = [$attribute = 'legacy' => 'spiritual'];

        [self::createChildA(), self::createChildA($grandparentAttributes), self::createChildA(),];

        self::assertEquals(
            $grandparentAttributes,
            ChildAModel::where($grandparentAttributes)->first()->only($attribute)
        );
    }

    /**
     * @dataProvider updatingAttributeProvider
     */
    public function test_updating_attribute($attribute, $value)
    {
        $child = self::createChildA();

        $child->forceFill([$attribute  => $value])->save();

        self::assertEquals($value, $child->refresh()->{$attribute});
    }

    public static function updatingAttributeProvider(): array
    {
        return [
            ['name', 'changedToSomethingElse'],
            ['lastname', 'changedToSomethingElse'],
            ['legacy', 'changedToSomethingElse'],
        ];
    }

    public function test_using_only_method_on_newly_create_model()
    {
        $child = self::createChildA();

        self::assertEquals($child->parentModel->only(['lastname']), $child->only(['lastname']));

        self::assertEquals($child->parentModel->only(['lastname']), ChildAModel::first()->only(['lastname']));
    }

    public function test_retrieved_ids_belongs_to_actual_model()
    {
        [self::createParent(), self::createGrandparent(), self::createGrandparent()];

        $child = self::createChildA();
        self::assertEquals(1, $child->id);

        $child = ChildAModel::find(1);
        self::assertEquals(1, $child->id);

        $parent = DB::table('parent_models')->where('parentable_id', $child->id)->first();
        self::assertEquals($parent->id, $child->parentModel->id);

        $grandparent = DB::table('grandparent_models')->where('grandparentable_id', $parent->id)->first();
        self::assertEquals($grandparent->id, $child->grandparentModel->id);
        self::assertEquals($grandparent->id, $child->parentModel->grandparentModel->id);
    }

    public function test_query_count_while_creating_grandparent()
    {
        self::nPlusOne($count);

        self::createGrandparent();

        self::assertEquals(1, $count);
    }

    public function test_query_count_while_creating_parent()
    {
        self::nPlusOne($count);

        self::createParent();

        self::assertEquals(count(CREATING_QUERIES) + count(INITIAL_SETUP_QUERIES), $count);
    }

    public function test_query_count_while_creating_child()
    {
        self::nPlusOne($count);

        self::createChildA();

        self::assertEquals(-1 + count(CREATING_QUERIES) * 2 + count(INITIAL_SETUP_QUERIES) * 2, $count);
    }

    public function test_query_count_while_retrieving_all()
    {
        self::createChildA();
        self::nPlusOne($count);

        GrandparentModel::all();
        self::assertEquals($n = 1, $count);

        ParentModel::all();
        self::assertEquals(++$n, $count);

        ChildAModel::all();
        self::assertEquals(++$n, $count);
    }

    public function test_query_count_while_accessing_grandparent_attributes_through_child()
    {
        $child = self::createChildA();
        self::nPlusOne($count);

        $child->legacy;
        self::assertEquals($n = 0, $count);

        ChildAModel::first()->legacy;
        self::assertEquals(++$n, $count);
    }

    public function test_query_count_while_accessing_parent_attributes_through_child()
    {
        $child = self::createChildA();
        self::nPlusOne($count);

        $child->lastname;
        self::assertEquals($n = 0, $count);

        ChildAModel::first()->lastname;
        self::assertEquals(++$n, $count);
    }

    // for flexibility and performance sake closed!
    // public function test_parent_and_grandparent_are_loaded() 
    // {
    //     $child = self::createChildA();
    //     self::assertTrue($child->relationLoaded('parentModel'));
    //     self::assertTrue($child->relationLoaded('grandparentModel'));

    //     $child = ChildAModel::first();
    //     self::assertTrue($child->relationLoaded('parentModel'));
    //     self::assertTrue($child->relationLoaded('grandparentModel'));
    // }

    public function test_latest_added_attributes_are_at_top_of_attributes_array()
    {
        self::expectExceptionMessage(MUTATOR_MESSAGE);

        ParentModel::creating(function (ParentModel $self) {
            $self->early_initiated_attribute = 1;
            unset($self->legacy);
            $self->legacy = 1;
        });

        // ParentModel::cerate(["this will be"] + $definitions + ["this will NOT"=>"]);
        self::createParent();

        // BUG DESCRIPTION:      some attributes are not accessible in mutators looks like 
        // its creating a brand new model and passing it to them and it fixes when appending 
        // another array to the end of first one (only those values will get passed!) this 
        // test checks that exception placed in mutator is not thrown

        // FIX AND THE CAUSE:      when creating a model the moment reaches the array of
        // attributes its newing an instance and one by one set the attributes on model, so 
        // in this between it will trigger any of their setter and at that point because the
        // rest of attributes are not set yet they will not be available inside mutator either
    }

    public function test_getting_child()
    {
        [self::createGrandparent(), self::createParent(), self::createChildA()];
        $grandparent = ($parent = ($child = self::createChildA())->parentModel)->grandparentModel;
        [self::createGrandparent(), self::createParent(), self::createChildA()];

        self::assertEquals($parent::class, $grandparent->grandparentable::class);
        self::assertEquals($parent->id, $grandparent->grandparentable->id);

        self::assertEquals($child::class, $parent->parentable::class);
        self::assertEquals($child->id, $parent->parentable->id);

        self::assertEquals($parent->parentable->id, $grandparent->grandparentable->parentable->id);

        self::assertEquals($child::class, $grandparent->grandparentable->parentable::class);
        self::assertEquals($child->id, $grandparent->grandparentable->parentable->id);
    }

    // NOT TESTS
    // ___________________________________________________________________________________

    public static function assertArrayKeysContainInArray(array $needles, array $haystack)
    {
        collect($needles)->each(fn ($attribute, $key) =>
        self::assertArrayHasKey($key, $haystack));
    }

    public function createParent(array $attributes = []): ParentModel
    {
        ($parent = (new ParentModel)
            ->forceFill($attributes + $this->parentDefinitions)
        )->save();

        return $parent;
    }

    public function createChildA(array $attributes = []): ChildAModel
    {
        return ChildAModel::create($attributes + $this->childADefinitions);
    }

    public function createGrandparent(): GrandparentModel
    {
        ($grandparent = (new GrandparentModel)
            ->forceFill($this->grandparentDefinitions +
                ['grandparentable_id' => 3, 'grandparentable_type' => 'notNull'])
        )->save();

        return $grandparent;
    }
}

// SOME SAMPLE CLASS TO WORK WITH
// ______________________________________________________________________________________

class GrandparentModel extends Model
{

    protected $guarded = [];
    // protected $with = ['grandparentable'];
    public $timestamps = false;

    public function legacy(): Attribute
    {
        return Attribute::set(function ($value, $attributes) {
            if (array_key_exists('early_initiated_attribute', $attributes))
                throw new \Exception(MUTATOR_MESSAGE);

            return $value;
        });
    }

    public function grandparentable(): MorphTo
    {
        return $this->morphTo();
    }
}

trait HasParent
{
    use CanExtend;

    static function bootHasParent(): void
    {
        self::extends(ParentModel::class, 'parentable');
    }
}

class ParentModel extends Model
{
    use CanExtend;

    protected $casts = ['cast' => 'array'];
    protected $fillable = ['lastname', 'cast'];
    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        self::extends(GrandparentModel::class, 'grandparentable');
    }

    public function parentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class ChildAModel extends Model
{
    use CanExtend;

    protected $fillable = ['name'];
    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        self::extends(ParentModel::class, 'parentable');
    }
}

class ChildBModel extends Model
{
    use HasParent;

    protected $with = ['parentModel'];

    public $timestamps = false;
}
