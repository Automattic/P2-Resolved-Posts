<?php
/* Plugin Name: P2 Resolved Posts
 * Description: Allows you to mark P2 posts for resolution and filter by state
 * Author: Daniel Bachhuber (and Andrew Nacin)
 * Author URI: http://danielbachhuber.com/
 * Contributors: Hugo Baeta (css)
 * Version: 0.2
 */

/**
 * Andrew Nacin wrote this plugin,
 * but Daniel Bachhuber mostly rewrote it because it didn't work at all
 * when he added it to WordPress.com
 * Original source: https://gist.github.com/1353754
 */

if ( defined('WP_CLI') && WP_CLI )
	require_once( dirname( __FILE__ ) . '/php/class-wp-cli.php' );

define( 'P2_RESOLVED_POSTS_VERSION', 0.2 );

/**
 * @package P2_Resolved_Posts
 */
class P2_Resolved_Posts {

	static $instance;

	const taxonomy = 'p2_resolved';
	const audit_log_key = 'p2_resolved_log';
	const resolved_keyword = '!resolved';
	const unresolved_keyword = '!unresolved';
	const normal_keyword = '!normal';

	/**
	 * Constructor. Saves instance and sets up initial hook.
	 */
	function __construct() {
		self::$instance = $this;

		load_plugin_textdomain( 'p2-resolve', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		add_action( 'after_setup_theme', array( $this, 'after_setup_theme' ) );
	}

	/**
	 * Sets up initial hooks, if the P2 theme is in play.
	 */
	function after_setup_theme() {

		if ( ! class_exists( 'P2' ) ) {
			// Don't run the plugin if P2 isn't active, but display an admin notice
			add_action( 'admin_notices', array( $this, 'action_admin_notices' ) );
			return;
		}


		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_head', array( $this, 'action_wp_head_ajax' ) );
		add_action( 'init', array( $this, 'action_init_handle_state_change' ) );
		add_action( 'p2_action_links', array( $this, 'p2_action_links' ), 100 );
		add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );
		add_action( 'widgets_init', array( $this, 'widgets_init' ) );
		add_filter( 'request', array( $this, 'request' ) );
		add_action( 'p2_post_form', array( $this, 'post_form' ) );
		add_action( 'comment_post', array( $this, 'comment_submit' ), 10, 2 );
		add_action( 'wp_insert_post', array( $this, 'post_submit' ), 10, 2 );
		add_action( 'wp_ajax_p2_resolved_posts_get_status', array( $this, 'p2_action_links' ) );
		add_action( 'wp_ajax_no_priv_p2_resolved_posts_get_status', array( $this, 'p2_action_links' ) );

		$this->register_taxonomy();
		if ( ! term_exists( 'unresolved', self::taxonomy ) )
			wp_insert_term( 'unresolved', self::taxonomy );

		// Posts can be marked unresolved automatically by default
		// if the user wishes
		if ( apply_filters( 'p2_resolved_posts_mark_new_as_unresolved', false ) )
			add_action( 'publish_post', array( $this, 'mark_new_as_unresolved' ), 10, 2 );

		// Comments can be closed automatically when a post is resolved
		// if the user wishes
		if ( apply_filters( 'p2_resolved_posts_close_comments_when_resolved', false ) )
			add_action( 'p2_resolved_posts_changed_state', array( $this, 'close_comments' ), 10, 2 );

	}

	/**
	 * Display an admin notice if the plugin is active but P2 isn't enabled
	 */
	function action_admin_notices() {
		$message = sprintf( __( "P2 Resolved Posts is enabled. You'll also need to activate the <a href='%s' target='_blank'>P2 theme</a> to start using the plugin.", 'p2-resolve' ), 'http://p2theme.com/' );
		echo '<div class="error"><p>' . $message . '</p></div>';
	}

	/**
	 * Register the taxonomy we're using for tracking threads
	 */
	function register_taxonomy() {
		register_taxonomy( self::taxonomy, 'post', array(
			'public' => true,
			'query_var' => 'resolved',
			'rewrite' => false,
			'show_ui' => false,
		) );
	}

