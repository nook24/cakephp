<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource;

use Cake\Collection\Collection;
use Cake\Datasource\Exception\MissingPropertyException;
use Cake\ORM\Entity;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use InvalidArgumentException;

/**
 * An entity represents a single result row from a repository. It exposes the
 * methods for retrieving and storing fields associated in this row.
 */
trait EntityTrait
{
    /**
     * Holds all fields and their values for this entity.
     *
     * @var array<string, mixed>
     */
    protected array $fields = [];

    /**
     * Holds all fields that have been changed and their original values for this entity.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Holds all fields that have been initially set on instantiation, or after marking as clean
     *
     * @var array<string>
     */
    protected array $originalFields = [];

    /**
     * List of field names that should **not** be included in JSON or Array
     * representations of this Entity.
     *
     * @var array<string>
     */
    protected array $hidden = [];

    /**
     * List of computed or virtual fields that **should** be included in JSON or array
     * representations of this Entity. If a field is present in both _hidden and _virtual
     * the field will **not** be in the array/JSON versions of the entity.
     *
     * @var array<string>
     */
    protected array $virtual = [];

    /**
     * Holds a list of the fields that were modified or added after this object
     * was originally created.
     *
     * @var array<string, bool>
     */
    protected array $dirty = [];

    /**
     * Holds a cached list of getters/setters per class
     *
     * @var array<string, array<string, array<string, string>>>
     */
    protected static array $accessors = [];

    /**
     * Indicates whether this entity is yet to be persisted.
     * Entities default to assuming they are new. You can use Table::persisted()
     * to set the new flag on an entity based on records in the database.
     *
     * @var bool
     */
    protected bool $new = true;

    /**
     * List of errors per field as stored in this object.
     *
     * @var array<string, mixed>
     */
    protected array $errors = [];

    /**
     * List of invalid fields and their data for errors upon validation/patching.
     *
     * @var array<string, mixed>
     */
    protected array $invalid = [];

    /**
     * Map of fields in this entity that can be safely mass assigned.
     * Each field name points to a boolean indicating its status.
     * An empty array means no fields can be patched into the entity.
     *
     * The special field '\*' can also be mapped, meaning that any other field
     * not defined in the map will take its value. For example, `'*' => true`
     * means that any field not defined in the map will be patchable for mass
     * assignment by default.
     *
     * @var array<string, bool>
     */
    protected array $patchable = ['*' => true];

    /**
     * The alias of the repository this entity came from
     *
     * @var string
     */
    protected string $registryAlias = '';

    /**
     * Storing the current visitation status while recursing through entities getting errors.
     *
     * @var bool
     */
    protected bool $hasBeenVisited = false;

    /**
     * Whether the presence of a field is checked when accessing a property.
     *
     * If enabled an exception will be thrown when trying to access a non-existent property.
     *
     * @var bool
     */
    protected bool $requireFieldPresence = false;

    /**
     * Magic getter to access fields that have been set in this entity
     *
     * @param string $field Name of the field to access
     * @return mixed
     */
    public function &__get(string $field): mixed
    {
        return $this->getRequiredOrFail($field, $this->requireFieldPresence);
    }

    /**
     * Magic setter to add or edit a field in this entity
     *
     * @param string $field The name of the field to set
     * @param mixed $value The value to set to the field
     * @return void
     */
    public function __set(string $field, mixed $value): void
    {
        $this->set($field, $value);
    }

    /**
     * Returns whether this entity contains a field named $field
     * and is not set to null.
     *
     * @param string $field The field to check.
     * @return bool
     */
    public function __isset(string $field): bool
    {
        return $this->has($field) && $this->get($field) !== null;
    }

    /**
     * Removes a field from this entity
     *
     * @param string $field The field to unset
     * @return void
     */
    public function __unset(string $field): void
    {
        $this->unset($field);
    }

