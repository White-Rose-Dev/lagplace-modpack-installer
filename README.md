# LagPlace Modpack Installer

Part of [LagPlace](https://lagplace.xyz), a small Minecraft hosting service that runs on a customized
[Pterodactyl](https://pterodactyl.io) panel. This is the code that lets a user pick a modpack inside
the panel and get a complete, ready-to-start Minecraft server on their own game server.

This repo only has the modpack installer. The rest of the panel is private. The folders follow the
Pterodactyl panel layout, so you can see where each file lives.

## What it does

1. The user searches a provider and picks a pack and a version.
2. The panel makes a backup of the server, wipes the files and installs the right loader
   (Forge, NeoForge, Fabric, Quilt) and Java version.
3. It tells the game server (Wings) to download the pack's files, in small batches, and checks sizes.
4. The server is left stopped so the user can start it when ready.

Providers working today: **Modrinth** and **Feed The Beast**.
**CurseForge is not in this repo yet.** It is the reason for this repo: we want to add it through the
official API.

## Rules the code follows

- Downloads only from an allow-list of hosts. Nothing else is fetched, and redirects are re-checked.
- Every path from a pack is normalised. Absolute paths and `..` are rejected.
- Caps on number of files and total size.
- Never installs without a fresh backup first.
- For CurseForge: only files whose author allows third-party distribution, no workarounds for the
  rest (they get listed with a link to download them manually), API key kept on the server only,
  requests cached and rate limited, identifying User-Agent.

## Layout

- `app/Services/Lagplace/Modpacks/` providers, planner, installer, state, path/URL checks
- `app/Jobs/Lagplace/ModpackInstallJob.php` the queued install
- `app/Http/Controllers/Api/Client/Servers/ModpacksController.php` the API the panel UI calls
- `.blueprint/dev/` the [Blueprint](https://blueprint.zip) extension (React UI)
- `resources/scripts/components/lagplace/ResourceCard.tsx` the shared card, grid and field styles (also used by our Mods and Plugins pages)
- `scripts/lagplace/test-*.php` plain PHP tests, run with `php scripts/lagplace/test-ftb.php`
  (they need a Pterodactyl checkout, set `PANEL_DIR` to its path)

Some comments and test names are in Portuguese, since that is the author's language.
