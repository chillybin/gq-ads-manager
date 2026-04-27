# GQ Ads Manager - Setup Guide

## Overview

This plugin uses a three-tier hierarchy for managing ads:

**Placements → Groups → Ads**

- **Placements**: Physical locations on your site (e.g., "leaderboard", "mrec", "sidebar")
- **Groups**: Organizational units within placements for rotation management
- **Ads**: Individual ad creatives (AdSense, HTML, or image ads)

## Installation

1. Copy this folder to: `wp-content/plugins/gq-ads-manager/`
2. Activate "gq Ads Manager" in wp-admin → Plugins
3. This creates the `wp_gq_ad_stats` database table

## ACF Field Setup

### 1. Ad Placements (gq_ad_placement)

No required fields - just create placements with slugs like:
- `leaderboard`
- `mrec`
- `sidebar`
- `footer`

### 2. Ad Groups (gq_ad_group)

Create a field group for `gq_ad_group`:

**Field: gq_group_placement**
- Type: Relationship
- Post Type: gq_ad_placement
- Return Format: Post ID
- Multiple: No (single placement per group)

**Field: gq_group_weight**
- Type: Number
- Default: 50
- Instructions: Weight for rotation (e.g., 50 = 50%, 75 = 75%)
- Min: 0
- Max: 100

### 3. Ads (gq_ad)

Create a field group for `gq_ad`:

**Field: gq_ad_type**
- Type: Select
- Choices:
  - `adsense` : AdSense
  - `html` : HTML/JavaScript
  - `image_link` : Image + Link
- Required: Yes

**Field: gq_ad_code**
- Type: Textarea
- Instructions: AdSense snippet or custom HTML/JS
- Conditional Logic: Show if gq_ad_type equals "adsense" OR "html"

**Field: gq_ad_image**
- Type: Image
- Return Format: Image ID
- Conditional Logic: Show if gq_ad_type equals "image_link"

**Field: gq_ad_target_url**
- Type: URL
- Instructions: Destination URL for image ads
- Conditional Logic: Show if gq_ad_type equals "image_link"

**Field: gq_ad_group**
- Type: Relationship
- Post Type: gq_ad_group
- Return Format: Post ID
- Multiple: Yes (ad can belong to multiple groups)
- Required: Yes

**Field: gq_ad_weight**
- Type: Number
- Default: 50
- Instructions: Weight for rotation within group (e.g., 50 = 50%)
- Min: 0
- Max: 100

## Example Setup

### Scenario: MREC placement with 50/50 rotation between 2 ads

1. **Create Placement**: "MREC"
   - Title: MREC
   - Slug: `mrec`

2. **Create Group**: "MREC Group A"
   - Title: MREC Group A
   - gq_group_placement: Select "MREC"
   - gq_group_weight: 50 (or leave blank for default 50%)

3. **Create Ads**:

   **Ad 1**: "MREC Ad 1"
   - gq_ad_type: adsense (or html/image_link)
   - gq_ad_code: [Your AdSense snippet]
   - gq_ad_group: Select "MREC Group A"
   - gq_ad_weight: 50

   **Ad 2**: "MREC Ad 2"
   - gq_ad_type: adsense
   - gq_ad_code: [Your AdSense snippet]
   - gq_ad_group: Select "MREC Group A"
   - gq_ad_weight: 50

With 2 ads at 50 weight each in the same group, they will rotate 50/50.

## How Rotation Works

### Weight-Based Selection

The plugin uses **weighted random selection** at both group and ad levels:

1. **Placement Level**: Selects a group based on `gq_group_weight`
2. **Group Level**: Selects an ad based on `gq_ad_weight`

### Weight Examples

**50/50 split (2 ads)**:
- Ad 1: weight = 50
- Ad 2: weight = 50
- Result: Each ad shows ~50% of the time

**75/25 split**:
- Ad 1: weight = 75
- Ad 2: weight = 25
- Result: Ad 1 shows ~75%, Ad 2 shows ~25%

**33/33/33 split (3 ads)**:
- Ad 1: weight = 33
- Ad 2: weight = 33
- Ad 3: weight = 34
- Result: Each ad shows ~33% of the time

### Default Behavior

- If no weights are set, defaults to 50 for each item
- For exactly 2 items with no weights, automatically does 50/50 split
- Weights are proportional (you can use 1/1 instead of 50/50 - same result)

## Displaying Ads

### Shortcode

```php
[gq_ad placement="mrec"]
```

### PHP Template Function

```php
<?php echo gq_ads_render_placement( 'mrec' ); ?>
```

### In Beaver Builder

Add a "Text Editor" or "HTML" module and insert the shortcode:
```
[gq_ad placement="leaderboard"]
```

## Impression Tracking

The plugin automatically tracks impressions via REST API:
- Endpoint: `/wp-json/gq-ads/v1/track`
- Data stored in: `wp_gq_ad_stats` table
- Tracked fields: ad_id, placement_id, group_id, stat_date, impressions

### Database Schema

```sql
wp_gq_ad_stats:
- id (primary key)
- ad_id
- placement_id
- group_id
- stat_date (YYYY-MM-DD)
- impressions (count)
- clicks (reserved for future use)
```

## Notes

- **AdSense Policy**: Click tracking is NOT implemented for AdSense ads to comply with policies
- **Impression Tracking**: Fires once per page load via JavaScript
- **Caching**: Works with page caching - rotation happens on each server-side render
- **Groups**: You can have multiple groups in one placement for A/B testing different ad sets

## Future Enhancements

Potential additions:
- Admin dashboard for viewing stats
- Date range reporting
- Group scheduling (show Group A Mon-Wed, Group B Thu-Sun)
- Geographic targeting per group
- Device-specific groups (mobile vs desktop)
