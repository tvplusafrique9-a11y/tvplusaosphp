<?php

use App\Enums\PoleRubriqueEnum;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\EditorialWorkflowController;
use App\Models\VeilleSubject;
use App\Rules\UrlPublique;
use App\Services\ScraperService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/internal/readiness', [DeploymentController::class, 'readiness'])->name('deployment.readiness');
Route::get('/internal/veille', [DeploymentController::class, 'veille'])->name('deployment.veille');
use Illuminate\Support\Str;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// 🔒 API v1 — AUTHENTIFIÉE + LIMITÉE EN DÉBIT.
// Ces routes étaient accessibles sans aucun contrôle : scrapper une URL
// arbitraire (SSRF) et écrire en base étaient possibles depuis l'extérieur.
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::prefix('veille')->group(function () {
        Route::post('/scrape-url', function (Request $request, ScraperService $scraper) {
            $validated = $request->validate([
                'url' => ['required', 'url', new UrlPublique],
                'source_id' => 'nullable|exists:source_veille,id',
                'save' => 'nullable|boolean',
            ]);

            if (! empty($validated['save'])) {
                Gate::authorize('create', VeilleSubject::class);
            }

            try {
                $type = $scraper->detectType($validated['url']);
                $items = $scraper->scrapeUrl($validated['url'], $type);
                $count = is_array($items) ? count($items) : 0;

                if ($count === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Aucun article détecté sur cette URL (vérifiez le lien ou réessayez).',
                        'url' => $validated['url'],
                        'type' => $type,
                        'items' => [],
                        'count' => 0,
                    ]);
                }

                $savedIds = [];
                if (! empty($validated['save'])) {
                    $slugFn = function (string $base, ?int $excludeId = null) {
                        $slug = Str::slug($base) ?: ('sujet-'.Str::random(6));
                        $cand = $slug;
                        $i = 1;
                        while (VeilleSubject::where('slug', $cand)
                            ->when($excludeId, fn ($q) => $q->where('id', '<>', $excludeId))
                            ->exists()) {
                            $cand = $slug.'-'.$i++;
                        }

                        return $cand;
                    };
                    foreach ($items as $item) {
                        if (empty($item['url']) && empty($item['title'])) {
                            continue;
                        }
                        $title = trim($item['title'] ?? 'Sans titre');
                        $itemUrl = trim((string) ($item['url'] ?? ''));
                        $existing = $itemUrl !== ''
                            ? VeilleSubject::where('url', $itemUrl)->first()
                            : null;

                        if (! $existing) {
                            $existingQuery = VeilleSubject::query()->where('title', $title);
                            if (! empty($validated['source_id'])) {
                                $existingQuery->where('source_id', $validated['source_id']);
                            } else {
                                $existingQuery->whereNull('source_id');
                            }
                            if (! empty($item['published_at'])) {
                                try {
                                    $existingQuery->whereDate('published_at', Carbon::parse($item['published_at'])->toDateString());
                                } catch (Throwable) {
                                    $existingQuery->whereNull('published_at');
                                }
                            } else {
                                $existingQuery->whereNull('published_at');
                            }
                            $existing = $existingQuery->first();
                        }

                        $scrapedValues = [
                            'summary' => $item['summary'] ?? null,
                            'content' => $item['content'] ?? null,
                            'image_url' => $item['image_url'] ?? null,
                            'category' => PoleRubriqueEnum::tryFromLoose($item['category'] ?? null)?->value,
                            'published_at' => $item['published_at'] ?? null,
                        ];

                        if ($existing) {
                            $updates = ['scraped_at' => $item['scraped_at'] ?? now()];
                            foreach ($scrapedValues as $field => $value) {
                                if (blank($existing->{$field}) && ! blank($value)) {
                                    $updates[$field] = $value;
                                }
                            }
                            if (blank($existing->source_id) && ! empty($validated['source_id'])) {
                                $updates['source_id'] = $validated['source_id'];
                            }
                            if (blank($existing->url) && $itemUrl !== '') {
                                $updates['url'] = $itemUrl;
                            }
                            if (blank($existing->slug)) {
                                $updates['slug'] = $slugFn($existing->title ?: $title, $existing->id);
                            }
                            $existing->update($updates);
                            $subject = $existing;
                        } else {
                            $subject = VeilleSubject::create([
                                'source_id' => $validated['source_id'] ?? null,
                                'status' => 'draft',
                                'title' => $title,
                                'slug' => $slugFn($title),
                                'url' => $itemUrl !== '' ? $itemUrl : null,
                                ...$scrapedValues,
                                'priority' => 5,
                                'scraped_at' => $item['scraped_at'] ?? now(),
                            ]);
                        }
                        $savedIds[] = $subject->id;
                    }
                }

                return response()->json([
                    'success' => true,
                    'message' => $count.' article(s) détecté(s)'.($savedIds ? ' et enregistré(s).' : '.'),
                    'url' => $validated['url'],
                    'type' => $type,
                    'items' => array_slice($items, 0, 20),
                    'count' => $count,
                    'saved_ids' => $savedIds,
                ]);
            } catch (Throwable $e) {
                Log::error('[API Veille scrape-url] '.$e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur temporaire pendant la collecte. Réessayez plus tard.',
                    'url' => $validated['url'],
                    'items' => [],
                    'count' => 0,
                ], 502);
            }
        });

        Route::post('/ia-action', function (Request $request) {
            $request->validate([
                'action' => 'required|string|in:angles,titles,reformulate',
                'content' => 'required|string',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Cette route API historique n’exécute pas de génération IA. Utilisez le workflow de remix validé.',
            ], 501);
        });

        Route::post('/promote/{subject}', [EditorialWorkflowController::class, 'storeEventFromVeilleApi'])
            ->whereNumber('subject');
    });

    Route::prefix('intervenants')->group(function () {
        // Placeholder endpoints: explicitly report that these workflows are not
        // implemented rather than returning a misleading success response.
        Route::post('/scan', function () {
            return response()->json([
                'success' => false,
                'message' => 'Cette route API historique n’est pas implémentée. Utilisez le scanner QR du module RH.',
            ], 501);
        });

        Route::get('/liste', function () {
            return response()->json([
                'success' => false,
                'data' => [],
                'message' => 'Cette route API historique n’est pas implémentée.',
            ], 501);
        });
    });

    Route::prefix('conducteurs')->group(function () {
        // Placeholder endpoint: do not claim synchronization until implemented.
        Route::post('/sync', function () {
            return response()->json([
                'success' => false,
                'message' => 'Cette route API historique n’est pas implémentée.',
            ], 501);
        });
    });
});
