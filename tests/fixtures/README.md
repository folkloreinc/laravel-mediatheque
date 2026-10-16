# Test fixtures

| Path | Content |
|---|---|
| `media/` | Sample media: images (JPEG, SVG, animated GIF), video (H.264, vertical HEVC), audio (WAV), a font (OTF) |
| `v1/migrations/` | The 1.x migrations, as of `v1.1`: the schema that `mediatheque:upgrade` converts |
| `v1/config.php` | The 1.x default config, as of `v1.1.3`: the shapes the compatibility layer reads |

The `v1/` fixtures are not package code. They stay as they were on `v1.1`, so the upgrade and compatibility tests run against what 1.x applications actually have.
