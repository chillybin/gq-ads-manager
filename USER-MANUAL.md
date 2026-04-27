# GQ Ads Manager — User Manual

**For:** Advertising & Editorial Teams  
**Version:** 1.0  
**Last updated:** April 2026

---

## Contents

1. [Overview](#1-overview)
2. [Getting Started](#2-getting-started)
3. [Understanding the Structure](#3-understanding-the-structure)
4. [Ad Placements](#4-ad-placements)
5. [Ad Groups & Campaigns](#5-ad-groups--campaigns)
6. [Ads & Creatives](#6-ads--creatives)
7. [Category Targeting](#7-category-targeting)
8. [Exclusive (100% Share of Voice) Campaigns](#8-exclusive-100-share-of-voice-campaigns)
9. [Campaign Scheduling](#9-campaign-scheduling)
10. [Google Ads Fallback](#10-google-ads-fallback)
11. [Statistics & Reporting](#11-statistics--reporting)
12. [Common Tasks — Quick Reference](#12-common-tasks--quick-reference)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Overview

GQ Ads Manager is the custom advertising platform built into the GQ website. It gives the team full control over:

- Which ads appear in which positions on the site
- Which advertisers are targeted to which content categories (e.g. Chanel appears only on Fashion articles)
- Exclusive campaigns where a single advertiser owns 100% of a placement
- Scheduled campaigns with fixed start and end dates
- Impression and click reporting with CSV export

All ad management happens inside the WordPress admin — no code changes required.

---

## 2. Getting Started

### Accessing the Ad Manager

Log into the WordPress admin. In the left-hand menu you will see:

- **Ad Placements** — the positions on the site (leaderboard, sidebar, etc.)
- **Ad Groups** — campaign containers that sit inside a placement
- **Ads** — individual creative units

The **Statistics** page is found under **Ad Placements → Statistics**.

### Who can do what

| Role | Can view stats | Can create/edit ads | Can create placements |
|---|---|---|---|
| Editor | ✅ | ✅ | ✅ |
| Author | ✅ | ✅ | ✅ |
| Subscriber | ❌ | ❌ | ❌ |

---

## 3. Understanding the Structure

Ads on GQ are organised in three layers:

```
AD PLACEMENT
│  (the position on the page, e.g. "Leaderboard")
│
└── AD GROUP
    │  (the campaign or advertiser, e.g. "Rolex — Q2 2026")
    │  Controls: which placement, campaign dates, category targeting, SOV
    │
    └── AD
        (the actual creative, e.g. a banner image or HTML unit)
        Controls: ad type, image, URL, weight
```

**Why three layers?**

- A single **placement** (e.g. the sidebar MREC) can run multiple campaigns simultaneously, each targeting different content sections.
- **Weighting** between groups means you can split impressions across advertisers — 60% Chanel, 40% Dior.
- **Groups** with category targeting ensure the right advertiser appears in the right editorial context automatically.

---

## 4. Ad Placements

Placements are set up by the development team and represent fixed positions on the website. You will rarely need to create new ones, but it is useful to know which placements exist.

### Viewing existing placements

Go to **Ad Placements** in the left menu. You will see a list of all positions on the site with their slugs (the short code name used internally).

### Common placements on GQ

| Placement Name | Slug | Where it appears |
|---|---|---|
| Leaderboard | `leaderboard` | Top of page, below nav |
| Leaderboard Mobile | `leaderboard-mobile` | Mobile only, top of page |
| Sidebar MREC | `sidebar-mrec` | Right sidebar, all articles |
| In-Article | `in-article` | Mid-article, after 3rd paragraph |
| Homepage Hero | `homepage-hero` | Homepage banner |

> **Note:** Mobile and desktop versions of a placement are paired automatically. If both `sidebar-mrec` and `sidebar-mrec-mobile` exist, the system serves the correct version to each device without any extra configuration.

---

## 5. Ad Groups & Campaigns

An **Ad Group** is how you create a campaign. One group = one advertiser's campaign within a given placement.

### Creating a new campaign

1. Go to **Ad Groups → Add New**
2. Enter a **title** that clearly identifies the campaign:
   - *Good:* `Chanel No.5 — Sidebar — May 2026`
   - *Avoid:* `New campaign` or `Banner 1`

3. In the **Placement & Weight** panel (right sidebar):
   - **Placement** — select the position this campaign will run in
   - **Weight** — controls how often this group is shown relative to others in the same placement. Default is 50. See the table below.

4. In the **Campaign & Targeting** panel:
   - Set campaign dates (optional — see [Campaign Scheduling](#9-campaign-scheduling))
   - Tick category targeting (optional — see [Category Targeting](#7-category-targeting))
   - Enable 100% SOV if this is an exclusive booking (see [Exclusive Campaigns](#8-exclusive-100-share-of-voice-campaigns))

5. Click **Publish**

### Understanding weights

Weights are relative, not percentages. They determine how impressions are split between groups in the same placement.

| Group A Weight | Group B Weight | Result |
|---|---|---|
| 50 | 50 | 50/50 split |
| 70 | 30 | 70% Group A, 30% Group B |
| 100 | 50 | 67% Group A, 33% Group B |
| 1 | 1 | 50/50 (same as 50/50) |

> The numbers only matter relative to each other. Setting both to 1 is the same as setting both to 50.

---

## 6. Ads & Creatives

An **Ad** is the individual creative — the banner image, HTML unit, or AdSense slot.

### Creating a new ad

1. Go to **Ads → Add New**
2. Enter a descriptive **title**:
   - *Good:* `Chanel No.5 — 300x250 — May 2026`

3. In the **Ad Settings** panel, choose the **Ad Type**:

---

#### Ad Type: HTML / JavaScript

Use this for standard display banners delivered by a third-party ad server (e.g. Campaign Manager, Xandr, The Trade Desk).

- Paste the full ad tag code into the **Ad Code** field
- The tag is output exactly as pasted — no modifications

**Example tag:**
```
<script src="https://cdn.example.com/ads/banner.js"></script>
```

---

#### Ad Type: Google AdSense

Use this for AdSense units managed through your Google AdSense account.

- Paste the full AdSense code snippet (including the `<script>` and `<ins>` tags) into the **Ad Code** field
- The AdSense library script is loaded automatically — you do not need to add it separately

> **Important:** Click tracking is intentionally disabled for AdSense ads in compliance with Google's Terms of Service. Impressions are still tracked.

---

#### Ad Type: Image + Link

Use this for a simple image banner with a click-through URL — no third-party tags required.

- **Ad Image** — click **Select Image** to choose from the media library, or upload a new image
- **Click-through URL** — the destination page when someone clicks the ad

---

4. Set the **Weight** (default 50) — controls rotation among ads within the same group
5. Under **Ad Groups**, tick the group(s) this ad belongs to
6. Click **Publish**

> **Tip:** You can assign one ad to multiple groups. For example, a Chanel leaderboard banner can appear in both the standard Chanel group and the Fashion Week special campaign group.

---

## 7. Category Targeting

Category targeting ensures an advertiser's ads appear only on articles in matching editorial categories. For example:

- **Chanel** appears only on **Fashion** articles
- **Rolex** appears only on **Watches** articles
- **Ferrari** appears only on **Cars** articles

### How to set up category targeting

1. Open the **Ad Group** for the campaign
2. In the **Campaign & Targeting** panel, scroll to **Category Targeting**
3. Tick the categories relevant to the advertiser

> **Leave all categories unticked** if the campaign should run globally (on all pages). This is the default behaviour.

### How it works in practice

When a reader views an article:

1. The system checks which categories the article belongs to
2. Any groups with matching category targeting are prioritised over global (untargeted) groups
3. If no category-targeted group matches, the system falls back to global groups for that placement

This means you can safely run both a targeted Chanel campaign (Fashion only) and a global fallback campaign in the same placement — Chanel shows on Fashion articles, the fallback runs everywhere else.

### Category targeting on archive pages

Category targeting also works on category archive pages (e.g. the main Fashion section page). A group targeting Fashion will activate on the `/fashion/` archive as well as on individual Fashion articles.

---

## 8. Exclusive (100% Share of Voice) Campaigns

A **100% Share of Voice** campaign means one advertiser owns the entire placement for the duration of the campaign — no other ads rotate in.

Use this for:
- Guaranteed premium campaigns
- Launch exclusives (e.g. a new fragrance launch owns the homepage for one week)
- Festival or event sponsorships

### Setting up an exclusive campaign

1. Open or create the **Ad Group** for the premium advertiser
2. In the **Campaign & Targeting** panel, tick **100% Share of Voice**
3. Set the campaign start and end dates (see [Campaign Scheduling](#9-campaign-scheduling))
4. Publish

While this group is active and its dates are current, it will receive **all** impressions for the placement. Other groups are bypassed.

> **Important:** If two groups both have 100% SOV enabled for the same placement and overlapping dates, the system picks between them using weight. Avoid overlapping exclusive campaigns for the same placement.

### Ending an exclusive campaign

When the campaign end date passes, the placement automatically reverts to normal rotation. No manual action is needed.

---

## 9. Campaign Scheduling

You can set a campaign to run only within a specific date window. Outside those dates, the group is invisible to the rotation and other groups take over.

### Setting campaign dates

In the **Campaign & Targeting** panel on the Ad Group edit screen:

- **Campaign Start** — the first day the campaign is active. Leave blank to start immediately.
- **Campaign End** — the last day the campaign is active. Leave blank to run indefinitely.

> Dates use the site's timezone (set in **Settings → General**).

### Always-on campaigns

Leave both dates blank for campaigns with no fixed end (e.g. house ads, evergreen sponsors).

### Example timeline

| Period | Active Groups |
|---|---|
| Before 1 May | Global fallback |
| 1–31 May | Chanel No.5 exclusive (100% SOV, dates set) |
| 1 June onwards | Global fallback resumes automatically |

---

## 10. Google Ads Fallback

Each placement can have a **fallback ad** — typically a Google Ads unit — that appears when no active campaign matches.

The fallback appears when:
- No groups are assigned to the placement
- All groups are outside their campaign date window
- A category-targeted campaign is active but the current page doesn't match any targeted category

### Setting a fallback

1. Open the **Ad Placement** edit screen
2. In the **Fallback Ad** panel, paste the Google Ads or any other HTML/JS unit
3. Save

---

## 11. Statistics & Reporting

### Accessing the dashboard

Go to **Ad Placements → Statistics** in the left menu.

### Dashboard overview

The statistics page shows:

- **Active Campaigns panel** — a live view of every campaign currently running, with its date window, targeted categories, and SOV status
- **Impressions chart** — a line chart of impression volume over your selected date range
- **Stats table** — per-ad breakdown of impressions, clicks, and CTR

### Filtering data

Use the filter bar at the top to narrow results:

| Filter | Purpose |
|---|---|
| Date range | Last 7 / 30 / 90 days, Last year, or custom range |
| View mode | Daily / Weekly / Monthly aggregation |
| Placement | Filter to one position on the site |
| Group | Filter to one campaign |
| Ad | Filter to one creative |

Click **Apply Filters** to refresh. Click **Clear Filters** to reset.

### Reading the stats table

| Column | What it means |
|---|---|
| **Ad** | The individual creative name |
| **Placement** | Where the ad appeared |
| **Group** | Which campaign it belongs to |
| **Impressions** | Total times the ad was served and entered the viewer's screen |
| **Clicks** | Total clicks recorded |
| **CTR** | Click-through rate (clicks ÷ impressions × 100) |
| **Date Range** | The first and last date this ad recorded activity |

The **Group** column also shows campaign metadata inline:
- Campaign window (e.g. `01 May 2026 → 31 May 2026`)
- Targeted categories (e.g. `Fashion, Beauty`)
- A **100% SOV** badge for exclusive campaigns

### CTR colour coding

| Colour | Meaning |
|---|---|
| 🟢 Green | CTR ≥ 1% — strong performance |
| 🟡 Yellow | CTR 0.5–1% — average |
| 🔴 Red | CTR < 0.5% — below average |

> **Note:** CTR benchmarks vary significantly by ad type and placement. These thresholds are indicative — always compare against your own historical data.

### Impression tracking note

Impressions are counted when at least **50% of the ad unit is visible in the viewer's screen** (the industry standard viewable impression threshold). This means:
- Ads below the fold that a reader never scrolls to are **not** counted
- The impression count will be lower than a DOMContentLoaded-based tracker, but the quality is higher

### Exporting to CSV

Click the **Export CSV** button at the top right. The export respects all active filters — if you have a placement or date range selected, only that data is exported.

The CSV includes: Ad, Placement, Group, Impressions, Clicks, CTR, Date Range.

Open in Excel or Google Sheets for further analysis.

---

## 12. Common Tasks — Quick Reference

### Set up a new campaign

1. Create the **Ad** creative (Ads → Add New)
2. Create the **Ad Group** (Ad Groups → Add New) — assign placement, set weight, dates, targeting
3. On the Ad, tick the new Group under **Ad Groups**
4. Publish both

### Run a category-exclusive campaign (e.g. Watches advertiser)

1. Create Ad Group → assign to `sidebar-mrec` → tick **Watches** under Category Targeting → set dates → Publish
2. Create Ad(s) → assign to the group → Publish
3. Done — the ad will only appear on Watch articles and the Watches archive page

### Set up a homepage takeover (exclusive)

1. Create Ad Group → assign to `homepage-hero` → tick **100% Share of Voice** → set start/end dates → Publish
2. Create Ad → assign to the group → Publish
3. The placement is now exclusively owned by this advertiser for those dates

### Change the weight of an existing campaign

1. Open the Ad Group
2. In **Placement & Weight**, update the Weight value
3. Update (save)

### Pause a campaign immediately

1. Open the Ad Group
2. Set the **Campaign End** date to yesterday
3. Update — the group is now inactive

### Check how a campaign performed

1. Go to **Ad Placements → Statistics**
2. Set your date range
3. Filter by **Group** and select the campaign
4. Review impressions, clicks, CTR
5. Export CSV if needed

---

## 13. Troubleshooting

### An ad is not appearing on the site

Work through this checklist:

1. **Is the Ad published?** — Go to Ads, confirm status is Published (not Draft)
2. **Is the Ad Group published?** — Same check for the group
3. **Is the campaign within its date window?** — Check start/end dates on the group
4. **Is the placement correct?** — Open the Ad Group, confirm the right Placement is selected
5. **Is the ad assigned to the group?** — Open the Ad, confirm the group is ticked under Ad Groups
6. **Is there a 100% SOV campaign blocking it?** — Another group with SOV enabled may be taking all impressions

### Stats show zero impressions for a live ad

- Impressions are counted when 50% of the ad is visible — ads entirely above the fold on very tall browser windows, or ads in hidden tabs, may record fewer impressions than expected
- Check that the ad is assigned to a group, and the group is assigned to a placement
- Try visiting the page yourself in a private/incognito window and scrolling to the ad

### The fallback ad is showing instead of the campaign

- The campaign date window may have ended — check start/end dates on the group
- The page category may not match the group's category targeting — check which categories are ticked
- The group may not be published

### I can't find the Ad Settings fields

If the Ad Settings panel (Ad Type, Ad Code, etc.) is not visible on the Ad edit screen, Advanced Custom Fields (ACF) may be installed and managing those fields instead. Contact the development team to configure the ACF field group for the `gq_ad` post type.

---

*GQ Ads Manager is maintained by [Chillybin Web Design](https://www.chillybin.co).*
