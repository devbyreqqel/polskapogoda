# PolskaPogoda

Prosta strona, która pokazuje **aktualną pogodę w polskich miejscowościach, uśrednioną z kilku niezależnych źródeł**. Oprócz średniej widać dane z każdego źródła osobno oraz to, jak bardzo się ze sobą zgadzają.

Projekt napisany jest w PHP. Nie potrzebuje bazy danych, kompilacji ani żadnego klucza API.

## Źródła danych

| Źródło | Co dostarcza | Uwagi |
|---|---|---|
| [IMGW-PIB](https://danepubliczne.imgw.pl) | pomiary ze stacji synoptycznych | najbliższa stacja w promieniu 100 km, dane godzinne |
| [MET Norway](https://api.met.no) | prognoza na bieżącą godzinę | licencja CC BY 4.0 |
| [Open-Meteo](https://open-meteo.com) | bieżące warunki oraz geokodowanie miejscowości | licencja CC BY 4.0, bez klucza, do użytku niekomercyjnego |

## Jak to działa

1. Przeglądarka wywołuje `api.php?city=Olsztyn`.
2. Backend zamienia nazwę na współrzędne (geokodowanie Open-Meteo, tylko Polska).
3. Odpytuje każde źródło osobno. Awaria jednego nie psuje reszty, a strona pokazuje, ile źródeł wzięło udział („2 z 3”).
4. Liczy średnią i zwraca jeden JSON, który renderuje `index.html`.

Zasady uśredniania:

- **temperatura, temperatura odczuwalna, wilgotność, ciśnienie, prędkość wiatru**: zwykła średnia z dostępnych źródeł (odczuwalna tylko ze źródeł, które ją podają),
- **kierunek wiatru**: średnia kołowa, żeby 350° i 10° dawały 0°, a nie 180°,
- **opis pogody**: wszystkie opisy sprowadzane są do wspólnych kategorii (bezchmurnie, zachmurzenie, deszcz itd.), wygrywa kategoria najczęstsza,
- **opady**: celowo nieuśredniane, bo źródła podają je dla różnych okresów.

## Struktura

```
index.html   interfejs (HTML, CSS i JS w jednym pliku)
api.php      backend: pobieranie, cache, uśrednianie
config.php   ustawienia (czasy cache)
.htaccess    ustawienia serwera
cache/       tworzony automatycznie, pliki JSON z odpowiedziami źródeł
```

## Konfiguracja

| Opcja w `config.php` | Znaczenie | Domyślnie |
|---|---|---|
| `ttl_imgw` | ważność cache IMGW (s) | 600 |
| `ttl_openmeteo` | ważność cache Open-Meteo (s) | 600 |
| `ttl_met` | ważność cache MET Norway (s) | 600 |

Jeśli źródło chwilowo nie odpowiada, backend użyje starszych danych z cache (do 6 razy dłuższych niż TTL).

## API

`GET api.php?city=<nazwa>` zwraca JSON:

```json
{
  "city": { "name": "Olsztyn", "region": "Warmia-Masuria", "lat": 53.77, "lon": 20.48 },
  "avg": { "temp": 12.4, "feels": 11.0, "humidity": 71, "pressure": 1014,
           "wind": 3.2, "windDir": 240, "catLabel": "Pochmurno" },
  "spread": 0.8,
  "used": 3,
  "total": 3,
  "sources": [ { "id": "imgw", "name": "IMGW", "ok": true, "data": { "temp": 12.1 } } ]
}
```

Prędkość wiatru jest w m/s. Błędy mają postać `{ "error": "..." }` z kodem 400, 404 lub 502.

## Ograniczenia

- Dane IMGW pochodzą z najbliższej stacji synoptycznej, a nie z samej miejscowości. Współrzędne stacji są wpisane w `api.php` w przybliżeniu (około 65 stacji), więc dla niektórych miejscowości wybrana stacja może być inna niż oczekiwana.
- MET Norway podaje prognozę na bieżącą godzinę, a nie pomiar.
- Strona pokazuje tylko pogodę bieżącą, bez prognozy na kolejne dni.
- Dane mają charakter informacyjny i nie powinny służyć do decyzji wymagających pewności (np. ostrzeżeń meteorologicznych).

## Licencje i atrybucja

MIT

Dane: IMGW-PIB (dane publiczne), MET Norway i Open-Meteo na licencji CC BY 4.0. Atrybucja jest widoczna w stopce strony, nie usuwaj jej.