    /**
     * Sets a single field inside this entity.
     *
     * ### Example:
     *
     * ```
     * $entity->set('name', 'Andrew');
     * ```
     *
     * Some times it is handy to bypass setter functions in this entity when assigning
     * fields. You can achieve this by disabling the `setter` option using the
     * `$options` parameter:
     *
     * ```
     * $entity->set('name', 'Andrew', ['setter' => false]);
     * ```
     *
     * You can use the `asOriginal` option to set the given field as original, if it wasn't
     * present when the entity was instantiated.
     *
     * ```
     * $entity = new Entity(['name' => 'andrew', 'id' => 1]);
     *
     * $entity->set('phone_number', '555-0134');
     * print_r($entity->getOriginalFields()) // prints ['name', 'id']
     *
     * $entity->set('phone_number', '555-0134', ['asOriginal' => true]);
     * print_r($entity->getOriginalFields()) // prints ['name', 'id', 'phone_number']
     * ```
     *
     * @param string $field The name of field to set.
     * @param mixed $value The value to set to the field.
     * @param array<string, mixed> $options Options to be used for setting the field. Allowed option
     * keys are `setter`, `guard` and `asOriginal`
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function set(string $field, mixed $value, array $options = []): static
    {
        $options += ['guard' => false];

        return $this->patch([$field => $value], $options);
    }

    /**
     * Patch (mass-assign) multiple fields to this entity.
     *
     * ### Example:
     *
     * ```
     * $entity->patch(['name' => 'andrew', 'id' => 1]);
     * echo $entity->name // prints andrew
     * echo $entity->id // prints 1
     * ```
     *
     * Some times it is handy to bypass setter functions in this entity when assigning
     * fields. You can achieve this by disabling the `setter` option using the
     * `$options` parameter:
     *
     * ```
     * $entity->patch(['name' => 'Andrew', 'id' => 1], ['setter' => false]);
     * ```
     *
     * Mass assignment should be treated carefully when accepting user input, by default
     * entities will guard all fields when fields are assigned in bulk. You can disable
     * the guarding for a single set call with the `guard` option:
     *
     * ```
     * $entity->patch(['name' => 'Andrew', 'id' => 1], ['guard' => false]);
     * ```
     *
     * You can use the `asOriginal` option to set the given field as original, if it wasn't
     * present when the entity was instantiated.
     *
     * ```
     * $entity = new Entity(['name' => 'andrew', 'id' => 1]);
     *
     * $entity->patch(['phone_number' => '555-0134']);
     * print_r($entity->getOriginalFields()) // prints ['name', 'id']
     *
     * $entity->patch(['phone_number' => '555-0134'], ['asOriginal' => true]);
     * print_r($entity->getOriginalFields()) // prints ['name', 'id', 'phone_number']
     * ```
     *
     * @param array<string, mixed> $values Map of fields with their respective values.
     * @param array<string, mixed> $options Options to be used for setting the field. Allowed option
     * keys are `setter`, `guard` and `asOriginal`
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function patch(array $values, array $options = []): static
    {
        $options += ['setter' => true, 'guard' => true, 'asOriginal' => false];

        if ($options['asOriginal'] === true) {
            $this->setOriginalField(array_keys($values));
        }

        foreach ($values as $name => $value) {
            $name = (string)$name;
            if ($name === '') {
                throw new InvalidArgumentException('Cannot set an empty field');
            }

            if ($options['guard'] === true && !$this->isPatchable($name)) {
                continue;
            }

            if ($options['asOriginal'] || $this->isModified($name, $value)) {
                $this->setDirty($name, true);
            } else {
                continue;
            }

            if ($options['setter']) {
                $setter = static::accessor($name, 'set');
                if ($setter) {
                    $value = $this->{$setter}($value);
                }
            }

            if (
                $this->isOriginalField($name) &&
                !array_key_exists($name, $this->original) &&
                array_key_exists($name, $this->fields) &&
                $value !== $this->fields[$name]
            ) {
                $this->original[$name] = $this->fields[$name];
            }

            $this->fields[$name] = $value;
        }

        return $this;
    }

    /**
     * Check if the provided value is same as existing value for a field.
     *
     * This check is used to determine if a field should be set as dirty or not.
     * It will return `false` for scalar values and objects which haven't changed.
     * For arrays `true` will be returned always because the original/updated list
     * could contain references to the same objects, even though those objects
     * may have changed internally.
     *
     * @param string $field The field to check.
     * @return bool
     */
    protected function isModified(string $field, mixed $value): bool
    {
        if (!array_key_exists($field, $this->fields)) {
            return true;
        }

        $existing = $this->fields[$field] ?? null;

        if (($value === null || is_scalar($value)) && $existing === $value) {
            return false;
        }

        if (
            is_object($value)
            && !($value instanceof EntityInterface)
            && $existing == $value
        ) {
            return false;
        }

        return true;
    }

