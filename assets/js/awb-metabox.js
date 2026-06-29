( function ( $ ) {
  function box() { return $( '.ovride-ss-awb' ); }
  function orderId() { return box().data( 'order' ); }

  // Chosen override (set when the merchant picks a city); threaded into re-estimate + issue.
  var override = { county_id: 0, city_id: 0 };

  function runEstimate() {
    var $msg = $( '.ovride-ss-msg' ).text( 'Estimating…' );
    var data = { action: 'ovride_smartship_estimate', _ajax_nonce: OvrideSmartShip.nonce, order_id: orderId() };
    if ( override.county_id && override.city_id ) { data.county_id = override.county_id; data.city_id = override.city_id; }
    $.post( OvrideSmartShip.ajax, data ).done( function ( r ) {
      if ( ! r.success ) { $msg.text( r.data && r.data.message ? r.data.message : 'Failed' ); return; }
      $msg.text( '' );
      renderCouriers( r.data.costs || [] );
      maybeRenderCityPicker( r.data.resolved );
    } );
  }

  function renderCouriers( costs ) {
    var $c = $( '.ovride-ss-couriers' ).empty();
    costs.forEach( function ( c ) {
      var id = 'ss-c-' + c.courier_id;
      $c.append( $( '<label/>' ).append(
        $( '<input type="radio" name="ss_courier"/>' ).val( c.courier_id ).attr( 'id', id ),
        ' ', document.createTextNode( c.courier_name + ' — ' + c.cost + ' lei (' + ( c.delivery_date || '' ) + ')' ), '<br/>'
      ) );
    } );
    $c.append( $( '<button type="button" class="button button-primary ovride-ss-issue">' ).text( 'Issue AWB' ) );
  }

  // Resolver wasn't confident: let the merchant pick the city for the resolved county.
  function maybeRenderCityPicker( resolved ) {
    if ( ! resolved || resolved.confident !== false || ! resolved.county_id ) { return; }
    var $wrap = $( '.ovride-ss-city-picker' );
    if ( ! $wrap.length ) {
      $wrap = $( '<div class="ovride-ss-city-picker"/>' );
      box().append( $wrap );
    }
    $wrap.empty();
    $wrap.append( $( '<p/>' ).text( "Couldn't match the city — pick it:" ) );
    var $sel = $( '<select class="ovride-ss-city"/>' );
    $wrap.append( $sel );
    $wrap.append( $( '<button type="button" class="button ovride-ss-reestimate">' ).text( 'Re-estimate' ) );
    $.post( OvrideSmartShip.ajax, {
      action: 'ovride_smartship_cities', _ajax_nonce: OvrideSmartShip.nonce,
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

  $( document ).on( 'change', '.ovride-ss-city', function () {
    override.city_id = parseInt( $( this ).val(), 10 ) || 0;
  } );

  $( document ).on( 'click', '.ovride-ss-estimate', function () {
    override = { county_id: 0, city_id: 0 };
    runEstimate();
  } );

  $( document ).on( 'click', '.ovride-ss-reestimate', function () {
    var $wrap = $( '.ovride-ss-city-picker' );
    var city  = $wrap.find( '.ovride-ss-city' ).val();
    if ( ! city ) { $( '.ovride-ss-msg' ).text( 'Pick a city.' ); return; }
    override = { county_id: $wrap.data( 'county' ), city_id: city };
    runEstimate();
  } );

  $( document ).on( 'click', '.ovride-ss-issue', function () {
    var courier = $( 'input[name=ss_courier]:checked' ).val();
    if ( ! courier ) { $( '.ovride-ss-msg' ).text( 'Pick a courier.' ); return; }
    // A city picker shown but no city chosen → don't issue with an unresolved address.
    if ( $( '.ovride-ss-city' ).length && ! override.city_id ) {
      $( '.ovride-ss-msg' ).text( 'Select the destination city first.' ); return;
    }
    $( '.ovride-ss-msg' ).text( 'Issuing…' );
    var data = { action: 'ovride_smartship_issue', _ajax_nonce: OvrideSmartShip.nonce, order_id: orderId(), courier_id: courier };
    if ( override.county_id && override.city_id ) { data.county_id = override.county_id; data.city_id = override.city_id; }
    $.post( OvrideSmartShip.ajax, data ).done( function ( r ) {
      if ( ! r.success ) { $( '.ovride-ss-msg' ).text( r.data && r.data.message ? r.data.message : 'Failed' ); return; }
      window.location.reload();
    } );
  } );
} )( jQuery );