	/**
	 * Parse the request if it's a request for our unresolved posts
	 */
	function request( $qvs ) {
		if ( ! isset( $qvs['resolved'] ) )
			return $qvs;

		if ( ! in_array( $qvs['resolved'], array( 'resolved', 'unresolved' ) ) ) {
			unset( $qvs['resolved'] );
			return $qvs;
		}

		// Just to be safe
		$qvs['resolved'] = sanitize_key( $qvs['resolved'] );

		add_action( 'parse_query', array( $this, 'parse_query' ) );
		add_filter( 'template_include', array( $this, 'force_home_template' ) );

		if ( ! isset( $qvs['tax_query'] ) )
			$qvs['tax_query'] = array();

		// Don't pay attention to sticky posts
		$qvs['ignore_sticky_posts'] = 1;

		// Filter the query to just the type of posts we're looking for
		$qvs['tax_query'][] = array(
			'taxonomy' => self::taxonomy,
			'terms' => array( $qvs['resolved'] ),
			'field' => 'slug',
			'operator' => 'IN',
		);
		if ( isset( $_GET['tags'] ) || isset( $_GET['post_tag'] ) ) {
				$filter_tags = ( isset( $_GET['tags'] ) ) ? $_GET['tags'] : $_GET['post_tag'];
			$filter_tags = (array)explode( ',', $filter_tags );
	 		foreach( (array)$filter_tags as $filter_tag ) {
	 			$filter_tag = sanitize_key( $filter_tag );
	 			$new_tax_query = array(
						'taxonomy' => 'post_tag',
					);
	 			if ( 0 === strpos( $filter_tag, '-') )
					$new_tax_query['operator'] = 'NOT IN';
				$filter_tag = trim( $filter_tag, '-' );
				if ( is_numeric( $filter_tag ) )
					$new_tax_query['field'] = 'ID';
				else
					$new_tax_query['field'] = 'slug';
				$new_tax_query['terms'] = $filter_tag;
	 			$qvs['tax_query'][] = $new_tax_query;
	 		}
	 	}
	 	if ( isset( $_GET['order'] ) && in_array( strtolower( $_GET['order'] ), array( 'asc', 'desc' ) ) )
	 		$qvs['order'] = sanitize_key( $_GET['order'] );

		return $qvs;
	}

	/**
	 * Nacin thought this was important
	 */
	function parse_query( $query ) {
		$query->is_home = true; // Force home detection.
	}

	/**
	 * Nacin thought this was important
	 */
	function force_home_template() {
		return get_home_template();
	}

	/**
	 * Sidebar widget for filtering posts
	 */
	function widgets_init() {
		include_once( dirname( __FILE__ ) . '/php/resolved-posts-links-widget.php' );
		include_once( dirname( __FILE__ ) . '/php/unresolved-posts-widget.php' );
		register_widget( 'P2_Resolved_Posts_Widget' );
		register_widget( 'P2_Resolved_Posts_Show_Unresolved_Posts_Widget' );
	}

