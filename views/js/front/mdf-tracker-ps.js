/**
 * Marques de France PrestaShop Attribution Tracker
 *
 * Ports mdf-attribution-context-wc.js for PrestaShop.
 *
 * Key differences vs WooCommerce version:
 *  - `window.mdfcforpsRuntime` instead of `window.mdfcforwcRuntime`
 *  - AJAX sends JSON to the PS front controller (not admin-ajax.php)
 *  - No WP nonce — PS front controller is unprotected (attribution only)
 */
( function () {
  'use strict';

  var COOKIE_TTL_DAYS = 60;
  var LS_TTL_MS       = COOKIE_TTL_DAYS * 24 * 60 * 60 * 1000;
  var MDF_SOURCE      = 'marques-de-france';

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
    return window.mdfcforpsRuntime || null;
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
    return match ? decodeURIComponent( match[1] ) : '';
  }

  // -------------------------------------------------------------------------
  // localStorage helpers
  // -------------------------------------------------------------------------

  function getLocalValue( key ) {
    try { return localStorage.getItem( key ) || ''; } catch ( e ) { return ''; }
  }

  function setLocalValue( key, value ) {
    try { localStorage.setItem( key, value ); } catch ( e ) {}
  }

  function removeLocalValue( key ) {
    try { localStorage.removeItem( key ); } catch ( e ) {}
  }

  // -------------------------------------------------------------------------
  // Dual read — localStorage first, cookie fallback
  // -------------------------------------------------------------------------

  function getStoredValue( lsKey, cookieName ) {
    return getLocalValue( lsKey ) || getCookie( cookieName );
  }

  // -------------------------------------------------------------------------
  // Per-key first-touch write
  // -------------------------------------------------------------------------

  function persistFirstTouch( lsKey, cookieName, value ) {
    if ( !value ) return;
    if ( getStoredValue( lsKey, cookieName ) ) return;
    setLocalValue( lsKey, value );
    setCookie( cookieName, value, COOKIE_TTL_DAYS );
  }

  // -------------------------------------------------------------------------
  // click_id — TTL-checked
  // -------------------------------------------------------------------------

  function sanitizeClickId( value ) {
    if ( !value || typeof value !== 'string' ) return '';
    var trimmed = value.trim();
    return /^[A-Za-z0-9_-]{8,128}$/.test( trimmed ) ? trimmed : '';
  }

  function getStoredClickId() {
    var id = sanitizeClickId( getLocalValue( 'mdf_click_id' ) );
    var ts = parseInt( getLocalValue( 'mdf_click_id_at' ) || '0', 10 );

    if ( id && ts > 0 && ( Date.now() - ts ) < LS_TTL_MS ) {
      return id;
    }

    if ( id ) {
      removeLocalValue( 'mdf_click_id' );
      removeLocalValue( 'mdf_click_id_at' );
    }

    var fromCookie = sanitizeClickId( getCookie( 'mdf_click_id' ) );
    if ( fromCookie ) {
      setLocalValue( 'mdf_click_id', fromCookie );
      setLocalValue( 'mdf_click_id_at', String( Date.now() ) );
      return fromCookie;
    }

    return '';
  }

  // -------------------------------------------------------------------------
  // Attribution flag — TS-gated LS check with cookie fallback
  // -------------------------------------------------------------------------

  function isAttributionValid() {
    var flag = getLocalValue( 'mdf_attributed' );
    var ts   = parseInt( getLocalValue( 'mdf_attributed_at' ) || '0', 10 );

    if ( flag === '1' && ts > 0 && ( Date.now() - ts ) < LS_TTL_MS ) {
      return true;
    }

    if ( getCookie( 'mdf_attributed' ) === '1' ) {
      setLocalValue( 'mdf_attributed', '1' );
      setLocalValue( 'mdf_attributed_at', String( Date.now() ) );
      return true;
    }

    return false;
  }

  // -------------------------------------------------------------------------
  // Rehydrate cookies from localStorage (Safari ITP recovery)
  // -------------------------------------------------------------------------

  function rehydrateContextCookies() {
    var i, entry, lsValue;
    for ( i = 0; i < CONTEXT_KEYS.length; i++ ) {
      entry = CONTEXT_KEYS[i];
      if ( getCookie( entry.cookie ) ) continue;
      lsValue = getLocalValue( entry.ls );
      if ( lsValue ) {
        setCookie( entry.cookie, lsValue, COOKIE_TTL_DAYS );
      }
    }
  }

  function getParam( params, key ) {
    return params.get( key ) || '';
  }

  // -------------------------------------------------------------------------
  // Main attribution logic
  // -------------------------------------------------------------------------

  function init() {
    var runtime = getRuntime();

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

    var referrerUrl   = document.referrer || '';
    var refParam      = getParam( params, 'ref' ) || getParam( params, 'landing_ref' );
    var isMdfUtm      = ( utmSource.indexOf( MDF_SOURCE ) !== -1 ) ||
                        ( utmMedium.indexOf( MDF_SOURCE ) !== -1 ) ||
                        ( utmCampaign.indexOf( MDF_SOURCE ) !== -1 );
    var isMdfRef      = refParam.indexOf( MDF_SOURCE ) !== -1;
    var isMdfReferrer = referrerUrl.indexOf( 'marques-de-france.fr' ) !== -1;
    var hasClickId    = clickId !== '';

    var isAttributed = isMdfUtm || isMdfRef || isMdfReferrer || hasClickId;

    var alreadyAttributed = isAttributionValid();

    if ( isAttributed && !alreadyAttributed ) {
      var landingUrl = window.location.href;

      setLocalValue( 'mdf_attributed',    '1' );
      setLocalValue( 'mdf_attributed_at', String( Date.now() ) );
      setCookie( 'mdf_attributed', '1', COOKIE_TTL_DAYS );

      if ( clickId ) {
        setLocalValue( 'mdf_click_id',    clickId );
        setLocalValue( 'mdf_click_id_at', String( Date.now() ) );
        setCookie( 'mdf_click_id', clickId, COOKIE_TTL_DAYS );
      }

      persistFirstTouch( 'mdf_utm_source',     'mdf_utm_source',     utmSource );
      persistFirstTouch( 'mdf_utm_medium',     'mdf_utm_medium',     utmMedium );
      persistFirstTouch( 'mdf_utm_campaign',   'mdf_utm_campaign',   utmCampaign );
      persistFirstTouch( 'mdf_utm_content',    'mdf_utm_content',    utmContent );
      persistFirstTouch( 'mdf_utm_term',       'mdf_utm_term',       utmTerm );
      persistFirstTouch( 'mdf_landing_site',   'mdf_landing_site',   landingUrl );
      persistFirstTouch( 'mdf_referring_site', 'mdf_referring_site', referrerUrl );
      persistFirstTouch( 'mdf_landing_ref',    'mdf_landing_ref',    refParam );

      stampSession( runtime, {
        mdf_attributed:     '1',
        mdf_utm_source:     utmSource,
        mdf_utm_medium:     utmMedium,
        mdf_utm_campaign:   utmCampaign,
        mdf_utm_content:    utmContent,
        mdf_utm_term:       utmTerm,
        mdf_landing_site:   landingUrl,
        mdf_referring_site: referrerUrl,
        mdf_landing_ref:    refParam,
        mdf_click_id:       clickId,
      } );

    } else if ( !isAttributed && alreadyAttributed ) {
      rehydrateContextCookies();

      stampSession( runtime, {
        mdf_attributed:     '1',
        mdf_utm_source:     getStoredValue( 'mdf_utm_source',     'mdf_utm_source' ),
        mdf_utm_medium:     getStoredValue( 'mdf_utm_medium',     'mdf_utm_medium' ),
        mdf_utm_campaign:   getStoredValue( 'mdf_utm_campaign',   'mdf_utm_campaign' ),
        mdf_utm_content:    getStoredValue( 'mdf_utm_content',    'mdf_utm_content' ),
        mdf_utm_term:       getStoredValue( 'mdf_utm_term',       'mdf_utm_term' ),
        mdf_landing_site:   getStoredValue( 'mdf_landing_site',   'mdf_landing_site' ),
        mdf_referring_site: getStoredValue( 'mdf_referring_site', 'mdf_referring_site' ),
        mdf_landing_ref:    getStoredValue( 'mdf_landing_ref',    'mdf_landing_ref' ),
        mdf_click_id:       getStoredClickId(),
      } );
    }
  }

  // -------------------------------------------------------------------------
  // AJAX session stamp — JSON POST to PS front controller
  // -------------------------------------------------------------------------

  function stampSession( runtime, data ) {
    fetch( runtime.ajaxUrl, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify( data ),
    } )
    .then( function ( res ) { return res.json(); } )
    .catch( function () {} );
  }

  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', init );
  } else {
    init();
  }
} )();
