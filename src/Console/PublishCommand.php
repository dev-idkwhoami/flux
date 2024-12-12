<?php

declare(strict_types=1);

namespace Flux\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\search;
use function Laravel\Prompts\warning;

#[AsCommand(name: 'flux:publish')]
class PublishCommand extends Command
{
    protected $signature = 'flux:publish {components?*} {--multiple} {--group} {--all} {--list} {--force}';

    protected $description = 'Publish individual flux components.';

    protected array $fluxComponents = [];

    public function handle(): void
    {
        $this->fluxComponents = $this->detectComponents();

        if ($this->option('list')) {
            $this->table(
                ['Component'],
                $this->flattenWithDelimiter($this->fluxComponents())
                    ->map(fn(string $item) => ['Collection' => $item])->all()
            );
            return;
        } elseif ($this->option('all')) {
            $components = $this->flattenWithDelimiter($this->fluxComponents());
        } elseif (count($this->argument('components')) > 0) {
            $components = $this->flattenWithDelimiter($this->fluxComponents())
                ->filter(fn(string $component) => in_array($component,
                    array_map('strtolower', $this->argument('components'))));
        } elseif ($this->option('group')) {
            $options = $this->fluxComponents()->keys()->values();
            $componentGroupIndices = (array) search(
                label: 'Which component group would you like to publish?',
                options: fn(string $value) => $this->searchGroupOptions($options, $value),
            );
            $components = $this->flattenWithDelimiter($this->fluxComponents()->filter(fn(
                $components,
                $key
            ) => in_array($key, $options->intersectByKeys(array_flip($componentGroupIndices))->all())));
        } elseif ($this->option('multiple')) {
            $componentNames = multisearch(
                label: 'Which components would you like to publish?',
                options: fn(string $value) => $this->searchOptions($value),
            );
        } else {
            $componentNames = (array) search(
                label: 'Which component would you like to publish?',
                options: fn(string $value) => $this->searchOptions($value),
            );
        }

        if (isset($componentNames)) {
            $components = $this->flattenWithDelimiter($this->fluxComponents())
                ->filter(fn(string $component) => in_array($component, $componentNames));
        }

        (new Filesystem)->ensureDirectoryExists(resource_path('views/flux'));

        $components = $components->map(fn(string $component) => str_replace('.', '/', $component));

        foreach ($components as $component) {
            $this->publishComponent($component);
        }
    }

    protected function fluxComponents(): Collection
    {
        return collect($this->fluxComponents['free'])
            ->when($this->isFluxProInstalled(), fn(Collection $collection) => $collection->merge(
                $this->fluxComponents['pro'],
            ))
            ->sortKeys();
    }

    protected function flattenWithDelimiter(Collection $collection, string $delimiter = '.'): Collection
    {
        return $collection->flatMap(fn($array, string $key) => array_map(fn(string $value) => $key.$delimiter.$value,
            $array));
    }

    protected function detectComponents(): array
    {
        $filesystem = (new Filesystem);

        $sourceAsDirectory = __DIR__.'/../../stubs/resources/views/flux/';
        $sourceAsProDirectory = __DIR__.'/../../../flux-pro/stubs/resources/views/flux/';

        $components = [];

        $directories = $filesystem->directories($sourceAsDirectory);
        foreach ($directories as $dir) {
            if (str_ends_with($dir, 'icon')) {
                continue;
            }
            foreach ($filesystem->files($dir) as $file) {
                $filePath = $file->getRealPath();
                $componentName = str($filePath)
                    ->afterLast(DIRECTORY_SEPARATOR)
                    ->before('.blade.php')
                    ->value();
                $path = str($filePath)
                    ->after('views'.DIRECTORY_SEPARATOR.'flux'.DIRECTORY_SEPARATOR)
                    ->before(DIRECTORY_SEPARATOR.$componentName.'.blade.php')
                    ->value();
                $components[] = array_merge(explode(DIRECTORY_SEPARATOR, $path), [$componentName]);
            }
        }

        if (!$this->isFluxProInstalled()) {
            return ['free' => $this->transformPathsToNestedArray($components)];
        }

        $free = $this->transformPathsToNestedArray($components);
        $components = [];

        $proDirectories = $filesystem->directories($sourceAsProDirectory);
        foreach ($proDirectories as $dir) {
            foreach ($filesystem->files($dir) as $file) {
                $filePath = $file->getRealPath();
                $componentName = str($filePath)
                    ->afterLast(DIRECTORY_SEPARATOR)
                    ->before('.blade.php')
                    ->value();
                $path = str($filePath)
                    ->after('views'.DIRECTORY_SEPARATOR.'flux'.DIRECTORY_SEPARATOR)
                    ->before(DIRECTORY_SEPARATOR.$componentName.'.blade.php')
                    ->value();
                $components[] = array_merge(explode(DIRECTORY_SEPARATOR, $path), [$componentName]);
            }
        }

        return [
            'free' => $free,
            'pro' => $this->transformPathsToNestedArray($components),
        ];
    }

    protected function transformPathsToNestedArray(array $paths): array
    {
        $result = [];

        foreach ($paths as $path) {
            $current = &$result;

            foreach ($path as $index => $key) {
                if ($index === count($path) - 1) {
                    if (!in_array($key, $current, true)) {
                        $current[] = $key;
                    }
                } else {
                    if (!isset($current[$key]) || !is_array($current[$key])) {
                        $current[$key] = [];
                    }
                    $current = &$current[$key];
                }
            }
        }

        return $result;
    }

    protected function isFluxProInstalled(): bool
    {
        return InstalledVersions::isInstalled('livewire/flux-pro');
    }

    protected function searchGroupOptions($options, string $value): array
    {
        if ($value === '') {
            return $options->all();
        }

        return $options
            ->filter(fn(string $component) => str($component)->lower()->startsWith($value))
            ->all();
    }

    protected function searchOptions(string $value): array
    {
        if ($value === '') {
            return $this->flattenWithDelimiter($this->fluxComponents())->values()->all();
        }

        return $this->flattenWithDelimiter($this->fluxComponents())
            ->filter(fn(string $component) => str($component)->lower()->startsWith(strtolower($value)))
            ->values()
            ->all();
    }

    protected function publishComponent(string $component): void
    {
        $filesystem = (new Filesystem);

        $sourceAsFile = __DIR__.'/../../stubs/resources/views/flux/'.$component.'.blade.php';
        $sourceAsProFile = __DIR__.'/../../../flux-pro/stubs/resources/views/flux/'.$component.'.blade.php';

        $destinationAsFile = resource_path('views/flux/'.$component.'.blade.php');

        $destination = $filesystem->isFile($sourceAsFile) ? $this->publishFile($component, $sourceAsFile,
            $destinationAsFile) : null;

        if ($destination) {
            info('Published: '.$destination);
        }

        $destination = $filesystem->isFile($sourceAsProFile) ? $this->publishFile($component, $sourceAsProFile,
            $destinationAsFile) : null;

        if ($destination) {
            info('Published: '.$destination);
        }

    }

    protected function publishFile($component, $source, $destination): ?string
    {
        $filesystem = (new Filesystem);

        $filesystem->ensureDirectoryExists(pathinfo($destination, PATHINFO_DIRNAME));

        if ($filesystem->exists($destination) && !$this->option('force')) {
            warning("Skipping [{$component}]. File already exists: {$destination}");

            return null;
        }

        $filesystem->copy($source, $destination);

        return $destination;
    }
}
