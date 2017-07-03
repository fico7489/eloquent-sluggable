<?php namespace Cviebrock\EloquentSluggable\Services;

use Cocur\Slugify\Slugify;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Class SlugService
 *
 * @package Cviebrock\EloquentSluggable\Services
 */
class SlugService
{

    /**
     * @var \Illuminate\Database\Eloquent\Model;
     */
    protected $model;

    /**
     * Slug the current model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param bool $force
     * @return bool
     */
    public function slug(Model $model, $force = false)
    {
        $this->setModel($model);

        $attributes = [];

        foreach ($this->model->sluggable() as $attribute => $config) {
            if (is_numeric($attribute)) {
                $attribute = $config;
                $config = $this->getConfiguration();
            } else {
                $config = $this->getConfiguration($config);
            }

            $slug = $this->buildSlug($attribute, $config, $force);

            $this->model->setAttribute($attribute, $slug);

            $attributes[] = $attribute;
        }

        return $this->model->isDirty($attributes);
    }

    /**
     * Get the sluggable configuration for the current model,
     * including default values where not specified.
     *
     * @param array $overrides
     * @return array
     */
    public function getConfiguration(array $overrides = [])
    {
        static $defaultConfig = null;
        if ($defaultConfig === null) {
            $defaultConfig = app('config')->get('sluggable');
        }

        return array_merge($defaultConfig, $overrides);
    }

    /**
     * Build the slug for the given attribute of the current model.
     *
     * @param string $attribute
     * @param array $config
     * @param bool $force
     * @return null|string
     */
    public function buildSlug($attribute, array $config, $force = null)
    {
        $slug = $this->model->getAttribute($attribute);

        if ($force || $this->needsSlugging($attribute, $config)) {
            $source = $this->getSlugSource($config['source']);

            if ($source) {
                $slug = $this->generateSlug($source, $config, $attribute);
                $slug = $this->validateSlug($slug, $config, $attribute);
                $slug = $this->makeSlugUnique($slug, $config, $attribute);
            }
        }

        return $slug;
    }

    /**
     * Determines whether the model needs slugging.
     *
     * @param string $attribute
     * @param array $config
     * @return bool
     */
    protected function needsSlugging($attribute, array $config)
    {
        if (
            empty($this->model->getAttributeValue($attribute)) ||
            $config['onUpdate'] === true
        ) {
            return true;
        }

        if ($this->model->isDirty($attribute)) {
            return false;
        }

        return (!$this->model->exists);
    }

    /**
     * Get the source string for the slug.
     *
     * @param mixed $from
     * @return string
     */
    protected function getSlugSource($from)
    {
        if (is_null($from)) {
            return $this->model->__toString();
        }

        $sourceStrings = array_map(function ($key) {
            return data_get($this->model, $key);
        }, (array)$from);

        return join($sourceStrings, ' ');
    }

    /**
     * Generate a slug from the given source string.
     *
     * @param string $source
     * @param array $config
     * @param string $attribute
     * @return string
     */
    protected function generateSlug($source, array $config, $attribute)
    {
        $separator = $config['separator'];
        $method = $config['method'];
        $maxLength = $config['maxLength'];

        if ($method === null) {
            $slugEngine = $this->getSlugEngine($attribute);
            $slug = $slugEngine->slugify($source, $separator);
        } elseif (is_callable($method)) {
            $slug = call_user_func($method, $source, $separator);
        } else {
            throw new \UnexpectedValueException('Sluggable "method" for ' . get_class($this->model) . ':' . $attribute . ' is not callable nor null.');
        }

        if (is_string($slug) && $maxLength) {
            $slug = mb_substr($slug, 0, $maxLength);
        }

        return $slug;
    }

    /**
     * Return a class that has a `slugify()` method, used to convert
     * strings into slugs.
     *
     * @param string $attribute
     * @return Slugify
     */
    protected function getSlugEngine($attribute)
    {
        static $slugEngines = [];

        $key = get_class($this->model) . '.' . $attribute;

        if (!array_key_exists($key, $slugEngines)) {
            $engine = new Slugify();
            if (method_exists($this->model, 'customizeSlugEngine')) {
                $engine = $this->model->customizeSlugEngine($engine, $attribute);
            }

            $slugEngines[$key] = $engine;
        }

        return $slugEngines[$key];
    }

    /**
     * Checks that the given slug is not a reserved word.
     *
     * @param string $slug
     * @param array $config
     * @param string $attribute
     * @return string
     */
    protected function validateSlug($slug, array $config, $attribute)
    {
        $separator = $config['separator'];
        $reserved = $config['reserved'];

        if ($reserved === null) {
            return $slug;
        }

        // check for reserved names
        if ($reserved instanceof \Closure) {
            $reserved = $reserved($this->model);
        }

        if (is_array($reserved)) {
            if (in_array($slug, $reserved)) {
                return $slug . $separator . '1';
            }

            return $slug;
        }

        throw new \UnexpectedValueException('Sluggable "reserved" for ' . get_class($this->model) . ':' . $attribute . ' is not null, an array, or a closure that returns null/array.');
    }

    /**
     * Checks if the slug should be unique, and makes it so if needed.
     *
     * @param string $slug
     * @param array $config
     * @param string $attribute
     * @return string
     */
    protected function makeSlugUnique($slug, array $config, $attribute)
    {
        //if we don't need unique slug return AS IS
        if (!$config['unique']) {
            return $slug;
        }

        $separator = $config['separator'];

        $result = $this->model->newQuery()
            ->where('slug', '=', $slug)
            ->first();

        if( ! $result){
            //slug does not exists
            return $slug;
        }

        if($result->id === $this->model->id){
            //current model for updating
            return $slug;
        }

        $results = $this->model->newQuery()
            ->where('slug', 'like', $slug . $separator . '%')
            ->where('id', '<>', $this->model->id)
            ->get()
            ->pluck('slug', 'id')
            ->toArray();

        $takenNumners = [];
        foreach($results as $idTmp => $slugTmp){
            $number = str_replace($slug . $separator, '', $slugTmp);
            if(is_numeric($number)){
                $takenNumners[] = $number;
            }
        }

        for($i = 1; $i < 10000; $i++){
            if( ! in_array($i, $takenNumners, true)){
                $suffix = $i;
                break;
            }
        }

        return $slug . $separator . $suffix;
    }

    /**
     * Does this model use softDeleting?
     *
     * @return bool
     */
    protected function usesSoftDeleting()
    {
        return method_exists($this->model, 'bootSoftDeletes');
    }

    /**
     * Generate a unique slug for a given string.
     *
     * @param \Illuminate\Database\Eloquent\Model|string $model
     * @param string $attribute
     * @param string $fromString
     * @param array $config
     * @return string
     */
    public static function createSlug($model, $attribute, $fromString, array $config = null)
    {
        if (is_string($model)) {
            $model = new $model;
        }
        $instance = (new self())->setModel($model);

        if ($config === null) {
            $config = array_get($model->sluggable(), $attribute);
        } elseif (!is_array($config)) {
            throw new \UnexpectedValueException('SlugService::createSlug expects an array or null as the fourth argument; ' . gettype($config) . ' given.');
        }

        $config = $instance->getConfiguration($config);

        $slug = $instance->generateSlug($fromString, $config, $attribute);
        $slug = $instance->validateSlug($slug, $config, $attribute);
        $slug = $instance->makeSlugUnique($slug, $config, $attribute);

        return $slug;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return $this
     */
    public function setModel(Model $model)
    {
        $this->model = $model;

        return $this;
    }
}
