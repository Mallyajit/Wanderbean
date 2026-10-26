# Wanderbean — trips brewed to your budget

Pick a destination, travel dates and a total budget. Wanderbean asks a few questions about how you travel, then plans every day of the trip without going over that budget. You see the plan as a calendar. Open a day for its timeline, open a stop for its details, and see everything pinned on a map. The Upgrades tab shows what a little more money would buy.

It works for almost any tourist destination using free, public data with no API keys. Jaipur, Kyoto and Lisbon also have bundled guides, so they work offline too.

Built with PHP 8 on the server and vanilla JavaScript (ES modules), HTML and CSS in the browser. There is no build step and no database.

## Run it

```bat
serve.bat            :: http://localhost:8000   (or: serve.bat 8080)
```

`serve.bat` turns on PHP's `curl`, `openssl` and `mbstring` extensions for that run only, if `php.ini` has them off. Without them, the app can only plan the three bundled guides.

## How the app is organised

| Screen | Route | What it shows |
|---|---|---|
| Landing | `#/` | A short explanation and one search card: destination (with autocomplete), dates and budget |
| Preferences | `#/prefs/1–6` | One question per screen: travellers, pace, interests, food, stay style, getting around. Every step can be skipped. |
| Brewing | `#/brewing` | The planner running, with progress steps |
| Calendar | `#/trip` | The trip as a month grid. Each trip day shows its weather, theme, stops and cost. |
| Day | `#/trip/day/:n` | That day's timeline, with travel legs between stops and a mini map |
| Stop details | (pop-up sheet) | Overview, getting there, reviews, nearby food and upgrades, plus **Open on map** and directions |
| Map | `#/trip/map[/:n]` | OpenStreetMap with numbered stops and road routes from OSRM, filterable by day |
| Stay | `#/trip/stay` | The chosen place to stay and why, other stays you can swap to, and city passes |
| Budget | `#/trip/budget` | Total spent, where the money goes, and spend per day |
| Upgrades | `#/trip/extras` | +10 / +15 / +25 % budget boosts, experiences, and ways to save |

The header always shows the information that applies everywhere: progress through the questions, then the trip and a live budget meter.

Client code is in `assets/js/`:
- `main.js` — router
- `store.js` — saved state and the live budget totals
- `header.js`
- `views/*.js` — one file per screen
- `art.js` — the illustrations
- `ui.js` — pop-up sheet and toasts
- `api.js` — calls to the server

## Open data used (from public-apis/public-apis)

| Service | Used for |
|---|---|
| **Wikivoyage** (MediaWiki API) | Sights, activities, markets, places to eat and stays, with coordinates, hours, prices and price tiers. Large cities are read district by district. |
| **Wikipedia** (GeoSearch, page views, page images) | Ranking sights by popularity, adding notable sights the guide leaves out, and photos |
| **Nominatim** and **Photon** (OpenStreetMap) | Finding the destination, autocomplete, and locating guide listings that only give a street address |
| **OSRM** | Road travel times between stops and the route lines on the map |
| **OpenStreetMap tiles** + **Leaflet** | The maps |
| **Open-Meteo** | Weather forecast, sunrise and sunset. Rainy days get the most indoor sights; viewpoints are timed to sunset. |
| **Frankfurter** (ECB) / **ExchangeRate-API** | Converting local prices into your currency |
| **Overpass** (OpenStreetMap) | Last-resort source of places when no Wikivoyage guide exists |

### Optional API keys

Set these as environment variables, or in `api/config.local.php` (gitignored):
- `GOOGLE_PLACES_API_KEY` — star ratings and written reviews
- `GEOAPIFY_API_KEY` or `OPENCAGE_API_KEY` — an alternative for finding the destination
- `ORS_API_KEY` — use OpenRouteService instead of OSRM for travel times

Places are looked up in this order: Google Places → bundled guide → Wikivoyage + Wikipedia → OpenStreetMap.

## Server code (`api/services/`)

| File | What it does |
|---|---|
| `TripPlanner.php` | Runs the whole planning process, start to finish |
| `GuideSource.php`, `WikivoyageService.php`, `WikipediaService.php` | Turn the open data into one list of places. Includes parsing price text such as "Adult 40,000 dong" or "USD12-25" and opening hours such as "Tu-Su 8-11:30, 13:30-16:00". |
| `ItineraryBuilder.php` | Builds each day's timetable: when stops open, golden-hour viewpoints, meals, markets, free time, and filling gaps with spare sights |
| `TransitPlanner.php` | Picks how to get between stops (walk, metro, bus, shared ride or taxi) by fare and time |
| `BudgetEngine.php` | Tracks spending, makes the cheaper swaps needed to fit the budget, and builds the add-ons and budget-boost tiers |
| `Geo.php` | Distance maths, grouping sights into days, and ordering each day's route |

Endpoints:
- `POST api/plan_trip.php` — plan a trip
- `GET api/suggest.php?q=` — destination autocomplete
- `GET api/route.php?c=lng,lat;…` — route line for the map

Prices and opening hours are estimates. Always check them before you go.
