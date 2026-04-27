/**
 * GQ Ads — impression and click tracking
 *
 * Replaces per-ad inline script blocks with:
 *  - IntersectionObserver for viewport-accurate impression counting
 *  - Single delegated click listener for all ads on the page
 *  - sendBeacon (fetch fallback) for fire-and-forget delivery
 *
 * Requires window.gqAdsTracking = { endpoint, nonce } set via wp_localize_script.
 *
 * NOTE: Impressions fire only when ≥50% of the ad enters the viewport. This
 * matches the IAB "viewable impression" threshold and produces lower but more
 * accurate counts than DOMContentLoaded-based tracking.
 */

const config = window.gqAdsTracking ?? null;

if (!config?.endpoint || !config?.nonce) {
  // Config is missing — silently bail so nothing breaks on the page.
  console.debug('GQ Ads: tracking config not found');
} else {
  initTracking(config.endpoint, config.nonce);
}

/**
 * @param {string} endpoint  REST API URL for /track
 * @param {string} nonce     WordPress nonce for gq_ads_track action
 */
function initTracking(endpoint, nonce) {
  /**
   * Extract typed tracking data from a .gq-ad wrapper element.
   *
   * @param {Element} el
   * @returns {{ adId: number, placementId: number, groupId: number, adType: string } | null}
   */
  const getAdData = (el) => {
    if (!(el instanceof HTMLElement)) return null;

    const adId        = parseInt(el.dataset.gqAdId       ?? '0', 10);
    const placementId = parseInt(el.dataset.gqPlacementId ?? '0', 10);
    const groupId     = parseInt(el.dataset.gqGroupId     ?? '0', 10);
    const adType      = el.dataset.gqAdType ?? '';

    if (adId <= 0 || placementId <= 0) return null;
    return { adId, placementId, groupId, adType };
  };

  /**
   * Fire a tracking ping to the REST endpoint.
   * Uses sendBeacon (fire-and-forget) with a fetch fallback.
   *
   * @param {object} data
   * @param {number} data.adId
   * @param {number} data.placementId
   * @param {number} data.groupId
   * @param {'impression'|'click'} data.action
   */
  const track = ({ adId, placementId, groupId, action }) => {
    const payload = JSON.stringify({
      ad_id:        adId,
      placement_id: placementId,
      group_id:     groupId,
      action,
      _wpnonce:     nonce,
    });

    const blob = new Blob([payload], { type: 'application/json' });

    // sendBeacon is ideal for analytics — browser queues it even on page unload.
    if (navigator.sendBeacon?.(endpoint, blob)) return;

    // Fallback: fetch with keepalive so the request survives navigation.
    fetch(endpoint, {
      method:    'POST',
      keepalive: true,
      headers:   { 'Content-Type': 'application/json' },
      body:      payload,
    }).catch((err) => {
      console.debug('GQ Ads: tracking error', err);
    });
  };

  // ── Impression tracking ──────────────────────────────────────────────────
  // Fires once per ad when at least 50% of its area enters the viewport.
  // Unobserves the element immediately after firing to prevent duplicates.

  const impressionObserver = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;

        const data = getAdData(entry.target);
        if (data) track({ ...data, action: 'impression' });

        impressionObserver.unobserve(entry.target);
      }
    },
    { threshold: 0.5 },
  );

  const observeAds = () => {
    document.querySelectorAll('.gq-ad').forEach((el) => {
      impressionObserver.observe(el);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', observeAds);
  } else {
    observeAds();
  }

  // ── Click tracking ───────────────────────────────────────────────────────
  // Single delegated listener on document — covers all ads including any
  // dynamically inserted after page load.
  // WeakSet dedup: allows the element to be garbage-collected if removed from DOM.

  const trackedClicks = new WeakSet();

  document.addEventListener('click', (event) => {
    // instanceof guard narrows type and excludes non-Element nodes (TextNode, Comment).
    const adEl = event.target instanceof Element ? event.target.closest('.gq-ad') : null;
    if (!adEl) return;

    const data = getAdData(adEl);
    if (!data) return;

    // AdSense click tracking violates Google ToS — always skip.
    if (data.adType === 'adsense') return;

    if (trackedClicks.has(adEl)) return;
    trackedClicks.add(adEl);

    track({ ...data, action: 'click' });
  });
}
