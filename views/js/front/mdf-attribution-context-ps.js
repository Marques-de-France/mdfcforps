/**
 * Marques de France PrestaShop Attribution Context
 *
 * Detects attribution signals on page load (UTM parameters, referrer, landing page),
 * persists them in localStorage (primary) + first-party cookies (fallback, 60-day TTL,
 * SameSite=Lax), and stamps the PrestaShop session via AJAX so checkout can read them
 * without relying on either storage mechanism alone.
 *
 * Storage strategy (mirrors WooCommerce + Shopify connectors):
 *   1. localStorage — primary durable store, TS-gated for explicit 60-day TTL enforcement.
 *   2. Cookies      — parallel write on every LS write; PHP server reads only $_COOKIE,
 *                     so cookies must always be maintained.
 *   3. Cookie-only recovery — when Safari ITP prunes localStorage after 7 days, the cookie
 *                     is used to re-hydrate localStorage and resume normal operation.
 *
 * Runs on every frontend page load. First-touch attribution: per-key guards ensure
 * existing values (in either LS or cookies) are never overwritten.
 *
 * Requires: window.mdfcforpsRuntime.ajaxUrl (injected via tracker.tpl)
 */
( function () {
  'use strict';

  var COOKIE_TTL_DAYS = 60;
  var LS_TTL_MS       = COOKIE_TTL_DAYS * 24 * 60 * 60 * 1000; // 60 days in ms
  var MDF_SOURCE      = 'marques-de-france';

  // Keys stored in localStorage alongside their cookie counterparts.
  // Order matches the session-stamp payload.
  var CONTEXT_KEYS = [
    { ls: 'mdf_utm_source',     cookie: 'mdf_utm_source' },
    { ls: 'mdf_utm_medium',     cookie: 'mdf_utm_medium' },
    { ls: 'mdf_utm_campaign',   cookie: 'mdf_utm_campaign' },
    { ls: 'mdf_utm_content',    cookie: 'mdf_utm_content' },
    { ls: 'mdf_utm_term',       cookie: 'mdf_utm_term' },
    { ls: 'mdf_landing_site',   cookie: 'mdf_landing_site' },
    { ls: 'mdf_referring_site', cookie: 'mdf_referring_site' },
    { ls: 'mdf_landing_ref',    cookie: 'mdf_landing_ref' },
    { ls: 'mdf_click_id',       cookie: 'mdf_click_id' },
  ];

  function getRuntime() {
    return window.mdfcforpsRuntime || window.mdfcforpsConfig || null;
  }

  // -------------------------------------------------------------------------
  // Cookie helpers
  // -------------------------------------------------------------------------

  function setCookie( name, value, days ) {
    if ( !value ) return;
    var expires = new Date( Date.now() + days * 864e5 ).toUTCString();
    document.cookie = name + '=' + encodeURIComponent( value ) +
      '; expires=' + expires +
      '; path=/; SameSite=Lax';
  }

  function getCookie( name ) {
    var match = document.cookie.match( '(?:^|; )' + name + '=([^;]*)' );
    return match ? decodeURIComponent( match[ 1 ] ) : '';
  }

  // -------------------------------------------------------------------------
  // localStorage helpers — always wrapped in try/catch (private browsing safe)
  // -------------------------------------------------------------------------

  function getLocalValue( key ) {
    try {
      return localStorage.getItem( key ) || '';
    } catch ( e ) {
      return '';
    }
  }

  function setLocalValue( key, value ) {
    try {
      localStorage.setItem( key, value );
    } catch ( e ) {}
  }

  function removeLocalValue( key ) {
    try {
      localStorage.removeItem( key );
    } catch ( e ) {}
  }

  // -------------------------------------------------------------------------
  // Dual read — localStorage first, cookie as fallback
  // -------------------------------------------------------------------------

  function getStoredValue( lsKey, cookieName ) {
    return getLocalValue( lsKey ) || getCookie( cookieName );
  }

  // -------------------------------------------------------------------------
  // Per-key first-touch write — writes to both LS and cookie only when neither
  // already has a value. Prevents any overwrite after the first attribution.
  // -------------------------------------------------------------------------

  function persistFirstTouch( lsKey, cookieName, value ) {
    if ( !value ) return;
    if ( getStoredValue( lsKey, cookieName ) ) return; // already stored — skip
    setLocalValue( lsKey, value );
    setCookie( cookieName, value, COOKIE_TTL_DAYS );
  }

  // -------------------------------------------------------------------------
  // click_id — sanitized, independently TTL-checked, with active LS purge
  // -------------------------------------------------------------------------

  function sanitizeClickId( value ) {
    if ( !value || typeof value !== 'string' ) return '';
    var trimmed = value.trim();
    return /^[A-Za-z0-9_-]{8,128}$/.test( trimmed ) ? trimmed : '';
  }

  /**
   * Returns the stored click_id if it is still within TTL, otherwise purges
   * the expired LS entries and falls back to the cookie.
   */
  function getStoredClickId() {
    var id = sanitizeClickId( getLocalValue( 'mdf_click_id' ) );
    var ts = parseInt( getLocalValue( 'mdf_click_id_at' ) || '0', 10 );

    if ( id && ts > 0 && ( Date.now() - ts ) < LS_TTL_MS ) {
      return id;
    }

    // Expired or missing timestamp — purge LS entries.
    if ( id ) {
      removeLocalValue( 'mdf_click_id' );
      removeLocalValue( 'mdf_click_id_at' );
    }

    // Cookie fallback (Safari ITP may have pruned LS after 7 days).
    var fromCookie = sanitizeClickId( getCookie( 'mdf_click_id' ) );
    if ( fromCookie ) {
      setLocalValue( 'mdf_click_id', fromCookie );
      setLocalValue( 'mdf_click_id_at', String( Date.now() ) );
      return fromCookie;
    }

    return '';
  }

  // -------------------------------------------------------------------------
  // Attribution flag — TS-gated LS check with cookie fallback + re-hydration
  // -------------------------------------------------------------------------

  /**
   * Returns true when a valid MDF attribution is already stored (LS or cookie).
   * On cookie-only recovery (Safari ITP pruned LS), re-populates LS + timestamp.
   */
  function isAttributionValid() {
    var flag = getLocalValue( 'mdf_attributed' );
    var ts   = parseInt( getLocalValue( 'mdf_attributed_at' ) || '0', 10 );

    if ( flag === '1' && ts > 0 && ( Date.now() - ts ) < LS_TTL_MS ) {
      return true;
    }

    // Cookie fallback — re-hydrate LS so subsequent page loads use the fast path.
    if ( getCookie( 'mdf_attributed' ) === '1' ) {
      setLocalValue( 'mdf_attributed', '1' );
      setLocalValue( 'mdf_attributed_at', String( Date.now() ) );
      return true;
    }

    return false;
  }

  // -------------------------------------------------------------------------
  // Rehydrate cookies from localStorage
  // Restores any cookie that was pruned by the browser (e.g. Safari ITP)
  // while localStorage still holds the value.
  // -------------------------------------------------------------------------

  function rehydrateContextCookies() {
    var i, entry, lsValue;
    for ( i = 0; i < CONTEXT_KEYS.length; i++ ) {
      entry = CONTEXT_KEYS[ i ];
      if ( getCookie( entry.cookie ) ) continue; // cookie still present — skip
      lsValue = getLocalValue( entry.ls );
      if ( lsValue ) {
        setCookie( entry.cookie, lsValue, COOKIE_TTL_DAYS );
      }
    }
  }

  // -------------------------------------------------------------------------
  // URL helpers
  // -------------------------------------------------------------------------

  function getParam( params, key ) {
    return params.get( key ) || '';
  }

  // -------------------------------------------------------------------------
  // Main attribution logic
  // -------------------------------------------------------------------------

  function init() {
    var runtime = getRuntime();

    // Skip if runtime config is not available.
    if ( !runtime || !runtime.ajaxUrl ) {
      return;
    }

    var params      = new URLSearchParams( window.location.search );
    var utmSource   = getParam( params, 'utm_source' );
    var utmMedium   = getParam( params, 'utm_medium' );
    var utmCampaign = getParam( params, 'utm_campaign' );
    var utmContent  = getParam( params, 'utm_content' );
    var utmTerm     = getParam( params, 'utm_term' );
    var clickId     = sanitizeClickId( getParam( params, 'mdf_click_id' ) );

    // Only attribute if this visit originates from Marques de France.
    // Signal 1 (utm):      utm_source / utm_medium / utm_campaign contains MDF_SOURCE
    // Signal 2 (ref):      ?ref= or ?landing_ref= contains MDF_SOURCE
    // Signal 3 (referrer): document.referrer contains 'marques-de-france.fr'
    // Signal 4 (click_id): any valid mdf_click_id query param
    var referrerUrl   = document.referrer || '';
    var refParam      = getParam( params, 'ref' ) || getParam( params, 'landing_ref' );
    var isMdfUtm      = ( utmSource.indexOf( MDF_SOURCE ) !== -1 ) ||
                        ( utmMedium.indexOf( MDF_SOURCE ) !== -1 ) ||
                        ( utmCampaign.indexOf( MDF_SOURCE ) !== -1 );
    var isMdfRef      = refParam.indexOf( MDF_SOURCE ) !== -1;
    var isMdfReferrer = referrerUrl.indexOf( 'marques-de-france.fr' ) !== -1;
    var hasClickId    = clickId !== '';

    var isAttributed = isMdfUtm || isMdfRef || isMdfReferrer || hasClickId;

    if ( runtime.debug === 'true' ) {
      console.log( '[MDF Attribution] utm=' + isMdfUtm + ' ref=' + isMdfRef + ' referrer=' + isMdfReferrer + ' click=' + hasClickId + ' attributed=' + isAttributed );
    }

    // -----------------------------------------------------------------------
    // First-touch: don't overwrite existing attribution.
    // isAttributionValid() checks localStorage (TS-gated) then cookie fallback.
    // -----------------------------------------------------------------------
    var alreadyAttributed = isAttributionValid();

    if ( isAttributed && !alreadyAttributed ) {
      // Landing page = full current URL (before any redirect).
      var landingUrl = window.location.href;

      // Write attribution flag to both LS (with timestamp) and cookie.
      setLocalValue( 'mdf_attributed',    '1' );
      setLocalValue( 'mdf_attributed_at', String( Date.now() ) );
      setCookie( 'mdf_attributed', '1', COOKIE_TTL_DAYS );

      // Write click_id with its own TS key for independent TTL tracking.
      if ( clickId ) {
        setLocalValue( 'mdf_click_id',    clickId );
        setLocalValue( 'mdf_click_id_at', String( Date.now() ) );
        setCookie( 'mdf_click_id', clickId, COOKIE_TTL_DAYS );
      }

      // Persist all context signals to LS + cookies (per-key first-touch guard).
      persistFirstTouch( 'mdf_utm_source',     'mdf_utm_source',     utmSource );
      persistFirstTouch( 'mdf_utm_medium',     'mdf_utm_medium',     utmMedium );
      persistFirstTouch( 'mdf_utm_campaign',   'mdf_utm_campaign',   utmCampaign );
      persistFirstTouch( 'mdf_utm_content',    'mdf_utm_content',    utmContent );
      persistFirstTouch( 'mdf_utm_term',       'mdf_utm_term',       utmTerm );
      persistFirstTouch( 'mdf_landing_site',   'mdf_landing_site',   landingUrl );
      persistFirstTouch( 'mdf_referring_site', 'mdf_referring_site', referrerUrl );
      persistFirstTouch( 'mdf_landing_ref',    'mdf_landing_ref',    refParam );

      stampSession( runtime, {
        mdf_attributed:    '1',
        mdf_utm_source:    utmSource,
        mdf_utm_medium:    utmMedium,
        mdf_utm_campaign:  utmCampaign,
        mdf_utm_content:   utmContent,
        mdf_utm_term:      utmTerm,
        mdf_landing_site:  landingUrl,
        mdf_referring_site:referrerUrl,
        mdf_landing_ref:   refParam,
        mdf_click_id:      clickId,
      } );

    } else if ( !isAttributed && alreadyAttributed ) {
      // Visitor returning without UTMs but attribution is still live.
      // Restore any cookies that were pruned (Safari ITP), then re-stamp the session.
      rehydrateContextCookies();

      stampSession( runtime, {
        mdf_attributed:    '1',
        mdf_utm_source:    getStoredValue( 'mdf_utm_source',     'mdf_utm_source' ),
        mdf_utm_medium:    getStoredValue( 'mdf_utm_medium',     'mdf_utm_medium' ),
        mdf_utm_campaign:  getStoredValue( 'mdf_utm_campaign',   'mdf_utm_campaign' ),
        mdf_utm_content:   getStoredValue( 'mdf_utm_content',    'mdf_utm_content' ),
        mdf_utm_term:      getStoredValue( 'mdf_utm_term',       'mdf_utm_term' ),
        mdf_landing_site:  getStoredValue( 'mdf_landing_site',   'mdf_landing_site' ),
        mdf_referring_site:getStoredValue( 'mdf_referring_site', 'mdf_referring_site' ),
        mdf_landing_ref:   getStoredValue( 'mdf_landing_ref',    'mdf_landing_ref' ),
        mdf_click_id:      getStoredClickId(),
      } );
    }
  }

  // -------------------------------------------------------------------------
  // AJAX session stamp (PrestaShop front controller)
  // -------------------------------------------------------------------------

  function stampSession( runtime, data ) {
    fetch( runtime.ajaxUrl, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify( data ),
    } )
    .then( function ( res ) { return res.json(); } )
    .then( function ( json ) {
      if ( runtime.debug === 'true' ) {
        console.log( '[MDF Attribution] Session stamped:', json );
      }
    } )
    .catch( function ( err ) {
      if ( runtime.debug === 'true' ) {
        console.warn( '[MDF Attribution] Stamp error:', err );
      }
    } );
  }

  // Run after DOM is interactive.
  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', init );
  } else {
    init();
  }
} )();
