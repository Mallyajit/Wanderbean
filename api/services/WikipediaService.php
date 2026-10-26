<?php
declare(strict_types=1);

/**
 * Wikipedia (keyless): GeoSearch for notable places around a point, plus
 * 30-day pageviews as a popularity signal, short descriptions and lead images.
 */
final class WikipediaService
{
    private const API = 'https://en.wikipedia.org/w/api.php';

    /** Descriptions that mark a page as something a visitor would go and see. */
    private const SIGHT = '/\b(museum|temple|palace|park|lake|church|pagoda|monument|fort|fortress|market|cathedral|bridge|tower|square|plaza|gate|mausoleum|garden|zoo|beach|opera|theat(re|er)|citadel|shrine|mosque|castle|landmark|historic|basilica|monastery|ruins|memorial|statue|aquarium|gallery|viewpoint|waterfall|island|old town|quarter|street|stadium|synagogue|abbey|bazaar|souk|harbour|harbor|lighthouse|palais|villa|tomb|stupa|wat)\b/i';
    private const NOT_SIGHT = '/\b(university|school|college|hospital|company|station|embassy|bank|hotel|district|ward|commune|neighbou?rhood|road|highway|railway|airport|ministry|agency|office|newspaper|radio|television|politician|footballer|born|census|constituency|village|township|subdistrict|metro line|bus)\b/i';

    public function __construct(private HttpClient $http)
    {
    }

    /**
     * Sights within 10 km, most viewed first.
     *
     * @return list<array{title:string, lat:float, lng:float, description:string, image:?string, views:int}>
     */
    public function nearby(float $lat, float $lng): array
    {
        $url = self::API . '?action=query&format=json&formatversion=2&generator=geosearch'
            . '&ggscoord=' . rawurlencode(sprintf('%.5f|%.5f', $lat, $lng)) . '&ggsradius=10000&ggslimit=80'
            . '&prop=coordinates|pageimages|description&piprop=thumbnail&pithumbsize=960&pilimit=80&colimit=80';
        $r = $this->http->json('Wikipedia geosearch', $url, ['ttl' => 86400 * 7, 'timeout' => 10]);
        $pages = [];
        foreach ($r['query']['pages'] ?? [] as $p) {
            $desc = (string)($p['description'] ?? '');
            $text = $p['title'] . ' ' . $desc;
            if (!isset($p['coordinates'][0]) || !preg_match(self::SIGHT, $text) || preg_match(self::NOT_SIGHT, $desc)) {
                continue;
            }
            $pages[$p['title']] = [
                'title' => (string)$p['title'],
                'lat' => (float)$p['coordinates'][0]['lat'],
                'lng' => (float)$p['coordinates'][0]['lon'],
                'description' => $desc,
                'image' => $p['thumbnail']['source'] ?? null,
                'views' => 0,
            ];
        }
        foreach ($this->details(array_keys($pages)) as $title => $d) {
            if (isset($pages[$title])) {
                $pages[$title]['views'] = $d['views'];
                $pages[$title]['image'] ??= $d['image'];
            }
        }
        $list = array_values($pages);
        usort($list, fn($a, $b) => $b['views'] <=> $a['views']);
        return $list;
    }

    /**
     * Pageviews (30 days), description and image for known article titles.
     *
     * @param list<string> $titles
     * @return array<string, array{views:int, description:string, image:?string}>
     */
    public function details(array $titles): array
    {
        $out = [];
        $requests = [];
        foreach (array_chunk(array_values(array_unique(array_filter($titles))), 40) as $i => $chunk) {
            $requests["c$i"] = [
                'service' => 'Wikipedia pageviews',
                'url' => self::API . '?action=query&format=json&formatversion=2&redirects=1&prop=pageviews|pageimages|description'
                    . '&pvipdays=30&piprop=thumbnail&pithumbsize=960&pilimit=50&titles=' . rawurlencode(implode('|', $chunk)),
                'ttl' => 86400 * 3,
            ];
        }
        foreach ($this->http->multi($requests) as $r) {
            // Map redirected/normalised titles back to what the caller asked for.
            $alias = [];
            foreach (array_merge($r['query']['normalized'] ?? [], $r['query']['redirects'] ?? []) as $n) {
                $alias[$n['to']][] = $n['from'];
            }
            foreach ($r['query']['pages'] ?? [] as $p) {
                if (!empty($p['missing'])) {
                    continue;
                }
                $row = [
                    'views' => (int)array_sum(array_filter((array)($p['pageviews'] ?? []), 'is_numeric')),
                    'description' => (string)($p['description'] ?? ''),
                    'image' => $p['thumbnail']['source'] ?? null,
                ];
                $out[$p['title']] = $row;
                foreach ($alias[$p['title']] ?? [] as $from) {
                    $out[$from] = $row;
                    foreach ($alias[$from] ?? [] as $from2) {
                        $out[$from2] = $row;
                    }
                }
            }
        }
        return $out;
    }
}
