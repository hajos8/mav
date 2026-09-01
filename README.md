# SzámosMÁVos

SzámosMÁVos is a WordPress plugin for live Hungarian train and tram positions,
current delays, and a daily Napfény InterCity summary.

It reads the public [Holavonat](https://holavonat.is) live feed, normalizes the
data, and exposes it through WordPress shortcodes and REST endpoints.

## Features

- Live train, tram, and tram-train positions on Leaflet/OpenStreetMap
- Direction markers based on vehicle heading
- Delay-based marker colors
- Search by train number, vehicle ID, or partial name
- Optional tram visibility toggle
- Automatic refresh every 60 seconds
- Daily Napfény InterCity tables separated by direction
- Persistence of Napfény services observed during the current day
- Responsive homepage, header, and footer shortcodes
- Public normalized WordPress REST API

## Delay colors

| Delay | Color |
| --- | --- |
| 0 minutes or early | Green |
| 1–4 minutes | Yellow |
| 5–14 minutes | Orange |
| 15–59 minutes | Brown |
| 60+ minutes | Red |

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Server access to the Holavonat feed
- Visitor access to OpenStreetMap and the Leaflet CDN

## Installation

1. Download or clone this repository.
2. Copy the plugin directory to wp-content/plugins/szamosmavos.
3. Open **WordPress Admin → Plugins**.
4. Activate **SzámosMÁVos**.
5. Create the pages described below.

### Local development with Docker

The included Compose file starts MariaDB and WordPress and mounts the plugin:

    docker compose up -d

Open [http://localhost:8080](http://localhost:8080), complete the WordPress
installer, and activate the plugin.

Stop the environment:

    docker compose down

Remove the local database and WordPress volumes too:

    docker compose down -v

> **Warning:** The command above permanently deletes local Docker data. The
> credentials in docker-compose.yml are for development only.

## Shortcodes

| Shortcode | Purpose |
| --- | --- |
| [szamosmavos_home] | Static homepage hero and feature cards |
| [szamosmavos_map] | Live map with vehicle search controls |
| [szamosmavos_napfeny] | Today's stored Napfény IC delay tables |
| [szamosmavos_header] | Custom site header and navigation |
| [szamosmavos_footer] | Custom footer, links, and disclaimer |

Add each shortcode through a WordPress **Shortcode block**.

## Recommended page setup

### Homepage

    [szamosmavos_home]

### Map page

Create a published page with the slug terkep:

    [szamosmavos_map]

### Napfény page

Create a published page with the slug napfeny-tablazat:

    [szamosmavos_napfeny]

The custom navigation resolves these two page slugs automatically.

### Site-wide header and footer

For a block theme:

1. Open **Appearance → Editor**.
2. Edit the **Header** template part.
3. Add [szamosmavos_header] in a Full-width Shortcode block.
4. Remove unused theme header blocks and spacers.
5. Edit the **Footer** template part.
6. Add [szamosmavos_footer] in a Full-width Shortcode block.
7. Remove unused theme footer blocks and save.

Do not repeat these shortcodes inside every page when they are already in the
template parts.

## Map behavior

The map:

- displays all currently available vehicles;
- searches exact train numbers and vehicle IDs;
- supports partial names such as NAPFÉNY and returns every match;
- can hide trams and tram-trains;
- shows route, next stop, and delay in marker popups; and
- refreshes the current view or active search every minute.

The server caches the upstream snapshot for 55 seconds.

## Napfény daily storage

The Holavonat vehicle feed is a live snapshot, not a complete daily timetable.
The plugin stores every Napfény service it observes so completed services remain
in the table for the rest of the WordPress-local day.

The table:

- separates **Szeged → Budapest-Nyugati** and
  **Budapest-Nyugati → Szeged**;
- uses scheduled departure time instead of train number;
- retains the latest observed delay after a train leaves the feed; and
- resets when the local date changes.

Services that finished before the plugin started observing the feed cannot be
reconstructed from the snapshot API.

## REST API

The plugin registers public, read-only routes under szamosmavos/v1.

### All live vehicles

    GET /wp-json/szamosmavos/v1/trains

### Search live vehicles

    GET /wp-json/szamosmavos/v1/trains?search=NAPF%C3%89NY

The search value is sanitized and limited to 300 bytes.

### Stored daily Napfény services

    GET /wp-json/szamosmavos/v1/napfeny

Example normalized vehicle:

    {
      "id": "1:example",
      "number": "716",
      "name": "716 NAPFÉNY InterCity InterCity",
      "mode": "rail",
      "lat": 47.1,
      "lon": 19.5,
      "heading": 132,
      "delay": 4,
      "from": "Budapest-Nyugati",
      "to": "Szeged",
      "departureTime": "16:42",
      "nextStop": "Cegléd",
      "date": "2026-09-01"
    }

## PHP API

Prefixed helper functions are available:

    szamosmavos_get_trains();
    szamosmavos_get_normalized_trains();
    szamosmavos_get_napfeny_intercity('2026-09-01');
    szamosmavos_search_live_trains('NAPFÉNY');
    szamosmavos_find_live_train('716');
    szamosmavos_get_stored_napfeny_trains();

Live-data functions return null when the upstream feed is unavailable.

## Data sources and attribution

- Live data: [Holavonat](https://holavonat.is)
- Map library: [Leaflet](https://leafletjs.com)
- Tiles and geographic data:
  [OpenStreetMap contributors](https://www.openstreetmap.org/copyright)

This project is not an official MÁV application. Information is provided for
guidance only; check official MÁV information before travelling.

## Troubleshooting

### The map does not appear

- Confirm the page uses [szamosmavos_map].
- Check the browser console for blocked Leaflet or OpenStreetMap requests.
- Confirm the server can reach the Holavonat JSON feed.
- Verify /wp-json/szamosmavos/v1/trains returns JSON.

### Header or footer has large gaps

Install the header and footer shortcodes in their matching template parts, not
inside page content. Set their blocks to Full width and remove empty theme
spacers or duplicate header/footer blocks.

### Navigation links return 404

Publish pages with the exact slugs terkep and napfeny-tablazat. Then open
**Settings → Permalinks** and save the selected structure again.

### Earlier Napfény services are missing

Daily storage begins when the plugin starts receiving snapshots. The live feed
cannot reconstruct services that disappeared before then.

## Repository structure

    .
    ├── docker-compose.yml
    ├── plugin/
    │   └── szamosmavos.php
    └── README.md

plugin/sample.json is optional development reference data. The plugin does not
read it at runtime and it is not required in production.
