# Mucronix Registration

A free user plugin for Joomla 6 that makes registration simpler and adds country and region to it.

- Hides the Name field on the registration form and stores the username as the name.
- Adds a country list and a region list that depends on it. Regions are loaded when a country is chosen.
- Stores the country, the region and the registration IP address in the user profile.
- Shows country and region names in the language of the site: English, Ukrainian or Russian.

Plugin element: `plg_user_mucronixregistration` (group `user`, element `mucronixregistration`).

## Requirements

- Joomla 6.0 or later
- PHP 8.3 or later
- MySQL or MariaDB. The countries and regions table is created with MySQL SQL only, so PostgreSQL is not supported.

## Installation

1. Install the ZIP in **System → Install → Extensions**.
2. A new plugin is disabled. After installation a message links to its settings. You can also find **User - Mucronix Registration** in **System → Manage → Plugins**.
3. Choose the settings and enable the plugin.

## What it does

**Name = username.** When "Hide Name Field" is on, the registration form has no Name field, and the name is always set to the username on the server, even if the request has a different name. This works without JavaScript. In the profile and in the administrator area the name can be changed as usual. Saving a profile does not overwrite it.

**Country and region.** The region list shows the regions of the chosen country. A region is required only when the country has regions and the region list is shown for it. Otherwise the region field is hidden with its label and is not required.

**Checks on the server.** The country must exist and be one of the countries shown. The region must exist and belong to that country. A value changed in the browser or sent by hand is refused with a clear message.

There is one exception. A country or region saved for a user stays valid while it is not changed, even if the administrator no longer shows it. Saving the profile does not erase a saved region.

**Without JavaScript.** The region list is built on the server for the country already in the form. If a country with regions is sent without a region, the form comes back with a message and the region list of that country.

**Profile.** The profile page on the site shows country and region names, not codes. In the administrator area (**Users → Manage → Edit**) country and region can always be changed. The country code and the registration IP address are shown there read-only.

**Registration IP address.** It is recorded once, at registration on the site, from `REMOTE_ADDR`. Proxy headers are not read, because anyone can send them. Editing the profile later does not change the address, and an administrator creating a user does not record one.

**Deleting a user** removes that user's country, region and IP address.

## Settings

| Setting | Default | What it does |
|---|---|---|
| Hide Name Field | Yes | Registration form without the Name field; the username is stored as the name. |
| Country Required | Yes | A country is required at registration and when the profile is edited on the site. In the administrator area the fields are never required. |
| Store IP Address | Yes | The visitor's IP address is recorded once at registration. |
| Country and Region in the Site Profile | View and edit | **Hide:** not shown on the profile page or the edit page. **View only:** shown on the profile page; only an administrator can change them, and a value sent by hand is ignored. **View and edit:** the user can change them. |
| Countries to Show | All | All countries, or only the ones selected. If none are selected, all are shown. |
| Show Regions | For all that have them | The region list for every country that has regions, or only for the selected countries. If none are selected, no region list is shown. |
| Countries at the Top | empty | Two-letter country codes separated by commas, in the order wanted, for example `UA, PL`. The other countries follow alphabetically in the language of the site. Unknown codes stop the settings from saving. |

## Stored data

Values go to the core table `#__user_profiles`:

| Key | Value |
|---|---|
| `mucronixregistration.country` | ISO 3166-1 alpha-2 country code, e.g. `UA` |
| `mucronixregistration.region` | ISO 3166-2 region code, e.g. `UA-53` |
| `mucronixregistration.reg_ip` | IP address at registration |

The plugin stores codes, not row numbers, because codes do not change when the countries and regions data is updated.

## Uninstalling

Uninstalling the plugin removes:

- the countries and regions table `#__mucronixregistration_regions`;
- the rows `mucronixregistration.*` in `#__user_profiles`, which are the country, region and registration IP address of **all** users.

The settings page says the same.

## Countries and regions data