    /**
     * Returns the value of a field by name
     *
     * @param string $field the name of the field to retrieve
     * @return mixed
     * @throws \InvalidArgumentException if an empty field name is passed
     */
    public function &get(string $field): mixed
    {
        return $this->getRequiredOrFail($field, false);
    }

    /**
     * Get field with option for requireFieldPresence.
     *
     * Note: The returned value might be null if the field is set to null.
     *
     * @param string $field the name of the field to retrieve
     * @param bool $requireFieldPresence Whether to throw an exception if the field is not present
     * @return mixed
     * @throws \InvalidArgumentException if an empty field name is passed
     * @throws \Cake\Datasource\Exception\MissingPropertyException If property does not exist and $requireFieldPresence
     */
    public function &getRequiredOrFail(string $field, bool $requireFieldPresence = true): mixed
    {
        if ($field === '') {
            throw new InvalidArgumentException('Cannot get an empty field');
        }

        $value = null;
        $fieldIsPresent = false;
        if (array_key_exists($field, $this->fields)) {
            $fieldIsPresent = true;
            $value = &$this->fields[$field];
        }

        $method = static::accessor($field, 'get');
        if ($method) {
            // Must be variable before returning: Only variable references should be returned by reference.
            $result = $this->{$method}($value);

            return $result;
        }

        if (!$fieldIsPresent && $requireFieldPresence) {
            throw new MissingPropertyException([
                'property' => $field,
                'entity' => $this::class,
            ]);
        }

        return $value;
    }

    /**
     * Enable/disable field presence check when accessing a property.
     *
     * If enabled an exception will be thrown when trying to access a non-existent property.
     *
     * @param bool $value `true` to enable, `false` to disable.
     */
    public function requireFieldPresence(bool $value = true): void
    {
        $this->requireFieldPresence = $value;
    }

    /**
     * Returns whether a field has an original value
     *
     * @param string $field
     * @return bool
     */
    public function hasOriginal(string $field): bool
    {
        return array_key_exists($field, $this->original);
    }

    /**
     * Returns the value of an original field by name
     *
     * @param string $field the name of the field for which original value is retrieved.
     * @param bool $allowFallback whether to allow falling back to the current field value if no original exists
     * @return mixed
     * @throws \InvalidArgumentException if an empty field name is passed.
     */
    public function getOriginal(string $field, bool $allowFallback = true): mixed
    {
        if ($field === '') {
            throw new InvalidArgumentException('Cannot get an empty field');
        }
        if (array_key_exists($field, $this->original)) {
            return $this->original[$field];
        }

        if (!$allowFallback) {
            throw new InvalidArgumentException(sprintf('Cannot retrieve original value for field `%s`', $field));
        }

        return $this->get($field);
    }

    /**
     * Gets all original values of the entity.
     *
     * @return array
     */
    public function getOriginalValues(): array
    {
        $originals = $this->original;
        $originalKeys = array_keys($originals);
        foreach ($this->fields as $key => $value) {
            if (
                !in_array($key, $originalKeys, true) &&
                $this->isOriginalField($key)
            ) {
                $originals[$key] = $value;
            }
        }

        return $originals;
    }

