( function ( $ ) {
  function box() { return $( '.webbership-ss-awb' ); }
  function orderId() { return box().data( 'order' ); }

  // Chosen override (set when the merchant picks a city/sector); threaded into re-estimate + issue.
  var override = { county_id: 0, city_id: 0, sector: '' };
  // Per-order sender (pickup point) choice; 0 = the settings default.
  var senderId = 0;

  // Shared .fail() so an expired nonce (403) or network error never leaves a
  // "Estimating…/Issuing…/Saving…" message frozen forever.
  function ajaxFail() { $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.requestFailed ); }

  // Package fields (weight/length/width/height): only sent when present and filled in.
  function addPackageData( data ) {
    $.each( { weight: '.webbership-ss-weight', length: '.webbership-ss-length', width: '.webbership-ss-width', height: '.webbership-ss-height' }, function ( key, sel ) {
      var $el = $( sel );
      if ( $el.length && $el.val() !== '' ) { data[ key ] = $el.val(); }
    } );
  }

  function runEstimate() {
    var $msg = $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.estimating );
    var data = { action: 'webbership_smartship_estimate', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId() };
    if ( override.county_id && override.city_id ) { data.county_id = override.county_id; data.city_id = override.city_id; }
    if ( override.sector ) { data.sector = override.sector; }
    if ( senderId ) { data.sender_id = senderId; }
    addPackageData( data );
    $.post( WebbershipSmartShip.ajax, data ).done( function ( r ) {
      if ( ! r.success ) { $msg.text( r.data && r.data.message ? r.data.message : WebbershipSmartShip.i18n.failed ); return; }
      renderSenderPicker( r.data.senders || [], r.data.sender_id || 0 );
      // No city resolved yet: show the picker, withhold couriers/Issue until re-estimate.
      // No county at all (foreign/blank state) → no picker to show, say so plainly.
      if ( r.data.needs_city ) {
        $( '.webbership-ss-couriers' ).empty();
        maybeRenderCityPicker( r.data.resolved );
        $msg.text( r.data.resolved && r.data.resolved.county_id ? WebbershipSmartShip.i18n.pickCityReest : WebbershipSmartShip.i18n.noCounty );
        return;
      }
      // City resolved (Bucuresti) but the sector couldn't be parsed from the address.
      if ( r.data.needs_sector ) {
        $( '.webbership-ss-couriers' ).empty();
        maybeRenderSectorPicker();
        $msg.text( WebbershipSmartShip.i18n.pickSector );
        return;
      }
      $msg.text( '' );
      renderCouriers( r.data.costs || [] );
      maybeRenderCityPicker( r.data.resolved );
    } ).fail( ajaxFail );
  }

  // Sender picker: only shown when the SmartShip account has more than one pickup point.
  function renderSenderPicker( senders, chosen ) {
    var $wrap = $( '.webbership-ss-sender' );
    if ( ! $wrap.length || senders.length < 2 ) { return; }
    var $sel = $( '<select class="webbership-ss-sender-select"/>' );
    senders.forEach( function ( s ) {
      // s.label is an API string → insert as text only, never as HTML.
      $sel.append( $( '<option/>' ).val( s.id ).text( s.label ).prop( 'selected', s.id === chosen ) );
    } );
    $wrap.empty().append( $( '<label/>' ).text( WebbershipSmartShip.i18n.sender + ' ' ).append( $sel ) );
  }

  $( document ).on( 'change', '.webbership-ss-sender-select', function () {
    senderId = parseInt( $( this ).val(), 10 ) || 0;
    // New origin → the prior quotes are stale; re-estimate with the chosen sender.
    $( '.webbership-ss-couriers' ).empty();
    runEstimate();
  } );

  function renderCouriers( costs ) {
    var $c = $( '.webbership-ss-couriers' ).empty();
    costs.forEach( function ( c ) {
      var id    = 'ss-c-' + c.courier_id;
      var label = c.courier_name + ' — ' + c.cost + ' lei' + ( c.delivery_date ? ' (' + c.delivery_date + ')' : '' );
      $c.append( $( '<label/>' ).append(
        $( '<input type="radio" name="ss_courier"/>' ).val( c.courier_id ).attr( 'id', id ),
        ' ', document.createTextNode( label ), '<br/>'
      ) );
    } );
    $c.append( $( '<button type="button" class="button button-primary webbership-ss-issue">' ).text( WebbershipSmartShip.i18n.issueAwb ) );
  }

  // Resolver wasn't confident: let the merchant pick the city for the resolved county.
  function maybeRenderCityPicker( resolved ) {
    if ( ! resolved || resolved.confident !== false || ! resolved.county_id ) { return; }
    $( '.webbership-ss-sector-picker' ).remove();
    var $wrap = $( '.webbership-ss-city-picker' );
    if ( ! $wrap.length ) {
      $wrap = $( '<div class="webbership-ss-city-picker"/>' );
      box().append( $wrap );
    }
    $wrap.empty();
    $wrap.append( $( '<p/>' ).text( WebbershipSmartShip.i18n.cantMatchCity ) );
    var $sel = $( '<select class="webbership-ss-city"/>' );
    // Placeholder so no real city is pre-selected: the merchant must pick the
    // correct one explicitly (auto-selecting the first city could be wrong), and
    // override.city_id stays 0 until a real choice fires `change`.
    $sel.append( $( '<option/>' ).val( '' ).prop( 'disabled', true ).prop( 'selected', true ).text( WebbershipSmartShip.i18n.selectCity ) );
    $wrap.append( $sel );
    $wrap.append( $( '<button type="button" class="button webbership-ss-reestimate">' ).text( WebbershipSmartShip.i18n.reestimate ) );
    $.post( WebbershipSmartShip.ajax, {
      action: 'webbership_smartship_cities', _ajax_nonce: WebbershipSmartShip.nonce,
      order_id: orderId(), county_id: resolved.county_id
    } ).done( function ( r ) {
      if ( ! r.success ) { return; }
      ( r.data.cities || [] ).forEach( function ( city ) {
        // city.city is an API string → insert as text only, never as HTML.
        $sel.append( $( '<option/>' ).val( city.id ).text( city.city ) );
      } );
    } ).fail( ajaxFail );
    // Remember the county so the chosen city is paired with it.
    $wrap.data( 'county', resolved.county_id );
    // A picker is shown → the current dropdown selection always wins, even if
    // the merchant clicks Issue without re-estimating. Seed the county now and
    // keep city_id in sync on every change.
    override = { county_id: resolved.county_id, city_id: parseInt( $sel.val(), 10 ) || 0, sector: '' };
  }

  // City resolved (Bucuresti) but the sector couldn't be parsed from the address —
  // let the merchant pick it. No AJAX call needed: sectors are a fixed 1-6.
  function maybeRenderSectorPicker() {
    $( '.webbership-ss-city-picker' ).remove();
    var $wrap = $( '.webbership-ss-sector-picker' );
    if ( ! $wrap.length ) {
      $wrap = $( '<div class="webbership-ss-sector-picker"/>' );
      box().append( $wrap );
    }
    $wrap.empty();
    var $sel = $( '<select class="webbership-ss-sector"/>' );
    $sel.append( $( '<option/>' ).val( '' ).prop( 'disabled', true ).prop( 'selected', true ).text( WebbershipSmartShip.i18n.selectSector ) );
    for ( var n = 1; n <= 6; n++ ) { $sel.append( $( '<option/>' ).val( n ).text( 'Sector ' + n ) ); }
    $wrap.append( $sel );
    $wrap.append( $( '<button type="button" class="button webbership-ss-sector-reestimate">' ).text( WebbershipSmartShip.i18n.reestimate ) );
    override.sector = '';
  }

  $( document ).on( 'change', '.webbership-ss-city', function () {
    override.city_id = parseInt( $( this ).val(), 10 ) || 0;
    // City changed → the prior estimate's couriers (and Issue button) are stale for
    // the new destination; clear them so the merchant must Re-estimate before issuing.
    $( '.webbership-ss-couriers' ).empty();
    $( '.webbership-ss-msg' ).text( override.city_id ? WebbershipSmartShip.i18n.cityChanged : '' );
  } );

  // Package changed (preset picked or a field hand-edited) → the prior estimate's
  // couriers (and Issue button) are stale for the new weight/dims.
  function packageChanged() {
    $( '.webbership-ss-couriers' ).empty();
    $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.packageChanged );
  }

  $( document ).on( 'change', '.webbership-ss-box-preset', function () {
    var $opt = $( this ).find( ':selected' );
    if ( $opt.val() === '' ) { return; }
    $( '.webbership-ss-length' ).val( $opt.data( 'l' ) );
    $( '.webbership-ss-width' ).val( $opt.data( 'w' ) );
    $( '.webbership-ss-height' ).val( $opt.data( 'h' ) );
    var $weight = $( '.webbership-ss-weight' );
    var base    = parseFloat( $weight.data( 'base' ) ) || 0;
    var boxKg   = parseFloat( $opt.data( 'kg' ) ) || 0;
    $weight.val( ( base + boxKg ).toFixed( 2 ) );
    packageChanged();
  } );

  $( document ).on( 'input', '.webbership-ss-weight, .webbership-ss-length, .webbership-ss-width, .webbership-ss-height', packageChanged );

  $( document ).on( 'change', '.webbership-ss-sector', function () {
    override.sector = $( this ).val() || '';
    // Sector changed → the prior estimate's couriers (and Issue button) are stale;
    // clear them so the merchant must Re-estimate before issuing.
    $( '.webbership-ss-couriers' ).empty();
  } );

  $( document ).on( 'click', '.webbership-ss-estimate', function () {
    override = { county_id: 0, city_id: 0, sector: '' };
    runEstimate();
  } );

  $( document ).on( 'click', '.webbership-ss-reestimate', function () {
    var $wrap = $( '.webbership-ss-city-picker' );
    var city  = $wrap.find( '.webbership-ss-city' ).val();
    if ( ! city ) { $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.pickCity ); return; }
    override = { county_id: $wrap.data( 'county' ), city_id: city, sector: '' };
    runEstimate();
  } );

  $( document ).on( 'click', '.webbership-ss-sector-reestimate', function () {
    var sector = $( '.webbership-ss-sector' ).val();
    if ( ! sector ) { $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.pickSector ); return; }
    override.sector = sector;
    runEstimate();
  } );

  $( document ).on( 'click', '.webbership-ss-issue', function () {
    var courier = $( 'input[name=ss_courier]:checked' ).val();
    if ( ! courier ) { $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.pickCourier ); return; }
    // A city picker shown but no city chosen → don't issue with an unresolved address.
    if ( $( '.webbership-ss-city' ).length && ! override.city_id ) {
      $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.selectCityFirst ); return;
    }
    // A sector picker shown but no sector chosen → don't issue with an unresolved Bucharest address.
    if ( $( '.webbership-ss-sector' ).length && ! override.sector ) {
      $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.selectSectorFirst ); return;
    }
    $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.issuing );
    var data = { action: 'webbership_smartship_issue', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId(), courier_id: courier };
    if ( override.county_id && override.city_id ) { data.county_id = override.county_id; data.city_id = override.city_id; }
    if ( override.sector ) { data.sector = override.sector; }
    if ( senderId ) { data.sender_id = senderId; }
    addPackageData( data );
    $.post( WebbershipSmartShip.ajax, data ).done( function ( r ) {
      if ( ! r.success ) { $( '.webbership-ss-msg' ).text( r.data && r.data.message ? r.data.message : WebbershipSmartShip.i18n.failed ); return; }
      window.location.reload();
    } ).fail( ajaxFail );
  } );

  $( document ).on( 'click', '.webbership-ss-cancel', function () {
    if ( ! window.confirm( WebbershipSmartShip.i18n.cancelConfirm ) ) { return; }
    $.post( WebbershipSmartShip.ajax, { action: 'webbership_smartship_cancel', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId() } )
      .done( function ( r ) { if ( r.success ) { window.location.reload(); } else { alert( r.data && r.data.message ); } } )
      .fail( function () { alert( WebbershipSmartShip.i18n.requestFailed ); } );
  } );
  $( document ).on( 'click', '.webbership-ss-track', function () {
    var $t = $( '.webbership-ss-tracking' ).text( WebbershipSmartShip.i18n.loading );
    $.post( WebbershipSmartShip.ajax, { action: 'webbership_smartship_status', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId() } )
      .done( function ( r ) { $t.text( r.success ? JSON.stringify( r.data.history || r.data ) : ( r.data && r.data.message ) ); } )
      .fail( function () { $t.text( WebbershipSmartShip.i18n.requestFailed ); } );
  } );

  // EasyBox hand-off: copy the recipient block for the SmartShip form.
  $( document ).on( 'click', '.webbership-ss-easybox-copy', function () {
    var $b   = $( this );
    var txt  = $b.data( 'recipient' );
    var orig = $b.text();
    var done = function () { $b.text( WebbershipSmartShip.i18n.copied ); setTimeout( function () { $b.text( orig ); }, 1500 ); };
    // Only confirm after a genuinely successful copy; otherwise try the textarea fallback.
    var fallback = function () {
      var $ta = $( '<textarea/>' ).val( txt ).appendTo( 'body' ).select();
      var copied = false;
      try { copied = document.execCommand( 'copy' ); } catch ( e ) {}
      $ta.remove();
      if ( copied ) { done(); }
    };
    if ( navigator.clipboard && navigator.clipboard.writeText ) {
      navigator.clipboard.writeText( txt ).then( done, fallback );
    } else {
      fallback();
    }
  } );

  // EasyBox hand-off: paste the manually-created AWB back onto the order.
  $( document ).on( 'click', '.webbership-ss-easybox-save', function () {
    var awb = $.trim( $( '.webbership-ss-easybox-awb' ).val() );
    if ( ! awb ) { $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.enterAwb ); return; }
    $( '.webbership-ss-msg' ).text( WebbershipSmartShip.i18n.saving );
    $.post( WebbershipSmartShip.ajax, { action: 'webbership_smartship_set_awb', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId(), awb: awb } )
      .done( function ( r ) {
        if ( r.success ) { window.location.reload(); return; }
        $( '.webbership-ss-msg' ).text( r.data && r.data.message ? r.data.message : WebbershipSmartShip.i18n.failed );
      } )
      .fail( ajaxFail );
  } );
} )( jQuery );