	/**
	 * Add our action links to the P2 post
	 * also respond to ajax request to update the links
	 */
	function p2_action_links() {

		global $post;

		$is_ajax_request = ( defined( 'DOING_AJAX' ) && DOING_AJAX && !empty( $_REQUEST['action'] ) && 'p2_resolved_posts_get_status' == $_REQUEST['action'] );

		if ( $is_ajax_request && !empty( $_REQUEST['post-id'] ) ) {
			$post_id = $_REQUEST['post-id'];
		} elseif ( is_object( $post ) && !empty( $post->ID ) ) {
			$post_id = $post->ID;
		} else {
			return;
		}

		$args = array(
			'action' => 'p2-resolve',
			'post-id' => $post_id,
			'nonce' => wp_create_nonce( 'p2-resolve-' . $post_id ),
		);
		$link = add_query_arg( $args, get_site_url() );

		$css = array(
			'p2-resolve-link',
		);

		if ( has_term( 'unresolved', self::taxonomy, $post_id ) ) {
			$state = 'unresolved';
			$link = add_query_arg( 'mark', 'resolved', $link );
			$title = __( 'Flag as Resolved', 'p2-resolve' );
			$text = __( 'Unresolved', 'p2-resolve' );
		} else if ( has_term( 'resolved', self::taxonomy, $post_id ) ) {
			$state = 'resolved';
			$link = add_query_arg( 'mark', 'normal', $link );
			$title = __( 'Remove Resolved Flag', 'p2-resolve' );
			$text = __( 'Resolved', 'p2-resolve' );
		} else {
			$state = '';
			$link = add_query_arg( 'mark', 'unresolved', $link );
			$title = __( 'Flag as Unresolved', 'p2-resolve' );
			$text = __( 'Flag Unresolved', 'p2-resolve' );
		}

		if ( !empty( $state ) )
			$css[] = 'state-' . $state;

		$output = ( $is_ajax_request ) ? '' : ' | ';
		$output .= '<span class="p2-resolve-wrap"><a title="' . esc_attr( $title ) . '" href="' . esc_url( $link ) . '" class="' . esc_attr( implode( ' ', $css ) ) . '">' . esc_html( $text ) . '</a>';

		$audit_logs = get_post_meta( $post_id, self::audit_log_key );

		if ( !empty( $audit_logs ) ) {
			$audit_logs = array_reverse( $audit_logs, true );

			$output .= '<ul class="p2-resolved-posts-audit-log">';
			foreach( $audit_logs as $audit_log ) {
				$output .= $this->single_audit_log_output( $audit_log );
			}
			$output .= '</ul>';
		}

		$output .= '</span>';

		if ( $is_ajax_request ) {
			header( 'Content-Type: application/json' );
			die( json_encode( array( 'output' => $output, 'state' => $state ) ) );
		} else {
			echo $output;
		}
	}

	function post_form() {

		if ( apply_filters( 'p2_resolved_posts_disable_mark_as_unresolved_checkbox', false ) )
			return;

		$mark_as_unresolved_checkbox = '<p class="p2_resolved_posts_mark_as_unresolved_checkbox">';
		$mark_as_unresolved_checkbox .= '<input type="checkbox" name="p2_resolved_posts_mark_as_unresolved_checkbox" id="p2_resolved_posts_mark_as_unresolved_checkbox" value="unresolved"/> ';
		$mark_as_unresolved_checkbox .= '<label class="p2_resolved_posts_mark_as_unresolved_label" id="p2_resolved_posts_mark_as_unresolved_label" for="p2_resolved_posts_mark_as_unresolved_checkbox">' . __( 'Mark as Unresolved', 'p2-resolve' ) . '</label>';
		$mark_as_unresolved_checkbox .= '</p>';
		echo apply_filters( 'p2_resolved_posts_mark_as_unresolved_checkbox', $mark_as_unresolved_checkbox );


	}

	/**
	 * process a comment after it's inserted and adjust the
	 * resolved state as needed
	 */
	function comment_submit( $comment_id, $approved ) {
		if ( 'spam' == $approved )
			return;

		$comment = get_comment( $comment_id );

		if ( ! $this->string_contains_state_keywords( $comment->comment_content ) )
			return;

		$comment->comment_content = $this->process_string_post_resolved_state( $comment->comment_content, $comment->comment_post_ID );
		wp_update_comment( (array) $comment );
	}

	/**
	 * process a post after it's inserted and adjust the
	 * resolved state as needed
	 */
	function post_submit( $post_id, $post ) {

		if ( ! $this->string_contains_state_keywords( $post->post_content ) )
			return;

		$post->post_content = $this->process_string_post_resolved_state( $post->post_content, $post_id );
		wp_update_post( $post );
	}

	/**
	 * detect which keywords a string contains and change the
	 * post's state accordingly, then strip the keyword
	 */
	function process_string_post_resolved_state( $string, $post_id ) {
		if ( $this->string_contains_resolved_keyword( $string ) )
			$this->change_state( $post_id, 'resolved' );

		if ( $this->string_contains_unresolved_keyword( $string ) )
			$this->change_state( $post_id, 'unresolved' );

		if ( $this->string_contains_normal_keyword( $string ) )
			$this->change_state( $post_id, 'normal' );

		clean_object_term_cache( $post_id, get_post_type( $post_id ) );
		return $this->erase_keywords_from_string( $string );
	}

