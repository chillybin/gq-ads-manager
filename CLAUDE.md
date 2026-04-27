# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

BK Ads Manager is a WordPress plugin for managing ad placements using a three-tier hierarchy: **Placements → Groups → Ads**. The plugin uses weighted random selection for ad rotation and tracks impressions via REST API.

## Architecture

### Core Components

**Custom Post Types** (`includes/post-types.php`)
- `gq_ad_placement`: Physical locations on the site (e.g., "leaderboard", "mrec", "sidebar")
- `gq_ad_group`: Organizational units within placements for rotation management
- `gq_ad`: Individual ad creatives (AdSense, HTML, or image ads)

**Rendering System** (`includes/render.php`)
- `gq_ads_render_placement()`: Main entry point - resolves placement by slug and renders selected ad
- `gq_ads_select_weighted_item()`: Weighted random selection algorithm used at both group and ad levels
- `gq_ads_render_single_ad()`: Renders final HTML based on ad type with tracking wrapper
- Device targeting via CSS classes: `-mobile` suffix for mobile-only, `-desktop` suffix for desktop-only

**Ad Types** (`includes/render.php:199-227`)
- `adsense`: AdSense units (script loaded once in footer via `includes/adsense.php`)
- `html`: Custom HTML/JavaScript snippets
- `image_link`: Image with target URL

**Tracking System**
- REST API endpoint: `/wp-json/bk-ads/v1/track` (`includes/rest.php`)
- JavaScript tracking fires on `DOMContentLoaded` for each rendered ad
- Database table: `wp_gq_ad_stats` with unique key on `(ad_id, placement_id, group_id, stat_date)`
- Admin stats page at Ads → Statistics (`includes/admin-stats.php`)

### Data Relationships (ACF)

**Groups → Placements**
- Field: `gq_group_placement` (Relationship, single)
- Meta query uses LIKE comparison to handle ACF's serialized storage formats

**Ads → Groups**
- Field: `gq_ad_group` (Relationship, multiple)
- Ads can belong to multiple groups
- Field: `gq_ad_weight` (Number, 0-100, default 50)

### Weight-Based Selection Logic

The plugin performs two-stage weighted random selection:
1. Select group from placement based on `gq_group_weight`
2. Select ad from group based on `gq_ad_weight`

Default behavior (`includes/render.php:136-189`):
- If no weights set, defaults to 50 for each item
- For exactly 2 items with no explicit weights, automatically does 50/50 split
- Weights are proportional (1/1 same as 50/50)

### Device Targeting Implementation

Device-specific placements use naming conventions (`includes/render.php:233-246`):
- Placement slug ending in `-mobile`: mobile-only (class: `bk-ad-mobile-only`)
- Placement slug ending in `-desktop`: desktop-only (class: `bk-ad-desktop-only`)
- If a placement has no suffix but a `-mobile` counterpart exists, it becomes desktop-only
- CSS in `bk-ads-manager.php:38-49` hides/shows based on screen width (767px breakpoint)

## Key Functions

**Template Functions**
- `gq_ads_render_placement( $placement_slug )`: Render ad by placement slug (used in shortcode and templates)
- Shortcode: `[gq_ad placement="slug"]`

**AdSense Integration**
- Global flag `$gq_ads_has_adsense` triggers script loading
- Publisher ID extracted from first AdSense ad or cached in transient
- Script loads once per page in footer with `async` attribute

## Database Schema

```sql
wp_gq_ad_stats:
- id (primary key)
- ad_id
- placement_id
- group_id
- stat_date (YYYY-MM-DD)
- impressions (count)
- clicks (reserved for future use)
- UNIQUE KEY: (ad_id, placement_id, group_id, stat_date)
```

## Important Notes

- **AdSense Policy**: Click tracking is NOT implemented for AdSense ads to comply with policies
- **ACF Relationship Queries**: Use OR meta_query with three formats: `"ID"`, `ID`, `:ID;` to handle ACF's serialized storage
- **Image Link Field Name**: Uses `gq_target_url` (not `gq_ad_target_url`) - see `render.php:216`
- **Caching**: Works with page caching - rotation happens on each server-side render
- **Debug Mode**: When `WP_DEBUG` is enabled, HTML comments show placement/group lookup failures