The data comes from **iso-codes 4.20.1** (Debian, https://salsa.debian.org/iso-codes-team/iso-codes), which is licensed under **LGPL-2.1-or-later**. The full licence text is in `LICENSE-data.txt` in the package.

- **Countries:** ISO 3166-1, 249 countries. The common name is used where iso-codes has one, for example "Bolivia" instead of "Bolivia, Plurinational State of".
- **Regions:** ISO 3166-2, first level only (oblasts, states, provinces). There are 3560 regions in 200 countries.
- **Languages:** names are stored in the table in English, Ukrainian and Russian, not in language files. An empty translation falls back to the English name, which iso-codes gives in the local form.
  - Ukrainian: all countries and regions;
  - Russian: all countries and about a third of the regions.

The data is changed from iso-codes in these ways. All changes are made by the build script, not by hand:

- **30 subdivisions are left out** because they are countries of ISO 3166-1 themselves, or parts of one:
  - CN-HK, CN-MO, CN-TW;
  - FI-01 (Åland);
  - the French overseas territories;
  - NL-AW, NL-CW, NL-SX, NL-BQ1–3;
  - NO-21, NO-22 (Svalbard and Jan Mayen);
  - US-AS, US-GU, US-MP, US-PR, US-UM, US-VI.
- **Notes in square brackets are dropped:** "Wales [Cymru GB-CYM]" becomes "Wales".
- **The type is added where two regions of one country have the same name:** "Chiayi (city)" and "Chiayi (county)". This applies to 9 pairs.
- **10 wrong translations are corrected,** where a region had the name of its neighbour. For example, the Ukrainian name of RU-KYA is "Красноярський край".

`tools/build-regions.php` builds `sql/install.mysql.utf8.sql` from the iso-codes files in `tools/source/`. To move to a new version of iso-codes:

1. Download the six files from the new tag.
2. Update the version and the checksums in the script.
3. Run it and read its messages.

The script stops when a correction is no longer needed or a code has disappeared.

Joomla does not run the install SQL when a plugin is updated. Data changes for sites that already have the plugin go into a new file in `sql/updates/mysql/`.

## Known limitations

- **Profile page display.** The profile page shows names through `HTMLHelper::register()`, the way the core profile plugin does. Joomla 6 marks this as deprecated and plans to remove it in Joomla 7. It stays here for now because the profile page of Joomla 6 looks only there; it will need another way when Joomla 7 is out.
- **Translations.** Translations come from iso-codes. Russian region names are incomplete, so about two thirds of the regions are shown in their local Latin form on Russian sites.
- **Without JavaScript.** The region list cannot appear before the form is sent once.
- **IP address behind a proxy.** The registration IP address is `REMOTE_ADDR`. Behind a proxy that does not pass the visitor's address to it, this is the address of the proxy.

## Building

    powershell -ExecutionPolicy Bypass -File build.ps1

This gives `build/plg_user_mucronixregistration-<version>.zip` and the unpacked files in `build/plg_user_mucronixregistration/`. The archive is reproducible: the same files give the same archive byte for byte.

The build stops when:

- the version differs between the manifest, `media/joomla.asset.json` and `update/plg_user_mucronixregistration.xml`;
- the manifest and the file list disagree;
- a PHP file has no licence header or `_JEXEC` line;
- the install SQL is not the generated one;
- a banned word is found;
- the checks in `tests/` fail.

The checks in `tests/` load the Joomla 6 libraries of a local site, read only, and use SQLite in memory, never a site database:

    php tests/language-check.php [--joomla=<site>] [--plugin=<folder>]
    php tests/logic-check.php [--joomla=<site>] [--plugin=<folder>]

## Licence

GNU General Public License version 2 or later, see `LICENSE.txt`. The countries and regions data is under LGPL-2.1-or-later, see `LICENSE-data.txt`.

(C) 2026 Mucronix, https://mucronix.com