	/**
	 * detect if a string contains any of the keywords
	 */
	function string_contains_state_keywords( $string ) {
		return $this->string_contains_helper( $string, array( self::resolved_keyword, self::unresolved_keyword, self::normal_keyword ) );
	}

	/**
	 * detect if a string contains the resolved keyword
	 */
	function string_contains_resolved_keyword( $string ) {
		return $this->string_contains_helper( $string, self::resolved_keyword );
	}

	/**
	 * detect if a string contains the unresolved keyword
	 */
	function string_contains_unresolved_keyword( $string ) {
		return $this->string_contains_helper( $string, self::unresolved_keyword );
	}

	/**
	 * detect if a string contains the normal keyword
	 */
	function string_contains_normal_keyword( $string ) {
		return $this->string_contains_helper( $string, self::normal_keyword );
	}

	/**
	 * helper function to detect if a string contains contains the specified keywords
	 */
	function string_contains_helper( $string, $keywords ) {

		if ( !is_array( $keywords ) )
			$keywords = array( $keywords );

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $string, $keyword ) )
				return true;
		}

		return false;
	}

	/**
	 * helper function to erase keywords from a string
	 */
	function erase_keywords_from_string( $string, $keywords = null ) {

		if ( empty( $keywords ) )
			$keywords = array( self::resolved_keyword, self::unresolved_keyword, self::normal_keyword );

		if ( !is_array( $keywords ) )
			$keywords = array( $keywords );

		foreach ( $keywords as $keyword )
			$string = str_replace( $keyword, '', $string );

		return trim( $string );

	}


	function enqueue() {
		wp_enqueue_style( 'p2-resolved-posts', plugins_url( 'css/p2-resolved-posts.css', __FILE__ ), array(), P2_RESOLVED_POSTS_VERSION );
	}

	/**
	 * Javascript to make this whole operation run like AJAX
	 */
	function action_wp_head_ajax() {
		?>
		<script type="text/javascript">

		jQuery(document).ready(function(){

			var p2_resolved_hover_in = null;
			var p2_resolved_hover_out = null;

			// Display the most recent audit log for each post
			jQuery('#main #postlist li.post .p2-resolved-posts-audit-log').each( function() {
				jQuery('li', this).last().show();
			});

			jQuery('.actions .p2-resolve-wrap').hover(
				function(){
					clearTimeout( p2_resolved_hover_out );
					var audit_log = jQuery(this).find('.p2-resolved-posts-audit-log');
					if ( audit_log.find('li').length ) {
						p2_resolved_hover_in = setTimeout( function() {
							audit_log.fadeIn();
						}, 1250 );
					}
				},
				function(){
					clearTimeout( p2_resolved_hover_in );
					var audit_log = jQuery(this).find('.p2-resolved-posts-audit-log');
					p2_resolved_hover_out = setTimeout( function() {
							audit_log.fadeOut();
						}, 500 );
				}
			);


			jQuery('#wrapper').ajaxComplete(function(e, xhr, options){
				if ( 'undefined' === typeof(options.data) )
					return;

				var query_string = options.data;
				var query_vars = {};
				query_string.replace(
					new RegExp("([^?=&]+)(=([^&]*))?", "g"),
    			function($0, $1, $2, $3) { query_vars[$1] = $3; }
    		);

        if ( 'undefined' === typeof( query_vars.comment_post_ID ) )
        	return;

      	var post_id = query_vars.comment_post_ID;

      	jQuery.ajax({
					type: 'GET',
					url: '<?php echo $this->get_polling_ajax_uri(); ?>' + '&post-id=' + parseInt(post_id),
					success: function( response ) {
						$the_post = jQuery( '.post-' + post_id );
						$the_post.removeClass( 'state-resolved', 'state-unresolved' );
						if ( '' != response.state )
							$the_post.addClass( 'state-' + response.state );

						jQuery( '.post-' + post_id + ' .p2-resolve-wrap ').replaceWith( response.output );
					}
				});

			});

			jQuery('.actions .p2-resolve-link').click(function(){
				var original_link = jQuery(this);
				// Mark the thread as unresolved
				jQuery(this).html('Saving...');
				jQuery(this).addClass('p2-resolve-ajax-action');
				jQuery.get( original_link.attr('href') + '&ajax', function(data){
					// The update was successful
					if ( data.indexOf('<') == 0 ) {
						// Depending on the action we took, update the DOM
						// Need to replace the text, href and the title attribute
						if ( original_link.attr('href').indexOf('mark=unresolved') != -1 ) {
							original_link.closest('.post').addClass('state-unresolved');
							original_link.addClass('state-unresolved');
							var new_url = original_link.attr('href').replace('mark=unresolved', 'mark=resolved');
							original_link.attr('href', new_url );
							original_link.html('<?php _e("Unresolved","p2-resolve"); ?>');
							original_link.attr('title','<?php _e("Flag as Resolved","p2-resolve"); ?>');
						} else if ( original_link.attr('href').indexOf('mark=resolved') != -1 ) {
							original_link.closest('.post').removeClass('state-unresolved').addClass('state-resolved');
							original_link.removeClass('state-unresolved').addClass('state-resolved');
							var new_url = original_link.attr('href').replace('mark=resolved', 'mark=normal');
							original_link.attr('href', new_url );
							original_link.html('<?php _e("Resolved","p2-resolve"); ?>');
							original_link.attr('title','<?php _e("Remove Resolved Flag","p2-resolve"); ?>');
						} else if ( original_link.attr('href').indexOf('mark=normal') != -1 ) {
							original_link.closest('.post').removeClass('state-resolved');
							original_link.removeClass('state-resolved');
							var new_url = original_link.attr('href').replace('mark=normal', 'mark=unresolved');
							original_link.attr('href', new_url );
							original_link.html('<?php _e("Flag Unresolved","p2-resolve"); ?>');
							original_link.attr('title','<?php _e("Flag as Unresolved","p2-resolve"); ?>');
						}
						// Update the audit log
						original_link.closest('.post').find('ul.p2-resolved-posts-audit-log').prepend( data );

					} else {
						// Display the error if it happened
						original_link.html(data);
						original_link.attr('style', 'color: #FF0000 !important;');
					}
					original_link.removeClass('p2-resolve-ajax-action');
					return false;
				});
				return false;
			});
		});
		</script>
		<?
	}

	function get_polling_ajax_uri() {
		return add_query_arg( array( 'action' => 'p2_resolved_posts_get_status' ), wp_nonce_url( admin_url( 'admin-ajax.php' ), 'p2_resolved_posts_get_status' ) );
	}

	/**
	 * Extra CSS to style the post when there are open threads
	 */
	function post_class( $classes, $class, $post_id ) {
		if ( has_term( 'resolved', self::taxonomy, $post_id ) )
			$classes[] = 'state-resolved';

		if ( has_term( 'unresolved', self::taxonomy, $post_id ) )
			$classes[] = 'state-unresolved';
		return $classes;
	}

	/**
	 * Handle changing the state between 'unresolved', 'resolved' and normal
	 */
	function action_init_handle_state_change() {

		// Bail if the action isn't ours
		if ( !isset( $_GET['post-id'], $_GET['action'], $_GET['nonce'], $_GET['mark'] ) || $_GET['action'] != 'p2-resolve' )
			return;

		$error = false;
		$current_user = wp_get_current_user();

		$states = array(
			'resolved',
			'unresolved',
			'normal',
		);
		$state = '';
		if ( in_array( $_GET['mark'], $states ) )
			$state = sanitize_key( $_GET['mark'] );
		else
			$error = __( 'Bad state', 'p2-resolve' );

		$error = false;
		$post_id = intval( $_GET['post-id'] );

		$post = get_post( $post_id );
		if ( !$post )
			$error = __( 'Invalid post', 'p2-resolve' );

		if ( !wp_verify_nonce( $_GET['nonce'], 'p2-resolve-' . $post_id ) )
			$error = __( "Nonce error", 'p2-resolve' );

		// If there were no errors, set the post in that state
		if ( !$error ) {
			if ( $state == 'normal' )
				$state = '';
			$message = $this->change_state( $post_id, $state );
			clean_object_term_cache( $post->ID, $post->post_type );
		} else {
			$message = $error;
		}

		// Echo data if this was an AJAX request, otherwise go to the post
		if ( isset( $_GET['ajax'] ) ) {
			echo $message;
		} else {
			wp_safe_redirect( get_permalink( $post->ID ) );
		}
		die;

	}

	/**
	 * Change the state of a given post
	 */
	function change_state( $post_id, $state ) {
		if ( ! taxonomy_exists( self::taxonomy ) )
			$this->register_taxonomy();

		wp_set_object_terms( $post_id, (array)$state, self::taxonomy, false );
		$args = array(
				'new_state' => $state,
			);
		$args = $this->log_state_change( $post_id, $args );
		do_action( 'p2_resolved_posts_changed_state', $state, $post_id );

		return $this->single_audit_log_output( $args );
	}

	/**
	 * Log when a post has changed resolution state.
	 * Don't blow away existing logs though.
	 *
	 * @since 0.2
	 */
	function log_state_change( $post_id, $args = array() ) {

		$defaults = array(
				'user_login' => wp_get_current_user()->user_login,
				'new_state' => '',
				'timestamp' => time(),
			);
		$args = array_merge( $defaults, $args );
		add_post_meta( $post_id, self::audit_log_key, $args );
		return $args;
	}

	/**
	 * Produce the HTML for a single audit log
	 *
	 * @since 0.2
	 */
	function single_audit_log_output( $args ) {

		$date = get_date_from_gmt( date( 'Y-m-d H:i:s', $args['timestamp'] ), get_option( 'date_format' ) );
		$time = get_date_from_gmt( date( 'Y-m-d H:i:s', $args['timestamp'] ), get_option( 'time_format' ) );
		$date_time = sprintf( __( '<span class="date-time">%1$s on %2$s</span>', 'p2-resolve' ), esc_html( $time ), esc_html( $date ) );

		$user = get_user_by( 'login', $args['user_login'] );
		// Accomodate for removed users
		if ( $user ) {
			$avatar = get_avatar( $user->ID, 16 );
			$display_name = $user->display_name;
		} else {
			$avatar = '';
			$display_name = __( 'Someone', 'p2-resolve' );
		}

		// If there's a 'resolved' or 'unresolved' state currently set
		if ( $args['new_state'] )
			$text = sprintf( __( '%1$s marked this %2$s<br />%3$s', 'p2-resolve' ), esc_html( $display_name ), esc_html( $args['new_state'] ), $date_time );
		else
			$text = sprintf( __( '%1$s removed resolution<br />%2$s', 'p2-resolve' ), esc_html( $display_name ), $date_time );

		$html = '<li>' . $avatar . '<span class="audit-log-text">' . $text . '</span></li>';
		return $html;
	}

	/**
	 * Automatically mark a newly published post as unresolved
	 * To enable, include the following in your theme's functions.php:
	 * - add_filter( 'p2_resolved_posts_mark_new_as_unresolved', '__return_true' );
	 *
	 * @since 0.2
	 */
	function mark_new_as_unresolved( $post_id, $post ) {

		// Allow certain types of posts to not be marked as unresolved
		if ( !apply_filters( 'p2_resolved_posts_maybe_mark_new_as_unresolved', true, $post ) )
			return;

		wp_set_post_terms( $post_id, array( 'unresolved' ), self::taxonomy );
		$args = array(
					'new_state' => 'unresolved',
				);
		$this->log_state_change( $post_id, $args );
	}

	/**
	 * Automatically close comments on resolved posts
	 * To enable, include the following in your theme's functions.php:
	 * - add_filter( 'p2_resolved_posts_close_comments_when_resolved', '__return_true' );
	 *
	 * @since 0.3
	 */
	function close_comments( $state, $post_id ) {
		if ( 'resolved' != $state )
			return;

		$the_post = get_post( $post_id );
		$the_post->comment_status = 'closed';
		wp_update_post( $the_post );

	}

}

$p2_resolved_posts = new P2_Resolved_Posts();