    /**
     * Returns whether this entity contains a field named $field.
     *
     * It will return `true` even for fields set to `null`.
     *
     * ### Example:
     *
     * ```
     * $entity = new Entity(['id' => 1, 'name' => null]);
     * $entity->has('id'); // true
     * $entity->has('name'); // true
     * $entity->has('last_name'); // false
     * ```
     *
     * You can check multiple fields by passing an array:
     *
     * ```
     * $entity->has(['name', 'last_name']);
     * ```
     *
     * When checking multiple fields all fields must have a value (even `null`)
     * present for the method to return `true`.
     *
     * @param array<string>|string $field The field or fields to check.
     * @return bool
     */
    public function has(array|string $field): bool
    {
        return array_all((array)$field, function ($prop) {
            return !(!array_key_exists($prop, $this->fields) && !static::accessor($prop, 'get'));
        });
    }

    /**
     * Checks that a field has a value.
     *
     * This method will return true for
     *
     * - Non-empty strings
     * - Non-empty arrays
     * - Any object
     * - Integer, even `0`
     * - Float, even 0.0
     *
     * and false in all other cases.
     *
     * @param string $field The field to check.
     * @return bool
     */
    public function hasValue(string $field): bool
    {
        $value = $this->get($field);
        if (
            $value === null ||
            (
                $value === [] ||
                $value === ''
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Removes a field or list of fields from this entity
     *
     * ### Examples:
     *
     * ```
     * $entity->unset('name');
     * $entity->unset(['name', 'last_name']);
     * ```
     *
     * @param array<string>|string $field The field to unset.
     * @return $this
     */
    public function unset(array|string $field): static
    {
        $field = (array)$field;
        foreach ($field as $p) {
            unset($this->fields[$p], $this->dirty[$p]);
        }

        return $this;
    }

    /**
     * Sets hidden fields.
     *
     * @param array<string> $fields An array of fields to hide from array exports.
     * @param bool $merge Merge the new fields with the existing. By default false.
     * @return $this
     */
    public function setHidden(array $fields, bool $merge = false): static
    {
        if ($merge === false) {
            $this->hidden = $fields;

            return $this;
        }

        $fields = array_merge($this->hidden, $fields);
        $this->hidden = array_unique($fields);

        return $this;
    }

    /**
     * Gets the hidden fields.
     *
     * @return array<string>
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Sets the virtual fields on this entity.
     *
     * @param array<string> $fields An array of fields to treat as virtual.
     * @param bool $merge Merge the new fields with the existing. By default false.
     * @return $this
     */
    public function setVirtual(array $fields, bool $merge = false): static
    {
        if ($merge === false) {
            $this->virtual = $fields;

            return $this;
        }

        $fields = array_merge($this->virtual, $fields);
        $this->virtual = array_unique($fields);

        return $this;
    }

    /**
     * Gets the virtual fields on this entity.
     *
     * @return array<string>
     */
    public function getVirtual(): array
    {
        return $this->virtual;
    }

    /**
     * Gets the list of visible fields.
     *
     * The list of visible fields is all standard fields
     * plus virtual fields minus hidden fields.
     *
     * @return array<string> A list of fields that are 'visible' in all
     *     representations.
     */
    public function getVisible(): array
    {
        $fields = array_keys($this->fields);
        $fields = array_merge($fields, $this->virtual);

        return array_diff($fields, $this->hidden);
    }

    /**
     * Returns an array with all the fields that have been set
     * to this entity
     *
     * This method will recursively transform entities assigned to fields
     * into arrays as well.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->getVisible() as $field) {
            $value = $this->get($field);
            if (is_array($value)) {
                $result[$field] = [];
                foreach ($value as $k => $entity) {
                    if ($entity instanceof EntityInterface) {
                        $result[$field][$k] = $entity->toArray();
                    } else {
                        $result[$field][$k] = $entity;
                    }
                }
            } elseif ($value instanceof EntityInterface) {
                $result[$field] = $value->toArray();
            } else {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    /**
     * Returns the fields that will be serialized as JSON
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->extract($this->getVisible());
    }

    /**
     * Implements isset($entity);
     *
     * @param string $offset The offset to check.
     * @return bool Success
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset($offset);
    }

    /**
     * Implements $entity[$offset];
     *
     * @param string $offset The offset to get.
     * @return mixed
     */
    public function &offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Implements $entity[$offset] = $value;
     *
     * @param string $offset The offset to set.
     * @param mixed $value The value to set.
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    /**
     * Implements unset($result[$offset]);
     *
     * @param string $offset The offset to remove.
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->unset($offset);
    }

    /**
     * Fetch accessor method name
     * Accessor methods (available or not) are cached in $accessors
     *
     * @param string $property the field name to derive getter name from
     * @param string $type the accessor type ('get' or 'set')
     * @return string method name or empty string (no method available)
     */
    protected static function accessor(string $property, string $type): string
    {
        $class = static::class;

        if (isset(static::$accessors[$class][$type][$property])) {
            return static::$accessors[$class][$type][$property];
        }

        if (!empty(static::$accessors[$class])) {
            return static::$accessors[$class][$type][$property] = '';
        }

        if (static::class === Entity::class) {
            return '';
        }

        foreach (get_class_methods($class) as $method) {
            $prefix = substr($method, 1, 3);
            if (!str_starts_with($method, '_') || ($prefix !== 'get' && $prefix !== 'set')) {
                continue;
            }
            $field = lcfirst(substr($method, 4));
            $snakeField = Inflector::underscore($field);
            $titleField = ucfirst($field);
            static::$accessors[$class][$prefix][$snakeField] = $method;
            static::$accessors[$class][$prefix][$field] = $method;
            static::$accessors[$class][$prefix][$titleField] = $method;
        }

        if (!isset(static::$accessors[$class][$type][$property])) {
            static::$accessors[$class][$type][$property] = '';
        }

        return static::$accessors[$class][$type][$property];
    }

    /**
     * Returns an array with the requested fields
     * stored in this entity, indexed by field name
     *
     * @param array<string> $fields list of fields to be returned
     * @param bool $onlyDirty Return the requested field only if it is dirty
     * @return array<string, mixed>
     */
    public function extract(array $fields, bool $onlyDirty = false): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!$onlyDirty || $this->isDirty($field)) {
                $result[$field] = $this->has($field) ? $this->get($field) : null;
            }
        }

        return $result;
    }

