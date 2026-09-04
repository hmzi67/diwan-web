# private-storage

**Nothing in here is web accessible, and nothing in here is deployed by CI.**

```
private-storage/
├── releases/   installer binaries (.exe/.dmg/.apk) — uploaded MANUALLY, once
└── logs/       application + PHP error logs
```

## Why installers are not in git and not in the pipeline

1. Git is not a binary store — a 200 MB `.exe` per release bloats the repo forever.
2. Every deploy would re-upload them over FTP, turning a 10-second deploy into a
   30-minute one.
3. `deploy.yml` never targets this directory, so a bad deploy cannot delete a
   release your customers are actively downloading.

## Uploading a new release

1. FTP the file to `~/app/private-storage/releases/` (production) using your
   FTP client — **not** into `public_html`.
2. `chmod 640` the file; the web server user must be able to read it, nobody
   else needs to.
3. Record it in the database so `download.php` can find it:

```sql
INSERT INTO releases (platform, version, filename, storage_path, checksum_sha256, size_bytes, is_active, released_at)
VALUES ('windows', '1.4.0', 'Diwan-Setup-1.4.0.exe', 'windows/Diwan-Setup-1.4.0.exe',
        '<sha256sum output>', 84213760, 1, NOW());

UPDATE releases SET is_active = 0
 WHERE platform = 'windows' AND version <> '1.4.0';
```

`storage_path` is relative to `private-storage/releases/`. `DownloadService`
resolves it with `realpath()` and rejects anything that escapes this directory.
