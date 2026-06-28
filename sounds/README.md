# CAP-Alert sound library

Alert audio shipped as **8 kHz mono ulaw** (`.ulaw`), matching ASL3 / Asterisk conventions.

## Install location

`install.sh` copies these files to `/usr/share/cap-alert/sounds/`.

Runtime search order (see `lib/Config.php`):

1. `/usr/share/cap-alert/sounds` — bundled alert library
2. `/var/lib/cap-alert/new_sounds` — TTS-generated ulaw
3. `/usr/share/asterisk/sounds/en/wx` — generic weather words (Pi temp)
4. `/usr/share/asterisk/sounds/en/digits` — spoken numbers
5. `/usr/share/asterisk/sounds/en/silence` — pause files
6. `/usr/share/asterisk/sounds/en/rpt` — AllStar node sounds

Only `.ulaw` files are used. GSM and other formats are not loaded.

## Regenerating from legacy GSM

If you have the original GSM pack:

```bash
scripts/convert-sounds.sh /path/to/legacy/sounds
```

Requires `sox`. Output is written to `sounds/*.ulaw`.

## Naming

Filenames use underscores: `tornado_warning.ulaw`, `severe_thunderstorm_warning.ulaw`.

Lookup aliases (e.g. `strong click` → `boop`) are handled in `lib/SoundLibrary.php`:

| Spoken / event name | File alias |
|---------------------|------------|
| strong click, star dull | boop |
| updated weather information | updated_weather_info |
| nws, national-weather-service | weather_service |
| burn-ban | burn_ban |
| special-weather-statement | special_weather_statement |
| tornado | tornado_warning |
| earthquake-warning | earthquake_warning |
| fire-warning | fire_warning |
| currently-throttled | throttling-has-occurred |

Event names from NWS (e.g. `Tornado Warning`) are normalized to filenames like `tornado_warning.ulaw`.
