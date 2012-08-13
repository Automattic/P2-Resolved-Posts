<?php
/**
 * class P2_Resolved_Posts_Show_Unresolved_Posts_Widget
 * Show a listing of resolved or unresolved posts with optional filters
 *
 * @author danielbachhuber
 */
 class P2_Resolved_Posts_Show_Unresolved_Posts_Widget extends WP_Widget {

 	private $widget_args = array(
		'title' => '',
		'posts_per_page' => 5,
		'order' => 'DESC',
	 	);

 	function __construct() {

 		$widget_ops = array(
			'classname' => 'p2-resolved-posts-show-unresolved-posts',
			'description' => __( 'Display an (optionally filtered) list of unresolved posts', 'p2-resolved-posts' )
		);
		parent::__construct( 'p2_resolved_posts_show_unresolved_posts', __( 'P2 Unresolved Posts', 'p2-resolved-posts' ), $widget_ops );

 	}

 	/**
 	 * Form for the widget settings
 	 */
 	function form( $instance ) {
 		$instance = wp_parse_args( (array)$instance, $this->widget_args );
 		extract( $instance );

 		?>
		<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e( 'Title', 'p2-resolved-posts' ); ?>: <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" /></label></p>
		<p><label for="<?php echo $this->get_field_id('posts_per_page'); ?>"><?php _e( 'Posts Per Page', 'p2-resolved-posts' ); ?>: <input class="widefat" id="<?php echo $this->get_field_id('posts_per_page'); ?>" name="<?php echo $this->get_field_name('posts_per_page'); ?>" type="text" value="<?php echo esc_attr( $posts_per_page ); ?>" maxlength="2" /></label></p>
		<p><label for="<?php echo $this->get_field_id('filter_tags'); ?>"><?php _e( 'Filter to these tags', 'p2-resolved-posts' ); ?>: <input class="widefat" id="<?php echo $this->get_field_id('filter_tags'); ?>" name="<?php echo $this->get_field_name('filter_tags'); ?>" type="text" value="<?php if ( !empty( $filter_tags ) ) echo esc_attr( $filter_tags ); ?>" /></label><br />
			<span class="description"><?php _e( "Separate multiple tags with commas, and prefix with '-' to exclude.", 'p2-resolved-posts' ); ?></span>
		</p>
		<p><label for="<?php echo $this->get_field_id('order'); ?>"><?php _e( 'Show', 'p2-resolved-posts' ); ?>: <select id="<?php echo $this->get_field_id('order'); ?>" name="<?php echo $this->get_field_name('order'); ?>">
			<?php $order_options = array(
					'DESC' => __( 'Newest posts first', 'p2-resolved-posts' ),
					'ASC' => __( 'Oldest posts first', 'p2-resolved-posts' ),
				);
				foreach( $order_options as $key => $order_option ) {
					echo '<option value="' . esc_attr( $key ) . '"' . selected( $order, $key, false ) . '>' . esc_html( $order_option ) . '</option>';
				} ?>
		</select>
		</p>
<?php
 	}

 	/**
 	 * Validate any new widget form data
 	 */
 	function update( $new_instance, $old_instance ) {
 		$instance = $old_instance;
		$new_instance = wp_parse_args( (array)$new_instance, $this->widget_args );
		// Sanitize the values
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		$instance['posts_per_page'] = (int)$new_instance['posts_per_page'];
		if ( $instance['posts_per_page'] < 1 || $instance['posts_per_page'] > 99 )
			$instance['posts_per_page'] = 1;
		$multi_tags = (array)explode( ',', $new_instance['filter_tags'] );
		$multi_tags = array_map( 'sanitize_key', $multi_tags );
		// We only want to save tags that actually exist
		foreach( $multi_tags as $key => $multi_tag ) {
			if ( 0 === strpos( $multi_tag, '-') )
				$invert = '-';
			else
				$invert = '';
			if ( is_numeric( $multi_tag ) ) {
				if ( false === ( $tag = get_term_by( 'id', $multi_tag, 'post_tag' ) ) )
					unset( $multi_tags[$key] );
				if ( is_object( $tag ) )
					$multi_tags[$key] = $invert . $tag->term_id;
			} else {
				if ( false === ( $tag = get_term_by( 'slug', $multi_tag, 'post_tag' ) ) )
					unset( $multi_tags[$key] );
				if ( is_object( $tag ) )
					$multi_tags[$key] = $invert . $tag->slug;
			}
		}
		$instance['filter_tags'] = implode( ',', $multi_tags );
		if ( $new_instance['order'] != 'ASC' )
			$new_instance['order'] = 'DESC';
		$instance['order'] = $new_instance['order'];
		return $instance;
 	}

 	/**
 	 * Display the widget
 	 *
 	 * @todo The output should be cached and regenerated when a post is marked as resolved, etc.
 	 */
 	function widget( $args, $instance ) {
 		extract( $args );
 		extract( $instance );

 		echo $before_widget;
 		$link_args = array(
 				'resolved' => 'unresolved',
 				'tags' => $filter_tags,
 				'order' => $order,
	 		);
 		$more_link = add_query_arg( $link_args, get_site_url() );
 		if ( $title )
 			echo $before_title . $title . '&nbsp;<a title="' . esc_attr( __( 'See all matching unresolved posts', 'p2-resolved-posts' ) ) . '" href="' . esc_url( $more_link ) . '">&raquo;</a>' . $after_title;

 		if ( $posts_per_page > 0 ) {
 			$query_args = array(
 					'posts_per_page' =>  50, // Load a lot of posts so we can AJAX paginate through them
 					'tax_query' => array(
 							array(
 									'taxonomy' => P2_Resolved_Posts::taxonomy,
 									'field' => 'slug',
 									'terms' => 'unresolved',
	 							),
	 					),
	 				'order' => sanitize_key( $order ),
	 			);
	 		$filter_tags = (array)explode( ',', $filter_tags );
	 		foreach( (array)$filter_tags as $filter_tag ) {
	 			if ( !$filter_tag )
	 				continue;
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
	 			$query_args['tax_query'][] = $new_tax_query;
	 		}
 			$unresolved_posts = new WP_Query( $query_args );
 			if ( $unresolved_posts->have_posts() ) {
 				$current_post = 1;
 				if ( $unresolved_posts->found_posts < $posts_per_page )
 					$posts_per_page = $unresolved_posts->found_posts;
 				echo '<p class="p2-resolved-posts-show-unresolved-posts-pagination">';
 				if ( $unresolved_posts->found_posts > $posts_per_page )
		 			echo '<a href="#" class="p2-resolved-posts-previous-posts p2-resolved-posts-pagination-link" class="inactive">' . __( '&larr;', 'p2-resolved-posts' ) . '</a>&nbsp;&nbsp;';
		 		echo sprintf( __( 'Showing <span class="p2-resolved-posts-first-post">1</span>-<span class="p2-resolved-posts-last-post">%1$d</span> of <span class="p2-resolved-posts-total-posts">%2$d</span> unresolved posts'), esc_html( $posts_per_page ), esc_html( $unresolved_posts->found_posts ) );
		 		if ( $unresolved_posts->found_posts > $posts_per_page )
		 			echo '&nbsp;&nbsp;<a href="#" class="p2-resolved-posts-next-posts p2-resolved-posts-pagination-link">' . __( '&rarr;', 'p2-resolved-posts' ) . '</a>';
		 		echo '</p>';
		 		echo '<span class="hidden p2-resolved-posts-ppp">' . esc_html( $posts_per_page ) . '</span>';
 				echo '<ul>';
 				while( $unresolved_posts->have_posts() ) {
 					$unresolved_posts->the_post();
 					global $post;
					echo '<li';
					if ( $current_post > $posts_per_page )
						echo ' class="hidden"';
					else
						echo ' class="active"';
					echo '>';
					echo get_avatar( $post->post_author, 32 );
					echo '<div class="inner"><a href="' . get_permalink() . '" title="' . esc_attr( get_the_excerpt() ) . '">' . get_the_title() . '</a><br />';
					$post_timestamp = strtotime( $post->post_date );
					echo '<span>' . sprintf( __( '%s old', 'p2-resolved-posts' ), esc_html( human_time_diff( $post_timestamp ) ) ) . ', ';
					comments_number( __( 'no comments', 'p2-resolved-posts' ), __( 'one comment', 'p2-resolved-posts' ), __( '% comments', 'p2-resolved-posts' ) );
					echo '</span>';
					echo '</div></li>';
					$current_post++;
 				}
				wp_reset_postdata();
 				echo '</ul>';
 			} else {
 				echo '<p>' . __( 'Nice work! Everything in this list has been resolved.', 'p2-resolved-posts' ) . '</p>';
 			}
 		}

 		echo $after_widget;
 	}

 }