<?php

namespace Spatie\MediaLibrary\Conversion;

use BadMethodCallException;
use Spatie\Image\Manipulations;
use Spatie\MediaLibrary\ImageGenerators\ImageGenerator;
use Illuminate\Support\Collection;

/** @mixin \Spatie\Image\Manipulations */
class Conversion
{
    /** @var string */
    protected $name = '';

    /** @var int */
    protected $extractVideoFrameAtSecond = 0;

    /** @var \Spatie\Image\Manipulations */
    protected $manipulations;

    /** @var Illuminate\Support\Collection */
    protected $generatorParams;
    
    /** @var array */
    protected $performOnCollections = [];

    /** @var bool */
    protected $performOnQueue = true;

    /** @var bool */
    protected $keepOriginalImageFormat = false;

    public function __construct(string $name)
    {
        $this->name = $name;

        $this->manipulations = (new Manipulations())
            ->optimize(config('medialibrary.image_optimizers'))
            ->format('jpg');

        $this->generatorParams = collect();
        $this->getImageGenerators()
            ->map(function(string $className) {
                $generator = app($className);

                $this->generatorParams[$className] = collect($generator->getParams());
            });
    }

    public static function create(string $name)
    {
        return new static($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function keepOriginalImageFormat(): Conversion
    {
        $this->keepOriginalImageFormat = true;

        return $this;
    }

    public function shouldKeepOriginalImageFormat(): Bool
    {
        return $this->keepOriginalImageFormat;
    }

    public function getManipulations(): Manipulations
    {
        return $this->manipulations;
    }

    public function removeManipulation(string $manipulationName)
    {
        $this->manipulations->removeManipulation($manipulationName);

        return $this;
    }

    public function __call($name, $arguments)
    {
        // Manipulation call
        if (method_exists($this->manipulations, $name)) {
            $this->manipulations->$name(...$arguments);
            return $this;
        }

        // Get ImageGenerator param call
        if(preg_match('/^get(.*)$/', $name, $getParamMatch)) {
            $paramName = strtolower($getParamMatch[1]);
            $validGeneratorParams = $this->generatorParams
                ->filter(function($generatorParams) use ($paramName) {
                    return $generatorParams->has($paramName);
                });
            
            if(count($validGeneratorParams)) {
                $firstValidGeneratorParams = $validGeneratorParams->first();
                if($firstValidGeneratorParams) {
                    return $firstValidGeneratorParams[$paramName];
                }
            }

            throw new BadMethodCallException("Generator Parameter `{$paramName}` does not exist");
        }

        // Set ImageGenerator param call
        $paramName = strtolower($name);
        $settableGeneratorParamGroups = $this->generatorParams
            ->filter(function($generatorParams) use ($paramName) {
                return $generatorParams->has($paramName);
            });
        
        if(count($settableGeneratorParamGroups)) {
            $value = $arguments[0];
            $settableGeneratorParamGroups
                ->map(function($settableGeneratorParams) use ($paramName, $value) {
                    $settableGeneratorParams[$paramName] = $value;
                });
            
            return $this;
        }

        throw new BadMethodCallException("Manipulation or Generator Parameter `{$name}` does not exist");
    }

    /**
     * Set the manipulations for this conversion.
     *
     * @param \Spatie\Image\Manipulations|closure $manipulations
     *
     * @return $this
     */
    public function setManipulations($manipulations)
    {
        if ($manipulations instanceof Manipulations) {
            $this->manipulations = $this->manipulations->mergeManipulations($manipulations);
        }

        if (is_callable($manipulations)) {
            $manipulations($this->manipulations);
        }

        return $this;
    }

    /**
     * Add the given manipulations as the first ones.
     *
     * @param \Spatie\Image\Manipulations $manipulations
     *
     * @return $this
     */
    public function addAsFirstManipulations(Manipulations $manipulations)
    {
        $manipulationSequence = $manipulations->getManipulationSequence()->toArray();

        $this->manipulations
            ->getManipulationSequence()
            ->mergeArray($manipulationSequence);

        return $this;
    }

    /**
     * Set the collection names on which this conversion must be performed.
     *
     * @param  $collectionNames
     *
     * @return $this
     */
    public function performOnCollections(...$collectionNames)
    {
        $this->performOnCollections = $collectionNames;

        return $this;
    }

    /*
     * Determine if this conversion should be performed on the given
     * collection.
     */
    public function shouldBePerformedOn(string $collectionName): bool
    {
        //if no collections were specified, perform conversion on all collections
        if (! count($this->performOnCollections)) {
            return true;
        }

        if (in_array('*', $this->performOnCollections)) {
            return true;
        }

        return in_array($collectionName, $this->performOnCollections);
    }

    /**
     * Mark this conversion as one that should be queued.
     *
     * @return $this
     */
    public function queued()
    {
        $this->performOnQueue = true;

        return $this;
    }

    /**
     * Mark this conversion as one that should not be queued.
     *
     * @return $this
     */
    public function nonQueued()
    {
        $this->performOnQueue = false;

        return $this;
    }

    /**
     * Avoid optimization of the converted image.
     *
     * @return $this
     */
    public function nonOptimized()
    {
        $this->removeManipulation('optimize');

        return $this;
    }

    /*
     * Determine if the conversion should be queued.
     */
    public function shouldBeQueued(): bool
    {
        return $this->performOnQueue;
    }

    /*
     * Get the extension that the result of this conversion must have.
     */
    public function getResultExtension(string $originalFileExtension = ''): string
    {
        if ($this->shouldKeepOriginalImageFormat()) {
            return $originalFileExtension;
        }

        if ($manipulationArgument = $this->manipulations->getManipulationArgument('format')) {
            return $manipulationArgument;
        }

        return $originalFileExtension;
    }

    /**
     * Collection of all ImageGenerator drivers.
     */
    protected function getImageGenerators(): Collection
    {
        return collect(config('medialibrary.image_generators'));
    }
}
