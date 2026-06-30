/**
 * EasyBox checkout locker picker.
 *
 * Lazy: loads Leaflet + markercluster only when the EasyBox shipping method is the
 * chosen rate. Shows a clustered map + a searchable list kept in sync. The LIST is
 * the primary, keyboard/screen-reader-accessible path; the map is the visual aid.
 *
 * Writes the chosen locker (snapshot) into #webbership_ss_locker as JSON.
 */
( function ( $ ) {
  'use strict';

  var W = window.WebbershipEasyBox || {};
  var i18n = W.i18n || {};

  // Module state (one picker per page).
  var lockers = null;       // loaded + cached locker array (null until fetched)
  var leafletReady = null;  // promise, set once we start loading Leaflet
  var map = null;
  var cluster = null;
  var markersById = {};     // locker id -> Leaflet marker
  var selected = null;      // chosen locker object
  var built = false;        // map + list DOM built
  var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

  // Romania bounding center, used when we can't derive the customer's city.
  var RO_CENTER = [ 45.9432, 24.9668 ];
  var RO_ZOOM = 7;

  function $box() { return $( '.webbership-ss-easybox' ); }
  function $field() { return $( '#webbership_ss_locker' ); }

  // --- selection detection -------------------------------------------------

  // EasyBox is chosen when the selected shipping rate id starts with the method id.
  // Rate ids look like "webbership_smartship_easybox:3" (method:instance).
  function easyboxChosen() {
    var id = W.methodId;
    if ( ! id ) { return false; }
    var hit = false;
    // Radio/hidden inputs that carry the chosen rate id.
    $( 'input.shipping_method:checked, #shipping_method input:checked' ).each( function () {
      if ( this.value === id || String( this.value ).indexOf( id + ':' ) === 0 ) { hit = true; }
    } );
    // Single-method checkouts render a hidden input (not a radio) with no :checked.
    if ( ! hit ) {
      $( 'input.shipping_method[type="hidden"]' ).each( function () {
        if ( this.value === id || String( this.value ).indexOf( id + ':' ) === 0 ) { hit = true; }
      } );
    }
    return hit;
  }

  function onShippingChange() {
    if ( easyboxChosen() ) { showPicker(); } else { hidePicker(); }
  }

  function hidePicker() {
    // Hide the whole review-table row, not just the inner div, so a non-EasyBox
    // checkout doesn't get an empty padded row.
    $box().prop( 'hidden', true ).closest( '.webbership-ss-easybox-row' ).prop( 'hidden', true );
  }

  function showPicker() {
    var $b = $box();
    if ( ! $b.length ) { return; }
    $b.prop( 'hidden', false ).closest( '.webbership-ss-easybox-row' ).prop( 'hidden', false );
    // `updated_checkout` re-renders the review table, replacing our container with
    // a fresh empty one — so trust the live DOM, not the `built` flag. Re-render
    // only when the map container is actually gone.
    if ( built && document.getElementById( 'webbership-ss-map' ) ) { return; }
    map = null;
    cluster = null;
    markersById = {};
    built = false;
    renderLoading();
    loadLeaflet()
      .then( loadLockers )
      .then( renderMapAndList )
      .catch( function () { renderError(); } );
  }

  // --- lazy Leaflet loader -------------------------------------------------

  function injectCss( href ) {
    if ( document.querySelector( 'link[href="' + href + '"]' ) ) { return; }
    var l = document.createElement( 'link' );
    l.rel = 'stylesheet';
    l.href = href;
    document.head.appendChild( l );
  }

  function injectScript( src ) {
    return new Promise( function ( resolve, reject ) {
      if ( document.querySelector( 'script[src="' + src + '"]' ) ) { resolve(); return; }
      var s = document.createElement( 'script' );
      s.src = src;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject( new Error( 'script load failed: ' + src ) ); };
      document.head.appendChild( s );
    } );
  }

  function loadLeaflet() {
    if ( leafletReady ) { return leafletReady; }
    var lf = W.leaflet || {};
    ( lf.css || [] ).forEach( injectCss );
    // Leaflet core first, then markercluster (which extends L).
    leafletReady = injectScript( lf.js )
      .then( function () { return lf.cluster ? injectScript( lf.cluster ) : null; } );
    return leafletReady;
  }

  // --- locker data ---------------------------------------------------------

  function loadLockers() {
    if ( lockers ) { return Promise.resolve( lockers ); }
    return new Promise( function ( resolve, reject ) {
      $.ajax( {
        url: W.ajaxUrl,
        method: 'GET',
        data: { action: W.action },
        dataType: 'json'
      } ).done( function ( res ) {
        if ( res && res.success && res.data && Array.isArray( res.data.lockers ) ) {
          lockers = res.data.lockers;
          resolve( lockers );
        } else {
          reject( new Error( 'bad response' ) );
        }
      } ).fail( function () { reject( new Error( 'ajax failed' ) ); } );
    } );
  }

  // --- rendering -----------------------------------------------------------

  function renderLoading() {
    $box().html(
      '<div class="webbership-ss-state" role="status">' +
      '<span class="webbership-ss-spinner" aria-hidden="true"></span> ' +
      esc( i18n.loading || 'Loading lockers…' ) +
      '</div>'
    );
  }

  function renderError() {
    var $b = $box().empty();
    var $wrap = $( '<div class="webbership-ss-state webbership-ss-error" role="alert"></div>' );
    $wrap.append( $( '<p/>' ).text( i18n.error || "Couldn't load lockers." ) );
    var $retry = $( '<button type="button" class="button webbership-ss-retry"></button>' ).text( i18n.retry || 'Retry' );
    $retry.on( 'click', function () {
      leafletReady = null; // allow re-attempt of script load
      built = false;
      renderLoading();
      loadLeaflet().then( loadLockers ).then( renderMapAndList ).catch( renderError );
    } );
    $wrap.append( $retry );
    $b.append( $wrap );
  }

  // Derive a starting center from the checkout city field, else Romania.
  function startCenter() {
    var city = ( $( '#shipping_city' ).val() || $( '#billing_city' ).val() || '' ).trim().toLowerCase();
    if ( city && lockers ) {
      for ( var i = 0; i < lockers.length; i++ ) {
        if ( String( lockers[ i ].city ).toLowerCase() === city && lockers[ i ].lat && lockers[ i ].lng ) {
          return { center: [ lockers[ i ].lat, lockers[ i ].lng ], zoom: 12 };
        }
      }
    }
    return { center: RO_CENTER, zoom: RO_ZOOM };
  }

  var LIST_MAX = 60;

  function dist2( l, c ) {
    if ( ! l.lat || ! l.lng ) { return Infinity; }
    var dx = l.lat - c[0], dy = l.lng - c[1];
    return dx * dx + dy * dy;
  }
  // Nearest-first (squared lat/lng distance to the start center) so the capped list is useful.
  function byDistance( items ) {
    var c = startCenter().center;
    return items.slice().sort( function ( a, b ) { return dist2( a, c ) - dist2( b, c ); } );
  }
  // Sync the map cluster to a subset so a search narrows map + list together.
  function updateMarkers( items ) {
    if ( ! cluster ) { return; }
    cluster.clearLayers();
    items.forEach( function ( l ) { var m = markersById[ l.id ]; if ( m ) { cluster.addLayer( m ); } } );
  }
  // Render the capped, nearest-first list AND narrow the map to the current query.
  function showResults( q ) {
    var matched = q ? filterLockers( q ) : lockers;
    var sorted  = byDistance( matched );
    renderList( sorted.slice( 0, LIST_MAX ), sorted.length );
    updateMarkers( matched );
  }

  function renderMapAndList() {
    if ( ! lockers.length ) { renderEmpty(); return; }

    var $b = $box().empty();
    $b.append(
      '<label class="webbership-ss-search-label" for="webbership-ss-search">' + esc( i18n.choose || 'Choose an EasyBox locker' ) + '</label>' +
      '<input type="text" id="webbership-ss-search" class="webbership-ss-search" autocomplete="off" placeholder="' + esc( i18n.search || '' ) + '">' +
      '<div class="webbership-ss-live" aria-live="polite"></div>' +
      '<div class="webbership-ss-layout">' +
      '<div class="webbership-ss-map" id="webbership-ss-map"></div>' +
      '<ul class="webbership-ss-list" id="webbership-ss-list" role="listbox" aria-label="' + esc( i18n.choose || 'Choose an EasyBox locker' ) + '"></ul>' +
      '</div>'
    );

    initMap();
    showResults( '' );
    built = true;

    // Re-apply a prior choice first (the review-table refresh blanked the hidden
    // field), else pre-select the saved preferred locker (logged-in customers).
    var preset = selected ? findById( selected.id ) : ( W.preferred && W.preferred.id ? findById( W.preferred.id ) : null );
    if ( preset ) { selectLocker( preset, true ); }

    var debounced = debounce( function () {
      var q = $( '#webbership-ss-search' ).val().trim().toLowerCase();
      showResults( q );
    }, 250 );
    $b.on( 'input', '#webbership-ss-search', debounced );
  }

  function renderEmpty() {
    $box().html( '<div class="webbership-ss-state" role="status">' + esc( i18n.empty || 'No lockers found.' ) + '</div>' );
  }

  function initMap() {
    var L = window.L;
    var s = startCenter();
    map = L.map( 'webbership-ss-map', { scrollWheelZoom: false } ).setView( s.center, s.zoom );
    L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    } ).addTo( map );

    cluster = L.markerClusterGroup ? L.markerClusterGroup() : L.layerGroup();
    markersById = {};
    lockers.forEach( function ( l ) {
      if ( ! l.lat || ! l.lng ) { return; }
      var m = L.marker( [ l.lat, l.lng ] );
      m.bindPopup( popupHtml( l ) );
      m.on( 'click', function () { selectLocker( l, false ); } );
      markersById[ l.id ] = m;
      cluster.addLayer( m );
    } );
    map.addLayer( cluster );
    // Recalculate size once the container is visible (it was hidden on init).
    setTimeout( function () { if ( map ) { map.invalidateSize(); } }, 0 );
  }

  function renderList( items, total ) {
    var $list = $( '#webbership-ss-list' ).empty();
    if ( ! items.length ) {
      $list.append( '<li class="webbership-ss-empty">' + esc( i18n.empty || 'No lockers found.' ) + '</li>' );
      return;
    }
    items.forEach( function ( l ) {
      var $li = $( '<li/>', {
        'class': 'webbership-ss-row',
        'role': 'option',
        'tabindex': '0',
        'data-id': l.id
      } );
      $li.append( $( '<span class="webbership-ss-check" aria-hidden="true">✓</span>' ) );
      $li.append(
        $( '<span class="webbership-ss-row-text"/>' ).append(
          $( '<strong/>' ).text( l.name ),
          $( '<span class="webbership-ss-row-addr"/>' ).text( l.address ),
          $( '<span class="webbership-ss-row-city"/>' ).text( l.city )
        )
      );
      if ( selected && selected.id === l.id ) {
        $li.addClass( 'is-selected' ).attr( 'aria-selected', 'true' );
      } else {
        $li.attr( 'aria-selected', 'false' );
      }
      $li.on( 'click', function () { selectLocker( l, false ); } );
      $li.on( 'keydown', function ( e ) {
        if ( e.key === 'Enter' || e.key === ' ' || e.keyCode === 13 || e.keyCode === 32 ) {
          e.preventDefault();
          selectLocker( l, false );
        }
      } );
      $list.append( $li );
    } );
    if ( total && total > items.length ) {
      $list.append( $( '<li class="webbership-ss-more" aria-hidden="true"/>' ).text( i18n.more || 'Type to narrow the list…' ) );
    }
  }

  function filterLockers( q ) {
    if ( ! q ) { return lockers; }
    return lockers.filter( function ( l ) {
      return ( String( l.name ) + ' ' + String( l.city ) + ' ' + String( l.address ) )
        .toLowerCase().indexOf( q ) !== -1;
    } );
  }

  // --- selection -----------------------------------------------------------

  function selectLocker( l, isPreset ) {
    selected = l;

    // List highlight (re-mark currently rendered rows; row may not be in the
    // filtered list, that's fine — the hidden field still holds the choice).
    $( '.webbership-ss-row' ).removeClass( 'is-selected' ).attr( 'aria-selected', 'false' );
    var $row = $( '.webbership-ss-row' ).filter( function () { return String( $( this ).attr( 'data-id' ) ) === String( l.id ); } );
    $row.addClass( 'is-selected' ).attr( 'aria-selected', 'true' );

    // Map: open the marker popup, center on it.
    var m = markersById[ l.id ];
    if ( m && map ) {
      if ( cluster && cluster.zoomToShowLayer ) {
        cluster.zoomToShowLayer( m, function () { m.openPopup(); } );
      } else {
        m.openPopup();
      }
      if ( reduceMotion ) { map.setView( [ l.lat, l.lng ], Math.max( map.getZoom(), 13 ) ); }
      else { map.flyTo( [ l.lat, l.lng ], Math.max( map.getZoom(), 13 ) ); }
    }

    // aria-live announcement.
    $( '.webbership-ss-live' ).text( ( i18n.selected || 'Selected' ) + ': ' + l.name + ', ' + l.city );

    // Write the snapshot the checkout submits + notify WooCommerce.
    var snap = { id: l.id, name: l.name, city: l.city, address: l.address, lat: l.lat, lng: l.lng };
    $field().val( JSON.stringify( snap ) ).trigger( 'change' );

    if ( isPreset && $row.length ) {
      var el = $row.get( 0 );
      if ( el && el.scrollIntoView ) { el.scrollIntoView( { block: 'nearest' } ); }
    }
  }

  // --- helpers -------------------------------------------------------------

  function findById( id ) {
    if ( ! lockers ) { return null; }
    id = parseInt( id, 10 );
    for ( var i = 0; i < lockers.length; i++ ) {
      if ( parseInt( lockers[ i ].id, 10 ) === id ) { return lockers[ i ]; }
    }
    return null;
  }

  function popupHtml( l ) {
    return '<strong>' + esc( l.name ) + '</strong><br>' + esc( l.address ) + '<br>' + esc( l.city );
  }

  function esc( s ) {
    return String( s == null ? '' : s )
      .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
      .replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
  }

  function debounce( fn, ms ) {
    var t;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout( t );
      t = setTimeout( function () { fn.apply( ctx, args ); }, ms );
    };
  }

  // --- bindings ------------------------------------------------------------

  $( document.body ).on( 'change', 'input.shipping_method', onShippingChange );
  // updated_checkout re-renders the review area (and our placeholder); re-check.
  $( document.body ).on( 'updated_checkout', onShippingChange );
  $( onShippingChange );

} )( jQuery );
