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

  // Inline magnifier (no emoji icons; inherits currentColor).
  var SEARCH_ICON = '<svg class="webbership-ss-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';

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
  var focusedId = null;   // locker previewed via its map pin (not yet committed)

  function dist2( l, c ) {
    if ( ! l.lat || ! l.lng ) { return Infinity; }
    var dx = l.lat - c[0], dy = l.lng - c[1];
    return dx * dx + dy * dy;
  }
  // Sort the list/markers around what the map is currently centered on, falling
  // back to the derived start center before the map exists.
  function center() {
    if ( map ) { var c = map.getCenter(); return [ c.lat, c.lng ]; }
    return startCenter().center;
  }
  function byDistance( items ) {
    var c = center();
    return items.slice().sort( function ( a, b ) { return dist2( a, c ) - dist2( b, c ); } );
  }
  // Cluster shows every search match; panning the map never hides a matched pin.
  function updateMarkers( items ) {
    if ( ! cluster ) { return; }
    cluster.clearLayers();
    items.forEach( function ( l ) { var m = markersById[ l.id ]; if ( m ) { cluster.addLayer( m ); } } );
  }
  function currentQuery() {
    return ( $( '#webbership-ss-search' ).val() || '' ).trim().toLowerCase();
  }
  function inBounds( l ) {
    if ( ! map || ! l.lat || ! l.lng ) { return true; }
    return map.getBounds().contains( [ l.lat, l.lng ] );
  }
  // The list mirrors what the map is showing: markers reflect the search, and the
  // list is the matched lockers inside the current viewport, nearest-center first.
  // Pan/zoom the map → the list follows.
  function showResults() {
    var matched = currentQuery() ? filterLockers( currentQuery() ) : lockers;
    updateMarkers( matched );
    var inView = byDistance( matched.filter( inBounds ) );
    renderList( inView.slice( 0, LIST_MAX ), inView.length );
  }
  // Frame the map around the search matches; the resulting moveend refreshes the list.
  function fitToMatched( matched ) {
    if ( ! map ) { return; }
    var pts = matched.filter( function ( l ) { return l.lat && l.lng; } )
                     .map( function ( l ) { return [ l.lat, l.lng ]; } );
    if ( pts.length === 1 ) { map.setView( pts[ 0 ], Math.max( map.getZoom(), 13 ) ); }
    else if ( pts.length ) { map.fitBounds( pts, { maxZoom: 14, padding: [ 24, 24 ] } ); }
  }
  function applySearch() {
    var q = currentQuery();
    var matched = q ? filterLockers( q ) : lockers;
    if ( q && matched.length ) { fitToMatched( matched ); }
    showResults();
  }

  function renderMapAndList() {
    if ( ! lockers.length ) { renderEmpty(); return; }

    var $b = $box().empty();
    var chooseLbl = esc( i18n.choose || 'Choose an EasyBox locker' );
    $b.append(
      '<div class="webbership-ss-confirm" role="status" hidden></div>' +
      '<label class="webbership-ss-search-label" for="webbership-ss-search">' + chooseLbl + '</label>' +
      '<div class="webbership-ss-search-wrap">' + SEARCH_ICON +
      '<input type="search" id="webbership-ss-search" class="webbership-ss-search" autocomplete="off" placeholder="' + esc( i18n.search || '' ) + '">' +
      '</div>' +
      '<div class="webbership-ss-live" aria-live="polite"></div>' +
      '<div class="webbership-ss-layout">' +
      '<div class="webbership-ss-map" id="webbership-ss-map"></div>' +
      '<ul class="webbership-ss-list" id="webbership-ss-list" role="listbox" aria-label="' + chooseLbl + '"></ul>' +
      '</div>'
    );

    initMap();
    showResults();
    built = true;

    // Re-apply a prior choice first (the review-table refresh blanked the hidden
    // field), else pre-select the saved preferred locker (logged-in customers).
    var preset = selected ? findById( selected.id ) : ( W.preferred && W.preferred.id ? findById( W.preferred.id ) : null );
    if ( preset ) { selectLocker( preset, true ); }

    $b.on( 'input', '#webbership-ss-search', debounce( applySearch, 250 ) );
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
      m._lockerId = l.id;
      markersById[ l.id ] = m;
      cluster.addLayer( m );
    } );
    map.addLayer( cluster );

    // Opening a pin previews it in the list (scroll + highlight) without committing;
    // the commit happens via the popup's Select button or a list-row click.
    map.on( 'popupopen', function ( e ) {
      var src = e.popup && e.popup._source;
      if ( src && src._lockerId != null ) {
        var l = findById( src._lockerId );
        if ( l ) { focusLocker( l ); }
      }
    } );
    // The list mirrors the map: re-derive it whenever the viewport settles.
    map.on( 'moveend', showResults );
    // Commit the chosen locker straight from its pin popup.
    $( '#webbership-ss-map' ).on( 'click', '.webbership-ss-popup-select', function () {
      var l = findById( $( this ).attr( 'data-id' ) );
      if ( l ) { selectLocker( l, false ); }
    } );

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
      if ( focusedId != null && String( focusedId ) === String( l.id ) ) {
        $li.addClass( 'is-focused' );
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
    focusedId = null;   // a commit supersedes any pin preview

    // List highlight (re-mark currently rendered rows; the row may not be in the
    // filtered list, that's fine — the hidden field still holds the choice).
    $( '.webbership-ss-row' ).removeClass( 'is-selected is-focused' ).attr( 'aria-selected', 'false' );
    var $row = rowById( l.id );
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

    renderConfirm( l );
    $( '.webbership-ss-live' ).text( ( i18n.selected || 'Selected' ) + ': ' + l.name + ', ' + l.city );

    // Write the snapshot the checkout submits + notify WooCommerce.
    var snap = { id: l.id, name: l.name, city: l.city, address: l.address, lat: l.lat, lng: l.lng };
    $field().val( JSON.stringify( snap ) ).trigger( 'change' );

    scrollRowIntoView( $row );
  }

  // Preview a locker from its map pin: scroll its row into view + highlight, but
  // don't commit (commit is the popup Select button or a row click).
  function focusLocker( l ) {
    focusedId = l.id;
    $( '.webbership-ss-row' ).removeClass( 'is-focused' );
    scrollRowIntoView( rowById( l.id ).addClass( 'is-focused' ) );
  }

  // Persistent, sighted confirmation of the committed locker (the aria-live region
  // only speaks to screen readers).
  function renderConfirm( l ) {
    var $c = $( '.webbership-ss-confirm' );
    if ( ! $c.length ) { return; }
    if ( ! l ) { $c.prop( 'hidden', true ).empty(); return; }
    $c.prop( 'hidden', false ).empty().append(
      $( '<span class="webbership-ss-confirm-check" aria-hidden="true">✓</span>' ),
      $( '<span class="webbership-ss-confirm-text"/>' ).append(
        $( '<span class="webbership-ss-confirm-label"/>' ).text( i18n.selected || 'Selected' ),
        $( '<strong/>' ).text( l.name ),
        $( '<span/>' ).text( l.city )
      )
    );
  }

  function rowById( id ) {
    return $( '.webbership-ss-row' ).filter( function () {
      return String( $( this ).attr( 'data-id' ) ) === String( id );
    } );
  }

  function scrollRowIntoView( $row ) {
    var el = $row.get( 0 );
    if ( el && el.scrollIntoView ) { el.scrollIntoView( { block: 'nearest' } ); }
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
    return '<div class="webbership-ss-popup">' +
      '<strong>' + esc( l.name ) + '</strong>' +
      '<span>' + esc( l.address ) + '</span>' +
      '<span>' + esc( l.city ) + '</span>' +
      '<button type="button" class="button webbership-ss-popup-select" data-id="' + esc( l.id ) + '">' +
      esc( i18n.select || 'Select this locker' ) + '</button>' +
      '</div>';
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
