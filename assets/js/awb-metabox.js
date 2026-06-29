( function ( $ ) {
  function box() { return $( '.webbership-ss-awb' ); }
  function orderId() { return box().data( 'order' ); }

  // Chosen override (set when the merchant picks a city); threaded into re-estimate + issue.
  var override = { county_id: 0, city_id: 0 };

  function runEstimate() {
    var $msg = $( '.webbership-ss-msg' ).text( 'Estimating…' );
    var data = { action: 'webbership_smartship_estimate', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId() };
    if ( override.county_id && override.city_id ) { data.county_id = override.county_id; data.city_id = override.city_id; }
    $.post( WebbershipSmartShip.ajax, data ).done( function ( r ) {
      if ( ! r.success ) { $msg.text( r.data && r.data.message ? r.data.message : 'Failed' ); return; }
      // No city resolved yet: show the picker, withhold couriers/Issue until re-estimate.
      if ( r.data.needs_city ) {
        $( '.webbership-ss-couriers' ).empty();
        maybeRenderCityPicker( r.data.resolved );
        $msg.text( 'Pick the destination city, then Re-estimate.' );
        return;
      }
      $msg.text( '' );
      renderCouriers( r.data.costs || [] );
      maybeRenderCityPicker( r.data.resolved );
    } );
  }

  function renderCouriers( costs ) {
    var $c = $( '.webbership-ss-couriers' ).empty();
    costs.forEach( function ( c ) {
      var id = 'ss-c-' + c.courier_id;
      $c.append( $( '<label/>' ).append(
        $( '<input type="radio" name="ss_courier"/>' ).val( c.courier_id ).attr( 'id', id ),
        ' ', document.createTextNode( c.courier_name + ' — ' + c.cost + ' lei (' + ( c.delivery_date || '' ) + ')' ), '<br/>'
      ) );
    } );
    $c.append( $( '<button type="button" class="button button-primary webbership-ss-issue">' ).text( 'Issue AWB' ) );
  }

  // Resolver wasn't confident: let the merchant pick the city for the resolved county.
  function maybeRenderCityPicker( resolved ) {
    if ( ! resolved || resolved.confident !== false || ! resolved.county_id ) { return; }
    var $wrap = $( '.webbership-ss-city-picker' );
    if ( ! $wrap.length ) {
      $wrap = $( '<div class="webbership-ss-city-picker"/>' );
      box().append( $wrap );
    }
    $wrap.empty();
    $wrap.append( $( '<p/>' ).text( "Couldn't match the city — pick it:" ) );
    var $sel = $( '<select class="webbership-ss-city"/>' );
    // Placeholder so no real city is pre-selected: the merchant must pick the
    // correct one explicitly (auto-selecting the first city could be wrong), and
    // override.city_id stays 0 until a real choice fires `change`.
    $sel.append( $( '<option/>' ).val( '' ).prop( 'disabled', true ).prop( 'selected', true ).text( '— Select city —' ) );
    $wrap.append( $sel );
    $wrap.append( $( '<button type="button" class="button webbership-ss-reestimate">' ).text( 'Re-estimate' ) );
    $.post( WebbershipSmartShip.ajax, {
      action: 'webbership_smartship_cities', _ajax_nonce: WebbershipSmartShip.nonce,
      order_id: orderId(), county_id: resolved.county_id
    } ).done( function ( r ) {
      if ( ! r.success ) { return; }
      ( r.data.cities || [] ).forEach( function ( city ) {
        // city.city is an API string → insert as text only, never as HTML.
        $sel.append( $( '<option/>' ).val( city.id ).text( city.city ) );
      } );
    } );
    // Remember the county so the chosen city is paired with it.
    $wrap.data( 'county', resolved.county_id );
    // A picker is shown → the current dropdown selection always wins, even if
    // the merchant clicks Issue without re-estimating. Seed the county now and
    // keep city_id in sync on every change.
    override = { county_id: resolved.county_id, city_id: parseInt( $sel.val(), 10 ) || 0 };
  }

  $( document ).on( 'change', '.webbership-ss-city', function () {
    override.city_id = parseInt( $( this ).val(), 10 ) || 0;
    // City changed → the prior estimate's couriers (and Issue button) are stale for
    // the new destination; clear them so the merchant must Re-estimate before issuing.
    $( '.webbership-ss-couriers' ).empty();
    $( '.webbership-ss-msg' ).text( override.city_id ? 'City changed — click Re-estimate.' : '' );
  } );

  $( document ).on( 'click', '.webbership-ss-estimate', function () {
    override = { county_id: 0, city_id: 0 };
    runEstimate();
  } );

  $( document ).on( 'click', '.webbership-ss-reestimate', function () {
    var $wrap = $( '.webbership-ss-city-picker' );
    var city  = $wrap.find( '.webbership-ss-city' ).val();
    if ( ! city ) { $( '.webbership-ss-msg' ).text( 'Pick a city.' ); return; }
    override = { county_id: $wrap.data( 'county' ), city_id: city };
    runEstimate();
  } );

  $( document ).on( 'click', '.webbership-ss-issue', function () {
    var courier = $( 'input[name=ss_courier]:checked' ).val();
    if ( ! courier ) { $( '.webbership-ss-msg' ).text( 'Pick a courier.' ); return; }
    // A city picker shown but no city chosen → don't issue with an unresolved address.
    if ( $( '.webbership-ss-city' ).length && ! override.city_id ) {
      $( '.webbership-ss-msg' ).text( 'Select the destination city first.' ); return;
    }
    $( '.webbership-ss-msg' ).text( 'Issuing…' );
    var data = { action: 'webbership_smartship_issue', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId(), courier_id: courier };
    if ( override.county_id && override.city_id ) { data.county_id = override.county_id; data.city_id = override.city_id; }
    $.post( WebbershipSmartShip.ajax, data ).done( function ( r ) {
      if ( ! r.success ) { $( '.webbership-ss-msg' ).text( r.data && r.data.message ? r.data.message : 'Failed' ); return; }
      window.location.reload();
    } );
  } );

  $( document ).on( 'click', '.webbership-ss-cancel', function () {
    if ( ! window.confirm( 'Cancel this AWB?' ) ) { return; }
    $.post( WebbershipSmartShip.ajax, { action: 'webbership_smartship_cancel', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId() } )
      .done( function ( r ) { if ( r.success ) { window.location.reload(); } else { alert( r.data && r.data.message ); } } );
  } );
  $( document ).on( 'click', '.webbership-ss-track', function () {
    var $t = $( '.webbership-ss-tracking' ).text( 'Loading…' );
    $.post( WebbershipSmartShip.ajax, { action: 'webbership_smartship_status', _ajax_nonce: WebbershipSmartShip.nonce, order_id: orderId() } )
      .done( function ( r ) { $t.text( r.success ? JSON.stringify( r.data.history || r.data ) : ( r.data && r.data.message ) ); } );
  } );
} )( jQuery );
