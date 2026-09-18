<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class I18nTranslationController extends Controller
{
    public function translate()
    {
        $locales = ['zh-cn', 'en', 'km'];
        $data = [];

        foreach ($locales as $locale) {
            $cacheKey = 'i18n_translate_' . $locale;
            $translated = Cache::remember($cacheKey, 3600, function () use ($locale) {
                return $this->loadTranslationFiles($locale);
            });
            $data[$locale] = $translated;
        }

        // Return exact format expected by frontend: { code: '1', data: { 'zh-cn': {...}, 'en': {...}, 'km': {...} } }
        return response()->json([
            'code' => '1',
            'data' => $data
        ]);
    }

    protected function loadTranslationFiles($locale)
    {
        $langPath = resource_path('lang/' . $locale);

        if (!is_dir($langPath)) {
            return [];
        }

        $messages = [];

        // Load all JSON translation files recursively
        $files = File::allFiles($langPath);
        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                try {
                    $content = File::get($file->getPathname());
                    $translations = json_decode($content, true);
                    if (is_array($translations)) {
                        $messages = array_merge($messages, $translations);
                    }
                } catch (\Exception $e) {
                    // Skip invalid JSON files
                    \Log::warning("Failed to load translation file: {$file->getPathname()}", ['error' => $e->getMessage()]);
                }
            }
        }

        return $messages;
    }

    public function getAllLocales()
    {
        $langPath = resource_path('lang');
        $locales = [];

        if (is_dir($langPath)) {
            foreach (scandir($langPath) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                if (is_dir($langPath . '/' . $dir)) {
                    $locales[$dir] = $dir;
                }
            }
        }

        return response()->json(['code' => '1', 'data' => $locales]);
    }
}