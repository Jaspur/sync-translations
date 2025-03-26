<?php

declare(strict_types=1);

namespace Jaspur\SyncTranslations\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type Translations array<string, string>
 * @phpstan-type Meta array{_meta?: array{todo: list<non-empty-string>}}
 */
final class SyncTranslationsCommand extends Command
{
    protected $signature = 'sync:translations
        {--locales= : comma-separated list of locales (e.g., nl,en,de)}
        {--path= : comma-separated paths to scan (default: app,resources,routes)}
        {--mark-todo : mark new untranslated keys into _meta.todo}
        {--dry-run : display changes only, no file writes}
        {--interactive : ask before adding each new key}';

    protected $description = 'Sync translation strings from the codebase into lang/{locale}.json';

    /**
     * @return int<0,255>
     */
    public function handle(): int
    {
        $locales = $this->parseOptionList('locales', $this->detectLocales());
        $paths = $this->parseOptionList('path', ['app', 'resources', 'routes']);
        $markTodo = (bool) $this->option('mark-todo');
        $dryRun = (bool) $this->option('dry-run');
        $interactive = (bool) $this->option('interactive');

        $keys = $this->extractTranslationKeys($paths);

        if (count($keys) === 0) {
            $this->warn('No translation keys found.');

            return self::SUCCESS;
        }

        foreach ($locales as $locale) {
            $path = resource_path("lang/{$locale}.json");

            /** @var array<string, string|array> $translations */
            $translations = File::exists($path)
                ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR)
                : [];

            foreach ($keys as $key) {
                if (array_key_exists($key, $translations)) {
                    continue;
                }

                if ($interactive) {
                    $action = $this->choice(
                        "Nieuwe string gevonden: \"{$key}\" — toevoegen?",
                        ['ja', 'nee', 'bewerken'],
                        0
                    );

                    if ($action === 'nee') {
                        continue;
                    }

                    if ($action === 'bewerken') {
                        $translation = $this->ask("Geef vertaling voor: \"{$key}\"", $key);
                        if (is_string($translation)) {
                            $translations[$key] = $translation;
                        }

                        continue;
                    }
                }

                $translations[$key] = $this->translateKey($key, $locale);
            }

            ksort($translations);

            /** @var array<string, string|array> $finalJson */
            $finalJson = $translations;

            if ($markTodo) {
                $todoList = $this->buildTodoList($translations, $keys, $locale);

                if ($todoList !== []) {
                    $finalJson['_meta'] = ['todo' => $todoList];
                } elseif (isset($finalJson['_meta'])) {
                    unset($finalJson['_meta']);
                }
            }

            $encoded = json_encode($finalJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new RuntimeException('JSON encoding failed.');
            }

            if ($dryRun) {
                $this->info("[Dry Run] lang/{$locale}.json:");
                $this->line($encoded);
            } else {
                File::put($path, $encoded);
                $this->info("✅ Bijgewerkt: lang/{$locale}.json");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<non-empty-string>  $paths
     * @return list<non-empty-string>
     */
    private function extractTranslationKeys(array $paths): array
    {
        $pattern = '/(?:__|@lang)\(\s*[\'"](?<key>[^\'"]+)[\'"]\s*[),]/';
        $keys = [];

        foreach ($paths as $path) {
            foreach (File::allFiles(base_path($path)) as $file) {
                if (!Str::endsWith($file->getFilename(), ['.php', '.blade.php', '.vue'])) {
                    continue;
                }

                $contents = File::get($file->getRealPath());
                if (!is_string($contents)) {
                    continue;
                }

                $noComments = preg_replace('!//.*|/\*.*?\*/!s', '', $contents);
                if (!is_string($noComments)) {
                    continue;
                }

                if (preg_match_all($pattern, $noComments, $matches)) {
                    foreach ($matches['key'] as $match) {
                        if ($match !== '') {
                            $keys[] = $match;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  non-empty-string  $option
     * @param  list<non-empty-string>  $default
     * @return list<non-empty-string>
     */
    private function parseOptionList(string $option, array $default): array
    {
        $raw = $this->option($option);

        if (is_string($raw)) {
            /** @var list<non-empty-string> $list */
            $list = array_filter(array_map('trim', explode(',', $raw)));

            return $list;
        }

        return $default;
    }

    /**
     * @return list<non-empty-string>
     */
    private function detectLocales(): array
    {
        $files = File::files(resource_path('lang'));
        $locales = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $locale = $file->getBasename('.json');
            if ($locale !== '') {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique($locales));
    }

    /**
     * @param  non-empty-string  $key
     * @param  non-empty-string  $locale
     */
    private function translateKey(string $key, string $locale): string
    {
        if ($locale === 'en') {
            return $key;
        }

        return '[TODO] ' . $key;
    }

    /**
     * @param  array<string, string>  $translations
     * @param  list<non-empty-string>  $newKeys
     * @param  non-empty-string  $locale
     * @return list<non-empty-string>
     */
    private function buildTodoList(array $translations, array $newKeys, string $locale): array
    {
        /** @var list<non-empty-string> $existingTodo */
        $existingTodo = [];
        if (isset($translations['_meta']['todo']) && is_array($translations['_meta']['todo'])) {
            foreach ($translations['_meta']['todo'] as $item) {
                if (is_string($item) && $item !== '') {
                    $existingTodo[] = $item;
                }
            }
        }

        /** @var list<non-empty-string> $newTodo */
        $newTodo = $existingTodo;

        foreach ($newKeys as $key) {
            $translation = $translations[$key] ?? '';

            if (!isset($translations[$key]) || $translation === '' || str_starts_with((string) $translation, '[TODO]')) {
                $newTodo[] = $key;
            }

            if (isset($translations[$key]) && $translation !== '' && !str_starts_with((string) $translation, '[TODO]')) {
                $newTodo = array_filter($newTodo, static fn (string $item): bool => $item !== $key);
            }
        }

        return array_values(array_unique($newTodo));
    }
}