    /**
     * Returns an array with the requested original fields
     * stored in this entity, indexed by field name, if they exist.
     *
     * Fields that are unchanged from their original value will be included in the
     * return of this method.
     *
     * @param array<string> $fields List of fields to be returned
     * @return array<string, mixed>
     */
    public function extractOriginal(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if ($this->hasOriginal($field)) {
                $result[$field] = $this->getOriginal($field);
            } elseif ($this->isOriginalField($field)) {
                $result[$field] = $this->get($field);
            }
        }

        return $result;
    }

    /**
     * Returns an array with only the original fields
     * stored in this entity, indexed by field name, if they exist.
     *
     * This method will only return fields that have been modified since
     * the entity was built. Unchanged fields will be omitted.
     *
     * @param array<string> $fields List of fields to be returned
     * @return array<string, mixed>
     */
    public function extractOriginalChanged(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!$this->hasOriginal($field)) {
                continue;
            }

            $original = $this->getOriginal($field);
            if ($original !== $this->get($field)) {
                $result[$field] = $original;
            }
        }

        return $result;
    }

    /**
     * Returns whether a field is an original one
     *
     * @return bool
     */
    public function isOriginalField(string $name): bool
    {
        return in_array($name, $this->originalFields, true);
    }

    /**
     * Returns an array of original fields.
     * Original fields are those that the entity was initialized with.
     *
     * @return array<string>
     */
    public function getOriginalFields(): array
    {
        return $this->originalFields;
    }

    /**
     * Sets the given field or a list of fields to as original.
     * Normally there is no need to call this method manually.
     *
     * @param array<string>|string $field the name of a field or a list of fields to set as original
     * @param bool $merge
     * @return $this
     */
    protected function setOriginalField(string|array $field, bool $merge = true): static
    {
        if (!$merge) {
            $this->originalFields = (array)$field;

            return $this;
        }

        $fields = (array)$field;
        foreach ($fields as $field) {
            $field = (string)$field;
            if (!$this->isOriginalField($field)) {
                $this->originalFields[] = $field;
            }
        }

        return $this;
    }

    /**
     * Sets the dirty status of a single field.
     *
     * @param string $field the field to set or check status for
     * @param bool $isDirty true means the field was changed, false means
     * it was not changed. Defaults to true.
     * @return $this
     */
    public function setDirty(string $field, bool $isDirty = true): static
    {
        if ($isDirty === false) {
            $this->setOriginalField($field);

            unset($this->dirty[$field], $this->original[$field]);

            return $this;
        }

        $this->dirty[$field] = true;
        unset($this->errors[$field], $this->invalid[$field]);

        return $this;
    }

    /**
     * Checks if the entity is dirty or if a single field of it is dirty.
     *
     * @param string|null $field The field to check the status for. Null for the whole entity.
     * @return bool Whether the field was changed or not
     */
    public function isDirty(?string $field = null): bool
    {
        return $field === null
            ? $this->dirty !== []
            : isset($this->dirty[$field]);
    }

    /**
     * Gets the dirty fields.
     *
     * @return array<string>
     */
    public function getDirty(): array
    {
        return array_keys($this->dirty);
    }

    /**
     * Sets the entire entity as clean, which means that it will appear as
     * no fields being modified or added at all. This is an useful call
     * for an initial object hydration
     *
     * @return void
     */
    public function clean(): void
    {
        $this->dirty = [];
        $this->errors = [];
        $this->invalid = [];
        $this->original = [];
        $this->setOriginalField(array_keys($this->fields), false);
    }

    /**
     * Set the status of this entity.
     *
     * Using `true` means that the entity has not been persisted in the database,
     * `false` that it already is.
     *
     * @param bool $new Indicate whether this entity has been persisted.
     * @return $this
     */
    public function setNew(bool $new): static
    {
        if ($new) {
            foreach ($this->fields as $k => $p) {
                $this->dirty[$k] = true;
            }
        }

        $this->new = $new;

        return $this;
    }

    /**
     * Returns whether this entity has already been persisted.
     *
     * @return bool Whether the entity has been persisted.
     */
    public function isNew(): bool
    {
        return $this->new;
    }

    /**
     * Returns whether this entity has errors.
     *
     * @param bool $includeNested true will check nested entities for hasErrors()
     * @return bool
     */
    public function hasErrors(bool $includeNested = true): bool
    {
        if ($this->hasBeenVisited) {
            // While recursing through entities, each entity should only be visited once. See https://github.com/cakephp/cakephp/issues/17318
            return false;
        }

        if (Hash::filter($this->errors)) {
            return true;
        }

        if ($includeNested === false) {
            return false;
        }

        $this->hasBeenVisited = true;
        try {
            foreach ($this->fields as $field) {
                if ($this->readHasErrors($field)) {
                    return true;
                }
            }
        } finally {
            $this->hasBeenVisited = false;
        }

        return false;
    }

    /**
     * Returns all validation errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        if ($this->hasBeenVisited) {
            // While recursing through entities, each entity should only be visited once. See https://github.com/cakephp/cakephp/issues/17318
            return [];
        }

        $diff = array_diff_key($this->fields, $this->errors);

        $this->hasBeenVisited = true;
        try {
            $errors = $this->errors + new Collection($diff)
                ->filter(function ($value) {
                    return is_array($value) || $value instanceof EntityInterface;
                })
                ->map(function ($value) {
                    return $this->readError($value);
                })
                ->filter()
                ->toArray();
        } finally {
            $this->hasBeenVisited = false;
        }

        return $errors;
    }

    /**
     * Returns validation errors of a field
     *
     * @param string $field Field name to get the errors from
     * @return array
     */
    public function getError(string $field): array
    {
        return $this->errors[$field] ?? $this->nestedErrors($field);
    }

    /**
     * Sets error messages to the entity
     *
     * ## Example
     *
     * ```
     * // Sets the error messages for multiple fields at once
     * $entity->setErrors(['salary' => ['message'], 'name' => ['another message']]);
     * ```
     *
     * @param array $errors The array of errors to set.
     * @param bool $overwrite Whether to overwrite pre-existing errors for $fields
     * @return $this
     */
    public function setErrors(array $errors, bool $overwrite = false): static
    {
        if ($overwrite) {
            foreach ($errors as $f => $error) {
                $this->errors[$f] = (array)$error;
            }

            return $this;
        }

        foreach ($errors as $f => $error) {
            $this->errors += [$f => []];

            // String messages are appended to the list,
            // while more complex error structures need their
            // keys preserved for nested validator.
            if (is_string($error)) {
                $this->errors[$f][] = $error;
            } else {
                foreach ($error as $k => $v) {
                    $this->errors[$f][$k] = $v;
                }
            }
        }

        return $this;
    }

    /**
     * Sets errors for a single field
     *
     * ### Example
     *
     * ```
     * // Sets the error messages for a single field
     * $entity->setError('salary', ['must be numeric', 'must be a positive number']);
     * ```
     *
     * @param string $field The field to get errors for, or the array of errors to set.
     * @param array|string $errors The errors to be set for $field
     * @param bool $overwrite Whether to overwrite pre-existing errors for $field
     * @return $this
     */
    public function setError(string $field, array|string $errors, bool $overwrite = false): static
    {
        if (is_string($errors)) {
            $errors = [$errors];
        }

        return $this->setErrors([$field => $errors], $overwrite);
    }

    /**
     * Auxiliary method for getting errors in nested entities
     *
     * @param string $field the field in this entity to check for errors
     * @return array Errors in nested entity if any
     */
    protected function nestedErrors(string $field): array
    {
        // Only one path element, check for nested entity with error.
        if (!str_contains($field, '.')) {
            if (!$this->has($field)) {
                return [];
            }

            $entity = $this->get($field);
            if ($entity instanceof EntityInterface || is_iterable($entity)) {
                return $this->readError($entity);
            }

            return [];
        }
        // Try reading the errors data with field as a simple path
        $error = Hash::get($this->errors, $field);
        if ($error !== null) {
            return $error;
        }
        $path = explode('.', $field);

        // Traverse down the related entities/arrays for
        // the relevant entity.
        $entity = $this;
        $len = count($path);
        while ($len) {
            /** @var string $part */
            $part = array_shift($path);
            $len = count($path);
            $val = null;
            if ($entity instanceof EntityInterface) {
                if ($entity->has($part)) {
                    $val = $entity->get($part);
                }
            } elseif (is_array($entity)) {
                $val = $entity[$part] ?? false;
            }

            if (
                is_iterable($val) ||
                $val instanceof EntityInterface
            ) {
                $entity = $val;
            } else {
                $path[] = $part;
                break;
            }
        }
        if (count($path) <= 1) {
            return $this->readError($entity, array_pop($path));
        }

        return [];
    }

    /**
     * Reads if there are errors for one or many objects.
     *
     * @param \Cake\Datasource\EntityInterface|array $object The object to read errors from.
     * @return bool
     */
    protected function readHasErrors(mixed $object): bool
    {
        if ($object instanceof EntityInterface && $object->hasErrors()) {
            return true;
        }

        if (is_array($object)) {
            foreach ($object as $value) {
                if ($this->readHasErrors($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Read the error(s) from one or many objects.
     *
     * @param \Cake\Datasource\EntityInterface|iterable $object The object to read errors from.
     * @param string|null $path The field name for errors.
     * @return array
     */
    protected function readError(EntityInterface|iterable $object, ?string $path = null): array
    {
        if ($path !== null && $object instanceof EntityInterface) {
            return $object->getError($path);
        }
        if ($object instanceof EntityInterface) {
            return $object->getErrors();
        }

        $array = array_map(function ($val) {
            if ($val instanceof EntityInterface) {
                return $val->getErrors();
            }
        }, (array)$object);

        return array_filter($array);
    }

    /**
     * Get a list of invalid fields and their data for errors upon validation/patching
     *
     * @return array<string, mixed>
     */
    public function getInvalid(): array
    {
        return $this->invalid;
    }

    /**
     * Get a single value of an invalid field. Returns null if not set.
     *
     * @param string $field The name of the field.
     * @return mixed|null
     */
    public function getInvalidField(string $field): mixed
    {
        return $this->invalid[$field] ?? null;
    }

    /**
     * Set fields as invalid and not patchable into the entity.
     *
     * This is useful for batch operations when one needs to get the original value for an error message after patching.
     * This value could not be patched into the entity and is simply copied into the _invalid property for debugging
     * purposes or to be able to log it away.
     *
     * @param array<string, mixed> $fields The values to set.
     * @param bool $overwrite Whether to overwrite pre-existing values for $field.
     * @return $this
     */
    public function setInvalid(array $fields, bool $overwrite = false): static
    {
        foreach ($fields as $field => $value) {
            if ($overwrite) {
                $this->invalid[$field] = $value;
                continue;
            }
            $this->invalid += [$field => $value];
        }

        return $this;
    }

    /**
     * Sets a field as invalid and not patchable into the entity.
     *
     * @param string $field The value to set.
     * @param mixed $value The invalid value to be set for $field.
     * @return $this
     */
    public function setInvalidField(string $field, mixed $value): static
    {
        $this->invalid[$field] = $value;

        return $this;
    }

    /**
     * Stores whether a field value can be changed or set in this entity.
     * The special field `*` can also be marked as patchable or protected, meaning
     * that any other field specified before will take its value. For example
     * `$entity->setPatchable('*', true)` means that any field not specified already
     * will be patchable by default.
     *
     * You can also call this method with an array of fields, in which case they
     * will each take the accessibility value specified in the second argument.
     *
     * ### Example:
     *
     * ```
     * $entity->setPatchable('id', true); // Mark id as not protected
     * $entity->setPatchable('author_id', false); // Mark author_id as protected
     * $entity->setPatchable(['id', 'user_id'], true); // Mark both fields as patchable
     * $entity->setPatchable('*', false); // Mark all fields as protected
     * ```
     *
     * @param array<string>|string $field Single or list of fields to change its accessibility
     * @param bool $set True marks the field as patchable, false will
     * mark it as protected.
     * @return $this
     */
    public function setPatchable(array|string $field, bool $set): static
    {
        if ($field === '*') {
            $this->patchable = array_map(fn($p) => $set, $this->patchable);
            $this->patchable['*'] = $set;

            return $this;
        }

        foreach ((array)$field as $prop) {
            $this->patchable[$prop] = $set;
        }

        return $this;
    }

    /**
     * Returns the raw patchable configuration for this entity.
     * The `*` wildcard refers to all fields.
     *
     * @return array<bool>
     */
    public function getPatchable(): array
    {
        return $this->patchable;
    }

    /**
     * Checks if a field can be patched
     *
     * ### Example:
     *
     * ```
     * $entity->isPatchable('id'); // Returns whether it can be set or not
     * ```
     *
     * @param string $field Field name to check
     * @return bool
     */
    public function isPatchable(string $field): bool
    {
        $value = $this->patchable[$field] ?? null;

        return ($value === null && !empty($this->patchable['*'])) || $value;
    }

    /**
     * Returns the alias of the repository from which this entity came from.
     *
     * @return string
     */
    public function getSource(): string
    {
        return $this->registryAlias;
    }

    /**
     * Sets the source alias
     *
     * @param string $alias the alias of the repository
     * @return $this
     */
    public function setSource(string $alias): static
    {
        $this->registryAlias = $alias;

        return $this;
    }

    /**
     * Returns an array that can be used to describe the internal state of this
     * object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $fields = $this->fields;
        foreach ($this->virtual as $field) {
            $fields[$field] = $this->$field;
        }

        return $fields + [
            '[new]' => $this->isNew(),
            '[patchable]' => $this->patchable,
            '[dirty]' => $this->dirty,
            '[original]' => $this->original,
            '[originalFields]' => $this->originalFields,
            '[virtual]' => $this->virtual,
            '[hasErrors]' => $this->hasErrors(),
            '[errors]' => $this->errors,
            '[invalid]' => $this->invalid,
            '[repository]' => $this->registryAlias,
        ];
    }
}
