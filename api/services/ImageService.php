<?php
declare(strict_types=1);

/**
 * Natural, unfiltered destination photography from the Wikipedia REST summary
 * endpoint (keyless). Used for curated datasets and OSM places carrying a
 * wikipedia tag; Google-sourced places use Place Photos instead.
 */
final class ImageService
{
    public function __construct(private HttpClient $http)
    {
    }

    /**
     * @param list<string> $titles English Wikipedia article titles
     * @return array<string, array{image:string, credit:string, page:string}>
     */
    public function lookup(array $titles): array
    {
        $requests = [];
        foreach (array_unique(array_filter($titles)) as $title) {
            $slug = rawurlencode(str_replace(' ', '_', $title));
            $requests[$title] = [
                'service' => 'Wikipedia summary',
                'url' => "https://en.wikipedia.org/api/rest_v1/page/summary/{$slug}",
                'ttl' => 86400 * 30,
                'timeout' => 6,
            ];
        }
        if (!$requests) {
            return [];
        }

        $out = [];
        foreach ($this->http->multi($requests) as $title => $r) {
            $thumb = $r['thumbnail']['source'] ?? null;
            $original = $r['originalimage'] ?? null;
            if (!$thumb && !$original) {
                continue;
            }
            // Prefer a 960px rendition: big enough for a full-bleed card, small enough to load fast.
            $image = $original && ($original['width'] ?? 0) <= 1400
                ? $original['source']
                : (preg_replace('#/(\d+)px-#', '/960px-', (string)$thumb) ?: $thumb);
            $out[$title] = [
                'image' => (string)$image,
                'credit' => 'WIKIMEDIA COMMONS',
                'page' => (string)($r['content_urls']['desktop']['page'] ?? ''),
            ];
        }
        return $out;
    }
}
