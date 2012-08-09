jQuery(document).ready(function($){

	p2_resolved_not_defined = function( object ) {
		return ( 'undefined' === typeof( object ) );
	}

	p2_resolved_parse_query_string = function( query_string ) {
		var query_vars = {};
		query_string.replace(
			new RegExp("([^?=&]+)(=([^&]*))?", "g"),
			function($0, $1, $2, $3) { if ( $3) query_vars[$1] = $3; }
		);
		return query_vars;
	}

	p2_resolved_ajax_update_post_state = function( post_id ) {
		$.get( p2rp.ajaxPollingUrl + '&post-id=' + parseInt( post_id ), function( response ) {
			p2_resolved_update_post_state_ui( post_id, reponse.state, response.action_links );
		});
	}

	p2_resolved_update_post_state_ui = function( post_id, state, action_links ) {
		$the_post = $( '.post-' + post_id );
		$the_post.removeClass( 'state-resolved state-unresolved' );
		console.log( state );
		if ( '' != state )
			$the_post.addClass( 'state-' + state );
		$( '.post-' + post_id + ' .p2-resolve-wrap ').replaceWith( action_links );
	}

	p2_resolved_build_resolve_ajax_url = function( query_vars ) {
		return p2rp.ajaxurl + '?' + $.param( query_vars );
	}

	var p2_resolved_hover_in = null;
	var p2_resolved_hover_out = null;

	/**
	 * Display the most recent audit log for each post
	 */
	$( '#main #postlist li.post .p2-resolved-posts-audit-log' ).each( function() {
		$( 'li', this ).last().show();
	});

	/**
	 * Display audit log on hover
	 */
	$( '.actions' ).on( 'hover.p2_resolved', '.p2-resolve-wrap',
		function(){
			clearTimeout( p2_resolved_hover_out );
			var audit_log = $(this).find( '.p2-resolved-posts-audit-log' );
			if ( audit_log.find('li').length ) {
				p2_resolved_hover_in = setTimeout( function() {
					audit_log.fadeIn();
				}, 1250 );
			}
		},
		function(){
			clearTimeout( p2_resolved_hover_in );
				var audit_log = $(this).find( '.p2-resolved-posts-audit-log' );
				p2_resolved_hover_out = setTimeout( function() {
					audit_log.fadeOut();
				}, 500 );
		}
	);

	/**
	 * detect the mark as unresolved checkbox and insert the special keyword
	 */
	$( '#new_post' ).on( 'submit.p2_resolved', function(e){
		if ( ! $( '#p2_resolved_posts_mark_as_unresolved_checkbox' ).prop( 'checked' ) )
			return;

		$('#posttext').val( $('#posttext').val() + '!unresolved' );
		e.preventDefault();
	});

	/**
	 * click to change state
	 */
	$( '#postlist' ).on( 'click.p2_resolved', '.actions .p2-resolve-link', function(e){
		e.preventDefault();

		var $original_link = $(this);
		query_vars = p2_resolved_parse_query_string( $original_link.attr('href') );

		if ( p2_resolved_not_defined( query_vars.post_id ) )
			return;

		$original_link.html( 'Saving...' );
		$original_link.addClass( 'p2-resolve-ajax-action' );
		url = p2_resolved_build_resolve_ajax_url( query_vars );
		$.get( url, function( response ){
			p2_resolved_update_post_state_ui( query_vars.post_id, response.state, response.action_links )
			$original_link.removeClass( 'p2-resolve-ajax-action' );
		});

		return false;
	});


	/**
	 * update the state of a post after a comment is inserted
	 */
	$( '#wrapper' ).ajaxComplete( function( e, xhr, options ){
		if ( p2_resolved_not_defined( options.data ) )
			return;

		query_vars = p2_resolved_parse_query_string( options.data );

		if ( p2_resolved_not_defined( query_vars.comment_post_ID ) )
			return;

		p2_resolved_ajax_update_post_state( query_vars.comment_post_ID );
	});

	/**
	 * handle pagination for the unresolved posts widget
	 */
	$('.p2-resolved-posts-pagination-link').on( 'click.p2_resolved', function(){
		var $this = $(this);

		var $parent = $this.closest( '.p2-resolved-posts-show-unresolved-posts' );
		var first_post = parseInt( $parent.find( '.p2-resolved-posts-first-post' ).html() );
		var last_post = parseInt( $parent.find( '.p2-resolved-posts-last-post' ).html() );
		var total_posts = parseInt( $parent.find( '.p2-resolved-posts-total-posts' ).html() )
		var posts_per_page = parseInt( $parent.find ('.p2-resolved-posts-ppp' ).html() );

		if ( $this.hasClass( 'p2-resolved-posts-previous-posts' ) ) {

			// Don't paginate if we're at the first item
			if ( first_post <= 1 )
				return false;

			// Calculate the first and last post to display
			first_post = first_post - posts_per_page;
			last_post = first_post + ( posts_per_page - 1);

		} else if ( $this.hasClass( 'p2-resolved-posts-next-posts' ) ) {

			// Don't paginate if we're at the last item
			if ( last_post >= total_posts )
				return false;

			// Calculate the first and last post to display
			first_post = first_post + posts_per_page;
			last_post = last_post + posts_per_page;
			if ( last_post > total_posts )
				last_post = total_posts;

		}

		// Show posts based on our pagination counter
		$parent.find( 'ul li' ).removeClass( 'active' ).addClass( 'hidden' );
		$parent.find( 'ul li' ).each(function( index, value ) {
			if ( ( index + 1 ) >= first_post && ( index + 1 ) <= last_post )
				$(this).addClass( 'active' ).removeClass( 'hidden') ;

		});

		// Reset the pagination
		$parent.find( '.p2-resolved-posts-first-post' ).html( first_post );
		$parent.find( '.p2-resolved-posts-last-post' ).html( last_post );
		return false;
	});

});