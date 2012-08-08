jQuery(document).ready(function($){

	$('.p2-resolved-posts-pagination-link').click(function(){

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